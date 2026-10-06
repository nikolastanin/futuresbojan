import { useEffect } from 'react';
import { toast } from 'sonner';
import {
    getAlerts,
    markTriggered,
    usePriceAlerts,
} from '@/lib/price-alerts';
import type { PriceAlert } from '@/lib/price-alerts';
import { tickers as tickersRoute } from '@/routes/futures';
import { coinLabel } from '@/types/futures';

const CHECK_INTERVAL = 5_000;

function announce(alert: PriceAlert, livePrice: number): void {
    const title = `${coinLabel(alert.symbol)} hit ${alert.price} (${alert.direction === 'above' ? 'rose to' : 'fell to'} ${livePrice})`;

    toast.warning(title, {
        description: alert.label,
        duration: Infinity,
        closeButton: true,
    });

    try {
        if (
            typeof Notification !== 'undefined' &&
            Notification.permission === 'granted'
        ) {
            new Notification(title, { body: alert.label });
        }
    } catch {
        // some browsers throw on the constructor outside a service worker
    }
}

/**
 * Renders nothing. While any alert is active it polls prices every few seconds and
 * fires a toast (plus a system notification if the user allowed them) when price
 * crosses a level. Mounted once at the dashboard level so alerts keep working when
 * the Analysis panel is minimised or showing a different coin.
 */
export function PriceAlertWatcher() {
    const alerts = usePriceAlerts();
    const activeKey = alerts
        .filter((a) => a.triggeredAt === null)
        .map((a) => a.id)
        .join(',');

    useEffect(() => {
        if (activeKey === '') {
            return;
        }

        const check = async () => {
            try {
                const res = await fetch(tickersRoute.url(), {
                    headers: { Accept: 'application/json' },
                });
                const json = await res.json();
                const prices = new Map<string, number>(
                    (json.data ?? []).map(
                        (t: { symbol: string; fairPrice: string }) => [
                            t.symbol,
                            parseFloat(t.fairPrice),
                        ],
                    ),
                );

                for (const alert of getAlerts()) {
                    if (alert.triggeredAt !== null) {
                        continue;
                    }

                    const price = prices.get(alert.symbol);

                    if (price === undefined || Number.isNaN(price)) {
                        continue;
                    }

                    const hit =
                        alert.direction === 'above'
                            ? price >= alert.price
                            : price <= alert.price;

                    if (hit) {
                        markTriggered(alert.id);
                        announce(alert, price);
                    }
                }
            } catch {
                // a missed poll just means the next one catches it
            }
        };

        check();
        const id = setInterval(check, CHECK_INTERVAL);

        return () => clearInterval(id);
    }, [activeKey]);

    return null;
}
