<?php

namespace App\Console\Commands;

use App\Bot\Ai\AiSignalValidationService;
use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Bot\MarketData\DominanceService;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Signal\SignalEngine;
use App\Bot\TradeManagement\TradeManager;
use App\Bot\Universe\UniverseScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Main bot loop: scans the universe, scores signals, risk-checks and opens
 * qualifying ones (paper unless real_trading_enabled), and manages every open
 * position's TP/SL/time-exit/daily-loss-breach each cycle.
 */
class BotRunCommand extends Command
{
    protected $signature = 'bot:run {--once : Run a single cycle and exit}';

    protected $description = 'Run the MEXC futures bot loop (market scan, signal scoring, risk-checked paper/real trading)';

    private ?array $currentPairs = null;
    private ?int $lastUniverseScanAt = null;

    public function handle(
        UniverseScanner $universeScanner,
        SignalEngine $signalEngine,
        MarketDataService $marketData,
        TradeManager $tradeManager,
        DominanceService $dominanceService,
        AiSignalValidationService $aiValidator,
    ): int {
        if ($this->option('once')) {
            if (! BotConfig::get('bot_enabled')) {
                $this->warn('bot_enabled is false — turn it on from the Bot settings page (or set BOT_ENABLED=true) before running --once.');
                return self::FAILURE;
            }

            $this->info('Bot starting (single cycle). real_trading_enabled=' . (BotConfig::get('real_trading_enabled') ? 'true (LIVE)' : 'false (paper trading)'));
            $this->runCycleLocked($universeScanner, $signalEngine, $marketData, $tradeManager, $dominanceService, $aiValidator);

            return self::SUCCESS;
        }

        // Persistent process: never exits on its own (so it's safe to run under
        // `composer run dev`'s concurrently --kill-others without taking down the
        // other processes). Idles and re-checks bot_enabled every 10s so toggling
        // it from the Bot settings page takes effect live, with no restart needed.
        $this->info('Bot process started — idling until bot_enabled is turned on from the Bot settings page.');

        $wasEnabled = false;

        do {
            // Without this, a persistent process would keep serving whatever
            // bot_settings snapshot it first read on startup forever — see
            // BotConfig::clearCache() docblock.
            BotConfig::clearCache();

            $enabled = BotConfig::get('bot_enabled');

            if (! $enabled) {
                if ($wasEnabled) {
                    $this->info('bot_enabled turned off — pausing.');
                }
                $wasEnabled = false;
                sleep(10);
                continue;
            }

            if (! $wasEnabled) {
                $this->info('bot_enabled turned on — starting cycles. real_trading_enabled=' . (BotConfig::get('real_trading_enabled') ? 'true (LIVE)' : 'false (paper trading)'));
                $wasEnabled = true;
            }

            // A transient failure (e.g. a network blip against MEXC's API) must not kill
            // this persistent process — it would otherwise sit dead until someone notices
            // and manually restarts it. --once (above) intentionally lets exceptions
            // propagate for debug visibility; this loop instead logs and tries again next
            // tick, same as how a single symbol's analyze() failure is already handled.
            try {
                $this->runCycleLocked($universeScanner, $signalEngine, $marketData, $tradeManager, $dominanceService, $aiValidator);
            } catch (\Throwable $e) {
                $this->error("Cycle failed: {$e->getMessage()} — will retry next cycle.");
                BotLogger::error('system', "Cycle failed: {$e->getMessage()}", []);
            }

            sleep((int) BotConfig::get('signal_scan_interval_seconds'));
        } while (true);
    }

    /**
     * Guards runCycle() with a DB-backed atomic lock so that if more than one
     * instance of this process is ever running at once (e.g. a hosting
     * platform autoscaling the worker), only one of them executes a cycle at
     * a time. Without this, concurrent instances could each pass risk checks
     * and open duplicate positions for the same signal. The lock
     * auto-expires so a crashed process can't wedge future cycles forever.
     */
    private function runCycleLocked(
        UniverseScanner $universeScanner,
        SignalEngine $signalEngine,
        MarketDataService $marketData,
        TradeManager $tradeManager,
        DominanceService $dominanceService,
        AiSignalValidationService $aiValidator,
    ): void {
        $lock = Cache::lock('bot:run-cycle', 300);

        if (! $lock->get()) {
            $this->warn('Another bot instance already has a cycle in progress — skipping this tick.');
            return;
        }

        try {
            $this->runCycle($universeScanner, $signalEngine, $marketData, $tradeManager, $dominanceService, $aiValidator);
        } finally {
            $lock->release();
        }
    }

    private function runCycle(
        UniverseScanner $universeScanner,
        SignalEngine $signalEngine,
        MarketDataService $marketData,
        TradeManager $tradeManager,
        DominanceService $dominanceService,
        AiSignalValidationService $aiValidator,
    ): void {
        $pairs = $this->pairsForThisCycle($universeScanner);
        $contracts = $marketData->getActiveUsdtContracts()->keyBy('symbol');
        $dominanceTrend = $dominanceService->getTrend();

        if ($dominanceTrend) {
            $this->info("USDT dominance: {$dominanceTrend['current_usdt_dominance_pct']}% (change {$dominanceTrend['change_pct']}pp over {$dominanceTrend['lookback_minutes']}m)");
        }

        $this->info('Analyzing ' . count($pairs) . ' pairs: ' . implode(', ', $pairs));

        $opened = [];
        foreach ($pairs as $symbol) {
            try {
                $takerFeeRate = isset($contracts[$symbol]) ? (float) $contracts[$symbol]['takerFeeRate'] : null;
                $signal = $signalEngine->analyze($symbol, $takerFeeRate, $dominanceTrend);

                if ($signal->skip_reason === 'pending_risk_evaluation') {
                    $signal = $aiValidator->apply($signal, $takerFeeRate);
                }

                $qualifies = $signal->skip_reason === 'pending_risk_evaluation';
                $line = "{$symbol}: " . ($signal->direction ?? 'NO SIGNAL') . " (confidence {$signal->confidence_score})";

                if ($qualifies && isset($contracts[$symbol])) {
                    $tradeManager->openTrade($signal, $contracts[$symbol]);
                    $signal->refresh();

                    if ($signal->opened) {
                        $opened[] = $symbol;
                        $this->line("<info>{$line} — OPENED</info>");
                    } else {
                        $this->line("{$line} — risk-blocked: {$signal->skip_reason}");
                    }
                } else {
                    $this->line($line);
                }
            } catch (\Throwable $e) {
                $this->error("{$symbol}: failed — {$e->getMessage()}");
                BotLogger::error('signal', "Failed to analyze {$symbol}: {$e->getMessage()}", [], $symbol);
            }
        }

        $tradeManager->manageOpenPositions($marketData);

        BotLogger::info('system', 'Cycle complete', [
            'pairs_analyzed' => count($pairs),
            'opened_trades'  => $opened,
        ]);
    }

    /** @return array<int, string> */
    private function pairsForThisCycle(UniverseScanner $universeScanner): array
    {
        if (! BotConfig::get('universe_scanner_enabled')) {
            return BotConfig::get('default_pairs');
        }

        $scanIntervalSeconds = BotConfig::get('universe_scan_interval_minutes') * 60;
        $needsScan = $this->currentPairs === null
            || $this->lastUniverseScanAt === null
            || (time() - $this->lastUniverseScanAt) >= $scanIntervalSeconds;

        if ($needsScan) {
            $this->currentPairs = $universeScanner->scan();
            $this->lastUniverseScanAt = time();
        }

        return $this->currentPairs ?: BotConfig::get('default_pairs');
    }
}
