import { useEffect, useState } from 'react';
import { account as accountRoute } from '@/routes/futures';
import type { AccountAsset } from '@/types/futures';

// Your total equity, for a page that shows it next to something else (the Market chart). The
// dashboard polls the same endpoint every 5 seconds; so does this, and a failed poll keeps the
// last good answer (the exchange's private API being down must not blank the number).

const POLL_INTERVAL = 5_000;

/** Null until the first answer arrives. */
export function useAccountEquity(): number | null {
    const [equity, setEquity] = useState<number | null>(null);

    useEffect(() => {
        let cancelled = false;

        const load = () => {
            fetch(accountRoute.url(), {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((json) => {
                    if (cancelled || !json.success) {
                        return;
                    }

                    const usdt = (json.data as AccountAsset[]).find(
                        (a) => a.currency === 'USDT',
                    );

                    setEquity(usdt?.equity ?? 0);
                })
                .catch(() => {});
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, []);

    return equity;
}
