import { Layers, Target, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { fmtSigned, fmtUsdt } from '@/lib/totals';
import type { Totals } from '@/lib/totals';
import { cn } from '@/lib/utils';

const toneOf = (n: number) =>
    n > 0 ? 'text-emerald-500' : n < 0 ? 'text-red-500' : 'text-foreground';

/**
 * One third of the strip, in the column of the card it stands for: the label and the number
 * (stacked on a phone, side by side beyond that), and optionally something under them.
 */
function Figure({
    icon,
    label,
    tone = 'text-foreground',
    footer,
    children,
}: {
    icon: ReactNode;
    label: string;
    tone?: string;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col justify-center gap-1">
            <div className="flex min-w-0 flex-col sm:flex-row sm:items-baseline sm:gap-3">
                <span className="flex items-center gap-1.5 text-[10px] leading-4 font-medium tracking-widest whitespace-nowrap text-muted-foreground uppercase">
                    <span className="hidden self-center sm:block">{icon}</span>
                    {label}
                </span>
                <span
                    className={cn(
                        'text-sm font-semibold whitespace-nowrap tabular-nums sm:text-base',
                        tone,
                    )}
                >
                    {children}
                </span>
            </div>
            {footer}
        </div>
    );
}

/**
 * The three cards at the top of the dashboard as one slim row, for the strip that sticks to the top
 * of the screen once the cards have scrolled out of sight: the same three numbers, in the same
 * three columns as the cards (total positions, unrealized PNL, equity with a thin line for the
 * progress to the next equity milestone).
 */
export function TotalsBar({ totals }: { totals: Totals }) {
    return (
        <div
            role="group"
            aria-label="Account totals"
            className="grid grid-cols-3 gap-3 px-3 py-1.5 sm:px-4 sm:py-2"
        >
            <Figure
                icon={<Layers className="size-3.5 text-sky-500" />}
                label="Positions"
                tone={toneOf(totals.netValue)}
            >
                {fmtSigned(totals.netValue)}
            </Figure>
            <Figure
                icon={<Wallet className="size-3.5 text-violet-500" />}
                label="Unrealized PNL"
                tone={toneOf(totals.unrealized)}
            >
                {fmtSigned(totals.unrealized)}
            </Figure>
            <Figure
                icon={<Target className="size-3.5 text-amber-500" />}
                label="Equity"
                footer={
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
                }
            >
                {fmtUsdt(totals.equity)}
                <span className="ml-1 text-xs font-normal text-muted-foreground">
                    USDT
                </span>
            </Figure>
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
