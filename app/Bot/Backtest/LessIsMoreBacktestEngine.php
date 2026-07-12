<?php

namespace App\Bot\Backtest;

use App\Bot\Config\BotConfig;
use App\Bot\Indicators\IndicatorService;
use App\Bot\Signal\SignalEngine;
use App\Services\MexcFuturesService;

/**
 * Walk-forward simulation of the "Less Is More" Dashboard tool: keeps up to $count
 * micro positions ($min-$max nominal each, fixed leverage, TP-only at a fixed % move,
 * no SL) open at all times, refilling from the highest-confidence SignalEngine::score()
 * candidates among symbols not already open whenever a slot frees up (a TP hit).
 * Mirrors BacktestEngine's tick-walk machinery but with this tool's own much simpler
 * open/close rules — never touches bot_trades, ManualPaperTrade, or MEXC orders.
 */
class LessIsMoreBacktestEngine
{
    /** @var LessIsMorePosition[] */
    private array $openPositions = [];

    /** @var LessIsMorePosition[] */
    private array $closedPositions = [];

    public function __construct(
        private BacktestDataService $dataService,
        private IndicatorService $indicators,
        private SignalEngine $signalEngine,
        private MexcFuturesService $mexc,
    ) {}

    /**
     * @param array<int, string> $symbols
     * @return array{summary: array, trades: array}
     */
    public function run(
        array $symbols,
        int $fromTs,
        int $toTs,
        int $count,
        float $tpPercent,
        float $nominalMin = 100,
        float $nominalMax = 200,
    ): array {
        $this->openPositions   = [];
        $this->closedPositions = [];

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
            $this->checkTakeProfits($tickTime, $priceBySymbol);

            if (count($this->openPositions) < $count) {
                $this->refill($symbols, $symbolData, $pointers, $tickTime, $feeRates, $count, $tpPercent, $nominalMin, $nominalMax, $lookback);
            }
        }

        $lastTick = end($ticks);
        foreach ($this->openPositions as $pos) {
            $pos->close($priceBySymbol[$pos->symbol] ?? $pos->entryPrice, $lastTick, 'backtest_end');
            $this->closedPositions[] = $pos;
        }
        $this->openPositions = [];

        return [
            'summary' => $this->buildSummary($count),
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
                $countCandles = count($candles);
                while ($pointers[$symbol][$tf] < $countCandles && $candles[$pointers[$symbol][$tf]]['time'] <= $tickTime) {
                    $pointers[$symbol][$tf]++;
                }
            }
            if ($pointers[$symbol]['5M'] > 0) {
                $priceBySymbol[$symbol] = $symbolData[$symbol]['5M'][$pointers[$symbol]['5M'] - 1]['close'];
            }
        }
    }

    private function checkTakeProfits(int $tickTime, array $priceBySymbol): void
    {
        foreach ($this->openPositions as $pos) {
            $price = $priceBySymbol[$pos->symbol] ?? null;
            if ($price === null) {
                continue;
            }
            if ($pos->takeProfitHit($price)) {
                $pos->close($price, $tickTime, 'take_profit');
                $this->closedPositions[] = $pos;
            }
        }

        $this->openPositions = array_values(array_filter($this->openPositions, fn ($p) => $p->status === 'open'));
    }

    private function refill(
        array $symbols,
        array $symbolData,
        array $pointers,
        int $tickTime,
        array $feeRates,
        int $count,
        float $tpPercent,
        float $nominalMin,
        float $nominalMax,
        int $lookback,
    ): void {
        $needed = $count - count($this->openPositions);
        if ($needed <= 0) {
            return;
        }

        $openSymbols = array_map(fn ($p) => $p->symbol, $this->openPositions);

        $candidates = [];
        foreach ($symbols as $symbol) {
            if (in_array($symbol, $openSymbols, true)) {
                continue;
            }
            if ($pointers[$symbol]['1H'] < $lookback || $pointers[$symbol]['15M'] < $lookback || $pointers[$symbol]['5M'] < $lookback) {
                continue; // not enough history yet at this point in the walk
            }

            $tf1h        = $this->indicators->analyze($this->sliceCandles($symbolData[$symbol]['1H'], $pointers[$symbol]['1H']));
            $tf15m       = $this->indicators->analyze($this->sliceCandles($symbolData[$symbol]['15M'], $pointers[$symbol]['15M']));
            $tf5mCandles = $this->sliceCandles($symbolData[$symbol]['5M'], $pointers[$symbol]['5M']);
            $tf5m        = $this->indicators->analyze($tf5mCandles);

            $currentPrice = $tf5m['last_close'];
            if ($currentPrice <= 0) {
                continue;
            }

            // USDT dominance isn't simulated historically, same documented limitation as BacktestEngine.
            // LONG-only, matching the live tool: SHORT-biased coins are skipped entirely.
            $scored = $this->signalEngine->score($tf1h, $tf15m, $tf5m, $tf5mCandles, $currentPrice, null);
            if ($scored['direction'] !== 'LONG') {
                continue;
            }

            $candidates[] = [
                'symbol'     => $symbol,
                'direction'  => $scored['direction'],
                'confidence' => $scored['confidence'],
                'price'      => $currentPrice,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        foreach (array_slice($candidates, 0, $needed) as $c) {
            $nominal    = mt_rand((int) $nominalMin, (int) $nominalMax);
            $takeProfit = round($c['direction'] === 'LONG'
                ? $c['price'] * (1 + $tpPercent / 100)
                : $c['price'] * (1 - $tpPercent / 100), 8);
            $feeRate = $feeRates[$c['symbol']] ?? 0.0004;
            $fee     = round(2 * $nominal * $feeRate, 4);

            $this->openPositions[] = new LessIsMorePosition(
                $c['symbol'], $c['direction'], $nominal, $c['price'], $tickTime, $takeProfit, $fee, $c['confidence'],
            );
        }
    }

    private function sliceCandles(array $candles, int $pointer, int $window = 200): array
    {
        return array_slice($candles, max(0, $pointer - $window), min($pointer, $window));
    }

    private function buildSummary(int $requestedCount): array
    {
        $trades = $this->closedPositions;
        $total  = count($trades);

        if ($total === 0) {
            return ['total_trades' => 0];
        }

        $winners = array_filter($trades, fn ($t) => $t->netProfitUsdt > 0);
        $losers  = array_filter($trades, fn ($t) => $t->netProfitUsdt <= 0);

        $netProfit = array_sum(array_map(fn ($t) => $t->netProfitUsdt, $trades));

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

        $avgHoldMinutes = round(array_sum(array_map(fn ($t) => ($t->exitTime - $t->entryTime) / 60, $trades)) / $total, 1);

        return [
            'requested_concurrent_positions' => $requestedCount,
            'total_trades'                => $total,
            'win_rate_pct'                => round(count($winners) / $total * 100, 2),
            'average_profit_usdt'         => count($winners) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $winners)) / count($winners), 4) : 0,
            'average_loss_usdt'           => count($losers) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $losers)) / count($losers), 4) : 0,
            'net_profit_after_fees_usdt'  => round($netProfit, 4),
            'max_drawdown_usdt'           => round($maxDrawdown, 4),
            'largest_daily_loss_usdt'     => round(min(0, min($dailyPnl ?: [0])), 4),
            'avg_hold_minutes'            => $avgHoldMinutes,
            'take_profit_hits'            => $closeReasonCounts['take_profit'] ?? 0,
            'still_open_at_test_end'      => $closeReasonCounts['backtest_end'] ?? 0,
            'best_pairs'                  => array_slice($pairsRanked, 0, 5),
            'worst_pairs'                 => array_slice(array_reverse($pairsRanked), 0, 5),
        ];
    }
}
