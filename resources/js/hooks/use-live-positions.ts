import { useEffect, useState } from 'react';
import { positions as positionsRoute } from '@/routes/futures';
import type { Position } from '@/types/futures';

// Your open positions, for a page that shows them next to something else (the Market chart).
// The dashboard polls the same endpoint every 5 seconds; so does this, only while it is
// switched on, and a failed poll keeps the last good answer (the exchange's private API
// being down must not blank a chart).

const POLL_INTERVAL = 5_000;

export function useLivePositions(enabled: boolean): Position[] {
    const [positions, setPositions] = useState<Position[]>([]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;

        const load = () => {
            fetch(positionsRoute.url(), {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((json) => {
                    if (!cancelled && json.success) {
                        setPositions(json.data);
                    }
                })
                .catch(() => {});
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, [enabled]);

    return enabled ? positions : [];
}
