import { Bell, BellRing, X, Zap } from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ExtraLevels } from '@/hooks/use-analysis-extras';
import type { PriceLevels as PriceLevelsData } from '@/hooks/use-signal-previews';
import {
    addAlert,
    clearTriggered,
    findActiveAlert,
    removeAlert,
    requestAlertNotifications,
    usePriceAlerts,
} from '@/lib/price-alerts';
import { coinLabel } from '@/types/futures';

interface Props {
    symbol: string;
    current: number;
    levels: PriceLevelsData;
    /** Weekly/monthly/volume-profile levels; null until they've loaded. */
    extra: ExtraLevels | null;
}

type LevelKey =
    | keyof ExtraLevels
    | 'r2'
    | 'r1'
    | 'week_high'
    | 'prior_day_high'
    | 'ema20'
    | 'ema10'
    | 'pivot'
    | 'prior_day_low'
    | 'week_low'
    | 's1'
    | 's2';

const LEVEL_META: Record<
    LevelKey,
    { label: string; dot: string; text: string; hint: string }
> = {
    r2: { label: 'R2', dot: 'bg-red-600', text: 'text-red-500', hint: 'Daily pivot resistance 2' },
    r1: { label: 'R1', dot: 'bg-red-500', text: 'text-red-400', hint: 'Daily pivot resistance 1' },
    week_high: { label: 'WH', dot: 'bg-orange-500', text: 'text-orange-400', hint: 'High of the last 7 days' },
    prior_day_high: { label: 'PDH', dot: 'bg-amber-500', text: 'text-amber-400', hint: 'Prior day high' },
    ema20: { label: 'EMA20', dot: 'bg-violet-400', text: 'text-violet-400', hint: 'Daily EMA 20' },
    ema10: { label: 'EMA10', dot: 'bg-sky-400', text: 'text-sky-400', hint: 'Daily EMA 10' },
    pivot: { label: 'DP', dot: 'bg-slate-400', text: 'text-slate-400', hint: 'Daily pivot' },
    prior_day_low: { label: 'PDL', dot: 'bg-teal-400', text: 'text-teal-400', hint: 'Prior day low' },
    week_low: { label: 'WL', dot: 'bg-cyan-500', text: 'text-cyan-400', hint: 'Low of the last 7 days' },
    s1: { label: 'S1', dot: 'bg-emerald-500', text: 'text-emerald-400', hint: 'Daily pivot support 1' },
    s2: { label: 'S2', dot: 'bg-emerald-600', text: 'text-emerald-500', hint: 'Daily pivot support 2' },
    weekly_pivot: { label: 'WP', dot: 'bg-fuchsia-400', text: 'text-fuchsia-400', hint: 'Weekly pivot (from the prior week)' },
    prior_week_high: { label: 'PWH', dot: 'bg-rose-400', text: 'text-rose-400', hint: 'Prior week high' },
    prior_week_low: { label: 'PWL', dot: 'bg-lime-400', text: 'text-lime-400', hint: 'Prior week low' },
    monthly_pivot: { label: 'MP', dot: 'bg-indigo-400', text: 'text-indigo-400', hint: 'Monthly pivot (from the prior month)' },
    prior_month_high: { label: 'PMH', dot: 'bg-pink-500', text: 'text-pink-400', hint: 'Prior month high' },
    prior_month_low: { label: 'PML', dot: 'bg-green-500', text: 'text-green-400', hint: 'Prior month low' },
    poc: { label: 'POC', dot: 'bg-yellow-400', text: 'text-yellow-400', hint: 'Volume profile point of control — the price with the most volume traded over the last 7 days' },
    vah: { label: 'VAH', dot: 'bg-yellow-500', text: 'text-yellow-500', hint: 'Value area high — top of the band holding 70% of the last 7 days\' volume' },
    val: { label: 'VAL', dot: 'bg-yellow-600', text: 'text-yellow-600', hint: 'Value area low — bottom of the band holding 70% of the last 7 days\' volume' },
};

// Levels this close together (as a fraction of price) count as stacked — two or more
// independent sources agreeing on a price is a stronger zone than either alone.
const CONFLUENCE_TOLERANCE = 0.002;

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

const fmtPct = (n: number) => `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;

interface Entry {
    key: LevelKey;
    price: number;
}

type Item = { kind: 'level'; entry: Entry } | { kind: 'now'; price: number };

/** Groups levels sitting within CONFLUENCE_TOLERANCE of their neighbour; only groups of 2+ are returned. */
export function findConfluences(entries: Entry[]): Map<LevelKey, LevelKey[]> {
    const ascending = [...entries].sort((a, b) => a.price - b.price);
    const result = new Map<LevelKey, LevelKey[]>();
    let group: Entry[] = [];

    const flush = () => {
        if (group.length >= 2) {
            const keys = group.map((e) => e.key);

            for (const key of keys) {
                result.set(key, keys);
            }
        }

        group = [];
    };

    for (const entry of ascending) {
        const last = group[group.length - 1];

        if (last && (entry.price - last.price) / last.price > CONFLUENCE_TOLERANCE) {
            flush();
        }

        group.push(entry);
    }

    flush();

    return result;
}

/**
 * Every price level that matters for this coin as one vertical ladder with NOW in
 * its place: the daily pivots and EMAs, plus weekly/monthly pivots, prior week and
 * month highs/lows, and the volume-profile POC and value area. Levels that stack
 * within 0.2% of each other are flagged as confluence. The bell on each row sets a
 * price alert at that level.
 */
export function LevelsLadder({ symbol, current, levels, extra }: Props) {
    const alerts = usePriceAlerts();
    const [customPrice, setCustomPrice] = useState('');

    const base: Entry[] = [
        { key: 'r2', price: levels.r2 },
        { key: 'r1', price: levels.r1 },
        { key: 'week_high', price: levels.week_high },
        { key: 'prior_day_high', price: levels.prior_day_high },
        { key: 'pivot', price: levels.pivot },
        { key: 'prior_day_low', price: levels.prior_day_low },
        { key: 'week_low', price: levels.week_low },
        { key: 's1', price: levels.s1 },
        { key: 's2', price: levels.s2 },
    ];

    if (levels.ema20 !== null) {
        base.push({ key: 'ema20', price: levels.ema20 });
    }

    if (levels.ema10 !== null) {
        base.push({ key: 'ema10', price: levels.ema10 });
    }

    const extraEntries: Entry[] = extra
        ? (Object.keys(extra) as (keyof ExtraLevels)[])
              .filter((k) => extra[k] !== null)
              .map((k) => ({ key: k, price: extra[k] as number }))
        : [];

    const entries = [...base, ...extraEntries];
    const confluences = findConfluences(entries);

    const nowItem: Item = { kind: 'now', price: current };
    const priceOf = (item: Item) =>
        item.kind === 'now' ? item.price : item.entry.price;
    const items: Item[] = [
        ...entries.map((entry): Item => ({ kind: 'level', entry })),
        nowItem,
    ].sort((a, b) => priceOf(b) - priceOf(a));

    const toggleAlert = (price: number, label: string) => {
        const existing = findActiveAlert(alerts, symbol, price);

        if (existing) {
            removeAlert(existing.id);

            return;
        }

        requestAlertNotifications();
        addAlert({
            symbol,
            price,
            direction: price > current ? 'above' : 'below',
            label,
        });
    };

    const customValue = parseFloat(customPrice);
    const canAddCustom = Number.isFinite(customValue) && customValue > 0;

    const active = alerts.filter((a) => a.triggeredAt === null);
    const triggered = alerts.filter((a) => a.triggeredAt !== null);

    return (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-background p-2.5">
            <div className="flex items-center justify-between">
                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    Levels
                </p>
                <span className="flex items-center gap-1 text-[9px] text-muted-foreground">
                    <Zap className="size-2.5 text-amber-400" />
                    = stacked within 0.2%
                </span>
            </div>

            <div className="flex flex-col gap-px">
                {items.map((item) => {
                    if (item.kind === 'now') {
                        return (
                            <div
                                key="now"
                                className="flex items-center justify-between rounded bg-foreground/10 px-2 py-1 text-[11px] font-bold text-foreground"
                            >
                                <span>NOW</span>
                                <span className="tabular-nums">
                                    ${fmtPrice(item.price)}
                                </span>
                            </div>
                        );
                    }

                    const { key, price } = item.entry;
                    const meta = LEVEL_META[key];
                    const stack = confluences.get(key);
                    const hasAlert = findActiveAlert(alerts, symbol, price) !== undefined;

                    return (
                        <div
                            key={key}
                            className={`flex items-center gap-2 rounded px-2 py-1 text-[11px] ${
                                stack
                                    ? 'border-l-2 border-amber-400/70 bg-amber-400/5'
                                    : 'bg-muted/20'
                            }`}
                        >
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        className={`flex w-14 shrink-0 cursor-default items-center gap-1 font-semibold ${meta.text}`}
                                    >
                                        <span
                                            className={`size-1.5 shrink-0 rounded-full ${meta.dot}`}
                                        />
                                        {meta.label}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="left"
                                    className="max-w-[220px] text-[11px]"
                                >
                                    {meta.hint}
                                </TooltipContent>
                            </Tooltip>

                            <span className="text-foreground tabular-nums">
                                ${fmtPrice(price)}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {fmtPct(((price - current) / current) * 100)}
                            </span>

                            {stack && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="flex cursor-default items-center gap-0.5 text-[10px] font-semibold text-amber-400">
                                            <Zap className="size-2.5" />×
                                            {stack.length}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="top"
                                        className="max-w-[220px] text-[11px]"
                                    >
                                        Stacked zone:{' '}
                                        {stack
                                            .map((k) => LEVEL_META[k].label)
                                            .join(' + ')}{' '}
                                        all sit within 0.2% of each other.
                                    </TooltipContent>
                                </Tooltip>
                            )}

                            <button
                                type="button"
                                onClick={() => toggleAlert(price, meta.label)}
                                title={
                                    hasAlert
                                        ? 'Remove the alert at this level'
                                        : `Alert me when price reaches ${meta.label}`
                                }
                                className={`ml-auto rounded p-0.5 transition-colors hover:bg-muted ${
                                    hasAlert
                                        ? 'text-amber-400'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {hasAlert ? (
                                    <BellRing className="size-3.5" />
                                ) : (
                                    <Bell className="size-3.5" />
                                )}
                            </button>
                        </div>
                    );
                })}
            </div>

            {extra === null && (
                <p className="text-[10px] text-muted-foreground">
                    Loading weekly, monthly and volume-profile levels…
                </p>
            )}

            <div className="flex items-center gap-1.5 border-t border-border pt-2">
                <input
                    value={customPrice}
                    onChange={(e) => setCustomPrice(e.target.value)}
                    inputMode="decimal"
                    placeholder={`Alert at price (${coinLabel(symbol)})`}
                    className="h-7 min-w-0 flex-1 rounded-md border border-border bg-background px-2 text-[11px] text-foreground outline-none focus:border-foreground/40"
                />
                <button
                    type="button"
                    disabled={!canAddCustom}
                    onClick={() => {
                        requestAlertNotifications();
                        addAlert({
                            symbol,
                            price: customValue,
                            direction: customValue > current ? 'above' : 'below',
                            label: 'Custom price',
                        });
                        setCustomPrice('');
                    }}
                    className="flex h-7 items-center gap-1 rounded-md border border-border px-2 text-[11px] font-medium text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:opacity-40"
                >
                    <Bell className="size-3" />
                    Set
                </button>
            </div>

            {(active.length > 0 || triggered.length > 0) && (
                <div className="flex flex-col gap-1">
                    {active.map((a) => (
                        <div
                            key={a.id}
                            className="flex items-center gap-2 rounded bg-amber-400/5 px-2 py-1 text-[11px]"
                        >
                            <BellRing className="size-3 shrink-0 text-amber-400" />
                            <span className="text-foreground">
                                {coinLabel(a.symbol)}{' '}
                                {a.direction === 'above' ? '≥' : '≤'} $
                                {fmtPrice(a.price)}
                            </span>
                            <span className="truncate text-muted-foreground">
                                {a.label}
                            </span>
                            <button
                                type="button"
                                onClick={() => removeAlert(a.id)}
                                aria-label="Remove alert"
                                className="ml-auto text-muted-foreground hover:text-foreground"
                            >
                                <X className="size-3" />
                            </button>
                        </div>
                    ))}

                    {triggered.length > 0 && (
                        <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                            <span>
                                {triggered.length} alert
                                {triggered.length > 1 ? 's' : ''} fired
                            </span>
                            <button
                                type="button"
                                onClick={clearTriggered}
                                className="underline-offset-2 hover:text-foreground hover:underline"
                            >
                                Clear
                            </button>
                        </div>
                    )}
                </div>
            )}

            <p className="text-[9px] text-muted-foreground">
                Alerts only fire while this dashboard is open in a browser tab.
            </p>
        </div>
    );
}
