import type { AccountAsset, Position } from '@/types/futures';

const MILESTONE = 1500;

interface Props {
    account: AccountAsset[];
    positions: Position[];
}

export function SummaryBar({ account, positions }: Props) {
    const totalPnl  = positions.reduce((sum, p) => sum + (p.unrealizedPnl ?? 0), 0);
    const longValue = positions.filter(p => p.positionType === 1).reduce((sum, p) => sum + (p.positionValue ?? 0), 0);
    const shortValue = positions.filter(p => p.positionType === 2).reduce((sum, p) => sum + (p.positionValue ?? 0), 0);
    const totalValue = longValue - shortValue;
    const equity    = account.find(a => a.currency === 'USDT')?.equity ?? 0;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    const milestoneProgress = Math.min((equity / MILESTONE) * 100, 100);
    const milestoneReached  = equity >= MILESTONE;

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {/* Total Positions + long/short breakdown */}
            <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">Total Positions</p>
                <p className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${totalValue > 0 ? 'text-emerald-500' : totalValue < 0 ? 'text-red-500' : 'text-foreground'}`}>
                    {totalValue >= 0 ? '+' : ''}{fmt(totalValue)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">USDT</span>
                </p>
                <div className="mt-2 flex items-center gap-3 text-xs tabular-nums">
                    <span className="text-emerald-500">↑ {fmt(longValue)}</span>
                    <span className="text-muted-foreground">·</span>
                    <span className="text-red-500">↓ {fmt(shortValue)}</span>
                </div>
            </div>

            {/* Unrealized PNL */}
            <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">Unrealized PNL</p>
                <p className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${totalPnl > 0 ? 'text-emerald-500' : totalPnl < 0 ? 'text-red-500' : 'text-foreground'}`}>
                    {totalPnl >= 0 ? '+' : ''}{fmt(totalPnl)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">USDT</span>
                </p>
            </div>

            {/* Total Equity + milestone bar */}
            <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">Total Equity</p>
                <p className="mt-1 text-xl font-semibold tabular-nums text-foreground sm:text-2xl">
                    {fmt(equity)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">USDT</span>
                </p>
                {/* Milestone bar */}
                <div className="mt-2">
                    <div className="mb-1 flex items-center justify-between text-[10px] text-muted-foreground">
                        <span className={milestoneReached ? 'font-semibold text-emerald-500' : ''}>
                            {milestoneReached ? '🎯 $1,500 reached!' : `Milestone $${MILESTONE.toLocaleString()}`}
                        </span>
                        <span className="tabular-nums">{milestoneProgress.toFixed(1)}%</span>
                    </div>
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full transition-all duration-500 ${milestoneReached ? 'bg-emerald-500' : 'bg-amber-500'}`}
                            style={{ width: `${milestoneProgress}%` }}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
