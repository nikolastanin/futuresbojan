import { useEffect, useState } from 'react';
import { symbols as symbolsRoute } from '@/routes/futures';

// A tiny fallback in case the live /futures/symbols fetch fails — just enough to
// keep a coin picker usable, not a substitute for the real (607-and-growing) list.
const FALLBACK_SYMBOLS = [
    'BTC_USDT',
    'ETH_USDT',
    'SOL_USDT',
    'BNB_USDT',
    'XRP_USDT',
];

/** Every active MEXC coin symbol, fetched once — the live superset of whatever any
 * curated pair list (top signals, scalp scanner, etc.) could ever surface, so the
 * search never misses a coin those tools already found. */
export function useActiveSymbols(): string[] {
    const [symbols, setSymbols] = useState<string[]>(FALLBACK_SYMBOLS);

    useEffect(() => {
        fetch(symbolsRoute.url(), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((json) => {
                if (
                    json.success &&
                    Array.isArray(json.data) &&
                    json.data.length > 0
                ) {
                    setSymbols(json.data);
                }
            })
            .catch(() => {});
    }, []);

    return symbols;
}
