import type { PaperPosition } from '@/types/futures';

interface Props {
    positions: PaperPosition[];
}

/** Same shape as SummaryBar's Total Positions / Unrealized PNL cards, but for
 * simulated paper positions — kept as its own strip so it's never confused
 * with the real-money numbers above it. */
export function PaperSummaryBar({ positions }: Props) {
    const totalPnl = positions.reduce((sum, p) => sum + (p.unrealized_pnl ?? 0), 0);
    const longValue = positions
        .filter((p) => p.direction === 'LONG')
        .reduce((sum, p) => sum + p.margin_usdt * p.leverage, 0);
    const shortValue = positions
        .filter((p) => p.direction === 'SHORT')
        .reduce((sum, p) => sum + p.margin_usdt * p.leverage, 0);
    const totalValue = longValue - shortValue;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    return (
        <div className="flex flex-col gap-2">
            <p className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                Paper Trading
            </p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                    <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
                        Total Positions
                    </p>
                    <p
                        className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${
                            totalValue > 0 ? 'text-emerald-500' : totalValue < 0 ? 'text-red-500' : 'text-foreground'
                        }`}
                    >
                        {positions.length}
                        <span className="ml-1 text-sm font-normal text-muted-foreground">open</span>
                    </p>
                    <div className="mt-2 flex items-center gap-3 text-xs tabular-nums">
                        <span className="text-emerald-500">↑ {fmt(longValue)}</span>
                        <span className="text-muted-foreground">·</span>
                        <span className="text-red-500">↓ {fmt(shortValue)}</span>
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                    <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
                        Unrealized PNL
                    </p>
                    <p
                        className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${
                            totalPnl > 0 ? 'text-emerald-500' : totalPnl < 0 ? 'text-red-500' : 'text-foreground'
                        }`}
                    >
                        {totalPnl >= 0 ? '+' : ''}
                        {fmt(totalPnl)}
                        <span className="ml-1 text-sm font-normal text-muted-foreground">USDT</span>
                    </p>
                </div>
            </div>
        </div>
    );
}
