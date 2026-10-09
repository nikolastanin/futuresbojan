<?php

namespace App\Manual;

use App\Bot\Indicators\IndicatorService;

/**
 * Decides whether one coin is at an extreme on one indicator, and how strongly, so a scan can
 * list the most oversold and the most overbought coins. Two kinds of indicator: those read
 * from a coin's candles (WaveTrend, RSI, MACD) and those the exchange's ticker already
 * carries for every coin (moves, funding, position in the 24h range).
 *
 * "Oversold" and "overbought" are the two sides of every list, whatever the indicator: for
 * a move they are the biggest losers and gainers, for funding the coins whose shorts or
 * longs are crowded. An extreme is a place to look, not a signal; nothing here predicts
 * anything or places an order.
 *
 * Pure and deterministic: the same candles, ticker and clock always give the same answer.
 * The WaveTrend rules are the card's: a cross counts once its candle has closed, and one on
 * the open candle is reported as pending.
 */
class ExtremesScreener
{
    /**
     * What each indicator is called, how its two lists are titled and, in plain words, what gets a
     * coin onto one. `kind` says where the reading comes from.
     */
    public const INDICATORS = [
        'wt_cross'  => ['kind' => 'candles', 'label' => 'WaveTrend — fresh cross at an extreme', 'oversold' => 'Crossed up from oversold', 'overbought' => 'Crossed down from overbought', 'rule' => 'A WaveTrend cross in the last 3 closed candles, or forming now, beyond the ±53 line. Pending ones are listed last.'],
        'wt_level'  => ['kind' => 'candles', 'label' => 'WaveTrend — most extreme level', 'oversold' => 'Oversold', 'overbought' => 'Overbought', 'rule' => 'WT1 beyond the ±53 line right now, most extreme first.'],
        'rsi'       => ['kind' => 'candles', 'label' => 'RSI (14)', 'oversold' => 'Oversold', 'overbought' => 'Overbought', 'rule' => 'RSI at 30 or lower, or at 70 or higher.'],
        'macd'      => ['kind' => 'candles', 'label' => 'MACD — momentum stretch', 'oversold' => 'Stretched down', 'overbought' => 'Stretched up', 'rule' => 'MACD histogram at least 1.5 times its usual size over the last 200 candles.'],
        'move_24h'  => ['kind' => 'ticker', 'label' => '24h move', 'oversold' => 'Biggest losers', 'overbought' => 'Biggest gainers', 'rule' => 'The biggest moves of the last 24 hours.'],
        'move_7d'   => ['kind' => 'ticker', 'label' => '7-day move', 'oversold' => 'Biggest losers', 'overbought' => 'Biggest gainers', 'rule' => 'The biggest moves of the last 7 days.'],
        'move_30d'  => ['kind' => 'ticker', 'label' => '30-day move', 'oversold' => 'Biggest losers', 'overbought' => 'Biggest gainers', 'rule' => 'The biggest moves of the last 30 days.'],
        'funding'   => ['kind' => 'ticker', 'label' => 'Funding rate', 'oversold' => 'Shorts crowded (negative funding)', 'overbought' => 'Longs crowded (positive funding)', 'rule' => 'Funding of at least 0.02% a period either way, most extreme first; the side paying is the crowded one. The period differs by coin.'],
        'range_24h' => ['kind' => 'ticker', 'label' => 'Position in the 24h range', 'oversold' => 'Sitting at the 24h low', 'overbought' => 'Sitting at the 24h high', 'rule' => 'Within a tenth of the 24h low or high, on a day that moved at least 3%.'],
    ];

    /** Candles an indicator needs before its reading is settled. */
    private const MIN_CANDLES = 60;

    /** A WaveTrend cross is fresh for this many closed candles (the last one and the two before it). */
    private const CROSS_WINDOW = 3;

    private const RSI_OVERSOLD = 30.0;

    private const RSI_OVERBOUGHT = 70.0;

    /**
     * The MACD histogram, as a multiple of its own usual size over its last 200 candles, beyond
     * which momentum counts as stretched. Set by looking at the 50 most traded coins on a live
     * day: 1.5 left a handful per timeframe, 2.0 left almost none.
     */
    private const MACD_STRETCH = 1.5;

    private const MACD_HISTORY = 200;

    /** A coin counts as sitting at its 24h low or high within this share of the range, if the range is wide enough to mean something. */
    private const RANGE_EDGE = 0.10;

    private const RANGE_MIN_PCT = 3.0;

    /** Funding below this many percent a period either way is the ordinary rate, not a crowded side (0.01% is the usual baseline). */
    private const FUNDING_MIN_PCT = 0.02;

    public function __construct(
        private IndicatorService $indicators,
        private WaveTrendReader $waveTrend,
    ) {}

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::INDICATORS);
    }

    public static function isCandleBased(string $indicator): bool
    {
        return (self::INDICATORS[$indicator]['kind'] ?? null) === 'candles';
    }

    /**
     * One coin's reading from its candles, or null when it is not at an extreme or has too
     * few candles. `side` says which list it belongs on, `strength` orders a list (bigger is
     * more extreme) and `group` puts the unconfirmed ones after the confirmed.
     *
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  Oldest first; the last may still be forming.
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>, volatility: array{kind: string, pct: ?float}}|null
     */
    public function fromCandles(string $indicator, array $candles, string $tf, int $intervalSeconds, int $now): ?array
    {
        $candles = array_values($candles);

        if (count($candles) < self::MIN_CANDLES || ! self::isCandleBased($indicator)) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $atr    = $this->indicators->atr($candles, 14);
        $last   = (float) end($closes);

        $hit = match ($indicator) {
            'wt_cross' => $this->waveTrendCross($candles, $tf, $intervalSeconds, $now),
            'wt_level' => $this->waveTrendLevel($candles, $tf, $intervalSeconds, $now),
            'rsi'      => $this->rsi($closes),
            'macd'     => $this->macdStretch($closes),
        };

        if ($hit === null) {
            return null;
        }

        return $hit + [
            'volatility' => ['kind' => 'atr', 'pct' => $atr !== null && $last > 0 ? round($atr / $last * 100, 2) : null],
        ];
    }

    /**
     * One coin's reading from its row in the exchange's ticker, or null when it is not at an
     * extreme (or the row lacks the field).
     *
     * @param  array<string, mixed>  $ticker  symbol, lastPrice, fundingRate, high24Price, lower24Price, riseFallRate, riseFallRates{r7, r30}
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>, volatility: array{kind: string, pct: ?float}}|null
     */
    public function fromTicker(string $indicator, array $ticker): ?array
    {
        if (self::isCandleBased($indicator) || ! isset(self::INDICATORS[$indicator])) {
            return null;
        }

        $last  = (float) ($ticker['lastPrice'] ?? 0);
        $high  = (float) ($ticker['high24Price'] ?? 0);
        $low   = (float) ($ticker['lower24Price'] ?? 0);
        $range = $last > 0 && $high > $low ? round(($high - $low) / $last * 100, 2) : null;

        $hit = match ($indicator) {
            'move_24h'  => $this->signed(self::fraction($ticker['riseFallRate'] ?? null), 'pct'),
            'move_7d'   => $this->signed(self::fraction($ticker['riseFallRates']['r7'] ?? null), 'pct'),
            'move_30d'  => $this->signed(self::fraction($ticker['riseFallRates']['r30'] ?? null), 'pct'),
            'funding'   => $this->signed(self::fraction($ticker['fundingRate'] ?? null), 'rate_pct', self::FUNDING_MIN_PCT),
            'range_24h' => $this->rangeEdge($last, $high, $low, $range),
        };

        if ($hit === null) {
            return null;
        }

        return $hit + ['volatility' => ['kind' => 'range', 'pct' => $range]];
    }

    /**
     * The top rows of each side of a scan: confirmed before unconfirmed, then strongest
     * first, then by name so equal readings keep a stable order. Only coins that qualified
     * are ever in the rows, so a side can be short or empty; it is never padded.
     *
     * @param  array<int, array{symbol: string, side: string, strength: float, group: int, tiebreak?: float}>  $rows
     * @return array{oversold: array<int, array>, overbought: array<int, array>, counts: array{oversold: int, overbought: int}}
     */
    public static function rank(array $rows, int $limit = 10): array
    {
        $sides = ['oversold' => [], 'overbought' => []];

        foreach ($rows as $row) {
            $sides[$row['side']][] = $row;
        }

        $counts = ['oversold' => count($sides['oversold']), 'overbought' => count($sides['overbought'])];

        foreach ($sides as $side => $list) {
            usort($list, fn (array $a, array $b) => [$a['group'], -$a['strength'], -($a['tiebreak'] ?? 0), $a['symbol']]
                <=> [$b['group'], -$b['strength'], -($b['tiebreak'] ?? 0), $b['symbol']]);

            $sides[$side] = array_slice($list, 0, $limit);
        }

        return $sides + ['counts' => $counts];
    }

    // ─── Candle indicators ───────────────────────────────────────────────────

    /**
     * A WaveTrend cross, on a closed candle in the last few or pending on the open one, that
     * happened beyond the first line: up from oversold, or down from overbought. The level is
     * WT2 there, where TradingView puts the dot. The newest event wins, so a recent cross that
     * the open candle is already undoing is not listed.
     *
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null
     */
    private function waveTrendCross(array $candles, string $tf, int $intervalSeconds, int $now): ?array
    {
        $read = $this->waveTrend->read($candles, $tf, $intervalSeconds, $now);

        if ($read === null) {
            return null;
        }

        $latest = $read['crosses'][0] ?? null;

        if ($read['forming_cross'] !== null) {
            $event = ['direction' => $read['forming_cross'], 'status' => 'pending', 'ago' => null, 'level' => $read['wt2']];
        } elseif ($latest !== null && $latest['ago'] < self::CROSS_WINDOW) {
            $event = ['direction' => $latest['direction'], 'status' => 'confirmed', 'ago' => $latest['ago'], 'level' => $latest['level']];
        } else {
            return null;
        }

        $side = match (true) {
            $event['direction'] === 'up' && $event['level'] <= -WaveTrendReader::OVERBOUGHT   => 'oversold',
            $event['direction'] === 'down' && $event['level'] >= WaveTrendReader::OVERBOUGHT  => 'overbought',
            default                                                                            => null,
        };

        if ($side === null) {
            return null;
        }

        return [
            'side'     => $side,
            'strength' => abs($event['level']),
            'group'    => $event['status'] === 'confirmed' ? 0 : 1,
            'tiebreak' => $event['ago'] === null ? 0.0 : -(float) $event['ago'], // fresher first
            'reading'  => $event + ['zone' => WaveTrendReader::zone($event['level']), 'wt1' => $read['wt1'], 'wt2' => $read['wt2']],
        ];
    }

    /**
     * How far WT1 is beyond the first line right now, and whether WT1 has already turned back
     * over WT2 (confirmed at the last close, or pending on the open candle).
     *
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null
     */
    private function waveTrendLevel(array $candles, string $tf, int $intervalSeconds, int $now): ?array
    {
        $read = $this->waveTrend->read($candles, $tf, $intervalSeconds, $now);

        if ($read === null) {
            return null;
        }

        $side = match (true) {
            $read['wt1'] <= -WaveTrendReader::OVERBOUGHT => 'oversold',
            $read['wt1'] >= WaveTrendReader::OVERBOUGHT  => 'overbought',
            default                                      => null,
        };

        if ($side === null) {
            return null;
        }

        // Turning means WT1 is back over WT2 for an oversold coin, under it for an overbought one:
        // confirmed at the last close, pending if only the open candle has crossed that way, or
        // fading if it had turned at the last close and the open candle is crossing back.
        $turned  = ($read['side'] === 'above') === ($side === 'oversold');
        $turning = match (true) {
            $turned && $read['forming_cross'] === null                          => 'confirmed',
            $turned                                                             => 'fading',
            $read['forming_cross'] === ($side === 'oversold' ? 'up' : 'down')   => 'pending',
            default                                                             => null,
        };

        return [
            'side'     => $side,
            'strength' => abs($read['wt1']),
            'group'    => 0,
            'tiebreak' => 0.0,
            'reading'  => ['wt1' => $read['wt1'], 'wt2' => $read['wt2'], 'zone' => $read['zone'], 'turning' => $turning],
        ];
    }

    /** @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null */
    private function rsi(array $closes): ?array
    {
        $rsi = $this->indicators->rsi($closes, 14);

        if ($rsi === null || ($rsi > self::RSI_OVERSOLD && $rsi < self::RSI_OVERBOUGHT)) {
            return null;
        }

        return [
            'side'     => $rsi <= self::RSI_OVERSOLD ? 'oversold' : 'overbought',
            'strength' => abs($rsi - 50),
            'group'    => 0,
            'tiebreak' => 0.0,
            'reading'  => ['rsi' => round($rsi, 1)],
        ];
    }

    /**
     * The MACD histogram against this coin's own usual size (the root mean square of its last
     * 200 candles): 2.0 means momentum is twice as stretched as it normally gets. Measured
     * against itself, so coins of any price and any timeframe compare.
     *
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null
     */
    private function macdStretch(array $closes): ?array
    {
        $history = array_values(array_filter(
            $this->indicators->macdHistogramSeries($closes),
            fn (?float $h) => $h !== null,
        ));

        if (count($history) < self::MIN_CANDLES) {
            return null;
        }

        $recent = array_slice($history, -self::MACD_HISTORY);
        $usual  = sqrt(array_sum(array_map(fn (float $h) => $h * $h, $recent)) / count($recent));

        if ($usual <= 0) {
            return null;
        }

        $stretch = end($history) / $usual;

        if (abs($stretch) < self::MACD_STRETCH) {
            return null;
        }

        return [
            'side'     => $stretch < 0 ? 'oversold' : 'overbought',
            'strength' => abs($stretch),
            'group'    => 0,
            'tiebreak' => 0.0,
            'reading'  => ['stretch' => round($stretch, 2)],
        ];
    }

    // ─── Ticker indicators ───────────────────────────────────────────────────

    /**
     * A signed figure as a list reading: negative is the oversold side, positive the overbought
     * one, and the bigger in size the stronger. Zero, missing or smaller than `$minPct` percent
     * is not an extreme.
     *
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null
     */
    private function signed(?float $fraction, string $field, float $minPct = 0.0): ?array
    {
        if ($fraction === null || $fraction == 0.0) {
            return null;
        }

        $pct = $fraction * 100;

        if (abs($pct) < $minPct) {
            return null;
        }

        return [
            'side'     => $pct < 0 ? 'oversold' : 'overbought',
            'strength' => abs($pct),
            'group'    => 0,
            'tiebreak' => 0.0,
            'reading'  => [$field => round($pct, 4)],
        ];
    }

    /**
     * Sitting within a tenth of the range of the 24h low or high. Many coins sit exactly on an
     * edge, so the wider the day's range, the more it says: that breaks the tie.
     *
     * @return array{side: string, strength: float, group: int, tiebreak: float, reading: array<string, mixed>}|null
     */
    private function rangeEdge(float $last, float $high, float $low, ?float $rangePct): ?array
    {
        if ($rangePct === null || $rangePct < self::RANGE_MIN_PCT) {
            return null;
        }

        $position = max(0.0, min(1.0, ($last - $low) / ($high - $low)));

        if ($position > self::RANGE_EDGE && $position < 1 - self::RANGE_EDGE) {
            return null;
        }

        $atLow = $position <= self::RANGE_EDGE;

        return [
            'side'     => $atLow ? 'oversold' : 'overbought',
            'strength' => round($atLow ? 1 - $position : $position, 4),
            'group'    => 0,
            'tiebreak' => $rangePct,
            'reading'  => ['position_pct' => round($position * 100, 1), 'range_pct' => $rangePct],
        ];
    }

    private static function fraction(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
