<?php

namespace App\Bot\Backtest;

use App\Services\MexcFuturesService;

/**
 * Fetches padded historical candles for a symbol across all configured timeframes,
 * covering [from - lookback, to] so the very first simulated tick already has enough
 * history for indicators (matching the live bot's minimum_historical_candles).
 */
class BacktestDataService
{
    public function __construct(private MexcFuturesService $mexc) {}

    /** @return array<string, array> Candles keyed by timeframe label ('5M', '15M', '1H'), oldest first. */
    public function fetchCandles(string $symbol, int $fromTs, int $toTs, int $lookbackCandles = 200): array
    {
        $timeframes = config('bot.timeframes');
        $result = [];

        foreach ($timeframes as $label => $interval) {
            $paddedStart = $fromTs - ($lookbackCandles * $this->mexc->intervalSeconds($interval));
            $result[$label] = $this->mexc->getKlinesRange($symbol, $interval, $paddedStart, $toTs);
        }

        return $result;
    }
}
