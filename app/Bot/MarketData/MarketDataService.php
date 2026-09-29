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
     * OHLCV candles for many symbols at once on the same timeframe, fetched
     * concurrently — used by ScalpScanner to scan a large coin pool in a few seconds
     * instead of one request at a time.
     *
     * @param array<int, string> $symbols
     * @return array<string, array>
     */
    public function getCandlesBatch(array $symbols, string $timeframeLabel, int $limit = 200): array
    {
        $interval = config("bot.timeframes.{$timeframeLabel}");

        if (! $interval) {
            throw new \InvalidArgumentException("Unknown timeframe label: {$timeframeLabel}");
        }

        return $this->mexc->getKlinesBatch($symbols, $interval, $limit);
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
