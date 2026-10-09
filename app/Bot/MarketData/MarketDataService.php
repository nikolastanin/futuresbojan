<?php

namespace App\Bot\MarketData;

use App\Bot\Config\BotConfig;
use App\Services\MexcFuturesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pulls raw market data from MEXC. Retrieval only — no indicator calculation
 * (that's IndicatorService's job) and no trading decisions.
 */
class MarketDataService
{
    public function __construct(private MexcFuturesService $mexc) {}

    /**
     * OHLCV candles for one pair/timeframe, oldest first.
     *
     * @param string $timeframeLabel One of the keys in config('bot.timeframes'), e.g. '5M', '15M', '1H'.
     */
    public function getCandles(string $symbol, string $timeframeLabel, int $limit = 200): array
    {
        $interval = config("bot.timeframes.{$timeframeLabel}");

        if (! $interval) {
            throw new \InvalidArgumentException("Unknown timeframe label: {$timeframeLabel}");
        }

        return $this->mexc->getKlines($symbol, $interval, $limit);
    }

    /**
     * Candles for every configured timeframe, keyed by timeframe label.
     *
     * @return array<string, array>
     */
    public function getCandlesForAllTimeframes(string $symbol, int $limit = 200): array
    {
        $result = [];

        foreach (array_keys(config('bot.timeframes')) as $label) {
            $result[$label] = $this->getCandles($symbol, $label, $limit);
        }

        return $result;
    }

    /**
     * Daily candles, oldest first — outside config('bot.timeframes') on purpose (that
     * set is what SignalEngine/the bot loop scans, and adding a daily entry there
     * would pull it into every live scan cycle for no bot benefit). Only used for the
     * Dashboard's manual-trading price-levels read (pivots/EMA10/EMA20/week range), so
     * it's cached — daily candles barely change within a few minutes, and this can be
     * polled from several widgets (order form, hedge instant, open positions) at once.
     */
    public function getDailyCandles(string $symbol, int $limit = 60): array
    {
        return Cache::remember(
            "daily_candles:{$symbol}",
            now()->addMinutes(15),
            fn () => $this->mexc->getKlines($symbol, 'Day1', $limit),
        );
    }

    /**
     * Candles for an arbitrary MEXC interval (e.g. 'Hour4', 'Day1'), cached briefly.
     * For the Analysis panel's multi-timeframe read, which reaches past
     * config('bot.timeframes') and is re-polled by several tabs/widgets — the cache
     * keeps that from multiplying kline requests to MEXC.
     */
    public function getCandlesCached(string $symbol, string $interval, int $limit, int $ttlSeconds): array
    {
        return Cache::remember(
            $this->candlesKey($symbol, $interval, $limit),
            now()->addSeconds($ttlSeconds),
            fn () => $this->mexc->getKlines($symbol, $interval, $limit),
        );
    }

    /**
     * getCandlesCached() for many symbols at once: what the cache already holds is used as
     * it is, and the rest is fetched in parallel batches and cached under the same keys (so
     * the Analysis panel and the charts share it). A symbol that could not be fetched is
     * left out of the result.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>>
     */
    public function getCandlesBatch(array $symbols, string $interval, int $limit, int $ttlSeconds): array
    {
        $found   = [];
        $missing = [];

        foreach (array_unique($symbols) as $symbol) {
            $cached = Cache::get($this->candlesKey($symbol, $interval, $limit));

            if (is_array($cached)) {
                $found[$symbol] = $cached;
            } else {
                $missing[] = $symbol;
            }
        }

        foreach ($this->mexc->getKlinesBatch($missing, $interval, $limit) as $symbol => $candles) {
            Cache::put($this->candlesKey($symbol, $interval, $limit), $candles, now()->addSeconds($ttlSeconds));

            $found[$symbol] = $candles;
        }

        return $found;
    }

    private function candlesKey(string $symbol, string $interval, int $limit): string
    {
        return "candles:{$symbol}:{$interval}:{$limit}";
    }

    /**
     * Raw ticker snapshot for every active contract, keyed by symbol.
     * Fields: fairPrice, lastPrice, volume24 (contracts), amount24 (USDT notional), riseFallRate.
     */
    public function getAllTickers(): Collection
    {
        return collect($this->mexc->getAllTickers())->keyBy('symbol');
    }

    /** Raw ticker for a single symbol, or null if not found. */
    public function getTicker(string $symbol): ?array
    {
        return $this->getAllTickers()->get($symbol);
    }

    /**
     * Active USDT-quoted perpetual contracts (raw contract/detail rows). MEXC lists
     * non-crypto instruments (stocks, indices, metals, oil) as USDT-quoted "futures"
     * alongside real cryptocurrencies, tagged internally as "tradfi" (traditional
     * finance) via conceptPlate — excluded by default so the bot only ever trades
     * actual crypto, per crypto_only.
     */
    public function getActiveUsdtContracts(): Collection
    {
        $cryptoOnly = BotConfig::get('crypto_only');

        return collect($this->mexc->getContractList())
            ->filter(fn (array $c) => ($c['quoteCoin'] ?? null) === 'USDT' && (int) ($c['state'] ?? 1) === 0)
            ->filter(fn (array $c) => ! $cryptoOnly || ! in_array('mc-trade-zone-tradfi', $c['conceptPlate'] ?? [], true));
    }
}
