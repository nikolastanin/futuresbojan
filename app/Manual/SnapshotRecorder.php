<?php

namespace App\Manual;

use App\Bot\MarketData\MarketDataService;
use App\Models\AccountSnapshot;
use App\Models\CoinSnapshot;
use App\Services\MexcFuturesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quietly writes down what cannot be worked out afterwards: the account's equity over
 * the day, and for each coin being traded its price, funding rate and open interest next
 * to the trader's own exposure in it. MEXC only serves the current value of those, so a
 * later question like "what was funding while I was short?" or "how far below today's
 * high is my equity?" can only be answered if the number was kept at the time.
 *
 * The app has no scheduler, so this is driven by the open dashboard (it asks every few
 * minutes) and there will be gaps while no dashboard is open. Two rules keep that safe:
 * a slot is taken atomically so several open tabs record once, not once each, and a
 * failure is logged and swallowed — recording must never be able to break the page.
 */
class SnapshotRecorder
{
    private const THROTTLE_KEY = 'snapshots:last-recorded';

    // A little under the dashboard's five-minute rhythm: a tab that asks slightly early
    // is still accepted, while a second open tab asking in between is not.
    private const MIN_INTERVAL_SECONDS = 240;

    private const MAX_EXTRA_SYMBOLS = 5;

    public function __construct(
        private MexcFuturesService $mexc,
        private MarketDataService $market,
    ) {}

    /**
     * Records one account reading and one reading per coin, unless one was taken in the
     * last few minutes.
     *
     * @param  list<string>  $extraSymbols  Coins on screen that are not held, so their market data is kept too.
     * @return array{recorded: bool, reason: ?string}
     */
    public function recordIfDue(array $extraSymbols = []): array
    {
        // Cache::add is atomic: of several tabs asking at the same moment, exactly one
        // gets the slot. It stays taken after a failure, so a persistent problem (an
        // expired key, a table not migrated yet) is retried at the normal cadence rather
        // than on every request.
        if (! Cache::add(self::THROTTLE_KEY, now()->getTimestamp(), self::MIN_INTERVAL_SECONDS)) {
            return ['recorded' => false, 'reason' => 'throttled'];
        }

        try {
            return $this->record($extraSymbols);
        } catch (\Throwable $e) {
            Log::warning("Could not record snapshots: {$e->getMessage()}");

            return ['recorded' => false, 'reason' => 'error'];
        }
    }

    /** @return array{recorded: bool, reason: ?string} */
    private function record(array $extraSymbols): array
    {
        $usdt = collect($this->mexc->getAccountAssets()['data'] ?? [])->firstWhere('currency', 'USDT');

        if (! $usdt) {
            return ['recorded' => false, 'reason' => 'no_account'];
        }

        $positions = collect($this->mexc->getEnrichedPositions());
        $tickers   = $this->market->getAllTickers();
        $bySymbol  = $positions->groupBy('symbol');
        $now       = now();

        $symbols = $bySymbol->keys()
            ->merge(collect($extraSymbols)->map(fn ($s) => strtoupper((string) $s))->take(self::MAX_EXTRA_SYMBOLS))
            ->unique()
            ->values();

        DB::transaction(function () use ($usdt, $positions, $tickers, $bySymbol, $symbols, $now) {
            AccountSnapshot::create([
                'equity'          => (float) ($usdt['equity'] ?? 0),
                'available'       => isset($usdt['availableBalance']) ? (float) $usdt['availableBalance'] : null,
                'position_margin' => isset($usdt['positionMargin']) ? (float) $usdt['positionMargin'] : null,
                'unrealized'      => isset($usdt['unrealized']) ? (float) $usdt['unrealized'] : null,
                'long_notional'   => (float) $positions->where('positionType', 1)->sum('positionValue'),
                'short_notional'  => (float) $positions->where('positionType', 2)->sum('positionValue'),
                'position_count'  => $positions->count(),
                'recorded_at'     => $now,
            ]);

            foreach ($symbols as $symbol) {
                $ticker = $tickers->get($symbol);
                $legs   = $bySymbol->get($symbol, collect());
                $price  = (float) ($ticker['fairPrice'] ?? $ticker['lastPrice'] ?? $legs->first()['fairPrice'] ?? 0);

                // A name MEXC does not list (or a ticker with no price) has nothing to keep.
                if ($price <= 0) {
                    continue;
                }

                CoinSnapshot::create([
                    'symbol'          => $symbol,
                    'price'           => $price,
                    'funding_rate'    => isset($ticker['fundingRate']) ? (float) $ticker['fundingRate'] : null,
                    'open_interest'   => isset($ticker['holdVol']) ? (float) $ticker['holdVol'] : null,
                    'long_notional'   => (float) $legs->where('positionType', 1)->sum('positionValue'),
                    'short_notional'  => (float) $legs->where('positionType', 2)->sum('positionValue'),
                    'combined_pnl'    => $legs->isEmpty() ? null : (float) $legs->sum('unrealizedPnl'),
                    'nearest_liq_pct' => self::nearestLiquidationPct($legs),
                    'recorded_at'     => $now,
                ]);
            }
        });

        return ['recorded' => true, 'reason' => null];
    }

    /**
     * How far, in % of the mark price, the closest liquidation price of these legs is.
     * A leg with no liquidation price, or one on the wrong side of the mark, is ignored.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     */
    public static function nearestLiquidationPct(Collection $legs): ?float
    {
        $distances = $legs
            ->map(function ($leg) {
                $mark = (float) ($leg['fairPrice'] ?? 0);
                $liq  = (float) ($leg['liquidatePrice'] ?? 0);

                if ($mark <= 0 || $liq <= 0) {
                    return null;
                }

                $pct = ($leg['positionType'] ?? null) == 1
                    ? ($mark - $liq) / $mark * 100
                    : ($liq - $mark) / $mark * 100;

                return $pct > 0 ? $pct : null;
            })
            ->filter()
            ->values();

        return $distances->isEmpty() ? null : round($distances->min(), 3);
    }

    /**
     * Today's (UTC, like MEXC's own day) account equity readings: where the day started
     * as far as the log knows, its high and low, and the latest reading. Null when
     * nothing has been recorded yet today or the table cannot be read.
     *
     * @return array{open: float, high: float, low: float, last: float, count: int, first_at: string, last_at: string}|null
     */
    public function equityToday(): ?array
    {
        try {
            $today = AccountSnapshot::where('recorded_at', '>=', now()->utc()->startOfDay());

            $stats = (clone $today)->selectRaw('count(*) as readings, min(equity) as low, max(equity) as high')->first();

            if (! $stats || (int) $stats->readings === 0) {
                return null;
            }

            $first = (clone $today)->orderBy('recorded_at')->orderBy('id')->first();
            $last  = (clone $today)->orderByDesc('recorded_at')->orderByDesc('id')->first();

            return [
                'open'     => $first->equity,
                'high'     => (float) $stats->high,
                'low'      => (float) $stats->low,
                'last'     => $last->equity,
                'count'    => (int) $stats->readings,
                'first_at' => $first->recorded_at->toIso8601String(),
                'last_at'  => $last->recorded_at->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning("Could not read today's equity snapshots: {$e->getMessage()}");

            return null;
        }
    }
}
