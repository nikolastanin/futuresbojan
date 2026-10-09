/**
 * What the Market chart decides by arithmetic or wording rather than by drawing: price
 * precision, how each level is styled, turning the server's indicator series into the lines
 * the chart draws, the WaveTrend cross dots, and the sentences of the legend and the time axis.
 * Pure functions with no imports, so they are unit-tested straight from Node
 * (`npm run test:js`); the component only hands them to the chart library.
 */

export type ChartTimeframe = '5M' | '15M' | '1H' | '4H' | '1D';

export const CHART_TIMEFRAMES: ChartTimeframe[] = [
    '5M',
    '15M',
    '1H',
    '4H',
    '1D',
];

export interface ChartCandle {
    /** Open time, unix seconds. */
    time: number;
    open: number;
    high: number;
    low: number;
    close: number;
}

export interface KeyLevel {
    key: string;
    label: string;
    name: string;
    kind: 'high' | 'low' | 'mid';
    price: number;
}

/** One value per candle, in step with them: null until the indicator has enough candles. */
export interface SuperTrendData {
    line: (number | null)[];
    /** 1 while the trend is up (the line is under price), -1 while it is down (over it). */
    trend: (1 | -1 | null)[];
}

export interface WaveTrendData {
    wt1: (number | null)[];
    wt2: (number | null)[];
}

/** What GET /futures/market-chart returns. */
export interface ChartPayload {
    symbol: string;
    tf: ChartTimeframe;
    seconds: number;
    /** False for a refresh: only the candles from `since` on, and their indicator values. */
    full: boolean;
    candles: ChartCandle[];
    supertrend: Record<string, SuperTrendData>;
    wavetrend: WaveTrendData;
    levels: KeyLevel[];
    price: number;
    generated_at: number;
}

/** A point on a line series; a point with no value is a gap. */
export interface LinePoint {
    time: number;
    value?: number;
    /** Colour of the stretch that ends at this point, in place of the series' own. */
    color?: string;
}

/**
 * A colour that draws nothing. The chart library joins the points either side of a gap with a
 * straight line, and draws each stretch in the colour of the point it leaves, so the last point
 * before a gap gets this colour and the join is not drawn: that is what makes a gap a gap.
 */
export const HIDDEN = 'rgba(0, 0, 0, 0)';

/** The SuperTrend settings on the trader's chart: name (as the server calls it), label, ATR period, multiplier. */
export const SUPERTREND_SETTINGS = [
    { name: '12_2.5', label: 'SuperTrend 12 2.5' },
    { name: '10_3', label: 'SuperTrend 10 3' },
] as const;

// ─── Price ──────────────────────────────────────────────────────────────────

/** Decimals for a price: two for BTC, more as the price gets smaller, so a coin at 0.00001234 is not shown as 0.00. */
export function pricePrecision(price: number): {
    precision: number;
    minMove: number;
} {
    const precision =
        price >= 100
            ? 2
            : price >= 1
              ? 3
              : price >= 0.1
                ? 4
                : price >= 0.01
                  ? 5
                  : price >= 0.001
                    ? 6
                    : 8;

    return { precision, minMove: 10 ** -precision };
}

export const fmtPrice = (n: number, precision: number): string =>
    n.toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision,
    });

const signOf = (n: number) => (n > 0 ? '+' : '');

// ─── Levels ─────────────────────────────────────────────────────────────────

export interface LevelStyle {
    color: string;
    /** 1 to 4, like the chart library's own line widths. */
    width: 1 | 2 | 3 | 4;
    dashed: boolean;
}

const RED = '#ef4444';
const GREEN = '#22c55e';

/** Colours and weights as on the trader's TradingView chart: highs red, lows green, the middle of each range its own colour; longer periods heavier. */
export const LEVEL_STYLE: Record<string, LevelStyle> = {
    PYH: { color: RED, width: 3, dashed: false },
    PMH: { color: RED, width: 2, dashed: false },
    PWH: { color: RED, width: 2, dashed: false },
    PDH: { color: RED, width: 1, dashed: true },
    DP: { color: '#3b82f6', width: 2, dashed: false },
    WP: { color: '#f59e0b', width: 2, dashed: false },
    MP: { color: '#8b5cf6', width: 2, dashed: false },
    PDL: { color: GREEN, width: 1, dashed: true },
    PWL: { color: GREEN, width: 2, dashed: false },
    PML: { color: GREEN, width: 2, dashed: false },
    PYL: { color: GREEN, width: 3, dashed: false },
};

export function levelStyle(level: KeyLevel): LevelStyle {
    return (
        LEVEL_STYLE[level.key] ?? {
            color:
                level.kind === 'high'
                    ? RED
                    : level.kind === 'low'
                      ? GREEN
                      : '#a1a1aa',
            width: 1,
            dashed: true,
        }
    );
}

/** The kinds of line the positions overlay draws (the mini chart's), and how each looks. */
export const POSITION_LINE_STYLE: Record<
    string,
    { color: string; dashed: boolean; width: 1 | 2 }
> = {
    long_entry: { color: '#10b981', dashed: true, width: 1 },
    short_entry: { color: '#ef4444', dashed: true, width: 1 },
    break_even: { color: '#f59e0b', dashed: true, width: 1 },
    liquidation: { color: '#ef4444', dashed: true, width: 2 },
    stop: { color: '#fb923c', dashed: true, width: 1 },
    target: { color: '#38bdf8', dashed: true, width: 1 },
    watch: { color: '#a78bfa', dashed: true, width: 1 },
};

/**
 * The text beside a line on the chart. The axis tag already says the price, so the mini chart's
 * label ("L liq 190.91") loses its price here, and a bare "L" or "S" says what it is.
 */
export function positionLineTitle(label: string, kind: string): string {
    const name = label.replace(/\s+[\d,.]+$/, '');

    return kind === 'long_entry' || kind === 'short_entry'
        ? `${name} entry`
        : name;
}

// ─── Indicator lines ────────────────────────────────────────────────────────

/**
 * SuperTrend as two lines, so the line breaks where the trend flips (as on TradingView) instead
 * of joining the lower band to the upper one: one line for the uptrend (under price), one for
 * the downtrend (over it), each with a gap where the other has its turn. The chart library
 * would draw a straight line across such a gap, so the last point before every gap is
 * coloured to hide it.
 */
export function superTrendPoints(
    times: number[],
    data: SuperTrendData,
): { up: LinePoint[]; down: LinePoint[] } {
    const defined = (i: number, want: 1 | -1) =>
        data.trend[i] === want && (data.line[i] ?? null) !== null;

    const side = (want: 1 | -1): LinePoint[] =>
        times.map((time, i) => {
            if (!defined(i, want)) {
                return { time };
            }

            const value = data.line[i] as number;
            const beforeGap = i + 1 < times.length && !defined(i + 1, want);

            return beforeGap ? { time, value, color: HIDDEN } : { time, value };
        });

    return { up: side(1), down: side(-1) };
}

/** One value per candle as a line; a missing value is a gap. */
export function linePoints(
    times: number[],
    values: (number | null)[],
): LinePoint[] {
    return times.map((time, i) => {
        const value = values[i] ?? null;

        return value === null ? { time } : { time, value };
    });
}

export interface WaveTrendCross {
    time: number;
    direction: 'up' | 'down';
    /** WT2 on that candle, where the dot sits. */
    level: number;
}

/**
 * The candles on which WT1 ended up on the other side of WT2 than the candle before: the dots on
 * the TradingView pane. WT1 strictly over WT2 is "above", anything else "below", as on the
 * server, so the two always agree.
 */
export function waveTrendCrosses(
    times: number[],
    wt1: (number | null)[],
    wt2: (number | null)[],
): WaveTrendCross[] {
    const crosses: WaveTrendCross[] = [];

    for (let i = 1; i < times.length; i++) {
        const [a, b, pa, pb] = [wt1[i], wt2[i], wt1[i - 1], wt2[i - 1]];

        if (a == null || b == null || pa == null || pb == null) {
            continue;
        }

        const now = a > b;
        const before = pa > pb;

        if (now !== before) {
            crosses.push({
                time: times[i],
                direction: now ? 'up' : 'down',
                level: b,
            });
        }
    }

    return crosses;
}

// ─── Words ──────────────────────────────────────────────────────────────────

export interface CandleReadout {
    open: string;
    high: string;
    low: string;
    close: string;
    /** "+114.12 (+0.14%)": the candle's own move, close against open. */
    change: string;
    up: boolean;
}

export function candleReadout(
    c: ChartCandle,
    precision: number,
): CandleReadout {
    const move = c.close - c.open;
    const pct = c.open !== 0 ? (move / c.open) * 100 : 0;

    return {
        open: fmtPrice(c.open, precision),
        high: fmtPrice(c.high, precision),
        low: fmtPrice(c.low, precision),
        close: fmtPrice(c.close, precision),
        change: `${signOf(move)}${fmtPrice(move, precision)} (${signOf(pct)}${pct.toFixed(2)}%)`,
        up: move >= 0,
    };
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** The parts of a unix time on the clock in `timeZone` (the browser's own when none is given). */
function clockParts(unix: number, timeZone?: string) {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(new Date(unix * 1000));
    const get = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);

    return {
        year: get('year'),
        month: get('month'),
        day: get('day'),
        hour: get('hour'),
        minute: get('minute'),
    };
}

const two = (n: number) => String(n).padStart(2, '0');

/** "10 Oct '26 06:00", as the crosshair shows it on TradingView. */
export function formatTime(unix: number, timeZone?: string): string {
    const t = clockParts(unix, timeZone);

    return `${t.day} ${MONTHS[t.month - 1]} '${two(t.year % 100)} ${two(t.hour)}:${two(t.minute)}`;
}

/**
 * A label on the time axis. The library says which kind of tick it is drawing (the first of a
 * year, of a month, of a day, or a time within a day) and expects a label for it.
 */
export function formatTick(
    unix: number,
    kind: 'year' | 'month' | 'day' | 'time',
    timeZone?: string,
): string {
    const t = clockParts(unix, timeZone);

    switch (kind) {
        case 'year':
            return String(t.year);
        case 'month':
            return MONTHS[t.month - 1];
        case 'day':
            return String(t.day);
        default:
            return `${two(t.hour)}:${two(t.minute)}`;
    }
}

// ─── Keeping the data current ────────────────────────────────────────────────

/**
 * Folds a refresh into the data already on the page. The refresh starts at the newest candle
 * the page had (still forming then), so that candle is replaced and any new ones are added;
 * the indicator values are cut and joined at the same place, and the levels and the price are
 * the refresh's own.
 */
export function mergeTail(
    base: ChartPayload,
    tail: ChartPayload,
): ChartPayload {
    if (tail.candles.length === 0) {
        return base;
    }

    const firstTime = tail.candles[0].time;
    const found = base.candles.findIndex((c) => c.time >= firstTime);
    const start = found === -1 ? base.candles.length : found;
    const join = <T>(head: T[], more: T[]): T[] => [
        ...head.slice(0, start),
        ...more,
    ];

    return {
        ...tail,
        full: true,
        candles: join(base.candles, tail.candles),
        supertrend: Object.fromEntries(
            Object.entries(tail.supertrend).map(([name, t]) => [
                name,
                {
                    line: join(base.supertrend[name]?.line ?? [], t.line),
                    trend: join(base.supertrend[name]?.trend ?? [], t.trend),
                },
            ]),
        ),
        wavetrend: {
            wt1: join(base.wavetrend.wt1, tail.wavetrend.wt1),
            wt2: join(base.wavetrend.wt2, tail.wavetrend.wt2),
        },
    };
}

export interface Legend {
    time: string;
    readout: CandleReadout;
    supertrend: {
        name: string;
        label: string;
        /** Empty before the indicator has enough candles. */
        value: string;
        trend: 1 | -1 | null;
    }[];
}

/** The line of figures at the top of the chart for the candle at `index`: its time, OHLC and move, and each SuperTrend there. */
export function buildLegend(
    data: ChartPayload,
    index: number,
    precision: number,
    timeZone?: string,
): Legend | null {
    const candle = data.candles[index];

    if (!candle) {
        return null;
    }

    return {
        time: formatTime(candle.time, timeZone),
        readout: candleReadout(candle, precision),
        supertrend: SUPERTREND_SETTINGS.map(({ name, label }) => {
            const line = data.supertrend[name]?.line[index] ?? null;

            return {
                name,
                label,
                value: line === null ? '' : fmtPrice(line, precision),
                trend: data.supertrend[name]?.trend[index] ?? null,
            };
        }),
    };
}
