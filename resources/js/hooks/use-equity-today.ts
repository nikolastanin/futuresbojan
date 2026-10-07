import { useEffect, useState } from 'react';
import { equityToday as equityTodayRoute } from '@/routes/futures';

// Today's account equity as the snapshot recorder has seen it. The server only knows
// readings it took while a dashboard was open, so "open" is the first reading of the
// UTC day, not necessarily midnight — the UI says so. Callers combine this with the live
// equity for "now", rather than trusting `last`, which can be minutes old.

export interface EquityToday {
    open: number;
    high: number;
    low: number;
    last: number;
    count: number;
    first_at: string;
    last_at: string;
}

const POLL_INTERVAL = 60_000;

/** Null until the first reading of the day exists (or while the log can't be read). */
export function useEquityToday(): EquityToday | null {
    const [today, setToday] = useState<EquityToday | null>(null);

    useEffect(() => {
        const load = () => {
            fetch(equityTodayRoute.url(), {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((json) => {
                    if (json.success) {
                        setToday(json.data);
                    }
                })
                .catch(() => {
                    // keep showing the last good reading
                });
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => clearInterval(id);
    }, []);

    return today;
}
