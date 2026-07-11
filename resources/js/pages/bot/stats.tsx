import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    ChevronLeft,
    ChevronRight,
    TrendingDown,
    TrendingUp,
    Trophy,
} from 'lucide-react';
import { useState } from 'react';
import { ReasonList } from '@/components/bot/reason-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { stats } from '@/routes/bot';

interface Overview {
    total_trades: number;
    win_rate: number;
    net_profit_usdt: number;
    total_fees_usdt: number;
    avg_win_usdt: number;
    avg_loss_usdt: number;
    best_trade_usdt: number;
    worst_trade_usdt: number;
}

interface OpenPosition {
    id: number;
    trade_set_id: string;
    leg: string;
    symbol: string;
    direction: string;
    mode: string;
    margin_usd: number;
    leverage: number;
    entry_price: number;
    current_price: number | null;
    take_profit: number | null;
    stop_loss: number | null;
    unrealized_pnl_usdt: number | null;
    target_net_profit_usdt: number;
    progress_to_target_pct: number | null;
    confidence_score: number;
    trailing_active: boolean;
    reasons: string[];
    opened_at: string;
}

interface OpenSummary {
    count: number;
    margin_deployed_usdt: number;
    unrealized_pnl_usdt: number;
}

interface CoinStat {
    symbol: string;
    pnl: number;
    trades: number;
    wins: number;
    losses: number;
    best: number | null;
    worst: number | null;
}

interface ClosedTrade {
    id: number;
    leg: string;
    symbol: string;
    direction: string;
    mode: string;
    margin_usd: number;
    leverage: number;
    entry_price: number;
    exit_price: number | null;
    fee_usdt: number | null;
    net_profit_usdt: number | null;
    confidence_score: number;
    close_reason: string | null;
    opened_at: string;
    closed_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    year: number;
    month: number;
    mode: string | null;
    symbol: string | null;
    direction: string | null;
    overview: Overview;
    openPositions: OpenPosition[];
    openSummary: OpenSummary;
    dailyPnl: Record<string, number>;
    coinStats: CoinStat[];
    trades: Paginated<ClosedTrade>;
}

const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];
const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function fmt(n: number, decimals = 2) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(n);
}

function fmtSigned(n: number) {
    return `${n >= 0 ? '+' : ''}${fmt(n)}`;
}

function pnlColor(n: number | null) {
    if (n === null) {
return 'text-muted-foreground';
}

    return n > 0
        ? 'text-emerald-600 dark:text-emerald-400'
        : n < 0
          ? 'text-red-600 dark:text-red-400'
          : 'text-foreground';
}

/** Turns progress_to_target_pct into a short human read on where the trade stands. */
function evaluationLabel(pct: number | null): string {
    if (pct === null) {
return 'Waiting on live price data';
}

    if (pct >= 100) {
return 'Target reached — closing imminent';
}

    if (pct >= 50) {
return `${pct.toFixed(0)}% to target — building nicely`;
}

    if (pct > 0) {
return `${pct.toFixed(0)}% to target`;
}

    if (pct === 0) {
return 'Flat — right at entry';
}

    if (pct > -50) {
return `${Math.abs(pct).toFixed(0)}% toward stop-loss`;
}

    return `${Math.abs(pct).toFixed(0)}% toward stop-loss — at risk`;
}

export default function BotStats({
    year, month, mode, symbol, direction,
    overview, openPositions, openSummary, dailyPnl, coinStats, trades,
}: Props) {
    const [tab, setTab] = useState<'calendar' | 'performance' | 'history'>('calendar');
    const [symbolInput, setSymbolInput] = useState(symbol ?? '');
    const [loading, setLoading] = useState(false);
    const [expandedPositionId, setExpandedPositionId] = useState<number | null>(null);

    function applyFilters(overrides: Record<string, string | number | undefined>) {
        setLoading(true);
        router.get(
            stats.url(),
            {
                year, month,
                ...(mode ? { mode } : {}),
                ...(symbol ? { symbol } : {}),
                ...(direction ? { direction } : {}),
                ...overrides,
            },
            { preserveState: true, preserveScroll: true, onFinish: () => setLoading(false) },
        );
    }

    function goToPage(url: string | null) {
        if (!url) {
return;
}

        setLoading(true);
        router.visit(url, { preserveState: true, preserveScroll: true, onFinish: () => setLoading(false) });
    }

    const prevMonth = () => applyFilters(month === 1 ? { year: year - 1, month: 12 } : { month: month - 1 });
    const nextMonth = () => applyFilters(month === 12 ? { year: year + 1, month: 1 } : { month: month + 1 });
    const isCurrentMonth = year === new Date().getFullYear() && month === new Date().getMonth() + 1;

    // Calendar grid — weeks start Monday
    const firstDay = new Date(year, month - 1, 1);
    const daysInMonth = new Date(year, month, 0).getDate();
    const startOffset = (firstDay.getDay() + 6) % 7;
    const totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;
    const cells: (number | null)[] = [
        ...Array(startOffset).fill(null),
        ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
        ...Array(totalCells - startOffset - daysInMonth).fill(null),
    ];
    const maxAbsPnl = Math.max(...Object.values(dailyPnl).map(Math.abs), 1);
    const monthlyTotal = Object.values(dailyPnl).reduce((a, b) => a + b, 0);
    const winDays = Object.values(dailyPnl).filter((v) => v > 0).length;
    const lossDays = Object.values(dailyPnl).filter((v) => v < 0).length;

    return (
        <>
            <Head title="Bot Stats & PNL" />

            <div className="flex h-full flex-1 flex-col gap-6 p-3 sm:p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Bot Stats &amp; PNL</h1>
                        <p className="text-sm text-muted-foreground">
                            Every position the bot has opened and closed — paper and real — with live-tracked PnL.
                        </p>
                    </div>
                    <Select
                        value={mode ?? 'all'}
                        onValueChange={(v) => applyFilters({ mode: v === 'all' ? undefined : v })}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All modes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All modes</SelectItem>
                            <SelectItem value="paper">Paper only</SelectItem>
                            <SelectItem value="real">Real only</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* ── Overview summary cards ── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4">
                    <SummaryCard
                        label="All-Time Net PNL"
                        value={fmtSigned(overview.net_profit_usdt)}
                        unit="USDT"
                        positive={overview.net_profit_usdt > 0}
                        negative={overview.net_profit_usdt < 0}
                        icon={overview.net_profit_usdt >= 0 ? TrendingUp : TrendingDown}
                        large
                    />
                    <SummaryCard
                        label="Closed Trades"
                        value={String(overview.total_trades)}
                        unit={`${overview.win_rate}% win`}
                        icon={Trophy}
                        positive={overview.win_rate >= 50}
                    />
                    <SummaryCard
                        label="Open Positions"
                        value={String(openSummary.count)}
                        unit={`$${fmt(openSummary.margin_deployed_usdt)} margin`}
                        icon={Calendar}
                    />
                    <SummaryCard
                        label="Unrealized PNL"
                        value={fmtSigned(openSummary.unrealized_pnl_usdt)}
                        unit="USDT (open)"
                        positive={openSummary.unrealized_pnl_usdt > 0}
                        negative={openSummary.unrealized_pnl_usdt < 0}
                        icon={openSummary.unrealized_pnl_usdt >= 0 ? TrendingUp : TrendingDown}
                    />
                    <SummaryCard
                        label="Avg Win"
                        value={fmtSigned(overview.avg_win_usdt)}
                        unit="USDT"
                        positive
                        icon={TrendingUp}
                    />
                    <SummaryCard
                        label="Avg Loss"
                        value={fmtSigned(overview.avg_loss_usdt)}
                        unit="USDT"
                        negative={overview.avg_loss_usdt < 0}
                        icon={TrendingDown}
                    />
                    <SummaryCard
                        label="Best Trade"
                        value={fmtSigned(overview.best_trade_usdt)}
                        unit="USDT"
                        positive
                        icon={Trophy}
                    />
                    <SummaryCard
                        label="Total Fees Paid"
                        value={fmt(overview.total_fees_usdt)}
                        unit="USDT"
                        icon={AlertTriangle}
                    />
                </div>

                {/* ── Open positions (always visible — every open position, live) ── */}
                <Card>
                    <CardHeader>
                        <CardTitle>Open Positions</CardTitle>
                        <CardDescription>
                            Every position the bot currently holds, with live unrealized PnL from the current market price.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {openPositions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No open positions right now.</p>
                        ) : (
                            <div className="flex flex-col gap-3">
                                {openPositions.map((p) => {
                                    const pct = p.progress_to_target_pct;
                                    const barWidth = pct !== null ? Math.min(Math.abs(pct), 100) / 2 : 0;
                                    const barPositive = pct !== null && pct >= 0;
                                    const expanded = expandedPositionId === p.id;

                                    return (
                                        <div key={p.id} className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                                            {/* Top row: identity + confidence + opened time */}
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-semibold text-foreground">{p.symbol}</span>
                                                    <span className={`text-xs font-bold ${p.direction === 'LONG' ? 'text-green-600' : 'text-red-500'}`}>
                                                        {p.direction}
                                                    </span>
                                                    <Badge variant={p.mode === 'real' ? 'default' : 'secondary'}>{p.mode}</Badge>
                                                    {p.leg !== 'main' && <Badge variant="outline">hedge</Badge>}
                                                    {p.trailing_active && <Badge variant="outline">trailing</Badge>}
                                                </div>
                                                <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                                    <span>Confidence {p.confidence_score}</span>
                                                    <span>{new Date(p.opened_at).toLocaleString()}</span>
                                                </div>
                                            </div>

                                            {/* ── Middle: trade evaluation ── */}
                                            <div className="my-2.5 rounded-md border border-border/60 bg-background/40 px-3 py-2">
                                                <div className="flex items-center justify-between gap-2">
                                                    <p className={`text-xs font-medium ${pnlColor(pct)}`}>
                                                        {evaluationLabel(pct)}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Target {fmtSigned(p.target_net_profit_usdt)} USDT
                                                    </p>
                                                </div>
                                                <div className="relative mt-1.5 h-2 w-full overflow-hidden rounded-full bg-muted">
                                                    <div className="absolute top-0 left-1/2 h-full w-px -translate-x-1/2 bg-border" />
                                                    {pct !== null && (
                                                        <div
                                                            className={`absolute top-0 h-full rounded-full ${barPositive ? 'bg-emerald-500' : 'bg-red-500'}`}
                                                            style={{
                                                                left: barPositive ? '50%' : `${50 - barWidth}%`,
                                                                width: `${barWidth}%`,
                                                            }}
                                                        />
                                                    )}
                                                </div>
                                                {p.reasons.length > 0 && (
                                                    <>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="mt-1.5 h-6 px-1.5 text-xs"
                                                            onClick={() => setExpandedPositionId(expanded ? null : p.id)}
                                                        >
                                                            {expanded ? 'Hide entry reasoning' : 'Why did the bot open this?'}
                                                        </Button>
                                                        {expanded && (
                                                            <ReasonList
                                                                reasons={p.reasons}
                                                                className="mt-1.5 border-t border-border/60 pt-1.5 text-xs text-muted-foreground"
                                                            />
                                                        )}
                                                    </>
                                                )}
                                            </div>

                                            {/* Bottom row: numbers */}
                                            <div className="flex flex-wrap items-center gap-4 text-sm">
                                                <div className="flex flex-col">
                                                    <span className="text-[10px] text-muted-foreground">Margin / Lev</span>
                                                    <span className="tabular-nums text-foreground">${fmt(p.margin_usd)} · {p.leverage}x</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[10px] text-muted-foreground">Entry / Current</span>
                                                    <span className="tabular-nums text-foreground">{p.entry_price} / {p.current_price ?? '—'}</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[10px] text-muted-foreground">TP / SL</span>
                                                    <span className="tabular-nums text-foreground">{p.take_profit ?? '—'} / {p.stop_loss ?? '—'}</span>
                                                </div>
                                                <div className="ml-auto flex flex-col items-end">
                                                    <span className="text-[10px] text-muted-foreground">Unrealized PNL</span>
                                                    <span className={`text-base font-semibold tabular-nums ${pnlColor(p.unrealized_pnl_usdt)}`}>
                                                        {p.unrealized_pnl_usdt !== null ? fmtSigned(p.unrealized_pnl_usdt) : '—'}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Tab switcher ── */}
                <div className="flex w-fit gap-1 rounded-lg border border-border bg-card p-1">
                    {(['calendar', 'performance', 'history'] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`rounded-md px-4 py-1.5 text-sm font-medium capitalize transition-colors ${
                                tab === t
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t === 'calendar' ? 'Calendar' : t === 'performance' ? 'Performance' : 'Trade History'}
                        </button>
                    ))}
                </div>

                {/* ── Calendar tab ── */}
                {tab === 'calendar' && (
                    <div className="rounded-xl border border-border bg-card">
                        <div className="flex items-center justify-between border-b border-border px-5 py-4">
                            <div className="flex items-center gap-2">
                                <Calendar className="size-4 text-muted-foreground" />
                                <h2 className="text-base font-semibold text-foreground">
                                    {MONTH_NAMES[month - 1]} {year}
                                </h2>
                                {loading && <span className="text-xs text-muted-foreground">Loading…</span>}
                                <span className={`ml-2 text-sm font-semibold tabular-nums ${pnlColor(monthlyTotal)}`}>
                                    {fmtSigned(monthlyTotal)} USDT
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    ({winDays}W / {lossDays}L)
                                </span>
                            </div>
                            <div className="flex items-center gap-1">
                                <Button variant="ghost" size="icon" className="size-8" onClick={prevMonth}>
                                    <ChevronLeft className="size-4" />
                                </Button>
                                {!isCurrentMonth && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-xs text-muted-foreground"
                                        onClick={() => applyFilters({ year: new Date().getFullYear(), month: new Date().getMonth() + 1 })}
                                    >
                                        Today
                                    </Button>
                                )}
                                <Button variant="ghost" size="icon" className="size-8" onClick={nextMonth} disabled={isCurrentMonth}>
                                    <ChevronRight className="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div className="grid grid-cols-7 border-b border-border">
                            {DAY_LABELS.map((d) => (
                                <div key={d} className="py-2 text-center text-xs font-medium text-muted-foreground">
                                    {d}
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7">
                            {cells.map((day, idx) => {
                                if (day === null) {
                                    return <div key={`empty-${idx}`} className="min-h-[72px] border-b border-r border-border bg-muted/20 last:border-r-0" />;
                                }

                                const dateKey = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                                const pnl = dailyPnl[dateKey] ?? null;
                                const isToday = isCurrentMonth && day === new Date().getDate();
                                const intensity = pnl !== null ? Math.min(Math.abs(pnl) / maxAbsPnl, 1) : 0;

                                let bgClass = '';
                                let textClass = 'text-foreground';

                                if (pnl !== null && pnl > 0) {
                                    bgClass = intensity > 0.66 ? 'bg-emerald-500/30' : intensity > 0.33 ? 'bg-emerald-500/18' : 'bg-emerald-500/10';
                                    textClass = 'text-emerald-600 dark:text-emerald-400';
                                } else if (pnl !== null && pnl < 0) {
                                    bgClass = intensity > 0.66 ? 'bg-red-500/30' : intensity > 0.33 ? 'bg-red-500/18' : 'bg-red-500/10';
                                    textClass = 'text-red-600 dark:text-red-400';
                                }

                                const isLastRow = idx >= cells.length - 7;
                                const isLastCol = (idx + 1) % 7 === 0;

                                return (
                                    <div
                                        key={dateKey}
                                        className={`relative flex min-h-[48px] flex-col p-1 sm:min-h-[72px] sm:p-2 ${!isLastRow ? 'border-b' : ''} border-border ${!isLastCol ? 'border-r' : ''} ${bgClass} transition-colors`}
                                    >
                                        <span
                                            className={`mb-auto self-start text-[10px] leading-none font-medium sm:text-xs ${
                                                isToday
                                                    ? 'flex size-4 items-center justify-center rounded-full bg-primary text-primary-foreground sm:size-5'
                                                    : 'text-muted-foreground'
                                            }`}
                                        >
                                            {day}
                                        </span>
                                        {pnl !== null && (
                                            <span className={`mt-0.5 text-[9px] font-semibold tabular-nums sm:mt-1 sm:text-xs ${textClass}`}>
                                                {fmtSigned(pnl)}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ── Performance tab ── */}
                {tab === 'performance' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Performance by Coin — {MONTH_NAMES[month - 1]} {year}</CardTitle>
                            <CardDescription>Closed trades only, grouped by pair for the selected month.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {coinStats.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No closed trades this month.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="py-2 pr-4 font-medium">Coin</th>
                                                <th className="py-2 pr-4 font-medium">PNL</th>
                                                <th className="py-2 pr-4 font-medium">Trades</th>
                                                <th className="py-2 pr-4 font-medium">Win</th>
                                                <th className="py-2 pr-4 font-medium">Loss</th>
                                                <th className="py-2 pr-4 font-medium">Win rate</th>
                                                <th className="py-2 pr-4 font-medium">Best</th>
                                                <th className="py-2 pr-4 font-medium">Worst</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {coinStats.map((c) => {
                                                const winRate = c.trades > 0 ? Math.round((c.wins / c.trades) * 100) : 0;

                                                return (
                                                    <tr key={c.symbol} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">{c.symbol}</td>
                                                        <td className={`py-2 pr-4 font-semibold tabular-nums ${pnlColor(c.pnl)}`}>
                                                            {fmtSigned(c.pnl)}
                                                        </td>
                                                        <td className="py-2 pr-4">{c.trades}</td>
                                                        <td className="py-2 pr-4 text-emerald-600 dark:text-emerald-400">{c.wins}</td>
                                                        <td className="py-2 pr-4 text-red-600 dark:text-red-400">{c.losses}</td>
                                                        <td className="py-2 pr-4">{winRate}%</td>
                                                        <td className="py-2 pr-4 text-emerald-600 dark:text-emerald-400">
                                                            {c.best !== null ? fmtSigned(c.best) : '—'}
                                                        </td>
                                                        <td className="py-2 pr-4 text-red-600 dark:text-red-400">
                                                            {c.worst !== null ? fmtSigned(c.worst) : '—'}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* ── Trade History tab (every closed position, paginated) ── */}
                {tab === 'history' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Closed Trade History</CardTitle>
                            <CardDescription>
                                Every position the bot has ever closed — {trades.total} total. Filter by symbol or direction.
                            </CardDescription>
                            <div className="flex flex-wrap gap-2 pt-2">
                                <Input
                                    value={symbolInput}
                                    onChange={(e) => setSymbolInput(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters({ symbol: symbolInput || undefined })}
                                    placeholder="Filter by symbol (e.g. BTC)"
                                    className="max-w-xs"
                                />
                                <Select
                                    value={direction ?? 'all'}
                                    onValueChange={(v) => applyFilters({ direction: v === 'all' ? undefined : v })}
                                >
                                    <SelectTrigger className="w-32">
                                        <SelectValue placeholder="Direction" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Any direction</SelectItem>
                                        <SelectItem value="LONG">LONG</SelectItem>
                                        <SelectItem value="SHORT">SHORT</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button type="button" size="sm" onClick={() => applyFilters({ symbol: symbolInput || undefined })}>
                                    Search
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {trades.data.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No closed trades found.</p>
                            ) : (
                                <>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="py-2 pr-4 font-medium">Symbol</th>
                                                    <th className="py-2 pr-4 font-medium">Dir</th>
                                                    <th className="py-2 pr-4 font-medium">Mode</th>
                                                    <th className="py-2 pr-4 font-medium">Entry / Exit</th>
                                                    <th className="py-2 pr-4 font-medium">Net PNL</th>
                                                    <th className="py-2 pr-4 font-medium">Fee</th>
                                                    <th className="py-2 pr-4 font-medium">Conf.</th>
                                                    <th className="py-2 pr-4 font-medium">Close reason</th>
                                                    <th className="py-2 pr-4 font-medium">Closed</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {trades.data.map((t) => (
                                                    <tr key={t.id} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">
                                                            {t.symbol}
                                                            {t.leg !== 'main' && (
                                                                <Badge variant="outline" className="ml-2">hedge</Badge>
                                                            )}
                                                        </td>
                                                        <td className={`py-2 pr-4 ${t.direction === 'LONG' ? 'text-green-600' : 'text-red-500'}`}>
                                                            {t.direction}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            <Badge variant={t.mode === 'real' ? 'default' : 'secondary'}>{t.mode}</Badge>
                                                        </td>
                                                        <td className="py-2 pr-4 text-muted-foreground">
                                                            {t.entry_price} / {t.exit_price ?? '—'}
                                                        </td>
                                                        <td className={`py-2 pr-4 font-semibold tabular-nums ${pnlColor(t.net_profit_usdt)}`}>
                                                            {t.net_profit_usdt !== null ? fmtSigned(t.net_profit_usdt) : '—'}
                                                        </td>
                                                        <td className="py-2 pr-4 text-muted-foreground">
                                                            {t.fee_usdt !== null ? fmt(t.fee_usdt) : '—'}
                                                        </td>
                                                        <td className="py-2 pr-4">{t.confidence_score}</td>
                                                        <td className="py-2 pr-4 text-muted-foreground">{t.close_reason ?? '—'}</td>
                                                        <td className="py-2 pr-4 text-muted-foreground">
                                                            {t.closed_at ? new Date(t.closed_at).toLocaleString() : '—'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-xs text-muted-foreground">
                                            Page {trades.current_page} of {trades.last_page} ({trades.total} trades)
                                        </p>
                                        <div className="flex flex-wrap gap-1">
                                            {trades.links.map((link, i) => (
                                                <Button
                                                    key={i}
                                                    variant={link.active ? 'default' : 'ghost'}
                                                    size="sm"
                                                    disabled={!link.url}
                                                    onClick={() => goToPage(link.url)}
                                                    className="min-w-8"
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

BotStats.layout = {
    breadcrumbs: [{ title: 'Bot Stats & PNL', href: stats.url() }],
};

function SummaryCard({
    label, value, unit, positive, negative, icon: Icon, large,
}: {
    label: string;
    value: string;
    unit: string;
    positive?: boolean;
    negative?: boolean;
    icon: React.ElementType;
    large?: boolean;
}) {
    const valueColor = positive ? 'text-emerald-600 dark:text-emerald-400'
        : negative ? 'text-red-600 dark:text-red-400'
        : 'text-foreground';
    const iconColor = positive ? 'text-emerald-500' : negative ? 'text-red-500' : 'text-muted-foreground';

    return (
        <div className="rounded-xl border border-border bg-card px-4 py-3">
            <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{label}</p>
                <Icon className={`size-4 ${iconColor}`} />
            </div>
            <p className={`${large ? 'text-2xl' : 'text-xl'} font-semibold tabular-nums ${valueColor}`}>
                {value}
                <span className="ml-1 text-xs font-normal text-muted-foreground">{unit}</span>
            </p>
        </div>
    );
}
