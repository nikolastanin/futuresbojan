import { coinLabel } from '@/types/futures';

export interface TopSignal {
    symbol: string;
    direction: 'LONG' | 'SHORT';
    confidence_score: number;
    analyzed_at: string;
}

interface Props {
    signals: TopSignal[];
}

function timeAgo(iso: string): string {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}m ago`;
}

export function TopSignals({ signals }: Props) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                Top 10 Right Now
            </p>
            <p className="text-[11px] text-muted-foreground">
                Best bot confidence scores from the last 30 minutes.
            </p>

            {signals.length === 0 ? (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    No fresh signals yet — the bot hasn't scored anything in the last 30 minutes.
                </p>
            ) : (
                <ol className="flex flex-col gap-1.5">
                    {signals.map((s, i) => {
                        const isLong = s.direction === 'LONG';

                        return (
                            <li
                                key={s.symbol}
                                className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2.5 py-1.5"
                            >
                                <span className="w-4 shrink-0 text-[11px] font-medium text-muted-foreground">
                                    {i + 1}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">
                                    {coinLabel(s.symbol)}
                                </span>
                                <span
                                    className={`shrink-0 text-[11px] font-bold ${isLong ? 'text-emerald-500' : 'text-red-500'}`}
                                >
                                    {s.direction}
                                </span>
                                <span className="shrink-0 rounded-full bg-background px-2 py-0.5 text-[11px] font-bold tabular-nums text-foreground">
                                    {s.confidence_score}
                                </span>
                                <span className="shrink-0 text-[10px] text-muted-foreground">
                                    {timeAgo(s.analyzed_at)}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
