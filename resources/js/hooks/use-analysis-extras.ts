import { useEffect, useState } from 'react';
import { analysisExtras as analysisExtrasRoute } from '@/routes/futures';

// The Analysis panel's deeper read for one coin: a multi-timeframe grid,
// higher-timeframe and volume-profile levels, and strength vs BTC. Fetched only
// for the coin on screen (not per position row), at the same 60s cadence as the
// signal preview.

export interface MtfRow {
    tf: string;
    trend: string;
    rsi: number | null;
    macd: 'bullish' | 'bearish' | 'neutral' | null;
    lean: 'up' | 'down' | 'mixed';
}

export interface ExtraLevels {
    weekly_pivot: number | null;
    prior_week_high: number | null;
    prior_week_low: number | null;
    monthly_pivot: number | null;
    prior_month_high: number | null;
    prior_month_low: number | null;
    poc: number | null;
    vah: number | null;
    val: number | null;
}

export interface StrengthWindow {
    coin: number | null;
    btc: number | null;
    diff: number | null;
}

export interface AnalysisExtras {
    symbol: string;
    mtf: MtfRow[];
    levels: ExtraLevels;
    /** Null for BTC itself. */
    vs_btc: Record<string, StrengthWindow> | null;
}

const POLL_INTERVAL = 60_000;

export function useAnalysisExtras(
    symbol: string,
    enabled = true,
): AnalysisExtras | 'loading' | 'error' {
    const [bySymbol, setBySymbol] = useState<
        Record<string, AnalysisExtras | 'error'>
    >({});

    useEffect(() => {
        // Skipped while the panel is minimised — no point polling what isn't shown.
        if (!enabled) {
            return;
        }

        const load = () => {
            fetch(
                `${analysisExtrasRoute.url()}?symbol=${encodeURIComponent(symbol)}`,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => r.json())
                .then((json) =>
                    setBySymbol((prev) => ({
                        ...prev,
                        [symbol]: json.success ? json.data : 'error',
                    })),
                )
                .catch(() =>
                    setBySymbol((prev) => ({ ...prev, [symbol]: 'error' })),
                );
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => clearInterval(id);
    }, [symbol, enabled]);

    // Keyed by symbol so switching coins shows "loading" instead of the previous
    // coin's grid for a moment.
    return bySymbol[symbol] ?? 'loading';
}
