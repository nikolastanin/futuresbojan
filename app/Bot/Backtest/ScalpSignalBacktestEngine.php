<?php

namespace App\Bot\Backtest;

use App\Bot\Indicators\IndicatorService;
use App\Services\MexcFuturesService;

/**
 * Walk-forward backtest of the Scalp Scanner's three newest signals — Market
 * Structure (CHoCH), Candle Reading, and FVG — in isolation from RSI/MACD/
 * WaveTrend, so their standalone quality can be judged on its own. Opens a
 * position (either direction) whenever any of the three fire without
 * disagreeing, same combine rule as the live scanner. Unlike the live
 * scanner (informational only), this needs an actual exit rule to measure
 * performance: TP at a fixed % move, SL at 1.5x 15M ATR (the same convention
 * used elsewhere in this codebase). Never touches bot_trades or MEXC orders.
 */
class ScalpSignalBacktestEngine
{
    /** @var ScalpSignalPosition[] */
    private array $openPositions = [];

    /** @var ScalpSignalPosition[] */
    private array $closedPositions = [];

    public function __construct(
        private IndicatorService $indicators,
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
        float $tpPercent = 1.0,
        float $slAtrMultiple = 1.5,
        float $nominalMin = 100,
        float $nominalMax = 200,
    ): array {
        $this->openPositions   = [];
        $this->closedPositions = [];

        $lookback = 60; // candles of pre-range padding, enough for MarketStructure's 40-bar lookback

        $symbolData = [];
        foreach ($symbols as $symbol) {
            $paddedStart = $fromTs - ($lookback * $this->mexc->intervalSeconds('Min15'));
            $symbolData[$symbol] = ['15M' => $this->mexc->getKlinesRange($symbol, 'Min15', $paddedStart, $toTs)];
        }

        $contracts = collect($this->mexc->getContractList())->keyBy('symbol');
        $feeRates  = $contracts->only($symbols)->map(fn ($c) => (float) $c['takerFeeRate'])->all();

        $ticks = $this->buildTickList($symbolData, $fromTs, $toTs);
        if (empty($ticks)) {
            throw new \RuntimeException('No candle data available for the requested symbols/range.');
        }

        $pointers = [];
        foreach ($symbols as $symbol) {
            $pointers[$symbol] = 0;
        }

        $priceBySymbol = [];

        foreach ($ticks as $tickTime) {
            $this->advancePointers($symbols, $symbolData, $pointers, $tickTime, $priceBySymbol);
            $this->checkExits($tickTime, $priceBySymbol);
            $this->tryOpen($symbols, $symbolData, $pointers, $tickTime, $priceBySymbol, $feeRates, $tpPercent, $slAtrMultiple, $nominalMin, $nominalMax, $lookback);
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

    private function buildTickList(array $symbolData, int $fromTs, int $toTs): array
    {
        $tickSet = [];
        foreach ($symbolData as $tf) {
            foreach ($tf['15M'] as $c) {
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
            $candles = $symbolData[$symbol]['15M'];
            $countCandles = count($candles);
            while ($pointers[$symbol] < $countCandles && $candles[$pointers[$symbol]]['time'] <= $tickTime) {
                $pointers[$symbol]++;
            }
            if ($pointers[$symbol] > 0) {
                $priceBySymbol[$symbol] = $candles[$pointers[$symbol] - 1]['close'];
            }
        }
    }

    private function checkExits(int $tickTime, array $priceBySymbol): void
    {
        foreach ($this->openPositions as $pos) {
            $price = $priceBySymbol[$pos->symbol] ?? null;
            if ($price === null) {
                continue;
            }

            if ($pos->stopLossHit($price)) {
                $pos->close($price, $tickTime, 'stop_loss');
                $this->closedPositions[] = $pos;
            } elseif ($pos->takeProfitHit($price)) {
                $pos->close($price, $tickTime, 'take_profit');
                $this->closedPositions[] = $pos;
            }
        }

        $this->openPositions = array_values(array_filter($this->openPositions, fn ($p) => $p->status === 'open'));
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

    private function tryOpen(
        array $symbols,
        array $symbolData,
        array $pointers,
        int $tickTime,
        array $priceBySymbol,
        array $feeRates,
        float $tpPercent,
        float $slAtrMultiple,
        float $nominalMin,
        float $nominalMax,
        int $lookback,
    ): void {
        foreach ($symbols as $symbol) {
            if ($this->hasOpenPosition($symbol)) {
                continue;
            }
            if ($pointers[$symbol] < $lookback) {
                continue; // not enough history yet at this point in the walk
            }

            $candles = $this->sliceCandles($symbolData[$symbol]['15M'], $pointers[$symbol], $lookback);
            if (count($candles) < 40) {
                continue;
            }

            $price = $priceBySymbol[$symbol] ?? null;
            $atr   = $this->indicators->atr($candles, 14);
            if ($price === null || $price <= 0 || ! $atr || $atr <= 0) {
                continue;
            }

            $structure = $this->indicators->marketStructureShift($candles);
            $candle    = $this->indicators->candlePattern($candles);
            $fvg       = $this->indicators->fairValueGap($candles);

            $signals = array_filter(['MarketStructure' => $structure, 'CandleReading' => $candle, 'FVG' => $fvg]);
            if (empty($signals) || count(array_unique($signals)) > 1) {
                continue; // nothing fired, or they disagree — not a clean setup
            }

            $bias      = array_values($signals)[0];
            $direction = $bias === 'bullish' ? 'LONG' : 'SHORT';
            $matchedOn = implode('+', array_keys($signals));

            $nominal    = mt_rand((int) $nominalMin, (int) $nominalMax);
            $slDistance = $slAtrMultiple * $atr;
            $takeProfit = round($direction === 'LONG' ? $price * (1 + $tpPercent / 100) : $price * (1 - $tpPercent / 100), 8);
            $stopLoss   = round($direction === 'LONG' ? $price - $slDistance : $price + $slDistance, 8);
            $feeRate    = $feeRates[$symbol] ?? 0.0004;
            $fee        = round(2 * $nominal * $feeRate, 4);

            $this->openPositions[] = new ScalpSignalPosition(
                $symbol, $direction, $matchedOn, $nominal, $price, $tickTime, $takeProfit, $stopLoss, $fee,
            );
        }
    }

    private function sliceCandles(array $candles, int $pointer, int $window): array
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

        usort($trades, fn ($a, $b) => $a->exitTime <=> $b->exitTime);
        $equity = 0.0;
        $peak   = 0.0;
        $maxDrawdown = 0.0;
        foreach ($trades as $t) {
            $equity += $t->netProfitUsdt;
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak - $equity);
        }

        $byDirection = [];
        foreach ($trades as $t) {
            $byDirection[$t->direction]['count'] = ($byDirection[$t->direction]['count'] ?? 0) + 1;
            $byDirection[$t->direction]['net']   = ($byDirection[$t->direction]['net'] ?? 0) + $t->netProfitUsdt;
        }

        $byMatch = [];
        foreach ($trades as $t) {
            $byMatch[$t->matchedOn]['count'] = ($byMatch[$t->matchedOn]['count'] ?? 0) + 1;
            $byMatch[$t->matchedOn]['net']   = ($byMatch[$t->matchedOn]['net'] ?? 0) + $t->netProfitUsdt;
            $byMatch[$t->matchedOn]['wins']  = ($byMatch[$t->matchedOn]['wins'] ?? 0) + ($t->netProfitUsdt > 0 ? 1 : 0);
        }

        $closeReasonCounts = [];
        foreach ($trades as $t) {
            $closeReasonCounts[$t->closeReason] = ($closeReasonCounts[$t->closeReason] ?? 0) + 1;
        }

        return [
            'total_trades'               => $total,
            'win_rate_pct'               => round(count($winners) / $total * 100, 2),
            'average_profit_usdt'        => count($winners) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $winners)) / count($winners), 4) : 0,
            'average_loss_usdt'          => count($losers) > 0 ? round(array_sum(array_map(fn ($t) => $t->netProfitUsdt, $losers)) / count($losers), 4) : 0,
            'net_profit_after_fees_usdt' => round($netProfit, 4),
            'max_drawdown_usdt'          => round($maxDrawdown, 4),
            'take_profit_hits'           => $closeReasonCounts['take_profit'] ?? 0,
            'stop_loss_hits'             => $closeReasonCounts['stop_loss'] ?? 0,
            'still_open_at_test_end'     => $closeReasonCounts['backtest_end'] ?? 0,
            'by_direction'               => $byDirection,
            'by_matched_signal'          => $byMatch,
        ];
    }
}
