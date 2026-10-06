import { useSyncExternalStore } from 'react';

// Price alerts live in this browser only (localStorage) and are checked by
// PriceAlertWatcher while the dashboard is open. There is no server-side
// scheduler, so an alert cannot fire with the tab closed — the UI says so.

export interface PriceAlert {
    id: string;
    symbol: string;
    price: number;
    /** Which way price has to cross: set from where price was when the alert was made. */
    direction: 'above' | 'below';
    label: string;
    createdAt: number;
    triggeredAt: number | null;
}

const STORAGE_KEY = 'price-alerts';
const EMPTY: PriceAlert[] = [];

function isValid(a: unknown): a is PriceAlert {
    const x = a as PriceAlert;

    return (
        typeof x === 'object' &&
        x !== null &&
        typeof x.id === 'string' &&
        typeof x.symbol === 'string' &&
        typeof x.price === 'number' &&
        (x.direction === 'above' || x.direction === 'below')
    );
}

function load(): PriceAlert[] {
    try {
        const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed.filter(isValid) : [];
    } catch {
        return [];
    }
}

let alerts: PriceAlert[] = load();
const listeners = new Set<() => void>();

function commit(next: PriceAlert[]): void {
    alerts = next;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(alerts));
    } catch {
        // storage unavailable — alerts still work for this session
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

// Keep several open tabs in sync.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY) {
            alerts = load();
            listeners.forEach((listener) => listener());
        }
    });
}

export function getAlerts(): PriceAlert[] {
    return alerts;
}

export function usePriceAlerts(): PriceAlert[] {
    return useSyncExternalStore(subscribe, getAlerts, () => EMPTY);
}

export function addAlert(
    alert: Pick<PriceAlert, 'symbol' | 'price' | 'direction' | 'label'>,
): void {
    commit([
        ...alerts,
        {
            ...alert,
            id: `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
            createdAt: Date.now(),
            triggeredAt: null,
        },
    ]);
}

export function removeAlert(id: string): void {
    commit(alerts.filter((a) => a.id !== id));
}

export function markTriggered(id: string): void {
    commit(
        alerts.map((a) => (a.id === id ? { ...a, triggeredAt: Date.now() } : a)),
    );
}

export function clearTriggered(): void {
    commit(alerts.filter((a) => a.triggeredAt === null));
}

/** Ask once for permission to show system notifications; the in-page toast works without it. */
export function requestAlertNotifications(): void {
    try {
        if (
            typeof Notification !== 'undefined' &&
            Notification.permission === 'default'
        ) {
            Notification.requestPermission();
        }
    } catch {
        // notifications are a nicety on top of the in-page toast
    }
}

/** An active alert already set at (about) this price for this coin, if any. */
export function findActiveAlert(
    list: PriceAlert[],
    symbol: string,
    price: number,
): PriceAlert | undefined {
    return list.find(
        (a) =>
            a.triggeredAt === null &&
            a.symbol === symbol &&
            Math.abs(a.price - price) <= Math.abs(price) * 1e-6,
    );
}
