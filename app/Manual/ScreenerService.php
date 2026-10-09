<?php

namespace App\Manual;

use App\Bot\MarketData\MarketDataService;
use App\Services\MexcFuturesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Scans MEXC's crypto perpetuals for the most oversold and the most overbought coins on one
 * indicator, ten a side. The judging is ExtremesScreener's; this picks which coins to look at
 * and fetches what is needed.
 *
 * Two universes, so a scan stays fast and fair. The exchange-data indicators (moves, funding,
 * position in the 24h range) need only the ticker, which already lists every coin, so they
 * cover every crypto coin trading at least a couple of million dollars a day. The candle
 * indicators (WaveTrend, RSI, MACD) need candles per coin and the exchange limits how fast
 * they can be fetched, so they read the 50 most traded coins, fetched in parallel batches,
 * through the same candle cache the Analysis panel and the charts use. Illiquid coins are
 * left out on purpose: their extremes are noise you cannot trade at size.
 *
 * Results are cached for a minute (five on 4H), so pressing Scan again changes nothing until
 * the candles do. Read-only market data; nothing here touches an account or an order.
 */
class ScreenerService
{
    /** Most traded coins whose candles a scan reads. */
    public const CANDLE_UNIVERSE = 50;

    /** The exchange-data lists cover every crypto coin that trades at least this much (USDT) a day. */
    public const TICKER_MIN_TURNOVER = 2_000_000;

    /** Rows per side. */
    public const SHOWN = 10;

    /** label => [MEXC interval, candle length in seconds, cache seconds] — the same intervals and lifetimes the Analysis panel uses. */
    private const TIMEFRAMES = [
        '15M' => ['Min15', 900, 60],
        '1H'  => ['Min60', 3600, 60],
        '4H'  => ['Hour4', 14400, 300],
    ];

    private const TICKERS_CACHE_SECONDS = 60;

    public function __construct(
        private MarketDataService $market,
        private MexcFuturesService $mexc,
        private ExtremesScreener $screener,
    ) {}

    /** @return array<int, string> */
    public static function timeframes(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    /**
     * @return array<string, mixed>
     */
    public function scan(string $indicator, string $tf = '1H'): array
    {
        if (! isset(ExtremesScreener::INDICATORS[$indicator])) {
            throw new \InvalidArgumentException("Unknown indicator: {$indicator}");
        }

        $fromCandles = ExtremesScreener::isCandleBased($indicator);
        $tf          = isset(self::TIMEFRAMES[$tf]) ? $tf : '1H';
        $ttl         = $fromCandles ? self::TIMEFRAMES[$tf][2] : self::TICKERS_CACHE_SECONDS;

        return Cache::remember(
            'screener:'.$indicator.':'.($fromCandles ? $tf : 'all'),
            now()->addSeconds($ttl),
            fn () => $fromCandles ? $this->scanCandles($indicator, $tf) : $this->scanTickers($indicator),
        );
    }

    /** @return array<string, mixed> */
    private function scanCandles(string $indicator, string $tf): array
    {
        [$interval, $seconds, $ttl] = self::TIMEFRAMES[$tf];

        // Fifty coins in batches, paced under the exchange's limit, can run past PHP's default
        // 30 seconds on a slow day; this is the one request that is allowed to take a while.
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        $universe = $this->coins()->take(self::CANDLE_UNIVERSE);
        $candles  = $this->market->getCandlesBatch($universe->pluck('symbol')->all(), $interval, 200, $ttl);

        if ($candles === []) {
            throw new \RuntimeException("Couldn't read candles from MEXC right now. Try again in a moment.");
        }

        $now  = now()->getTimestamp();
        $rows = [];

        foreach ($universe as $coin) {
            $coinCandles = $candles[$coin['symbol']] ?? null;
            $hit         = $coinCandles ? $this->screener->fromCandles($indicator, $coinCandles, $tf, $seconds, $now) : null;

            if ($hit !== null) {
                $rows[] = $this->row($coin, $hit);
            }
        }

        return $this->result($indicator, $tf, $rows, [
            'basis'        => 'most_traded',
            'size'         => $universe->count(),
            'scanned'      => count($candles),
            'missing'      => $universe->count() - count($candles),
            'min_turnover' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function scanTickers(string $indicator): array
    {
        $universe = $this->coins()->filter(fn (array $coin) => $coin['amount24'] >= self::TICKER_MIN_TURNOVER);
        $rows     = [];

        foreach ($universe as $coin) {
            $hit = $this->screener->fromTicker($indicator, $coin);

            if ($hit !== null) {
                $rows[] = $this->row($coin, $hit);
            }
        }

        return $this->result($indicator, null, $rows, [
            'basis'        => 'min_turnover',
            'size'         => $universe->count(),
            'scanned'      => $universe->count(),
            'missing'      => 0,
            'min_turnover' => self::TICKER_MIN_TURNOVER,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $universe
     * @return array<string, mixed>
     */
    private function result(string $indicator, ?string $tf, array $rows, array $universe): array
    {
        $definition = ExtremesScreener::INDICATORS[$indicator];
        $ranked     = ExtremesScreener::rank($rows, self::SHOWN);
        $clean      = fn (array $list) => array_map(
            fn (array $row) => array_diff_key($row, array_flip(['side', 'strength', 'group', 'tiebreak'])),
            $list,
        );

        return [
            'indicator'    => $indicator,
            'tf'           => $tf,
            'label'        => $definition['label'],
            'kind'         => $definition['kind'],
            'rule'         => $definition['rule'],
            'titles'       => ['oversold' => $definition['oversold'], 'overbought' => $definition['overbought']],
            'universe'     => $universe,
            'generated_at' => now()->getTimestamp(),
            'oversold'     => $clean($ranked['oversold']),
            'overbought'   => $clean($ranked['overbought']),
            'counts'       => $ranked['counts'],
        ];
    }

    /**
     * A qualifying coin as one row: what the list shows about it, plus the keys that order it
     * (ExtremesScreener::rank() uses them and result() takes them out again).
     *
     * @param  array<string, mixed>  $coin
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function row(array $coin, array $hit): array
    {
        return [
            'symbol'     => $coin['symbol'],
            'price'      => $coin['lastPrice'],
            'change_24h' => $coin['riseFallRate'] !== null ? round($coin['riseFallRate'] * 100, 2) : null,
            'turnover'   => round($coin['amount24']),
            'rank'       => $coin['rank'],
            'volatility' => $hit['volatility'],
            'reading'    => $hit['reading'],
            'side'       => $hit['side'],
            'strength'   => $hit['strength'],
            'group'      => $hit['group'],
            'tiebreak'   => $hit['tiebreak'],
        ];
    }

    /**
     * Every active crypto perpetual with a ticker, most traded first, each with its rank. The
     * list of crypto contracts leaves out the stocks, metals and oil MEXC lists as USDT futures.
     * Only the fields a scan reads are kept, so the cached copy stays small.
     *
     * @return Collection<int, array{symbol: string, lastPrice: float, amount24: float, riseFallRate: ?float, fundingRate: ?float, high24Price: float, lower24Price: float, riseFallRates: array{r7: ?float, r30: ?float}, rank: int}>
     */
    private function coins(): Collection
    {
        $symbols = $this->cachedUnlessEmpty(
            'futures:active-symbols',
            600,
            fn () => $this->mexc->getActiveSymbols(),
        );

        $tickers = $this->cachedUnlessEmpty(
            'screener:tickers',
            self::TICKERS_CACHE_SECONDS,
            fn () => $this->market->getAllTickers()->map(fn (array $t) => [
                'symbol'        => (string) $t['symbol'],
                'lastPrice'     => (float) ($t['lastPrice'] ?? 0),
                'amount24'      => (float) ($t['amount24'] ?? 0),
                'riseFallRate'  => isset($t['riseFallRate']) ? (float) $t['riseFallRate'] : null,
                'fundingRate'   => isset($t['fundingRate']) ? (float) $t['fundingRate'] : null,
                'high24Price'   => (float) ($t['high24Price'] ?? 0),
                'lower24Price'  => (float) ($t['lower24Price'] ?? 0),
                'riseFallRates' => [
                    'r7'  => isset($t['riseFallRates']['r7']) ? (float) $t['riseFallRates']['r7'] : null,
                    'r30' => isset($t['riseFallRates']['r30']) ? (float) $t['riseFallRates']['r30'] : null,
                ],
            ])->values()->all(),
        );

        $crypto = array_flip($symbols);

        $coins = collect($tickers)
            ->filter(fn (array $t) => isset($crypto[$t['symbol']]) && $t['amount24'] > 0 && $t['lastPrice'] > 0)
            ->sortByDesc('amount24')
            ->values()
            ->map(fn (array $t, int $i) => $t + ['rank' => $i + 1]);

        if ($coins->isEmpty()) {
            throw new \RuntimeException("Couldn't load the coin list from MEXC right now. Try again in a moment.");
        }

        return $coins;
    }

    /**
     * Cached, but an empty answer is never kept: MEXC's public endpoints answer a failure with
     * an empty list rather than an error, and a minute of "no coins" would be worse than
     * asking again.
     *
     * @return array<int|string, mixed>
     */
    private function cachedUnlessEmpty(string $key, int $seconds, \Closure $load): array
    {
        $cached = Cache::get($key);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $fresh = $load();

        if ($fresh !== []) {
            Cache::put($key, $fresh, now()->addSeconds($seconds));
        }

        return $fresh;
    }
}
