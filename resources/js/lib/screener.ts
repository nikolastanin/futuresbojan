/**
 * Words and figures for the market scan lists. Pure functions with no imports, so they are
 * unit-tested straight from Node (`npm run test:js`): the component only lays them out, and
 * every sentence a list says about a coin is decided here.
 */

export type ScreenerSide = 'oversold' | 'overbought';

export type ScreenerIndicator =
    | 'wt_cross'
    | 'wt_level'
    | 'rsi'
    | 'macd'
    | 'move_24h'
    | 'move_7d'
    | 'move_30d'
    | 'funding'
    | 'range_24h';

export type ScreenerTimeframe = '15M' | '1H' | '4H';

export const SCREENER_TIMEFRAMES: ScreenerTimeframe[] = ['15M', '1H', '4H'];

/**
 * What the dropdown offers. The labels and the candle/ticker split mirror the server's list
 * (ExtremesScreener::INDICATORS); the server rejects a key it does not know, and answers
 * with its own label, so a stale option can only fail loudly.
 */
export const SCREENER_INDICATORS: {
    key: ScreenerIndicator;
    label: string;
    /** Read from candles of the chosen timeframe; the rest come from the exchange's ticker. */
    candles: boolean;
}[] = [
    {
        key: 'wt_cross',
        label: 'WaveTrend — fresh cross at an extreme',
        candles: true,
    },
    {
        key: 'wt_level',
        label: 'WaveTrend — most extreme level',
        candles: true,
    },
    { key: 'rsi', label: 'RSI (14)', candles: true },
    { key: 'macd', label: 'MACD — momentum stretch', candles: true },
    { key: 'move_24h', label: '24h move', candles: false },
    { key: 'move_7d', label: '7-day move', candles: false },
    { key: 'move_30d', label: '30-day move', candles: false },
    { key: 'funding', label: 'Funding rate', candles: false },
    { key: 'range_24h', label: 'Position in the 24h range', candles: false },
];

/** What a coin's reading holds; which fields are set depends on the indicator. */
export interface ScreenerReading {
    direction?: 'up' | 'down';
    status?: 'confirmed' | 'pending';
    ago?: number | null;
    level?: number;
    wt1?: number;
    turning?: 'confirmed' | 'pending' | 'fading' | null;
    rsi?: number;
    stretch?: number;
    pct?: number;
    rate_pct?: number;
    position_pct?: number;
}

export interface ScreenerUniverse {
    basis: 'most_traded' | 'min_turnover';
    size: number;
    scanned: number;
    /** Coins the scan wanted but got no candles for. */
    missing: number;
    min_turnover: number | null;
}

const signOf = (n: number) => (n > 0 ? '+' : '');

/** "+4.8" / "-14.2": always signed, so a loss and a gain cannot be misread. */
export const fmtSigned = (n: number, digits = 1): string =>
    `${signOf(n)}${n.toFixed(digits)}`;

export function fmtPrice(n: number): string {
    return n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });
}

/** A day's turnover in dollars, short: "$2.5B", "$110M", "$17.2M", "$850K". */
export function fmtTurnover(n: number): string {
    if (n >= 1e9) {
        return `$${(n / 1e9).toFixed(1)}B`;
    }

    if (n >= 1e8) {
        return `$${Math.round(n / 1e6)}M`;
    }

    if (n >= 1e6) {
        return `$${(n / 1e6).toFixed(1)}M`;
    }

    return `$${Math.round(n / 1e3)}K`;
}

/** 0 is the last closed candle, 1 the one before it… */
export function candlesAgo(ago: number): string {
    return ago === 0
        ? 'on the last closed candle'
        : ago === 1
          ? '1 candle ago'
          : `${ago} candles ago`;
}

/** The same, short enough for a list row. */
const shortAgo = (ago: number): string =>
    ago === 0 ? 'last candle' : candlesAgo(ago);

const MOVE_WINDOW: Record<string, string> = {
    move_24h: '24h',
    move_7d: '7 days',
    move_30d: '30 days',
};

/** The sentence a list shows beside a coin, and whether it rests on something not yet confirmed. */
export function describeReading(
    indicator: string,
    side: ScreenerSide,
    r: ScreenerReading,
): { text: string; pending: boolean } {
    switch (indicator) {
        case 'wt_cross': {
            const arrow = r.direction === 'up' ? '▲' : '▼';
            const level = (r.level ?? 0).toFixed(1);

            return r.status === 'pending'
                ? {
                      text: `${arrow} ${r.direction}-cross forming at ${level} — not confirmed until the candle closes`,
                      pending: true,
                  }
                : {
                      text: `${arrow} ${r.direction}-cross at ${level} · ${shortAgo(r.ago ?? 0)}`,
                      pending: false,
                  };
        }

        case 'wt_level': {
            const [back, onward] =
                side === 'oversold'
                    ? ['up', 'still falling']
                    : ['down', 'still rising'];
            const flipped = back === 'up' ? 'down' : 'up';
            const turning =
                r.turning === 'confirmed'
                    ? `turning ${back}`
                    : r.turning === 'pending'
                      ? `turning ${back} (not confirmed)`
                      : r.turning === 'fading'
                        ? `was turning ${back}, flipping back ${flipped}`
                        : onward;

            return {
                text: `WT1 ${(r.wt1 ?? 0).toFixed(1)} · ${turning}`,
                pending: r.turning === 'pending' || r.turning === 'fading',
            };
        }

        case 'rsi':
            return { text: `RSI ${(r.rsi ?? 0).toFixed(1)}`, pending: false };

        case 'macd':
            return {
                text: `MACD ${fmtSigned(r.stretch ?? 0)}× its usual size`,
                pending: false,
            };

        case 'funding': {
            const rate = r.rate_pct ?? 0;
            const digits = Math.abs(rate) < 0.1 ? 4 : 3;

            return {
                text: `Funding ${fmtSigned(rate, digits)}% · ${rate < 0 ? 'shorts pay longs' : 'longs pay shorts'}`,
                pending: false,
            };
        }

        case 'range_24h':
            return {
                text: `${Math.round(r.position_pct ?? 0)}% of its 24h range`,
                pending: false,
            };

        default:
            return {
                text: `${fmtSigned(r.pct ?? 0)}% in ${MOVE_WINDOW[indicator] ?? 'the period'}`,
                pending: false,
            };
    }
}

/** How jumpy the coin is, the way the scan measured it. */
export function volatilityText(v: {
    kind: 'atr' | 'range';
    pct: number | null;
}): string {
    if (v.pct === null) {
        return '';
    }

    return v.kind === 'atr'
        ? `ATR ${v.pct.toFixed(1)}%`
        : `24h range ${v.pct.toFixed(1)}%`;
}

/** What the scan covered, in a sentence. */
export function describeUniverse(u: ScreenerUniverse): string {
    if (u.basis === 'min_turnover') {
        return `${u.size} crypto coins trading at least ${fmtTurnover(u.min_turnover ?? 0)} a day`;
    }

    const missing =
        u.missing > 0
            ? ` · ${u.missing} ${u.missing === 1 ? 'coin' : 'coins'} had no candles to read`
            : '';

    return `The ${u.scanned} most traded coins${missing}`;
}

/** How long ago a scan was made. */
export function scanAge(generatedAt: number, nowSeconds: number): string {
    const seconds = Math.max(0, nowSeconds - generatedAt);

    if (seconds < 5) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds} s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    return `${Math.floor(seconds / 3600)} h ago`;
}
