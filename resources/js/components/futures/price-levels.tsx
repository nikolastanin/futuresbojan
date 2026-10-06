import { ChevronDown, ChevronUp } from 'lucide-react';
import { useState } from 'react';
import type { PriceLevels as PriceLevelsData } from '@/hooks/use-signal-previews';

interface Props {
    current: number;
    levels: PriceLevelsData;
    /** Start expanded — for roomy spots like the Analysis panel. Defaults to the
     * collapsed progressive-disclosure behavior used in denser rows. */
    defaultExpanded?: boolean;
}

type LevelKey =
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
    { label: string; dot: string; text: string }
> = {
    r2: { label: 'R2', dot: 'bg-red-600', text: 'text-red-500' },
    r1: { label: 'R1', dot: 'bg-red-500', text: 'text-red-400' },
    week_high: { label: 'WH', dot: 'bg-orange-500', text: 'text-orange-400' },
    prior_day_high: {
        label: 'PDH',
        dot: 'bg-amber-500',
        text: 'text-amber-400',
    },
    ema20: { label: 'EMA20', dot: 'bg-violet-400', text: 'text-violet-400' },
    ema10: { label: 'EMA10', dot: 'bg-sky-400', text: 'text-sky-400' },
    pivot: { label: 'PIV', dot: 'bg-slate-400', text: 'text-slate-400' },
    prior_day_low: { label: 'PDL', dot: 'bg-teal-400', text: 'text-teal-400' },
    week_low: { label: 'WL', dot: 'bg-cyan-500', text: 'text-cyan-400' },
    s1: { label: 'S1', dot: 'bg-emerald-500', text: 'text-emerald-400' },
    s2: { label: 'S2', dot: 'bg-emerald-600', text: 'text-emerald-500' },
};

interface LevelRow {
    key: LevelKey;
    price: number;
}

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

const fmtPct = (n: number) => `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;

/**
 * "Where does price sit relative to every level that matters" — pivots (R1/R2/S1/S2
 * from the prior day's H/L/C), prior-day and trailing-week high/low, and daily
 * EMA10/EMA20. A proportional strip gives an at-a-glance read of where NOW sits in
 * the range; the chip grid below has the exact numbers, each color-coded to match
 * its dot on the strip. Collapsed by default (same progressive-disclosure pattern
 * as the "Why?" reasons toggle elsewhere) since it's a lot of numbers for a row
 * that's already dense.
 */
export function PriceLevels({
    current,
    levels,
    defaultExpanded = false,
}: Props) {
    const [expanded, setExpanded] = useState(defaultExpanded);

    const entries: LevelRow[] = [
        { key: 'r2', price: levels.r2 },
        { key: 'r1', price: levels.r1 },
        { key: 'week_high', price: levels.week_high },
        { key: 'prior_day_high', price: levels.prior_day_high },
        ...(levels.ema20 !== null
            ? [{ key: 'ema20' as const, price: levels.ema20 }]
            : []),
        ...(levels.ema10 !== null
            ? [{ key: 'ema10' as const, price: levels.ema10 }]
            : []),
        { key: 'pivot', price: levels.pivot },
        { key: 'prior_day_low', price: levels.prior_day_low },
        { key: 'week_low', price: levels.week_low },
        { key: 's1', price: levels.s1 },
        { key: 's2', price: levels.s2 },
    ];

    const allPrices = [...entries.map((e) => e.price), current];
    const min = Math.min(...allPrices);
    const max = Math.max(...allPrices);
    const range = max - min || 1;
    const posFor = (price: number) => ((price - min) / range) * 100;

    const sortedEntries = [...entries].sort((a, b) => b.price - a.price);

    return (
        <div className="flex flex-col gap-1.5">
            <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="flex w-fit items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
            >
                Levels
                {expanded ? (
                    <ChevronUp className="size-3" />
                ) : (
                    <ChevronDown className="size-3" />
                )}
            </button>
            {expanded && (
                <div className="flex flex-col gap-2 rounded-md border border-border bg-background p-2.5">
                    {/* Proportional strip — a quick visual read of where NOW sits */}
                    <div className="relative h-2 w-full rounded-full bg-gradient-to-r from-emerald-500/20 via-muted to-red-500/20">
                        {entries.map((e) => (
                            <div
                                key={e.key}
                                className="absolute top-1/2 -translate-x-1/2 -translate-y-1/2"
                                style={{ left: `${posFor(e.price)}%` }}
                                title={`${LEVEL_META[e.key].label} $${fmtPrice(e.price)}`}
                            >
                                <div
                                    className={`size-2 rounded-full ring-2 ring-background ${LEVEL_META[e.key].dot}`}
                                />
                            </div>
                        ))}
                        <div
                            className="absolute top-1/2 -translate-x-1/2 -translate-y-1/2"
                            style={{ left: `${posFor(current)}%` }}
                            title={`NOW $${fmtPrice(current)}`}
                        >
                            <div className="size-3 rounded-full border-2 border-foreground bg-background" />
                        </div>
                    </div>

                    {/* Chip grid — exact numbers, color-matched to the strip's dots */}
                    <div className="grid grid-cols-2 gap-1">
                        <div className="col-span-2 flex items-center justify-between rounded bg-foreground/10 px-1.5 py-1 text-[10px] font-bold text-foreground">
                            <span>NOW</span>
                            <span className="tabular-nums">
                                ${fmtPrice(current)}
                            </span>
                        </div>
                        {sortedEntries.map((e) => {
                            const meta = LEVEL_META[e.key];
                            const distPct =
                                ((e.price - current) / current) * 100;

                            return (
                                <div
                                    key={e.key}
                                    className="flex items-center justify-between rounded bg-muted/30 px-1.5 py-1 text-[10px]"
                                >
                                    <span
                                        className={`flex items-center gap-1 font-semibold ${meta.text}`}
                                    >
                                        <span
                                            className={`size-1.5 shrink-0 rounded-full ${meta.dot}`}
                                        />
                                        {meta.label}
                                    </span>
                                    <span className="flex flex-col items-end text-foreground tabular-nums">
                                        <span>${fmtPrice(e.price)}</span>
                                        <span className="text-muted-foreground">
                                            {fmtPct(distPct)}
                                        </span>
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
