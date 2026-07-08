<?php

namespace App\Console\Commands;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Bot\MarketData\DominanceService;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Signal\SignalEngine;
use App\Bot\TradeManagement\TradeManager;
use App\Bot\Universe\UniverseScanner;
use Illuminate\Console\Command;

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
    ): int {
        if (! BotConfig::get('bot_enabled') && ! $this->option('once')) {
            $this->warn('bot_enabled is false — refusing to start the continuous loop. Use --once to force a single debug cycle, or set BOT_ENABLED=true.');
            return self::FAILURE;
        }

        $this->info('Bot starting. real_trading_enabled=' . (BotConfig::get('real_trading_enabled') ? 'true (LIVE)' : 'false (paper trading)'));

        do {
            $this->runCycle($universeScanner, $signalEngine, $marketData, $tradeManager, $dominanceService);

            if ($this->option('once')) {
                break;
            }

            sleep((int) BotConfig::get('signal_scan_interval_seconds'));
        } while (true);

        return self::SUCCESS;
    }

    private function runCycle(
        UniverseScanner $universeScanner,
        SignalEngine $signalEngine,
        MarketDataService $marketData,
        TradeManager $tradeManager,
        DominanceService $dominanceService,
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
                $signal = $signalEngine->analyze($symbol, isset($contracts[$symbol]) ? (float) $contracts[$symbol]['takerFeeRate'] : null, $dominanceTrend);

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
