import { useCallback, useState } from 'react';
import type {
    ScreenerIndicator,
    ScreenerReading,
    ScreenerTimeframe,
    ScreenerUniverse,
} from '@/lib/screener';
import { screener as screenerRoute } from '@/routes/futures';

// The market scan: the most oversold and most overbought coins on one indicator. On click
// only — nothing is asked of the server until Scan is pressed, and the answer stays until
// the next press, so the lists don't shuffle under the cursor.

export interface ScreenerRow {
    symbol: string;
    price: number;
    /** Percent over the last 24 hours; null if the exchange sent none. */
    change_24h: number | null;
    /** Turnover over the last 24 hours, in USDT. */
    turnover: number;
    /** 1 = the most traded crypto coin. */
    rank: number;
    volatility: { kind: 'atr' | 'range'; pct: number | null };
    reading: ScreenerReading;
}

/** What GET /futures/screener returns. */
export interface ScreenerData {
    indicator: ScreenerIndicator;
    /** Null for the indicators that come from the exchange's ticker. */
    tf: ScreenerTimeframe | null;
    label: string;
    kind: 'candles' | 'ticker';
    /** What gets a coin onto a list, in plain words. */
    rule: string;
    titles: { oversold: string; overbought: string };
    universe: ScreenerUniverse;
    /** Unix seconds. */
    generated_at: number;
    oversold: ScreenerRow[];
    overbought: ScreenerRow[];
    /** How many coins qualified on each side, before the top ten were cut. */
    counts: { oversold: number; overbought: number };
}

export type ScreenerState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'done'; data: ScreenerData };

export function useScreener() {
    const [state, setState] = useState<ScreenerState>({ status: 'idle' });

    const scan = useCallback(
        async (indicator: ScreenerIndicator, tf: ScreenerTimeframe) => {
            setState({ status: 'loading' });

            try {
                const res = await fetch(
                    `${screenerRoute.url()}?indicator=${indicator}&tf=${tf}`,
                    { headers: { Accept: 'application/json' } },
                );
                const json = await res.json().catch(() => null);

                if (res.ok && json?.success) {
                    setState({ status: 'done', data: json.data });
                } else {
                    setState({
                        status: 'error',
                        message:
                            json?.message ??
                            (res.status === 429
                                ? 'Too many scans — wait a minute.'
                                : 'The scan failed.'),
                    });
                }
            } catch {
                setState({ status: 'error', message: 'Network error.' });
            }
        },
        [],
    );

    return { state, scan };
}
