import { useEffect, useState } from 'react';
import type { Candle } from '@/lib/mini-chart';
import { miniCharts as miniChartsRoute } from '@/routes/futures';

// Candles for the small charts in Open Positions: one batched request for every coin
// held, once a minute — the server serves them from the same candle cache the Analysis
// panel fills. The live price comes from the 5-second positions poll, not from here.

const POLL_INTERVAL = 60_000;

/**
 * Per coin: the latest candles, 'error' if they could not be had, or undefined while the
 * first fetch is still on its way. A failed refresh keeps showing the last good candles.
 */
export function useMiniCharts(
    symbols: string[],
    tf: string,
    enabled: boolean,
): Record<string, Candle[] | 'error' | undefined> {
    const [byKey, setByKey] = useState<Record<string, Candle[] | 'error'>>({});
    const symbolsKey = symbols.slice().sort().join(',');

    useEffect(() => {
        const wanted = symbolsKey === '' ? [] : symbolsKey.split(',');

        // Skipped while the charts are hidden or nothing is held — nothing to draw.
        if (!enabled || wanted.length === 0) {
            return;
        }

        const settle = (data: Record<string, unknown> | null) =>
            setByKey((prev) => {
                const next = { ...prev };

                for (const symbol of wanted) {
                    const key = `${tf}:${symbol}`;
                    const candles = data?.[symbol];

                    // Keep the last good candles if this refresh came back without them.
                    next[key] = Array.isArray(candles)
                        ? (candles as Candle[])
                        : Array.isArray(prev[key])
                          ? prev[key]
                          : 'error';
                }

                return next;
            });

        const load = () => {
            const query = wanted
                .map((symbol) => `symbols[]=${encodeURIComponent(symbol)}`)
                .join('&');

            fetch(`${miniChartsRoute.url()}?tf=${tf}&${query}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((json) => settle(json.success ? json.data : null))
                .catch(() => settle(null));
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => clearInterval(id);
    }, [symbolsKey, tf, enabled]);

    return Object.fromEntries(
        symbols.map((symbol) => [symbol, byKey[`${tf}:${symbol}`]]),
    );
}
