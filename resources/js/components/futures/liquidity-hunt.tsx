import { ArrowDown, ArrowUp } from 'lucide-react';
import { coinLabel } from '@/types/futures';

export interface LiquidityHuntEntry {
    symbol: string;
    zone: 'support' | 'resistance';
    direction: 'higher' | 'lower';
    analyzed_at: string;
}

interface Props {
    entries: LiquidityHuntEntry[];
}

function timeAgo(iso: string): string {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}m ago`;
}

export function LiquidityHunt({ entries }: Props) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                Liquidity Hunt
            </p>
            <p className="text-[11px] text-muted-foreground">
                Pairs sitting right at a recent swing high/low, where stop-loss and
                breakout orders typically cluster — a proxy for where price is likely
                to get pushed next, not live order-book data.
            </p>

            {entries.length === 0 ? (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    Nothing sitting at a liquidity zone right now.
                </p>
            ) : (
                <ol className="flex flex-col gap-1.5">
                    {entries.map((e, i) => {
                        const isHigher = e.direction === 'higher';

                        return (
                            <li
                                key={`${e.symbol}-${i}`}
                                className="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 px-2.5 py-1.5"
                            >
                                <div className="flex items-center gap-2">
                                    {isHigher ? (
                                        <ArrowUp className="size-3.5 shrink-0 text-emerald-500" />
                                    ) : (
                                        <ArrowDown className="size-3.5 shrink-0 text-red-500" />
                                    )}
                                    <span className="min-w-0 flex-1 text-sm font-semibold text-foreground">
                                        {coinLabel(e.symbol)}
                                    </span>
                                    <span className="shrink-0 text-[10px] text-muted-foreground">
                                        {timeAgo(e.analyzed_at)}
                                    </span>
                                </div>
                                <div className="pl-[22px] text-[11px] text-muted-foreground">
                                    near {e.zone} ·{' '}
                                    <span
                                        className={`font-bold ${isHigher ? 'text-emerald-500' : 'text-red-500'}`}
                                    >
                                        strike {e.direction}
                                    </span>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
