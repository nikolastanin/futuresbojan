<?php

namespace App\Manual;

/**
 * Turns the price levels the dashboard already shows into a two-sided map of zones to
 * watch: levels stacked within 0.2% of each other form a zone, rated by how many
 * independent sources agree; zones below price are long candidates and zones above
 * are short candidates. Each zone gets confirmations (does the short-term picture
 * agree?), an invalidation level, an illustrative stop, the next zones as targets,
 * and a risk/reward. The stop is placed a full hourly ATR beyond invalidation, outside
 * normal hourly movement, and its distance is reported in ATRs and percent so it can
 * be weighed against leverage.
 *
 * Pure and deterministic: every number comes from the inputs, so it cannot invent a
 * level, and the same inputs always give the same plan. Nothing here places an order.
 */
class TradePlanBuilder
{
    /** Levels within this fraction of their neighbour stack into one zone (same as the ladder). */
    private const CLUSTER_TOLERANCE = 0.002;

    /** Zones further than this from price are not offered as entries (still usable as targets). */
    private const MAX_DISTANCE = 0.10;

    private const ZONES_PER_SIDE = 2;

    /**
     * Illustrative stop sits this many hourly ATRs beyond the invalidation level, so it
     * is outside normal hourly movement rather than inside the noise.
     */
    private const STOP_BUFFER_ATR = 1.0;

    /** Zones on the same side closer than this many hourly ATRs apart count as the same area. */
    private const MIN_ZONE_SEPARATION_ATR = 0.5;

    /** A target must be at least this many hourly ATRs beyond the zone to count. */
    private const MIN_TARGET_GAP_ATR = 1.0;

    private const IN_ZONE_TOLERANCE = 0.0005;

    /** Moving averages move with price, so they only count as part of a stack, never a zone alone. */
    private const DYNAMIC_LEVELS = ['EMA10', 'EMA20'];

    /**
     * @param  array<string, float>  $levels  Level label => price, e.g. ['PDH' => 309.29, 'POC' => 304.6].
     * @param  ?float  $atr  1H ATR in price units; null disables ATR-based stops/gaps.
     * @param  array<int, array{tf: string, macd: ?string, lean: string}>  $mtf  Rows from the multi-timeframe grid.
     * @param  ?string  $superTrend  15M SuperTrend direction: 'bullish', 'bearish' or null.
     * @return array{price: float, atr_1h: ?float, atr_pct: ?float, supertrend_15m: ?string, zones: array<int, array<string, mixed>>}
     */
    public function build(float $price, array $levels, ?float $atr, array $mtf, ?string $superTrend): array
    {
        $empty = [
            'price'          => $price,
            'atr_1h'         => $atr,
            'atr_pct'        => ($atr !== null && $price > 0) ? round($atr / $price * 100, 2) : null,
            'supertrend_15m' => $superTrend,
            'summary'        => $this->summarize([]),
            'zones'          => [],
        ];

        if ($price <= 0 || $levels === []) {
            return $empty;
        }

        $allZones = $this->clusters($levels);

        if ($allZones === []) {
            return $empty;
        }

        $mtfByTf = array_column($mtf, null, 'tf');
        $picked  = ['long' => [], 'short' => []];

        foreach ($allZones as $zone) {
            $distance = $this->distanceFraction($price, $zone);

            if ($distance > self::MAX_DISTANCE) {
                continue;
            }

            $picked[$this->sideFor($price, $zone)][] = $zone + ['distance' => $distance];
        }

        $zones = [];

        foreach (['short', 'long'] as $side) {
            usort($picked[$side], fn ($a, $b) => [$b['strength_rank'], $a['distance']] <=> [$a['strength_rank'], $b['distance']]);

            // Strongest first, but skip a zone sitting right next to one already chosen:
            // two levels 0.3% apart are one area, not two separate plans.
            $minSeparation = $atr !== null ? self::MIN_ZONE_SEPARATION_ATR * $atr : $price * 0.005;
            $chosen        = [];

            foreach ($picked[$side] as $zone) {
                if (count($chosen) >= self::ZONES_PER_SIDE) {
                    break;
                }

                $tooClose = false;

                foreach ($chosen as $existing) {
                    if ($this->gapBetween($zone, $existing) < $minSeparation) {
                        $tooClose = true;
                        break;
                    }
                }

                if (! $tooClose) {
                    $chosen[] = $zone;
                }
            }

            usort($chosen, fn ($a, $b) => $a['distance'] <=> $b['distance']);

            foreach ($chosen as $index => $zone) {
                $zones[] = $this->describe($side, $index + 1, $zone, $price, $atr, $allZones, $mtfByTf, $superTrend);
            }
        }

        return array_merge($empty, ['zones' => $zones, 'summary' => $this->summarize($zones)]);
    }

    /**
     * One or two plain sentences on where the plan stands: which zone(s) are fully
     * confirmed, or how close the best one is, plus the nearest zone if price is in or
     * right next to it. Built only from the zones' own fields, so it can never claim
     * something the cards below don't show.
     *
     * @param  array<int, array<string, mixed>>  $zones  Described zones, as returned in 'zones'.
     */
    private function summarize(array $zones): string
    {
        if ($zones === []) {
            return 'No level stacks near the current price, so there is no plan to watch right now.';
        }

        $label  = fn (array $z) => ucfirst($z['side']).' zone '.$z['number'];
        $total  = fn (array $z) => count($z['confirmations']);
        $where  = fn (array $z) => $z['status'] === 'in_zone'
            ? 'price is inside it'
            : "{$z['distance_pct']}% ".($z['side'] === 'short' ? 'above' : 'below');

        $anyKnown = false;

        foreach ($zones as $z) {
            foreach ($z['confirmations'] as $c) {
                $anyKnown = $anyKnown || $c['state'] !== 'unknown';
            }
        }

        if (! $anyKnown) {
            return 'The confirmations (SuperTrend, MACD, 4H backdrop) are not available yet, so the zones below are unconfirmed.';
        }

        $confirmed = array_values(array_filter($zones, fn ($z) => $z['confirmed'] === $total($z)));
        usort($confirmed, fn ($a, $b) => $a['distance_pct'] <=> $b['distance_pct']);

        $sentences = [];

        if (count($confirmed) === 1) {
            $z           = $confirmed[0];
            $sentences[] = "{$label($z)} is the only confirmed setup ({$z['strength']}, {$where($z)}).";
        } elseif (count($confirmed) > 1) {
            $sides = array_unique(array_column($confirmed, 'side'));
            $names = count($sides) === 1
                ? ucfirst($sides[0]).' zones '.$this->joinAnd(array_column($confirmed, 'number'))
                : $this->joinAnd(array_map($label, $confirmed));
            $nearest     = $confirmed[0];
            $sentences[] = "{$names} are confirmed setups; the nearer is {$label($nearest)} ({$nearest['strength']}, {$where($nearest)}).";
        } else {
            $best = $zones;
            usort($best, fn ($a, $b) => [$b['confirmed'], $b['strength'] === 'strong', $a['distance_pct']] <=> [$a['confirmed'], $a['strength'] === 'strong', $b['distance_pct']]);
            $best = $best[0];

            if ($best['confirmed'] >= 2) {
                $waiting = array_column(array_filter($best['confirmations'], fn ($c) => $c['state'] !== 'confirmed'), 'name');

                $sentences[] = "No zone is fully confirmed; {$label($best)} is closest at {$best['confirmed']}/{$total($best)} ({$this->joinAnd($waiting)} still waiting).";
            } else {
                $sentences[] = 'No zone is confirmed: the short-term picture does not back any of them yet.';
            }
        }

        // The zone price is in or next to deserves a mention even when it is not a confirmed one.
        $nearest = $zones;
        usort($nearest, fn ($a, $b) => $a['distance_pct'] <=> $b['distance_pct']);
        $nearest = $nearest[0];

        $alreadyNamed = count($confirmed) > 0 && $confirmed[0]['id'] === $nearest['id'];

        if (! $alreadyNamed && in_array($nearest['status'], ['in_zone', 'near'], true)) {
            $closeness = $nearest['status'] === 'in_zone' ? 'is where price is now' : 'is within one hourly ATR';
            $state     = $nearest['confirmed'] === $total($nearest)
                ? 'and fully confirmed'
                : "but only {$nearest['confirmed']}/{$total($nearest)} confirmed";

            $sentences[] = "{$label($nearest)} {$closeness}, {$state}.";
        }

        return implode(' ', $sentences);
    }

    /** "1", "1 and 2", "1, 2 and 3". */
    private function joinAnd(array $items): string
    {
        $items = array_values(array_map('strval', $items));

        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /**
     * Stack levels into zones, ascending by price.
     *
     * @param  array<string, float>  $levels
     * @return array<int, array{low: float, high: float, sources: array<int, array{label: string, price: float}>, strength: string, strength_rank: int}>
     */
    private function clusters(array $levels): array
    {
        $entries = [];

        foreach ($levels as $label => $level) {
            if (is_numeric($level) && $level > 0) {
                $entries[] = ['label' => (string) $label, 'price' => (float) $level];
            }
        }

        usort($entries, fn ($a, $b) => $a['price'] <=> $b['price']);

        $groups = [];
        $group  = [];

        foreach ($entries as $entry) {
            $last = $group === [] ? null : end($group);

            if ($last !== null && ($entry['price'] - $last['price']) / $last['price'] > self::CLUSTER_TOLERANCE) {
                $groups[] = $group;
                $group    = [];
            }

            $group[] = $entry;
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        $zones = [];

        foreach ($groups as $members) {
            if (count($members) === 1 && in_array($members[0]['label'], self::DYNAMIC_LEVELS, true)) {
                continue;
            }

            $count = count($members);
            $prices = array_column($members, 'price');

            $zones[] = [
                'low'           => min($prices),
                'high'          => max($prices),
                'sources'       => $members,
                'strength'      => $count >= 3 ? 'strong' : ($count === 2 ? 'solid' : 'weak'),
                'strength_rank' => min($count, 3),
            ];
        }

        return $zones;
    }

    /** Price gap between two zones (0 if they overlap). */
    private function gapBetween(array $a, array $b): float
    {
        return max(0.0, max($a['low'], $b['low']) - min($a['high'], $b['high']));
    }

    private function distanceFraction(float $price, array $zone): float
    {
        if ($price < $zone['low']) {
            return ($zone['low'] - $price) / $price;
        }

        if ($price > $zone['high']) {
            return ($price - $zone['high']) / $price;
        }

        return 0.0;
    }

    /** Zones below price are support (long candidates), above are resistance (short candidates). */
    private function sideFor(float $price, array $zone): string
    {
        if ($zone['high'] < $price * (1 - self::IN_ZONE_TOLERANCE)) {
            return 'long';
        }

        if ($zone['low'] > $price * (1 + self::IN_ZONE_TOLERANCE)) {
            return 'short';
        }

        return $price >= ($zone['low'] + $zone['high']) / 2 ? 'long' : 'short';
    }

    private function describe(string $side, int $number, array $zone, float $price, ?float $atr, array $allZones, array $mtfByTf, ?string $superTrend): array
    {
        $isLong = $side === 'long';
        $entry  = ($zone['low'] + $zone['high']) / 2;

        $invalidation = $isLong ? $zone['low'] : $zone['high'];
        $buffer       = $atr !== null ? self::STOP_BUFFER_ATR * $atr : $invalidation * 0.003;
        $stop         = $isLong ? $invalidation - $buffer : $invalidation + $buffer;
        $stopDistance = abs($entry - $stop);

        $gap     = $atr !== null ? self::MIN_TARGET_GAP_ATR * $atr : $entry * 0.003;
        $targets = $this->targets($isLong, $zone, $gap, $allZones);

        $rr = ($targets !== [] && $stopDistance > 0)
            ? round(abs($targets[0]['price'] - $entry) / $stopDistance, 2)
            : null;

        $stopAtr = ($atr !== null && $atr > 0) ? round($stopDistance / $atr, 2) : null;

        $warnings = [];

        // R:R cannot fall below 1 by construction (targets must clear one ATR, and so
        // does the stop), so the only case worth flagging is having no target at all.
        if ($targets === []) {
            $warnings[] = 'No further zone beyond this one to use as a target.';
        }

        $confirmations = $this->confirmations($isLong, $mtfByTf, $superTrend);
        $distance      = $zone['distance'];

        return [
            'id'               => "{$side}-{$number}",
            'side'             => $side,
            'number'           => $number,
            'strength'         => $zone['strength'],
            'low'              => round($zone['low'], 8),
            'high'             => round($zone['high'], 8),
            'sources'          => array_map(fn ($s) => ['label' => $s['label'], 'price' => round($s['price'], 8)], $zone['sources']),
            'distance_pct'     => round($distance * 100, 2),
            'status'           => $distance <= self::IN_ZONE_TOLERANCE
                ? 'in_zone'
                : (($atr !== null && ($distance * $price) <= $atr) ? 'near' : 'far'),
            'confirmations'    => $confirmations,
            'confirmed'        => count(array_filter($confirmations, fn ($c) => $c['state'] === 'confirmed')),
            'invalidation'     => round($invalidation, 8),
            'stop'             => round($stop, 8),
            'stop_distance_atr' => $stopAtr,
            'stop_distance_pct' => $entry > 0 ? round($stopDistance / $entry * 100, 2) : null,
            'targets'          => $targets,
            'rr'               => $rr,
            'warnings'         => $warnings,
        ];
    }

    /**
     * The next zones beyond this one in the trade's direction — a long targets the
     * lower edge of resistance above it, a short the upper edge of support below it.
     *
     * @return array<int, array{price: float, label: string}>
     */
    private function targets(bool $isLong, array $zone, float $gap, array $allZones): array
    {
        $candidates = array_values(array_filter($allZones, fn ($z) => $isLong
            ? $z['low'] > $zone['high'] + $gap
            : $z['high'] < $zone['low'] - $gap));

        usort($candidates, fn ($a, $b) => $isLong ? $a['low'] <=> $b['low'] : $b['high'] <=> $a['high']);

        return array_map(
            fn ($z) => [
                'price' => round($isLong ? $z['low'] : $z['high'], 8),
                'label' => implode('+', array_column($z['sources'], 'label')),
            ],
            array_slice($candidates, 0, 2),
        );
    }

    /**
     * Does the short-term picture agree with this zone's direction? "Waiting" means it
     * currently points the other way (or is undecided), not that the zone is wrong.
     *
     * @return array<int, array{name: string, state: string, detail: string}>
     */
    private function confirmations(bool $isLong, array $mtfByTf, ?string $superTrend): array
    {
        $wantTrend = $isLong ? 'bullish' : 'bearish';
        $wantLean  = $isLong ? 'up' : 'down';

        $macd15 = $mtfByTf['15M']['macd'] ?? null;
        $lean4h = $mtfByTf['4H']['lean'] ?? null;

        $state = fn (?string $actual, string $wanted) => $actual === null ? 'unknown' : ($actual === $wanted ? 'confirmed' : 'waiting');

        return [
            ['name' => 'SuperTrend 15M', 'state' => $state($superTrend, $wantTrend), 'detail' => $superTrend ?? 'n/a'],
            ['name' => 'MACD 15M', 'state' => $state($macd15, $wantTrend), 'detail' => $macd15 ?? 'n/a'],
            ['name' => '4H backdrop', 'state' => $state($lean4h, $wantLean), 'detail' => $lean4h ?? 'n/a'],
        ];
    }
}
