<?php

namespace App\Bot\Backtest;

use App\Bot\Config\BotConfig;
use App\Bot\Hedge\HedgeManager;
use App\Bot\Indicators\IndicatorService;
use App\Bot\Signal\SignalEngine;
use App\Bot\Sizing\PositionSizingService;
use App\Bot\TradeManagement\ExitDecider;
use App\Services\MexcFuturesService;
use Illuminate\Support\Str;

/**
 * Walk-forward simulation against historical MEXC candles. Reuses the exact same
 * scoring (SignalEngine::score), sizing (PositionSizingService), hedge-split
 * (HedgeManager) and exit-priority (ExitDecider) logic as live/paper trading, so a
 * backtest result reflects what the live bot would actually have done — never touches
 * bot_trades, OrderManager, or sends anything to MEXC.
 *
 * Simplifications vs. live trading (documented, not silent): the universe scanner
 * isn't re-run historically (symbols are caller-specified), USDT dominance isn't
 * simulated (no historical series wired up), and per-contract leverage-cap /
 * live-account-balance risk checks are skipped (margin/position/cooldown/daily-loss
 * caps are still enforced).
 */
class BacktestEngine
{
    /** @var BacktestPosition[] */
    private array $openPositions = [];

    /** @var BacktestPosition[] */
    private array $closedPositions = [];

    /** @var array<string, int> symbol => last activity unix ts (open or close), for cooldown */
    private array $lastActivity = [];

    public function __construct(
        private BacktestDataService $dataService,
        private IndicatorService $indicators,
        private SignalEngine $signalEngine,
        private PositionSizingService $sizing,
        private HedgeManager $hedge,
        private ExitDecider $exitDecider,
        private MexcFuturesService $mexc,
    ) {}

    /**
     * @param array<int, string> $symbols
     * @return array{summary: array, trades: array}
     */
    public function run(array $symbols, int $fromTs, int $toTs): array
    {
        $this->openPositions   = [];
        $this->closedPositions = [];
        $this->lastActivity    = [];

        $lookback = (int) BotConfig::get('minimum_historical_candles');

        $symbolData = [];
        foreach ($symbols as $symbol) {
            $symbolData[$symbol] = $this->dataService->fetchCandles($symbol, $fromTs, $toTs, $lookback);
        }

        $contracts = collect($this->mexc->getContractList())->keyBy('symbol');
        $feeRates  = $contracts->only($symbols)->map(fn ($c) => (float) $c['takerFeeRate'])->all();

        $ticks = $this->buildTickList($symbolData, $fromTs, $toTs);
        if (empty($ticks)) {
            throw new \RuntimeException('No candle data available for the requested symbols/range.');
        }

        $pointers = [];
        foreach ($symbols as $symbol) {
            $pointers[$symbol] = ['5M' => 0, '15M' => 0, '1H' => 0];
        }

        $priceBySymbol = [];

        foreach ($ticks as $tickTime) {
            $this->advancePointers($symbols, $symbolData, $pointers, $tickTime, $priceBySymbol);

            if ($this->dailyLossLimitBreached($tickTime) && ! empty($this->openPositions)) {
                foreach ($this->openPositions as $pos) {
                    $pos->close($priceBySymbol[$pos->symbol] ?? $pos->entryPrice, $tickTime, 'daily_loss_limit_breached');
                    $this->closedPositions[] = $pos;
                }
                $this->openPositions = [];
            } else {
                $this->manageOpenPositions($symbolData, $pointers, $tickTime, $priceBySymbol);
            }

            if ($this->dailyLossLimitBreached($tickTime)) {
                continue;
            }

            foreach ($symbols as $symbol) {
                if ($pointers[$symbol]['1H'] < $lookback || $pointers[$symbol]['15M'] < $lookback || $pointers[$symbol]['5M'] < $lookback) {
                    continue; // not enough history yet at this point in the walk
                }
                if ($this->hasOpenPosition($symbol)) {
                    continue;
                }

                $this->tryOpen($symbol, $symbolData, $pointers, $tickTime, $feeRates[$symbol] ?? null);
            }
        }

        $lastTick = end($ticks);
        foreach ($this->openPositions as $pos) {
            $pos->close($priceBySymbol[$pos->symbol] ?? $pos->entryPrice, $lastTick, 'backtest_end');
            $this->closedPositions[] = $pos;
        }
        $this->openPositions = [];

        return [
            'summary' => $this->buildSummary(),
            'trades'  => array_map(fn ($p) => $p->toArray(), $this->closedPositions),
        ];
    }

    /** @return array<int, int> Sorted, unique 5M candle timestamps within [from, to] across all symbols. */
    private function buildTickList(array $symbolData, int $fromTs, int $toTs): array
    {
        $tickSet = [];
        foreach ($symbolData as $tf) {
            foreach ($tf['5M'] as $c) {
                if ($c['time'] >= $fromTs && $c['time'] <= $toTs) {
                    $tickSet[$c['time']] = true;
                }
            }
        }
        $ticks = array_keys($tickSet);
        sort($ticks);

        return $ticks;
    }

    private function advancePointers(array $symbols, array $symbolData, array &$pointers, int $tickTime, array &$priceBySymbol): void
    {
        foreach ($symbols as $symbol) {
            foreach (['5M', '15M', '1H'] as $tf) {
                $candles = $symbolData[$symbol][$tf];
                $count   = count($candles);
                while ($pointers[$symbol][$tf] < $count && $candles[$pointers[$symbol][$tf]]['time'] <= $tickTime) {
                    $pointers[$symbol][$tf]++;
                }
            }
            if ($pointers[$symbol]['5M'] > 0) {
                $priceBySymbol[$symbol] = $symbolData[$symbol]['5M'][$pointers[$symbol]['5M'] - 1]['close'];
            }
        }
    }

    private function manageOpenPositions(array $symbolData, array $pointers, int $tickTime, array $priceBySymbol): void
    {
        $bySet = [];
        foreach ($this->openPositions as $pos) {
            $bySet[$pos->tradeSetId][] = $pos;
        }

        foreach ($bySet as $legs) {
            if (count($legs) >= 2) {
                $this->manageHedgeSet($legs, $symbolData, $pointers, $tickTime, $priceBySymbol);
            } else {
                $this->manageSingleLeg($legs[0], $symbolData, $pointers, $tickTime, $priceBySymbol);
            }
        }

        $this->openPositions = array_values(array_filter($this->openPositions, fn ($p) => $p->status === 'open'));
    }

    private function manageSingleLeg(BacktestPosition $pos, array $symbolData, array $pointers, int $tickTime, array $priceBySymbol): void
    {
        $price = $priceBySymbol[$pos->symbol] ?? null;
        if ($price === null) {
            return;
        }

        $netPnl      = $pos->netPnlAt($price);
        $minutesOpen = intdiv($tickTime - $pos->entryTime, 60);

        $decision = $this->exitDecider->decide(
            $pos->stopLossHit($price),
            $netPnl,
            $pos->trailingActive,
            $pos->peakNetProfitUsdt,
            fn () => $this->momentumWeakening($pos->symbol, $pos->direction, $symbolData, $pointers),
            $minutesOpen,
        );

        $pos->trailingActive     = $decision['trailing_active'];
        $pos->peakNetProfitUsdt  = $decision['peak_net_profit_usdt'];

        if ($decision['action'] !== null) {
            $pos->close($price, $tickTime, $decision['action']);
            $this->closedPositions[] = $pos;
            $this->lastActivity[$pos->symbol] = $tickTime;
        }
    }

    /** @param BacktestPosition[] $legs */
    private function manageHedgeSet(array $legs, array $symbolData, array $pointers, int $tickTime, array $priceBySymbol): void
    {
        $main = null;
        $hedgeLeg = null;
        foreach ($legs as $leg) {
            if ($leg->leg === 'main') {
                $main = $leg;
            } else {
                $hedgeLeg = $leg;
            }
        }

        if (! $main || ! $hedgeLeg) {
            foreach ($legs as $leg) {
                $this->manageSingleLeg($leg, $symbolData, $pointers, $tickTime, $priceBySymbol);
            }
            return;
        }

        $mainPrice  = $priceBySymbol[$main->symbol] ?? null;
        $hedgePrice = $priceBySymbol[$hedgeLeg->symbol] ?? null;
        if ($mainPrice === null || $hedgePrice === null) {
            return;
        }

        $combinedNetPnl = $main->netPnlAt($mainPrice) + $hedgeLeg->netPnlAt($hedgePrice);
        $minutesOpen    = intdiv($tickTime - $main->entryTime, 60);

        $decision = $this->exitDecider->decide(
            $main->stopLossHit($mainPrice),
            $combinedNetPnl,
            $main->trailingActive,
            $main->peakNetProfitUsdt,
            fn () => $this->momentumWeakening($main->symbol, $main->direction, $symbolData, $pointers),
            $minutesOpen,
        );

        $main->trailingActive    = $decision['trailing_active'];
        $main->peakNetProfitUsdt = $decision['peak_net_profit_usdt'];

        if ($decision['action'] !== null) {
            $main->close($mainPrice, $tickTime, $decision['action']);
            $hedgeLeg->close($hedgePrice, $tickTime, $decision['action']);
            $this->closedPositions[] = $main;
            $this->closedPositions[] = $hedgeLeg;
            $this->lastActivity[$main->symbol] = $tickTime;
        }
    }

    private function tryOpen(string $symbol, array $symbolData, array $pointers, int $tickTime, ?float $feeRate): void
    {
        $cooldownSeconds = (int) BotConfig::get('cooldown_minutes_per_pair') * 60;
        if (isset($this->lastActivity[$symbol]) && ($tickTime - $this->lastActivity[$symbol]) < $cooldownSeconds) {
            return;
        }

        $openSets = collect($this->openPositions)->pluck('tradeSetId')->unique()->count();
        if ($openSets >= (int) BotConfig::get('max_open_positions')) {
            return;
        }

        $tf1h        = $this->indicators->analyze($this->sliceCandles($symbolData[$symbol]['1H'], $pointers[$symbol]['1H']));
        $tf15m       = $this->indicators->analyze($this->sliceCandles($symbolData[$symbol]['15M'], $pointers[$symbol]['15M']));
        $tf5mCandles = $this->sliceCandles($symbolData[$symbol]['5M'], $pointers[$symbol]['5M']);
        $tf5m        = $this->indicators->analyze($tf5mCandles);

        $currentPrice = $tf5m['last_close'];
        if ($currentPrice <= 0) {
            return;
        }

        // USDT dominance isn't simulated historically — the live bot's dominance factor
        // simply doesn't contribute for this scoring pass (documented limitation above).
        $scored = $this->signalEngine->score($tf1h, $tf15m, $tf5m, $tf5mCandles, $currentPrice, null);

        $threshold = (int) BotConfig::get('minimum_confidence_to_trade');
        if ($scored['direction'] === null || $scored['confidence'] < $threshold) {
            return;
        }

        $plan = $this->sizing->plan($scored['direction'], $scored['confidence'], $currentPrice, $tf15m['atr'], $feeRate);

        $committed = collect($this->openPositions)->sum(fn ($p) => $p->marginUsd);
        if (($committed + $plan['margin_usd']) > (float) BotConfig::get('max_total_margin_usdt')) {
            return;
        }

        $correctSide = $scored['direction'] === 'LONG' ? $plan['stop_loss'] < $currentPrice : $plan['stop_loss'] > $currentPrice;
        if ($plan['stop_loss'] <= 0 || ! $correctSide) {
            return;
        }

        $tradeSetId = (string) Str::uuid();

        if ($this->hedge->shouldHedge($scored['confidence'])) {
            $this->openHedgeSet($symbol, $scored, $plan, $currentPrice, $tickTime, $tradeSetId, $feeRate);
        } else {
            $this->openPositions[] = new BacktestPosition(
                $tradeSetId, 'main', $symbol, $scored['direction'], $plan['margin_usd'], $plan['leverage'],
                $currentPrice, $tickTime, $plan['take_profit'], $plan['stop_loss'], $plan['estimated_fee_usdt'], $scored['confidence'],
            );
        }

        $this->lastActivity[$symbol] = $tickTime;
    }

    private function openHedgeSet(string $symbol, array $scored, array $plan, float $currentPrice, int $tickTime, string $tradeSetId, ?float $feeRate): void
    {
        $split          = $this->hedge->splitMargin($plan['margin_usd']);
        $hedgeDirection = $scored['direction'] === 'LONG' ? 'SHORT' : 'LONG';
        $slDistance     = abs($currentPrice - $plan['stop_loss']);
        $targetTotal    = (float) BotConfig::get('target_net_profit_per_trade');
        $mainRatio      = (float) BotConfig::get('main_position_ratio');
        $hedgeRatioCfg  = (float) BotConfig::get('hedge_position_ratio');

        $mainStopLoss  = $scored['direction'] === 'LONG' ? $currentPrice - $slDistance : $currentPrice + $slDistance;
        $hedgeStopLoss = $hedgeDirection === 'LONG' ? $currentPrice - $slDistance : $currentPrice + $slDistance;

        $mainTakeProfit  = $this->sizing->takeProfitForMargin($scored['direction'], $split['main'], $currentPrice, $feeRate, $targetTotal * $mainRatio);
        $hedgeTakeProfit = $this->sizing->takeProfitForMargin($hedgeDirection, $split['hedge'], $currentPrice, $feeRate, $targetTotal * $hedgeRatioCfg);

        $this->openPositions[] = new BacktestPosition(
            $tradeSetId, 'main', $symbol, $scored['direction'], $split['main'], $plan['leverage'],
            $currentPrice, $tickTime, $mainTakeProfit, $mainStopLoss,
            $this->sizing->feesForMargin($split['main'], $feeRate), $scored['confidence'],
        );
        $this->openPositions[] = new BacktestPosition(
            $tradeSetId, 'hedge', $symbol, $hedgeDirection, $split['hedge'], $plan['leverage'],
            $currentPrice, $tickTime, $hedgeTakeProfit, $hedgeStopLoss,
            $this->sizing->feesForMargin($split['hedge'], $feeRate), $scored['confidence'],
        );
    }

    private function momentumWeakening(string $symbol, string $direction, array $symbolData, array $pointers): bool
    {
        $candles = $this->sliceCandles($symbolData[$symbol]['5M'], $pointers[$symbol]['5M'], 30);
        if (count($candles) < 15) {
            return false;
        }

        $rsi = $this->indicators->rsi(array_column($candles, 'close'), 14);
        if ($rsi === null) {
            return false;
        }

        $momentum = $this->indicators->momentum($candles, 5);

        return $direction === 'LONG'
            ? $rsi > 70 && $momentum['streak_direction'] !== 'up'
            : $rsi < 30 && $momentum['streak_direction'] !== 'down';
    }

    private function hasOpenPosition(string $symbol): bool
    {
        foreach ($this->openPositions as $pos) {
            if ($pos->symbol === $symbol) {
                return true;
            }
        }

        return false;
    }

    private function dailyLossLimitBreached(int $tickTime): bool
    {
        $max      = (float) BotConfig::get('max_daily_loss_usdt');
        $dayStart = intdiv($tickTime, 86400) * 86400;

        $realizedToday = 0.0;
        foreach ($this->closedPositions as $pos) {
            if ($pos->exitTime !== null && $pos->exitTime >= $dayStart && $pos->exitTime <= $tickTime) {
                $realizedToday += $pos->netProfitUsdt;
            }
        }

        return $realizedToday <= -$max;
    }

    private function sliceCandles(array $candles, int $pointer, int $window = 200): array
    {
        return array_slice($candles, max(0, $pointer - $window), min($pointer, $window));
    }

    private function buildSummary(): array
    {
        $trades = $this->closedPositions;
        $total  = count($trades);

        if ($total === 0) {
            return ['total_trades' => 0];
        }

        $winners = array_filter($trades, fn ($t) => $t->netProfitUsdt > 0);
        $losers  = array_filter($trades, fn ($t) => $t->netProfitUsdt <= 0);

        $netProfit = array_sum(array_map(fn ($t) => $t->netProfitUsdt, $trades));

        // Max drawdown from the running equity curve (chronological by exit time).
        usort($trades, fn ($a, $b) => $a->exitTime <=> $b->exitTime);
        $equity = 0.0;
        $peak   = 0.0;
        $maxDrawdown = 0.0;
        $dailyPnl = [];
        foreach ($trades as $t) {
            $equity += $t->netProfitUsdt;
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak - $equity);

            $day = date('Y-m-d', $t->exitTime);
            $dailyPnl[$day] = ($dailyPnl[$day] ?? 0) + $t->netProfitUsdt;
        }

        $byPair = [];
        foreach ($trades as $t) {
            $byPair[$t->symbol]['count'] = ($byPair[$t->symbol]['count'] ?? 0) + 1;
            $byPair[$t->symbol]['net']   = ($byPair[$t->symbol]['net'] ?? 0) + $t->netProfitUsdt;
        }
        uasort($byPair, fn ($a, $b) => $b['net'] <=> $a['net']);
        $pairsRanked = array_keys($byPair);

        $closeReasonCounts = [];
        foreach ($trades as $t) {
            $closeReasonCounts[$t->closeReason] = ($closeReasonCounts[$t->closeReason] ?? 0) + 1;
        }

        $avgConfidence = fn (array $set) => count($set) > 0
            ? round(array_sum(array_map(fn ($t) => $t->confidenceScore, $set)) / count($set), 2)
            : null;

        return [
            'total_trades'                  => $total,
            'win_rate_pct'                  => round(count($winners) / $total * 100, 2),
            'average_profit_usdt'           => count($winners) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $winners)) / count($winners), 4) : 0,
            'average_loss_usdt'             => count($losers) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $losers)) / count($losers), 4) : 0,
            'net_profit_after_fees_usdt'    => round($netProfit, 4),
            'max_drawdown_usdt'             => round($maxDrawdown, 4),
            'largest_daily_loss_usdt'       => round(min(0, min($dailyPnl ?: [0])), 4),
            'trades_per_pair'               => array_map(fn ($p) => $byPair[$p]['count'], $pairsRanked),
            'best_pairs'                    => array_slice($pairsRanked, 0, 5),
            'worst_pairs'                   => array_slice(array_reverse($pairsRanked), 0, 5),
            'avg_confidence_winning_trades' => $avgConfidence($winners),
            'avg_confidence_losing_trades'  => $avgConfidence($losers),
            'smart_exit_activations'        => $closeReasonCounts['smart_exit'] ?? 0,
            'trailing_tp_activations'       => $closeReasonCounts['trailing_tp'] ?? 0,
            'stop_loss_activations'         => $closeReasonCounts['stop_loss'] ?? 0,
            'close_reason_breakdown'        => $closeReasonCounts,
        ];
    }
}
