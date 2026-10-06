import { useEffect, useRef, useState } from 'react';
import { equityMemory as equityMemoryRoute } from '@/routes/futures';

// "Last time price was here, was I actually better off?" — records (symbol,
// price, account-wide Total Equity) server-side roughly every couple of
// minutes and compares against the most recent prior visit to the same price
// level, independent of price just moving around. Poll cadence matches
// useSignalPreviews (60s); price/equity are read through refs each tick so
// the interval isn't torn down and restarted on every price/equity update.

export interface EquityMemory {
    matched: boolean;
    reference_price: number | null;
    reference_equity: number | null;
    reference_recorded_at: string | null;
    equity_delta: number | null;
    price_diff_pct: number | null;
}

const EQUITY_MEMORY_POLL_INTERVAL = 60_000;

export function useEquityMemory(
    symbol: string | null,
    price: number | null,
    totalEquity: number,
): EquityMemory | 'loading' | 'error' | undefined {
    const [result, setResult] = useState<
        EquityMemory | 'loading' | 'error' | undefined
    >(undefined);

    // Latest price/equity, read at fetch time so the interval isn't torn down and
    // restarted on every tick. Synced in an effect (declared before the fetching
    // effect so it has already run when the first fetch fires), not during render.
    const latest = useRef({ price, totalEquity });

    useEffect(() => {
        latest.current = { price, totalEquity };
    });

    useEffect(() => {
        if (!symbol) {
            return;
        }

        const fetchOnce = () => {
            const currentPrice = latest.current.price;
            const currentEquity = latest.current.totalEquity;

            if (currentPrice === null || currentPrice <= 0 || currentEquity <= 0) {
                return;
            }

            setResult((prev) => prev ?? 'loading');

            const csrfToken =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';

            fetch(equityMemoryRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    symbol,
                    price: currentPrice,
                    totalEquity: currentEquity,
                }),
            })
                .then((r) => r.json())
                .then((json) => {
                    setResult(json.success ? json.data : 'error');
                })
                .catch(() => setResult('error'));
        };

        fetchOnce();
        const id = setInterval(fetchOnce, EQUITY_MEMORY_POLL_INTERVAL);

        return () => clearInterval(id);
    }, [symbol]);

    return result;
}
