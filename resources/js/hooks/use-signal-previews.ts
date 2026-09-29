import { useEffect, useState } from 'react';
import { signalPreview as signalPreviewRoute } from '@/routes/futures';

// Reuses the bot's own SignalEngine::score() so a manual trade can be sanity-checked
// against the exact same confidence/reasoning the automated bot uses. This calls a
// live kline-fetch + indicator-calc endpoint (not a cheap ticker lookup), so it's
// fetched far less often than price.

export interface PriceLevels {
    pivot: number;
    r1: number;
    r2: number;
    s1: number;
    s2: number;
    prior_day_high: number;
    prior_day_low: number;
    week_high: number;
    week_low: number;
    ema10: number | null;
    ema20: number | null;
}

export interface SignalPreview {
    direction: 'LONG' | 'SHORT' | null;
    confidence: number;
    reasons: string[];
    current_price: number;
    /** 1H EMA50/200 read: 'up' | 'down' | 'sideways' | 'unknown'. */
    trend: string;
    /** 5M candle streak (2+ same-direction closes): 'up' | 'down' | 'neutral'. */
    momentum: string;
    /** 15M swing structure: 'bullish' | 'bearish' | null. */
    structure: 'bullish' | 'bearish' | null;
    /** 1H ATR as a % of price. */
    volatility_pct: number | null;
    change_24h_pct: number | null;
    high_24h: number | null;
    low_24h: number | null;
    /** Pivots/EMA10/EMA20/week range from daily candles, null if too little history. */
    levels: PriceLevels | null;
}

export type SignalPreviewMap = Record<
    string,
    SignalPreview | 'loading' | 'error' | undefined
>;

/** Friendly label + color for SignalPreview.trend (1H EMA50/200 read). */
export function trendLabel(trend: string): { label: string; color: string } {
    switch (trend) {
        case 'up':
            return { label: 'Up', color: 'text-emerald-500' };
        case 'down':
            return { label: 'Down', color: 'text-red-500' };
        default:
            return { label: 'Flat', color: 'text-muted-foreground' };
    }
}

/** Friendly label + color for SignalPreview.momentum (5M candle streak). */
export function momentumLabel(momentum: string): {
    label: string;
    color: string;
} {
    switch (momentum) {
        case 'up':
            return { label: 'Bullish', color: 'text-emerald-500' };
        case 'down':
            return { label: 'Bearish', color: 'text-red-500' };
        default:
            return { label: 'Neutral', color: 'text-muted-foreground' };
    }
}

/** Friendly label + color for SignalPreview.structure (15M swing structure), or null if unclear. */
export function structureLabel(
    structure: 'bullish' | 'bearish' | null,
): { label: string; color: string } | null {
    if (structure === 'bullish') {
        return { label: 'Higher highs/lows', color: 'text-emerald-500' };
    }

    if (structure === 'bearish') {
        return { label: 'Lower highs/lows', color: 'text-red-500' };
    }

    return null;
}

const SIGNAL_POLL_INTERVAL = 60_000;

export function useSignalPreviews(symbols: string[]): SignalPreviewMap {
    const [previews, setPreviews] = useState<SignalPreviewMap>({});
    const symbolsKey = symbols.slice().sort().join(',');

    useEffect(() => {
        if (!symbols.length) {
            return;
        }

        const fetchAll = () => {
            for (const symbol of symbols) {
                setPreviews((prev) => ({
                    ...prev,
                    [symbol]: prev[symbol] ?? 'loading',
                }));

                fetch(
                    `${signalPreviewRoute.url()}?symbol=${encodeURIComponent(symbol)}`,
                    {
                        headers: { Accept: 'application/json' },
                    },
                )
                    .then((r) => r.json())
                    .then((json) => {
                        if (json.success) {
                            setPreviews((prev) => ({
                                ...prev,
                                [symbol]: json.data,
                            }));
                        } else {
                            setPreviews((prev) => ({
                                ...prev,
                                [symbol]: 'error',
                            }));
                        }
                    })
                    .catch(() =>
                        setPreviews((prev) => ({ ...prev, [symbol]: 'error' })),
                    );
            }
        };

        fetchAll();
        const id = setInterval(fetchAll, SIGNAL_POLL_INTERVAL);

        return () => clearInterval(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [symbolsKey]);

    return previews;
}
