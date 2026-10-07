<?php

namespace App\Manual;

/**
 * Measures the latest candles of one timeframe and says in plain words what they did, so
 * the AI never has to "see" a pattern in a wall of numbers: code does the seeing (every
 * size, every wick, every touch of a zone is computed here and can be tested), the AI
 * only reads the result.
 *
 * Two rules keep it honest. Patterns are only called on candles that have CLOSED — a
 * candle still forming can change its mind every few seconds, so it is shown for
 * context but never labelled. And every flag is a statement about a candle and a number
 * that was passed in (a zone, a level, the last ten highs), never about the future.
 *
 * Pure and deterministic: the same candles, zones, levels and clock always give the same
 * tape. Nothing here places an order.
 */
class CandleReader
{
    /** Closed candles shown in a tape. */
    private const SHOWN = 12;

    private const ATR_PERIOD = 14;

    /** How far back a volume comparison and a swing high/low look. */
    private const VOLUME_LOOKBACK = 20;

    private const SWING_LOOKBACK = 10;

    /** Single-candle patterns need a candle at least this big (in ATRs); smaller ones are noise. */
    private const MIN_RANGE_ATR = 0.4;

    private const DOJI_BODY = 0.10;

    /** A long-wick candle has at least this share of its range as one wick and little of the other. */
    private const LONG_WICK = 0.55;

    private const WICK_OPPOSITE_MAX = 0.20;

    /** "Strong" candle bodies for three-in-a-row. */
    private const STRONG_BODY = 0.40;

    private const WIDE_RANGE_ATR = 1.8;

    private const VOLUME_SPIKE = 2.0;

    /** A level inside a plan zone (within this fraction) is covered by the zone, not reported twice. */
    private const ZONE_MEMBER_TOLERANCE = 0.001;

    private const MAX_FLAGS_PER_CANDLE = 3;

    /** Of those, at most this many may be tests of a zone or level, so patterns still get a slot. */
    private const MAX_LEVEL_FLAGS_PER_CANDLE = 2;

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  Oldest first; the last one may still be forming.
     * @param  string  $tf  Label such as '15M'.
     * @param  int  $intervalSeconds  Length of one candle.
     * @param  array<int, array{side: string, number: int, low: float, high: float}>  $zones  The plan's zones.
     * @param  array<string, float>  $levels  Level label => price.
     * @param  int  $now  Unix seconds; decides whether the last candle has closed.
     * @return array<string, mixed>|null  Null when there are too few closed candles to measure.
     */
    public function read(array $candles, string $tf, int $intervalSeconds, array $zones, array $levels, int $now): ?array
    {
        if ($candles === []) {
            return null;
        }

        $candles = array_values($candles);
        $last    = $candles[count($candles) - 1];
        $forming = ($last['time'] + $intervalSeconds > $now) ? $last : null;
        $closed  = $forming ? array_slice($candles, 0, -1) : $candles;
        $count   = count($closed);

        if ($count < self::ATR_PERIOD + 2) {
            return null;
        }

        $atrAt = $this->atrSeries($closed);
        $atr   = $atrAt[$count - 1] ?? null;

        if ($atr === null || $atr <= 0) {
            return null;
        }

        $levels = $this->standaloneLevels($levels, $zones);
        $first  = max(1, $count - self::SHOWN);
        $rows   = [];

        for ($i = $first; $i < $count; $i++) {
            // A candle is sized against the volatility that came BEFORE it: measured against
            // an ATR that includes itself, a huge candle would shrink its own reading.
            $priorAtr = $atrAt[$i - 1] ?? $atrAt[self::ATR_PERIOD];

            $rows[] = $this->closedRow($closed, $i, $priorAtr, $zones, $levels, $count - 1 - $i);
        }

        $lastClose = $closed[$count - 1]['close'];

        return [
            'tf'       => $tf,
            'atr'      => round($atr, 8),
            'atr_pct'  => $lastClose > 0 ? round($atr / $lastClose * 100, 2) : null,
            'forming'  => $forming ? $this->formingRow($forming, $atr) : null,
            'candles'  => array_reverse($rows), // newest first
            'sequence' => $this->sequence($closed, $first, $tf),
        ];
    }

    // ─── One candle ──────────────────────────────────────────────────────────

    /** @return array<string, float|string> */
    private function measure(array $c, float $atr): array
    {
        $range      = $c['high'] - $c['low'];
        $bodyTop    = max($c['open'], $c['close']);
        $bodyBottom = min($c['open'], $c['close']);

        return [
            'direction' => $c['close'] > $c['open'] ? 'up' : ($c['close'] < $c['open'] ? 'down' : 'flat'),
            'range'     => $range,
            'range_atr' => $range / $atr,
            'body'      => $range > 0 ? ($bodyTop - $bodyBottom) / $range : 0.0,
            'upper'     => $range > 0 ? ($c['high'] - $bodyTop) / $range : 0.0,
            'lower'     => $range > 0 ? ($bodyBottom - $c['low']) / $range : 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private function row(array $c, array $m, ?float $volumeRatio, bool $closed, ?int $ago, array $flags): array
    {
        return [
            'time'           => $c['time'],
            'closed'         => $closed,
            'ago'            => $ago,
            'open'           => $c['open'],
            'high'           => $c['high'],
            'low'            => $c['low'],
            'close'          => $c['close'],
            'volume'         => $c['volume'],
            'direction'      => $m['direction'],
            'range_atr'      => round($m['range_atr'], 2),
            'body_pct'       => (int) round($m['body'] * 100),
            'upper_wick_pct' => (int) round($m['upper'] * 100),
            'lower_wick_pct' => (int) round($m['lower'] * 100),
            'volume_ratio'   => $volumeRatio !== null ? round($volumeRatio, 1) : null,
            'flags'          => $flags,
        ];
    }

    /** The candle that is still forming: measured so far, never labelled, volume left out (it is partial). */
    private function formingRow(array $c, float $atr): array
    {
        return $this->row($c, $this->measure($c, $atr), null, false, null, []);
    }

    private function closedRow(array $closed, int $i, float $atr, array $zones, array $levels, int $ago): array
    {
        $m           = $this->measure($closed[$i], $atr);
        $volumeRatio = $this->volumeRatio($closed, $i);

        return $this->row(
            $closed[$i],
            $m,
            $volumeRatio,
            true,
            $ago,
            $this->flags($closed, $i, $m, $atr, $volumeRatio, $zones, $levels),
        );
    }

    private function volumeRatio(array $closed, int $i): ?float
    {
        $prior = array_slice($closed, max(0, $i - self::VOLUME_LOOKBACK), min(self::VOLUME_LOOKBACK, $i));

        if (count($prior) < 5) {
            return null;
        }

        $average = array_sum(array_column($prior, 'volume')) / count($prior);

        return $average > 0 ? $closed[$i]['volume'] / $average : null;
    }

    // ─── Flags ───────────────────────────────────────────────────────────────

    /**
     * Everything notable about closed candle $i, most important first, capped so one busy
     * candle cannot bury the rest.
     *
     * @return array<int, array{key: string, bias: string, label: string}>
     */
    private function flags(array $closed, int $i, array $m, float $atr, ?float $volumeRatio, array $zones, array $levels): array
    {
        $c        = $closed[$i];
        $p        = $closed[$i - 1];
        $big      = $m['range_atr'] >= self::MIN_RANGE_ATR;
        $found    = []; // [priority, distance, key, bias, label]
        $previous = $this->measure($p, $atr);

        // Where the last few candles have been: sweeps and breakouts are judged against them.
        $reference = array_slice($closed, max(0, $i - self::SWING_LOOKBACK), min(self::SWING_LOOKBACK, $i));

        // — Tests of the plan's zones and of standalone levels —
        foreach ($this->levelEvents($c, $p, $zones, $levels) as $event) {
            $found[] = $event;
        }

        if ($big && $previous['direction'] !== 'flat' && $m['direction'] !== 'flat'
            && $m['direction'] !== $previous['direction']
            && $previous['body'] >= 0.25
            && abs($c['close'] - $c['open']) >= 1.2 * abs($p['close'] - $p['open'])
        ) {
            $bullish = $m['direction'] === 'up'
                && $c['open'] <= $p['close'] && $c['close'] >= $p['open'];
            $bearish = $m['direction'] === 'down'
                && $c['open'] >= $p['close'] && $c['close'] <= $p['open'];

            if ($bullish) {
                $found[] = [30, 0.0, 'bullish_engulfing', 'bullish', "Bullish engulfing — the body swallowed the previous candle's body"];
            } elseif ($bearish) {
                $found[] = [30, 0.0, 'bearish_engulfing', 'bearish', "Bearish engulfing — the body swallowed the previous candle's body"];
            }
        }

        if (count($reference) >= 5) {
            $refHigh = max(array_column($reference, 'high'));
            $refLow  = min(array_column($reference, 'low'));
            $n       = count($reference);

            if ($c['high'] > $refHigh && $c['close'] < $refHigh) {
                $found[] = [40, 0.0, 'sweep_high', 'bearish', "Swept above the last {$n} candles' high, then closed back under it — a failed breakout"];
            } elseif ($c['low'] < $refLow && $c['close'] > $refLow) {
                $found[] = [40, 0.0, 'sweep_low', 'bullish', "Swept below the last {$n} candles' low, then closed back above it — a failed breakdown"];
            } elseif ($c['close'] > $refHigh) {
                $found[] = [50, 0.0, 'breakout_up', 'bullish', "Closed above the last {$n} candles' high"];
            } elseif ($c['close'] < $refLow) {
                $found[] = [50, 0.0, 'breakout_down', 'bearish', "Closed below the last {$n} candles' low"];
            }
        }

        if ($big && $m['lower'] >= self::LONG_WICK && $m['upper'] <= self::WICK_OPPOSITE_MAX) {
            $found[] = [60, 0.0, 'long_lower_wick', 'bullish', 'Long lower wick — buyers pushed back from the low'];
        } elseif ($big && $m['upper'] >= self::LONG_WICK && $m['lower'] <= self::WICK_OPPOSITE_MAX) {
            $found[] = [60, 0.0, 'long_upper_wick', 'bearish', 'Long upper wick — sellers pushed back from the high'];
        }

        if ($i >= 2 && $this->threeInARow($closed, $i, $m['direction'])) {
            $up      = $m['direction'] === 'up';
            $found[] = [70, 0.0, $up ? 'three_up' : 'three_down', $up ? 'bullish' : 'bearish', 'Three strong '.($up ? 'up' : 'down').' closes in a row'];
        }

        if ($m['range_atr'] >= self::WIDE_RANGE_ATR) {
            $bias    = $m['body'] >= 0.5 ? ($m['direction'] === 'up' ? 'bullish' : 'bearish') : 'neutral';
            $found[] = [80, 0.0, 'wide_range', $bias, sprintf('Wide-range candle — %.1f× the average range', $m['range_atr'])];
        }

        if ($volumeRatio !== null && $volumeRatio >= self::VOLUME_SPIKE) {
            $found[] = [85, 0.0, 'volume_spike', 'neutral', sprintf('Volume %.1f× the recent average', $volumeRatio)];
        }

        // An inside bar says something when the candle it sits inside was a real one.
        if ($previous['range_atr'] >= self::MIN_RANGE_ATR
            && $c['high'] <= $p['high'] && $c['low'] >= $p['low']
            && ($c['high'] - $c['low']) < ($p['high'] - $p['low'])
        ) {
            $found[] = [90, 0.0, 'inside_bar', 'neutral', "Inside bar — stayed inside the previous candle's range (compression)"];
        }

        if ($big && $m['body'] <= self::DOJI_BODY) {
            $found[] = [95, 0.0, 'doji', 'neutral', 'Doji — open and close almost equal; neither side won'];
        }

        usort($found, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $flags       = [];
        $levelFlags  = 0;

        foreach ($found as [$priority, , $key, $bias, $label]) {
            $isLevel = $priority < 30;

            if ($isLevel && $levelFlags >= self::MAX_LEVEL_FLAGS_PER_CANDLE) {
                continue;
            }

            $levelFlags += $isLevel ? 1 : 0;
            $flags[]     = ['key' => $key, 'bias' => $bias, 'label' => $label];

            if (count($flags) >= self::MAX_FLAGS_PER_CANDLE) {
                break;
            }
        }

        return $flags;
    }

    /**
     * How candle $c behaved at each plan zone and each standalone level: did it close
     * beyond one, or reach it and get turned back, or dip into it and hold. Zones come
     * first, and within each group the one closest to the close.
     *
     * Zones and levels are treated alike and by what the candle did, not by what the
     * zone is called now: "Short zone 1" is resistance today, but a candle from hours ago
     * may have traded far above it, so only crossings and turn-backs are reported.
     *
     * @return array<int, array{0: int, 1: float, 2: string, 3: string, 4: string}>
     */
    private function levelEvents(array $c, array $p, array $zones, array $levels): array
    {
        $events = [];

        foreach ($zones as $zone) {
            $low  = (float) $zone['low'];
            $high = (float) $zone['high'];
            $span = '$'.self::price($low).($high > $low ? '–$'.self::price($high) : '');
            $name = ucfirst((string) $zone['side']).' zone '.$zone['number']." ({$span})";
            // How far the close is from the zone (0 when it closed inside it).
            $near = $c['close'] > $high ? $c['close'] - $high : ($c['close'] < $low ? $low - $c['close'] : 0.0);

            foreach ($this->bandEvents($c, $p, $low, $high) as [$key, $bias, $template]) {
                $events[] = [str_starts_with($key, 'broke') ? 10 : 11, $near, "zone_{$key}", $bias, sprintf($template, $name)];
            }
        }

        foreach ($levels as $label => $level) {
            $level = (float) $level;
            $name  = "{$label} ($".self::price($level).')';
            $near  = abs($c['close'] - $level);

            foreach ($this->bandEvents($c, $p, $level, $level) as [$key, $bias, $template]) {
                $events[] = [str_starts_with($key, 'broke') ? 20 : 21, $near, "level_{$key}", $bias, sprintf($template, $name)];
            }
        }

        return $events;
    }

    /**
     * What one candle did against a price band (a zone, or a single level as a band of
     * no width). At most one thing: the strongest of closing through it, being turned
     * back from below, or dipping in from above and holding.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>  [key, bias, sprintf template taking the band's name]
     */
    private function bandEvents(array $c, array $p, float $low, float $high): array
    {
        if ($c['close'] > $high && $p['close'] <= $high) {
            return [['broke_up', 'bullish', 'Closed above %s']];
        }

        if ($c['close'] < $low && $p['close'] >= $low) {
            return [['broke_down', 'bearish', 'Closed below %s']];
        }

        if ($c['open'] < $low && $c['high'] >= $low && $c['close'] < $low) {
            return [['rejected', 'bearish', 'Reached %s and closed back under it — rejected']];
        }

        if ($c['open'] > $high && $c['low'] <= $high && $c['close'] > $high) {
            return [['held', 'bullish', 'Dipped into %s and closed back above it — held']];
        }

        return [];
    }

    /** Levels that are not already part of a plan zone — those are reported through the zone. */
    private function standaloneLevels(array $levels, array $zones): array
    {
        return array_filter($levels, function ($price) use ($zones) {
            if (! is_numeric($price) || $price <= 0) {
                return false;
            }

            foreach ($zones as $zone) {
                if ($price >= $zone['low'] * (1 - self::ZONE_MEMBER_TOLERANCE)
                    && $price <= $zone['high'] * (1 + self::ZONE_MEMBER_TOLERANCE)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** Candles $i-2..$i all close the same way, each with a real body, each beyond the last. */
    private function threeInARow(array $closed, int $i, string $direction): bool
    {
        if ($direction === 'flat') {
            return false;
        }

        $trio = [$closed[$i - 2], $closed[$i - 1], $closed[$i]];

        foreach ($trio as $k => $c) {
            $range = $c['high'] - $c['low'];

            if ($range <= 0 || abs($c['close'] - $c['open']) / $range < self::STRONG_BODY) {
                return false;
            }

            if (($direction === 'up') !== ($c['close'] > $c['open'])) {
                return false;
            }

            if ($k > 0 && ($direction === 'up' ? $c['close'] <= $trio[$k - 1]['close'] : $c['close'] >= $trio[$k - 1]['close'])) {
                return false;
            }
        }

        return true;
    }

    // ─── The sequence as a whole ─────────────────────────────────────────────

    /**
     * What the run of candles adds up to: how many closed each way, whether highs and
     * lows are stepping up or down, whether ranges and volume are shrinking or growing,
     * and where the last close sits in the window's range.
     *
     * @return array<string, mixed>
     */
    private function sequence(array $closed, int $first, string $tf): array
    {
        $window = array_slice($closed, $first);
        $count  = count($window);
        $up     = count(array_filter($window, fn ($c) => $c['close'] > $c['open']));
        $down   = count(array_filter($window, fn ($c) => $c['close'] < $c['open']));

        $windowHigh = max(array_column($window, 'high'));
        $windowLow  = min(array_column($window, 'low'));
        $lastClose  = $window[$count - 1]['close'];
        $firstOpen  = $window[0]['open'];
        $net        = $firstOpen > 0 ? round(($lastClose - $firstOpen) / $firstOpen * 100, 2) : 0.0;
        $position   = $windowHigh > $windowLow
            ? (int) round(($lastClose - $windowLow) / ($windowHigh - $windowLow) * 100)
            : 50;

        // Steps between the last six candles' highs and lows.
        $recent = array_slice($closed, -6);
        $hh = $hl = $lh = $ll = 0;

        for ($k = 1; $k < count($recent); $k++) {
            $hh += $recent[$k]['high'] > $recent[$k - 1]['high'] ? 1 : 0;
            $lh += $recent[$k]['high'] < $recent[$k - 1]['high'] ? 1 : 0;
            $hl += $recent[$k]['low'] > $recent[$k - 1]['low'] ? 1 : 0;
            $ll += $recent[$k]['low'] < $recent[$k - 1]['low'] ? 1 : 0;
        }

        // Four of the five steps must agree: alternating up and down steps is a range, not a trend.
        $structure = ($hh >= 4 && $hl >= 4) ? 'higher_highs_higher_lows'
            : (($lh >= 4 && $ll >= 4) ? 'lower_highs_lower_lows' : 'mixed');

        $rangeTrend  = $this->trend($this->averages($closed, 'range', 3, 3), 0.75, 1.35, 'compressing', 'expanding');
        $volumeTrend = $this->trend($this->averages($closed, 'volume', 3, 9), 0.7, 1.3, 'falling', 'rising');

        $parts = [
            "{$count} closed {$tf} candles: {$up} up, {$down} down (".sprintf('%+.2f', $net).'%)',
            match ($structure) {
                'higher_highs_higher_lows' => 'higher highs and higher lows',
                'lower_highs_lower_lows'   => 'lower highs and lower lows',
                default                    => 'no clear structure',
            },
        ];

        if ($rangeTrend !== 'steady') {
            $parts[] = "ranges {$rangeTrend}";
        }

        if ($volumeTrend !== 'steady') {
            $parts[] = "volume {$volumeTrend}";
        }

        $parts[] = "last close at {$position}% of the window's range (0 = its low, 100 = its high)";

        return [
            'closed'             => $count,
            'up'                 => $up,
            'down'               => $down,
            'net_change_pct'     => $net,
            'structure'          => $structure,
            'range_trend'        => $rangeTrend,
            'volume_trend'       => $volumeTrend,
            'close_in_range_pct' => $position,
            'summary'            => implode('; ', $parts).'.',
        ];
    }

    /**
     * Average of the latest $recent values of a field against the $before values just
     * before them. For 'range' the candle's high minus low is used.
     *
     * @return ?float  recent / before, or null when either side has no size.
     */
    private function averages(array $closed, string $field, int $recent, int $before): ?float
    {
        $value = fn (array $c) => $field === 'range' ? $c['high'] - $c['low'] : $c[$field];

        $latest = array_map($value, array_slice($closed, -$recent));
        $prior  = array_map($value, array_slice($closed, -($recent + $before), $before));

        if (count($latest) < $recent || count($prior) < $before) {
            return null;
        }

        $priorAverage = array_sum($prior) / count($prior);

        return $priorAverage > 0 ? (array_sum($latest) / count($latest)) / $priorAverage : null;
    }

    private function trend(?float $ratio, float $falling, float $rising, string $fallingLabel, string $risingLabel): string
    {
        if ($ratio === null) {
            return 'steady';
        }

        return $ratio < $falling ? $fallingLabel : ($ratio > $rising ? $risingLabel : 'steady');
    }

    /**
     * Wilder's ATR (the same smoothing the rest of the app uses) as it stood after each
     * candle, so every candle can be sized against the volatility before it. Keyed by
     * candle index; empty until there are enough candles.
     *
     * @return array<int, float>
     */
    private function atrSeries(array $candles): array
    {
        $period = self::ATR_PERIOD;

        if (count($candles) < $period + 1) {
            return [];
        }

        $ranges = []; // $ranges[$k] is the true range of candle $k + 1

        for ($i = 1; $i < count($candles); $i++) {
            $prevClose = $candles[$i - 1]['close'];
            $ranges[]  = max(
                $candles[$i]['high'] - $candles[$i]['low'],
                abs($candles[$i]['high'] - $prevClose),
                abs($candles[$i]['low'] - $prevClose),
            );
        }

        $atr            = array_sum(array_slice($ranges, 0, $period)) / $period;
        $series         = [];
        $series[$period] = $atr;

        for ($k = $period; $k < count($ranges); $k++) {
            $atr               = ($atr * ($period - 1) + $ranges[$k]) / $period;
            $series[$k + 1]    = $atr;
        }

        return $series;
    }

    // ─── Words for the AI ────────────────────────────────────────────────────

    /** A price as plain text: two decimals from 1 up, trimmed decimals below. */
    public static function price(float $n): string
    {
        if (abs($n) >= 1) {
            return number_format($n, 2, '.', '');
        }

        $text = rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /** "last closed", "1 back", "2 back"... — candles are never named by clock time. */
    public static function agoLabel(int $ago): string
    {
        return $ago === 0 ? 'last closed' : "{$ago} back";
    }

    /**
     * A short digest of a tape for a prompt that has other things to say: the sequence
     * in one sentence and the flags of the most recent closed candles.
     *
     * @param  array<string, mixed>  $tape
     * @return array<int, string>
     */
    public static function briefLines(array $tape, int $recent = 4): array
    {
        $flagged = [];

        foreach ($tape['candles'] ?? [] as $row) {
            // "The last N closed candles" means by how far back they are, not by position in the list.
            if ((int) ($row['ago'] ?? 0) >= $recent) {
                continue;
            }

            foreach ($row['flags'] ?? [] as $flag) {
                $flagged[] = '['.self::agoLabel((int) ($row['ago'] ?? 0)).'] '.($flag['label'] ?? '');
            }
        }

        return [
            '- '.($tape['tf'] ?? '?').': '.($tape['sequence']['summary'] ?? 'no summary'),
            '  Flagged in the last '.$recent.' closed candles: '.($flagged === [] ? 'nothing' : implode('; ', $flagged)),
        ];
    }

    /**
     * The whole tape as prompt lines: the sequence, then every shown candle with its
     * measurements and flags, newest first, and the forming candle marked as context only.
     *
     * @param  array<string, mixed>  $tape
     * @return array<int, string>
     */
    public static function fullLines(array $tape): array
    {
        $lines = [
            ($tape['tf'] ?? '?').' — 1 ATR = '.self::price((float) ($tape['atr'] ?? 0)).' ('.($tape['atr_pct'] ?? '?').'% of price). '.($tape['sequence']['summary'] ?? ''),
        ];

        foreach ($tape['candles'] ?? [] as $row) {
            $flags   = array_map(fn ($f) => $f['label'] ?? '', $row['flags'] ?? []);
            $lines[] = '  '.self::agoLabel((int) ($row['ago'] ?? 0)).': '.self::describeRow($row)
                .($flags === [] ? '' : '; flags: '.implode(' | ', $flags));
        }

        if (is_array($tape['forming'] ?? null)) {
            $lines[] = '  forming (NOT closed — context only, no patterns): '.self::describeRow($tape['forming']);
        }

        return $lines;
    }

    private static function describeRow(array $row): string
    {
        $text = sprintf(
            '%s, o %s h %s l %s c %s; range %.1fx ATR, body %d%%, upper wick %d%%, lower wick %d%%',
            $row['direction'] ?? 'flat',
            self::price((float) ($row['open'] ?? 0)),
            self::price((float) ($row['high'] ?? 0)),
            self::price((float) ($row['low'] ?? 0)),
            self::price((float) ($row['close'] ?? 0)),
            (float) ($row['range_atr'] ?? 0),
            (int) ($row['body_pct'] ?? 0),
            (int) ($row['upper_wick_pct'] ?? 0),
            (int) ($row['lower_wick_pct'] ?? 0),
        );

        if (isset($row['volume_ratio'])) {
            $text .= sprintf(', volume %.1fx average', (float) $row['volume_ratio']);
        }

        return $text;
    }
}
