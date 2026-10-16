import { Target } from 'lucide-react';
import type { ReactNode } from 'react';
import { fmtSigned, fmtUsdt } from '@/lib/totals';
import type { Totals } from '@/lib/totals';
import { cn } from '@/lib/utils';

const toneOf = (n: number) =>
    n > 0 ? 'text-emerald-500' : n < 0 ? 'text-red-500' : 'text-foreground';

/** One figure of the bar: a small label over the number. */
function Figure({
    label,
    tone = 'text-foreground',
    children,
}: {
    label: string;
    tone?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col">
            <span className="text-[10px] leading-4 font-medium tracking-widest whitespace-nowrap text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'text-sm font-semibold whitespace-nowrap tabular-nums',
                    tone,
                )}
            >
                {children}
            </span>
        </div>
    );
}

/**
 * The three cards at the top of the dashboard in one slim bar, to float over the page once the cards
 * have scrolled out of sight: the same three numbers (total positions, unrealized PNL, equity, with
 * a thin line for the progress to the next equity milestone).
 */
export function TotalsBar({ totals }: { totals: Totals }) {
    return (
        <div
            role="group"
            aria-label="Account totals"
            className="flex max-w-[calc(100vw-1.5rem)] items-center gap-4 overflow-hidden rounded-xl border border-border bg-card/95 px-3 py-1.5 shadow-lg backdrop-blur sm:gap-6 sm:px-4"
        >
            <Figure label="Positions" tone={toneOf(totals.netValue)}>
                {fmtSigned(totals.netValue)}
            </Figure>
            <Figure label="Unrealized PNL" tone={toneOf(totals.unrealized)}>
                {fmtSigned(totals.unrealized)}
            </Figure>
            <div className="flex min-w-0 flex-col gap-1">
                <Figure label="Equity">
                    {fmtUsdt(totals.equity)}
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        USDT
                    </span>
                </Figure>
                <div
                    className="h-0.5 w-full overflow-hidden rounded-full bg-muted"
                    title={`${totals.milestoneProgress.toFixed(1)}% of the way to $${totals.nextMilestone.toLocaleString()}`}
                >
                    <div
                        className={cn(
                            'h-full rounded-full',
                            totals.allMilestonesReached
                                ? 'bg-emerald-500'
                                : 'bg-amber-500',
                        )}
                        style={{ width: `${totals.milestoneProgress}%` }}
                    />
                </div>
            </div>
        </div>
    );
}

/** A small box with just your equity: on the Market chart, where the rest of the totals have no room. */
export function EquityPill({
    equity,
    className,
}: {
    /** Null until the first answer arrives. */
    equity: number | null;
    className?: string;
}) {
    return (
        <div
            title="Your total equity on MEXC"
            className={cn(
                'flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-xs whitespace-nowrap',
                className,
            )}
        >
            <Target className="size-3.5 text-amber-500" />
            <span className="text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                Equity
            </span>
            <span className="font-semibold text-foreground tabular-nums">
                {equity === null ? '—' : fmtUsdt(equity)}
            </span>
            <span className="text-muted-foreground">USDT</span>
        </div>
    );
}
