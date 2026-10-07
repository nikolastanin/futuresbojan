import { useEffect, useRef } from 'react';
import { snapshot as snapshotRoute } from '@/routes/futures';

// How often the page nudges the server. The server decides whether a snapshot is
// actually due (so several open tabs still record once), this is only the reminder.
const NUDGE_INTERVAL = 5 * 60_000;

/**
 * Renders nothing. While the dashboard is open it keeps the account-equity history and
 * each coin's price, funding rate, open interest and own exposure growing — the numbers
 * that can't be reconstructed later. `symbols` are coins on screen that aren't held, so
 * their market data is kept too. A failed nudge is ignored: it's only a record.
 */
export function SnapshotRecorder({ symbols }: { symbols: string[] }) {
    // Latest symbols, read at nudge time so the interval isn't restarted when the coin
    // in the order form changes. Synced in an effect declared before the nudging one so
    // it has run by the time the first nudge fires.
    const latest = useRef(symbols);

    useEffect(() => {
        latest.current = symbols;
    });

    useEffect(() => {
        const nudge = () => {
            const csrfToken =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';

            fetch(snapshotRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ symbols: latest.current }),
            }).catch(() => {
                // nothing to do — the next nudge tries again
            });
        };

        nudge();
        const id = setInterval(nudge, NUDGE_INTERVAL);

        return () => clearInterval(id);
    }, []);

    return null;
}
