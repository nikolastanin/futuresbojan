import { Layers, Target, Wallet } from 'lucide-react';
import { coinLabel } from '@/types/futures';
import type { AccountAsset, Position } from '@/types/futures';

const MILESTONES = [250, 500, 1000, 1500];
const FINAL_MILESTONE = MILESTONES[MILESTONES.length - 1];

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

export interface BotCapacity {
    open: number;
    max: number;
    marginCommitted: number;
    marginMax: number;
}

interface Props {
    account: AccountAsset[];
    positions: Position[];
    todayPnl?: TodayPnl | null;
    botCapacity?: BotCapacity | null;
}

export function SummaryBar({
    account,
    positions,
    todayPnl,
    botCapacity,
}: Props) {
    const totalPnl = positions.reduce(
        (sum, p) => sum + (p.unrealizedPnl ?? 0),
        0,
    );
    const longPositions = positions.filter((p) => p.positionType === 1);
    const shortPositions = positions.filter((p) => p.positionType === 2);
    const longValue = longPositions.reduce(
        (sum, p) => sum + (p.positionValue ?? 0),
        0,
    );
    const shortValue = shortPositions.reduce(
        (sum, p) => sum + (p.positionValue ?? 0),
        0,
    );
    const totalValue = longValue - shortValue;
    const equity = account.find((a) => a.currency === 'USDT')?.equity ?? 0;
    const topMovers = [...positions]
        .sort(
            (a, b) =>
                Math.abs(b.unrealizedPnl ?? 0) - Math.abs(a.unrealizedPnl ?? 0),
        )
        .slice(0, 5);
    const slotsLeft = botCapacity
        ? Math.max(0, botCapacity.max - botCapacity.open)
        : null;
    const marginLeft = botCapacity
        ? Math.max(0, botCapacity.marginMax - botCapacity.marginCommitted)
        : null;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(n);

    const nextMilestone = MILESTONES.find((m) => equity < m) ?? FINAL_MILESTONE;
    const allMilestonesReached = equity >= FINAL_MILESTONE;
    const milestoneProgress = Math.min((equity / FINAL_MILESTONE) * 100, 100);

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {/* Total Positions + long/short breakdown */}
            <div className="rounded-xl border border-t-2 border-border border-t-sky-500 bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="flex items-center gap-1.5 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    <Layers className="size-3.5 text-sky-500" />
                    Total Positions
                </p>
                <p
                    className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${totalValue > 0 ? 'text-emerald-500' : totalValue < 0 ? 'text-red-500' : 'text-foreground'}`}
                >
                    {totalValue >= 0 ? '+' : ''}
                    {fmt(totalValue)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                        USDT
                    </span>
                </p>
                <div className="mt-2 flex items-center gap-3 text-xs tabular-nums">
                    <span className="text-emerald-500">
                        ↑ {fmt(longValue)} ({longPositions.length})
                    </span>
                    <span className="text-muted-foreground">·</span>
                    <span className="text-red-500">
                        ↓ {fmt(shortValue)} ({shortPositions.length})
                    </span>
                </div>
                {topMovers.length > 0 && (
                    <div className="mt-2 flex flex-col gap-0.5 border-t border-border pt-2">
                        {topMovers.map((p) => (
                            <div
                                key={p.positionId}
                                className="flex items-center justify-between text-[11px] tabular-nums"
                            >
                                <span className="text-muted-foreground">
                                    {coinLabel(p.symbol)}
                                </span>
                                <span
                                    className={
                                        (p.unrealizedPnl ?? 0) >= 0
                                            ? 'text-emerald-500'
                                            : 'text-red-500'
                                    }
                                >
                                    {(p.unrealizedPnl ?? 0) >= 0 ? '+' : ''}
                                    {fmt(p.unrealizedPnl ?? 0)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Unrealized PNL */}
            <div className="rounded-xl border border-t-2 border-border border-t-violet-500 bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="flex items-center gap-1.5 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    <Wallet className="size-3.5 text-violet-500" />
                    Unrealized PNL
                </p>
                <p
                    className={`mt-1 text-xl font-semibold tabular-nums sm:text-2xl ${totalPnl > 0 ? 'text-emerald-500' : totalPnl < 0 ? 'text-red-500' : 'text-foreground'}`}
                >
                    {totalPnl >= 0 ? '+' : ''}
                    {fmt(totalPnl)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                        USDT
                    </span>
                </p>
                {todayPnl && (
                    <div className="mt-2 flex items-center gap-3 text-xs tabular-nums">
                        <span className="text-emerald-500">
                            Won +{fmt(todayPnl.realizedWon)} (
                            {todayPnl.wonCount})
                        </span>
                        <span className="text-muted-foreground">·</span>
                        <span className="text-red-500">
                            Lost {fmt(todayPnl.realizedLost)} (
                            {todayPnl.lostCount})
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
                                <span className="text-muted-foreground">
                                    {coinLabel(t.symbol)}
                                </span>
                                <span
                                    className={
                                        t.pnl >= 0
                                            ? 'text-emerald-500'
                                            : 'text-red-500'
                                    }
                                >
                                    {t.pnl >= 0 ? '+' : ''}
                                    {fmt(t.pnl)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Total Equity + milestone bar */}
            <div className="rounded-xl border border-t-2 border-border border-t-amber-500 bg-card px-4 py-3 sm:px-5 sm:py-4">
                <p className="flex items-center gap-1.5 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    <Target className="size-3.5 text-amber-500" />
                    Total Equity
                </p>
                <p className="mt-1 text-xl font-semibold text-foreground tabular-nums sm:text-2xl">
                    {fmt(equity)}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                        USDT
                    </span>
                </p>
                {/* Milestone bar */}
                <div className="mt-2">
                    <div className="mb-1 flex items-center justify-between text-[10px] text-muted-foreground">
                        <span
                            className={
                                allMilestonesReached
                                    ? 'font-semibold text-emerald-500'
                                    : ''
                            }
                        >
                            {allMilestonesReached
                                ? `🎯 $${FINAL_MILESTONE.toLocaleString()} reached!`
                                : `Next milestone: $${nextMilestone.toLocaleString()}`}
                        </span>
                        <span className="tabular-nums">
                            {milestoneProgress.toFixed(1)}%
                        </span>
                    </div>
                    <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full transition-all duration-500 ${allMilestonesReached ? 'bg-emerald-500' : 'bg-amber-500'}`}
                            style={{ width: `${milestoneProgress}%` }}
                        />
                        {MILESTONES.slice(0, -1).map((m) => (
                            <div
                                key={m}
                                className="absolute top-0 h-full w-px bg-background/70"
                                style={{
                                    left: `${(m / FINAL_MILESTONE) * 100}%`,
                                }}
                            />
                        ))}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-1.5 text-[9px] text-muted-foreground tabular-nums">
                        {MILESTONES.map((m, i) => {
                            const reached = equity >= m;

                            return (
                                <span
                                    key={m}
                                    className="flex items-center gap-1.5"
                                >
                                    {i > 0 && (
                                        <span className="opacity-40">·</span>
                                    )}
                                    <span
                                        className={
                                            reached
                                                ? 'font-semibold text-emerald-500'
                                                : m === nextMilestone
                                                  ? 'font-semibold text-amber-500'
                                                  : ''
                                        }
                                    >
                                        ${m.toLocaleString()}
                                        {reached ? ' ✓' : ''}
                                    </span>
                                </span>
                            );
                        })}
                    </div>
                </div>
                {botCapacity && (
                    <div className="mt-2 flex flex-col gap-0.5 border-t border-border pt-2 text-[11px] text-muted-foreground">
                        <p>
                            <span
                                className={
                                    slotsLeft === 0
                                        ? 'font-semibold text-red-500'
                                        : 'font-semibold text-foreground'
                                }
                            >
                                {slotsLeft}
                            </span>{' '}
                            slot{slotsLeft === 1 ? '' : 's'} left for new bot
                            positions ({botCapacity.open}/{botCapacity.max})
                        </p>
                        <p>
                            <span
                                className={
                                    marginLeft === 0
                                        ? 'font-semibold text-red-500'
                                        : 'font-semibold text-foreground'
                                }
                            >
                                ${fmt(marginLeft ?? 0)}
                            </span>{' '}
                            margin left for new bot trades ($
                            {fmt(botCapacity.marginCommitted)}/$
                            {fmt(botCapacity.marginMax)})
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
