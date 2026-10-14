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

// ─── Your trades ─────────────────────────────────────────────────────────────

/** MEXC's side numbers: 1 opens a long, 2 closes a short, 3 opens a short, 4 closes a long. */
export type FillSide = 1 | 2 | 3 | 4;

/** One fill (a piece of a completed order) as GET /futures/market-chart-trades sends it. */
export interface ChartFill {
    /** When it filled, unix seconds. */
    time: number;
    side: FillSide;
    price: number;
    /** Size in contracts. */
    vol: number;
    /** Size in USDT; null when the coin's contract size could not be had. */
    notional: number | null;
    /** MEXC's profit on the fill, nothing on one that opens a position. */
    profit: number;
    fee: number;
    order_id: string;
}

/** The fills of one side in one candle, joined into the one arrow the chart draws for them. */
export interface TradeMark {
    /** Open time of the candle it sits on. */
    time: number;
    /** That candle's place among the chart's candles, oldest first. */
    index: number;
    side: FillSide;
    /** Volume-weighted price of its fills. */
    price: number;
    vol: number;
    /** Null as soon as one of its fills has no size in USDT. */
    notional: number | null;
    profit: number;
    /** Completed orders it stands for: one order can fill in several pieces. */
    orders: number;
    /** When its first fill happened, unix seconds. */
    first: number;
}

/** What each side number does: opens or closes which leg, and whether that is a buy or a sell. */
const FILL_SIDES: Record<
    FillSide,
    { open: boolean; leg: 'long' | 'short'; buy: boolean }
> = {
    1: { open: true, leg: 'long', buy: true },
    2: { open: false, leg: 'short', buy: true },
    3: { open: true, leg: 'short', buy: false },
    4: { open: false, leg: 'long', buy: false },
};

/** Buying a coin is opening a long or closing a short; selling is opening a short or closing a long. */
export const isBuy = (side: FillSide): boolean => FILL_SIDES[side].buy;

/** Green for a buy, red for a sell, as the candles. */
export const markColor = (side: FillSide): string =>
    isBuy(side) ? GREEN : RED;

/**
 * The candle a moment falls in (its place in `times`): the last one that opened at or before it,
 * provided the moment is still inside that candle. Before the first candle, past the newest one, or
 * in a gap between two candles there is none, so a fill is never drawn on a candle it did not
 * happen in.
 */
function barIndexFor(
    times: number[],
    seconds: number,
    moment: number,
): number | null {
    let low = 0;
    let high = times.length - 1;
    let found = -1;

    while (low <= high) {
        const mid = (low + high) >> 1;

        if (times[mid] <= moment) {
            found = mid;
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }

    return found !== -1 && moment < times[found] + seconds ? found : null;
}

/**
 * Your fills as the arrows to draw: each fill goes to the candle it happened in, and the fills of
 * one side in one candle become one arrow (a market order often fills in several pieces, and a
 * scalper adds in steps), at their volume-weighted price. `times` are the candles' open times,
 * oldest first, and `seconds` is one candle's length. Oldest arrow first.
 */
export function tradeMarks(
    fills: ChartFill[],
    times: number[],
    seconds: number,
): TradeMark[] {
    const groups = new Map<string, { index: number; fills: ChartFill[] }>();

    for (const fill of fills) {
        const index = barIndexFor(times, seconds, fill.time);

        if (index === null) {
            continue;
        }

        const key = `${index}:${fill.side}`;
        const group = groups.get(key) ?? { index, fills: [] };

        group.fills.push(fill);
        groups.set(key, group);
    }

    return [...groups.values()]
        .map(({ index, fills: group }) => {
            const vol = group.reduce((sum, f) => sum + f.vol, 0);
            const worth = group.reduce((sum, f) => sum + f.price * f.vol, 0);
            const orders = new Set(
                group.map((f) => f.order_id).filter(Boolean),
            );

            return {
                time: times[index],
                index,
                side: group[0].side,
                price:
                    vol > 0
                        ? worth / vol
                        : group.reduce((sum, f) => sum + f.price, 0) /
                          group.length,
                vol,
                notional: group.every((f) => f.notional !== null)
                    ? group.reduce((sum, f) => sum + (f.notional ?? 0), 0)
                    : null,
                profit: group.reduce((sum, f) => sum + f.profit, 0),
                orders: Math.max(1, orders.size),
                first: Math.min(...group.map((f) => f.time)),
            };
        })
        .sort(
            (a, b) => a.time - b.time || a.first - b.first || a.side - b.side,
        );
}

/** "$520": whole dollars from a hundred up, cents below it. */
const usd = (n: number): string =>
    `$${(n >= 100 ? Math.round(n) : Number(n.toFixed(2))).toLocaleString('en-US')}`;

/** "+$1.20" or "-$0.40". */
const usdSigned = (n: number): string =>
    `${n < 0 ? '-' : '+'}$${Math.abs(n).toFixed(2)}`;

/** The profit of a closing arrow, when MEXC reported one worth a cent. */
function markProfit(mark: TradeMark): string | null {
    return !FILL_SIDES[mark.side].open && Math.abs(mark.profit) >= 0.005
        ? usdSigned(mark.profit)
        : null;
}

/** The tag beside an arrow: what it did to which leg, how big, and on a close what it made: "Open L $520", "Close S $100 +$1.20". */
export function markText(mark: TradeMark): string {
    const { open, leg } = FILL_SIDES[mark.side];

    return [
        `${open ? 'Open' : 'Close'} ${leg === 'long' ? 'L' : 'S'}`,
        mark.notional === null ? null : usd(mark.notional),
        markProfit(mark),
    ]
        .filter(Boolean)
        .join(' ');
}

/** About how wide a tag is on the chart, in pixels, at its 11px type. */
const tagWidth = (mark: TradeMark): number => markText(mark).length * 6 + 8;

/**
 * Which arrows get their tag written. Tags on top of each other cannot be read, so, from the newest
 * arrow back, a tag is written only if it ends before the next written one on the same side of the
 * price begins (buys write theirs under the arrow and sells over it, so the two kinds do not
 * compete). A tag that would run off either end of the chart is cut off by the price axis, so it
 * is left out too. `pxPerBar` is the chart's zoom: zoomed in there is room for every tag, zoomed
 * out the crowded ones go quiet, and the legend still lists them when you hover the candle.
 * `view` is where the first candle sits and how wide the chart is, in pixels, when that is known.
 * One flag per mark, in the order given.
 */
export function labelledMarks(
    marks: TradeMark[],
    pxPerBar: number,
    view?: { origin: number; width: number },
): boolean[] {
    const labelled = marks.map(() => false);
    const spacing = Math.max(pxPerBar, 1);
    // Where the nearest tag already written to the right begins, for each side of the price.
    const begins = { buy: Infinity, sell: Infinity };

    for (let i = marks.length - 1; i >= 0; i--) {
        const side = isBuy(marks[i].side) ? 'buy' : 'sell';
        const half = tagWidth(marks[i]) / 2;
        const centre = (view?.origin ?? 0) + marks[i].index * spacing;

        if (view && (centre - half < 0 || centre + half > view.width)) {
            continue;
        }

        if (centre + half <= begins[side]) {
            labelled[i] = true;
            begins[side] = centre - half;
        }
    }

    return labelled;
}

/** The same in words for the legend: "Close long $200 at 275.10 · +$1.20 · 2 orders · 14:12". */
export function markDetail(
    mark: TradeMark,
    precision: number,
    timeZone?: string,
): string {
    const { open, leg } = FILL_SIDES[mark.side];
    const what = [
        open ? 'Open' : 'Close',
        leg,
        mark.notional === null ? null : usd(mark.notional),
        `at ${fmtPrice(mark.price, precision)}`,
    ]
        .filter(Boolean)
        .join(' ');

    return [
        what,
        markProfit(mark),
        mark.orders > 1 ? `${mark.orders} orders` : null,
        formatTick(mark.first, 'time', timeZone),
    ]
        .filter(Boolean)
        .join(' · ');
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
    /** Your arrows on this candle, in words. */
    trades: { text: string; buy: boolean }[];
}

/**
 * The line of figures at the top of the chart for the candle at `index`: its time, OHLC and move,
 * each SuperTrend there, and what you did on it (`marks` are the arrows on the chart).
 */
export function buildLegend(
    data: ChartPayload,
    index: number,
    precision: number,
    timeZone?: string,
    marks: TradeMark[] = [],
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
        trades: marks
            .filter((mark) => mark.time === candle.time)
            .map((mark) => ({
                text: markDetail(mark, precision, timeZone),
                buy: isBuy(mark.side),
            })),
    };
}
