import { coinLabel } from '@/types/futures';
import type { AccountAsset, Position } from '@/types/futures';

const MILESTONE = 1500;

export interface RecentClosedTrade {
    symbol: string;
    pnl: number;
    closedAt: number;
}

export interface TodayPnl {
    realized: number;
    realizedWon: number;
    realizedLost: number;
    wonCount: number;
    lostCount: number;
    unrealized: number;
    total: number;
    openCount: number;
    recentClosed: RecentClosedTrade[];
    timestamp: number;
}

interface Props {
    account: AccountAsset[];
    positions: Position[];
    todayPnl?: TodayPnl | null;
}

export function SummaryBar({ account, positions, todayPnl }: Props) {
    const totalPnl = positions.reduce((sum, p) => sum + (p.unrealizedPnl ?? 0), 0);
    const longPositions = positions.filter((p) => p.positionType === 1);
    const shortPositions = positions.filter((p) => p.positionType === 2);
    const longValue = longPositions.reduce((sum, p) => sum + (p.positionValue ?? 0), 0);
    const shortValue = shortPositions.reduce((sum, p) => sum + (p.positionValue ?? 0), 0);
    const totalValue = longValue - shortValue;
    const equity = account.find((a) => a.currency === 'USDT')?.equity ?? 0;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    const milestoneProgress = Math.min((equity / MILESTONE) * 100, 100);
    const milestoneReached = equity >= MILESTONE;

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
                    <span className="text-emerald-500">↑ {fmt(longValue)} ({longPositions.length})</span>
                    <span className="text-muted-foreground">·</span>
                    <span className="text-red-500">↓ {fmt(shortValue)} ({shortPositions.length})</span>
                </div>
            </div>

            {/* Unrealized PNL */}
            <div className="rounded-xl border border-border bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">Unrealized PNL</p>
                <p className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${totalPnl > 0 ? 'text-emerald-500' : totalPnl < 0 ? 'text-red-500' : 'text-foreground'}`}>
                    {totalPnl >= 0 ? '+' : ''}{fmt(totalPnl)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">USDT</span>
                </p>
                {todayPnl && (
                    <div className="mt-2 flex items-center gap-3 text-xs tabular-nums">
                        <span className="text-emerald-500">
                            Won +{fmt(todayPnl.realizedWon)} ({todayPnl.wonCount})
                        </span>
                        <span className="text-muted-foreground">·</span>
                        <span className="text-red-500">
                            Lost {fmt(todayPnl.realizedLost)} ({todayPnl.lostCount})
                        </span>
                    </div>
                )}
                {todayPnl && todayPnl.recentClosed.length > 0 && (
                    <div className="mt-2 flex flex-col gap-0.5 border-t border-border pt-2">
                        {todayPnl.recentClosed.map((t, i) => (
                            <div
                                key={`${t.symbol}-${t.closedAt}-${i}`}
                                className="flex items-center justify-between text-[11px] tabular-nums"
                            >
                                <span className="text-muted-foreground">{coinLabel(t.symbol)}</span>
                                <span className={t.pnl >= 0 ? 'text-emerald-500' : 'text-red-500'}>
                                    {t.pnl >= 0 ? '+' : ''}{fmt(t.pnl)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
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
