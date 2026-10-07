/**
 * Geometry for the small candlestick chart in Open Positions.
 *
 * Pure functions with no imports, so they are unit-tested straight from Node
 * (`npm run test:js`). The component only turns these numbers into SVG; every decision
 * about scale, placement and spacing is made here so it can be checked without a browser.
 *
 * The one judgment in here is the scale: the chart is small, so it is fitted to the
 * recent candles, and a line of the trader's own (entry, break-even, stop) is only
 * allowed to stretch it when it is close to the action. A line far away (a liquidation
 * price 8% out, say) would flatten the candles into a stripe, so it is left off-scale and
 * pinned to the edge with an arrow instead.
 */

export interface Candle {
    /** Open time, unix seconds. */
    time: number;
    open: number;
    high: number;
    low: number;
    close: number;
}

/** Seconds in one candle of each timeframe the chart offers. */
export const TIMEFRAME_SECONDS: Record<string, number> = {
    '15M': 900,
    '1H': 3600,
    '4H': 14400,
};

export type LineKind =
    | 'long_entry'
    | 'short_entry'
    | 'break_even'
    | 'liquidation'
    | 'stop'
    | 'target'
    | 'price';

/** A horizontal line of the trader's own, or the live price. */
export interface ChartLine {
    kind: LineKind;
    price: number;
    /** Short text for the gutter, e.g. "L 307.00". */
    label: string;
    /** Longer explanation for a hover. */
    title: string;
}

export interface Scale {
    min: number;
    max: number;
}

/**
 * Folds the live price into the series so the last candle moves with the 5-second
 * poll instead of waiting for the next candle fetch. The forming candle takes it as its
 * close (widening its high or low if needed); if that candle's period has already ended
 * and the next one has not been fetched yet, a new candle opens at the live price.
 * Returns a new array; the input is never changed.
 */
export function withLivePrice(
    candles: Candle[],
    price: number | null,
    intervalSeconds: number,
    nowSeconds: number,
): Candle[] {
    if (price === null || !(price > 0) || candles.length === 0) {
        return candles;
    }

    const last = candles[candles.length - 1];

    if (last.time + intervalSeconds <= nowSeconds) {
        return [
            ...candles,
            {
                time:
                    Math.floor(nowSeconds / intervalSeconds) * intervalSeconds,
                open: last.close,
                high: Math.max(last.close, price),
                low: Math.min(last.close, price),
                close: price,
            },
        ];
    }

    return [
        ...candles.slice(0, -1),
        {
            ...last,
            close: price,
            high: Math.max(last.high, price),
            low: Math.min(last.low, price),
        },
    ];
}

/**
 * The price range to draw: the candles' own range, stretched to take in any line within
 * `reach` candle-ranges of it, then padded so nothing touches the edge. Lines further out
 * are left off-scale (see `placement`). Null when there are no candles.
 */
export function fitScale(
    candles: Candle[],
    linePrices: number[],
    reach = 0.75,
    pad = 0.06,
): Scale | null {
    if (candles.length === 0) {
        return null;
    }

    const low = Math.min(...candles.map((c) => c.low));
    const high = Math.max(...candles.map((c) => c.high));
    // A flat series still gets a window (0.2% of price) rather than a zero-height one.
    const range = Math.max(high - low, ((high + low) / 2) * 0.002, 1e-12);

    let lowest = low;
    let highest = high;

    for (const price of linePrices) {
        if (price >= low - reach * range && price <= high + reach * range) {
            lowest = Math.min(lowest, price);
            highest = Math.max(highest, price);
        }
    }

    const span = Math.max(highest - lowest, range);

    return { min: lowest - pad * span, max: highest + pad * span };
}

/** Vertical position of a price: `top` at the scale's max, `top + height` at its min. */
export function yFor(
    price: number,
    scale: Scale,
    top: number,
    height: number,
): number {
    const span = scale.max - scale.min;

    return span > 0
        ? top + ((scale.max - price) / span) * height
        : top + height / 2;
}

export type Placement = 'on' | 'above' | 'below';

/** Whether a price is inside the drawn range, or off it above or below. */
export function placement(price: number, scale: Scale): Placement {
    return price > scale.max ? 'above' : price < scale.min ? 'below' : 'on';
}

/**
 * Vertical positions for gutter labels so none overlap. Each label wants to sit level
 * with its line; walking from the top, one that would be closer than `gap` to the label
 * above is pushed down, and if the stack then runs past `bottom` it is pulled back up.
 * The result is in the same order as `wanted`.
 */
export function spreadLabels(
    wanted: number[],
    gap: number,
    top: number,
    bottom: number,
): number[] {
    const order = wanted
        .map((_, index) => index)
        .sort((a, b) => wanted[a] - wanted[b]);
    const placed = new Array<number>(wanted.length).fill(0);

    let previous = -Infinity;

    for (const index of order) {
        previous = Math.max(wanted[index], top, previous + gap);
        placed[index] = previous;
    }

    let next = Infinity;

    for (let k = order.length - 1; k >= 0; k--) {
        next = Math.min(placed[order[k]], bottom, next - gap);
        placed[order[k]] = next;
    }

    return placed;
}

/** How wide each candle's slot is and how wide its body is drawn, for `count` candles across `width`. */
export function candleLayout(
    count: number,
    width: number,
): { step: number; body: number } {
    const step = count > 0 ? width / count : 0;

    return { step, body: Math.max(1, Math.min(9, step * 0.62)) };
}
