import { ChevronDown, ChevronUp } from 'lucide-react';
import { useState } from 'react';
import type { PriceLevels as PriceLevelsData } from '@/hooks/use-signal-previews';

interface Props {
    current: number;
    levels: PriceLevelsData;
}

interface LevelRow {
    label: string;
    price: number;
    isNow?: boolean;
}

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * "Where does price sit relative to every level that matters" — pivots (R1/R2/S1/S2
 * from the prior day's H/L/C), prior-day and trailing-week high/low, and daily
 * EMA10/EMA20, sorted into one ladder with the current price's position marked.
 * Collapsed by default (same progressive-disclosure pattern as the "Why?" reasons
 * toggle elsewhere) since it's a lot of numbers for a row that's already dense.
 */
export function PriceLevels({ current, levels }: Props) {
    const [expanded, setExpanded] = useState(false);

    const entries: LevelRow[] = [
        { label: 'R2', price: levels.r2 },
        { label: 'R1', price: levels.r1 },
        { label: 'Week High', price: levels.week_high },
        { label: 'Prior Day High', price: levels.prior_day_high },
        ...(levels.ema20 !== null
            ? [{ label: 'EMA20', price: levels.ema20 }]
            : []),
        ...(levels.ema10 !== null
            ? [{ label: 'EMA10', price: levels.ema10 }]
            : []),
        { label: 'Pivot', price: levels.pivot },
        { label: 'Prior Day Low', price: levels.prior_day_low },
        { label: 'Week Low', price: levels.week_low },
        { label: 'S1', price: levels.s1 },
        { label: 'S2', price: levels.s2 },
    ];

    const rows: LevelRow[] = [
        ...entries,
        { label: 'NOW', price: current, isNow: true },
    ].sort((a, b) => b.price - a.price);

    return (
        <div className="flex flex-col gap-1">
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
                <div className="flex flex-col overflow-hidden rounded-md border border-border bg-background text-[11px]">
                    {rows.map((r, i) => (
                        <div
                            key={`${r.label}-${i}`}
                            className={`flex items-center justify-between px-2 py-1 ${
                                r.isNow
                                    ? 'bg-foreground/10 font-bold text-foreground'
                                    : r.price > current
                                      ? 'text-red-500'
                                      : 'text-emerald-500'
                            }`}
                        >
                            <span>{r.label}</span>
                            <span className="tabular-nums">
                                ${fmtPrice(r.price)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
