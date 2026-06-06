import { useCallback, useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, TrendingUp, TrendingDown, Calendar, Trophy, AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { pnl as pnlRoute } from '@/routes';
import { todayPnl as todayPnlRoute } from '@/routes/futures';

interface Props {
    year:         number;
    month:        number;
    dailyPnl:     Record<string, number>;
    monthlyTotal: number;
    winDays:      number;
    lossDays:     number;
    bestDay:      number;
    worstDay:     number;
}

interface TodayPnl {
    realized:   number;
    unrealized: number;
    total:      number;
    openCount:  number;
    timestamp:  number;
}

const MONTH_NAMES = [
    'January','February','March','April','May','June',
    'July','August','September','October','November','December',
];
const DAY_LABELS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function fmt(n: number, decimals = 2) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(n);
}

function fmtSigned(n: number) {
    return `${n >= 0 ? '+' : ''}${fmt(n)}`;
}

export default function PnlPage({
    year, month, dailyPnl, monthlyTotal, winDays, lossDays, bestDay, worstDay,
}: Props) {
    const [loading, setLoading]     = useState(false);
    const [today, setToday]         = useState<TodayPnl | null>(null);
    const [syncing, setSyncing]     = useState(false);
    const [lastSync, setLastSync]   = useState<Date | null>(null);

    const fetchToday = useCallback(async () => {
        setSyncing(true);
        try {
            const res  = await fetch(todayPnlRoute.url(), { headers: { Accept: 'application/json' } });
            const json = await res.json();
            if (json.success) {
                setToday(json.data);
                setLastSync(new Date());
            }
        } catch { /* silent */ } finally {
            setSyncing(false);
        }
    }, []);

    useEffect(() => {
        fetchToday();
        const id = setInterval(fetchToday, 15_000);
        return () => clearInterval(id);
    }, [fetchToday]);

    const navigate = (y: number, m: number) => {
        setLoading(true);
        router.visit(pnlRoute.url({ query: { year: y, month: m } }), {
            onFinish: () => setLoading(false),
        });
    };

    const prev = () => month === 1 ? navigate(year - 1, 12) : navigate(year, month - 1);
    const next = () => month === 12 ? navigate(year + 1, 1)  : navigate(year, month + 1);

    const isCurrentMonth = year === new Date().getFullYear() && month === new Date().getMonth() + 1;

    // Build calendar grid — weeks start Monday
    const firstDay  = new Date(year, month - 1, 1);
    const daysInMonth = new Date(year, month, 0).getDate();
    // getDay(): 0=Sun…6=Sat → convert to Mon-first: Mon=0…Sun=6
    const startOffset = (firstDay.getDay() + 6) % 7;
    const totalCells  = Math.ceil((startOffset + daysInMonth) / 7) * 7;

    const cells: (number | null)[] = [
        ...Array(startOffset).fill(null),
        ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
        ...Array(totalCells - startOffset - daysInMonth).fill(null),
    ];

    const maxAbsPnl = Math.max(...Object.values(dailyPnl).map(Math.abs), 1);

    return (
        <>
            <Head title="PNL Calendar" />
            <div className="flex flex-1 flex-col gap-6 p-4 overflow-x-auto">

                {/* ── Today live PNL ── */}
                <div className="rounded-xl border border-border bg-card">
                    <div className="flex items-center justify-between border-b border-border px-5 py-3">
                        <div className="flex items-center gap-2">
                            <span className="relative flex size-2">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                            </span>
                            <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                                Today's Live PNL
                            </p>
                        </div>
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <RefreshCw className={`size-3 ${syncing ? 'animate-spin text-emerald-500' : ''}`} />
                            {lastSync ? lastSync.toLocaleTimeString() : 'Loading…'}
                            <span className="opacity-40">· 15s</span>
                            <button
                                onClick={fetchToday}
                                disabled={syncing}
                                className="ml-1 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:opacity-40"
                            >
                                Sync now
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 divide-x divide-border sm:grid-cols-4">
                        <TodayCell
                            label="Total PNL"
                            value={today ? fmtSigned(today.total) : '—'}
                            positive={today ? today.total > 0 : undefined}
                            negative={today ? today.total < 0 : undefined}
                            large
                        />
                        <TodayCell
                            label="Realized"
                            value={today ? fmtSigned(today.realized) : '—'}
                            positive={today ? today.realized > 0 : undefined}
                            negative={today ? today.realized < 0 : undefined}
                        />
                        <TodayCell
                            label="Unrealized"
                            value={today ? fmtSigned(today.unrealized) : '—'}
                            positive={today ? today.unrealized > 0 : undefined}
                            negative={today ? today.unrealized < 0 : undefined}
                        />
                        <TodayCell
                            label="Open Positions"
                            value={today ? String(today.openCount) : '—'}
                        />
                    </div>
                </div>

                {/* ── Monthly summary cards ── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <SummaryCard
                        label="Monthly PNL"
                        value={fmtSigned(monthlyTotal)}
                        unit="USDT"
                        positive={monthlyTotal > 0}
                        negative={monthlyTotal < 0}
                        icon={monthlyTotal >= 0 ? TrendingUp : TrendingDown}
                        large
                    />
                    <SummaryCard label="Win Days"   value={String(winDays)}      unit="days" icon={Trophy}       positive />
                    <SummaryCard label="Loss Days"  value={String(lossDays)}     unit="days" icon={AlertTriangle} negative={lossDays > 0} />
                    <SummaryCard label="Best Day"   value={fmtSigned(bestDay)}   unit="USDT" icon={TrendingUp}   positive={bestDay > 0} />
                    <SummaryCard label="Worst Day"  value={fmtSigned(worstDay)}  unit="USDT" icon={TrendingDown}  negative={worstDay < 0} />
                </div>

                {/* ── Calendar ── */}
                <div className="rounded-xl border border-border bg-card">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-border px-5 py-4">
                        <div className="flex items-center gap-2">
                            <Calendar className="size-4 text-muted-foreground" />
                            <h2 className="text-base font-semibold text-foreground">
                                {MONTH_NAMES[month - 1]} {year}
                            </h2>
                            {loading && <span className="text-xs text-muted-foreground">Loading…</span>}
                        </div>
                        <div className="flex items-center gap-1">
                            <Button variant="ghost" size="icon" className="size-8" onClick={prev}>
                                <ChevronLeft className="size-4" />
                            </Button>
                            {!isCurrentMonth && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-xs text-muted-foreground"
                                    onClick={() => navigate(new Date().getFullYear(), new Date().getMonth() + 1)}
                                >
                                    Today
                                </Button>
                            )}
                            <Button variant="ghost" size="icon" className="size-8" onClick={next} disabled={isCurrentMonth}>
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    </div>

                    {/* Day labels */}
                    <div className="grid grid-cols-7 border-b border-border">
                        {DAY_LABELS.map(d => (
                            <div key={d} className="py-2 text-center text-xs font-medium text-muted-foreground">
                                {d}
                            </div>
                        ))}
                    </div>

                    {/* Cells */}
                    <div className="grid grid-cols-7">
                        {cells.map((day, idx) => {
                            if (day === null) {
                                return <div key={`empty-${idx}`} className="min-h-[72px] border-b border-r border-border last:border-r-0 bg-muted/20" />;
                            }

                            const dateKey = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                            const pnl     = dailyPnl[dateKey] ?? null;
                            const isToday = isCurrentMonth && day === new Date().getDate();

                            // Intensity: 0–1 relative to max abs PNL this month
                            const intensity = pnl !== null ? Math.min(Math.abs(pnl) / maxAbsPnl, 1) : 0;

                            let bgClass = '';
                            let textClass = 'text-foreground';

                            if (pnl !== null && pnl > 0) {
                                bgClass   = intensity > 0.66 ? 'bg-emerald-500/30' : intensity > 0.33 ? 'bg-emerald-500/18' : 'bg-emerald-500/10';
                                textClass = 'text-emerald-600 dark:text-emerald-400';
                            } else if (pnl !== null && pnl < 0) {
                                bgClass   = intensity > 0.66 ? 'bg-red-500/30' : intensity > 0.33 ? 'bg-red-500/18' : 'bg-red-500/10';
                                textClass = 'text-red-600 dark:text-red-400';
                            }

                            const isLastRow = idx >= cells.length - 7;
                            const isLastCol = (idx + 1) % 7 === 0;

                            return (
                                <div
                                    key={dateKey}
                                    className={`
                                        relative flex min-h-[72px] flex-col p-2
                                        ${!isLastRow ? 'border-b' : ''} border-border
                                        ${!isLastCol ? 'border-r' : ''} border-border
                                        ${bgClass}
                                        transition-colors
                                    `}
                                >
                                    {/* Day number */}
                                    <span className={`
                                        mb-auto self-start text-xs font-medium leading-none
                                        ${isToday
                                            ? 'flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground'
                                            : 'text-muted-foreground'}
                                    `}>
                                        {day}
                                    </span>

                                    {/* PNL value */}
                                    {pnl !== null && (
                                        <span className={`mt-1 text-xs font-semibold tabular-nums ${textClass}`}>
                                            {fmtSigned(pnl)}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

            </div>
        </>
    );
}

PnlPage.layout = {
    breadcrumbs: [
        { title: 'PNL Calendar', href: '/pnl' },
    ],
};

// ── Today cell ───────────────────────────────────────────────────────────────

function TodayCell({ label, value, positive, negative, large }: {
    label: string; value: string; positive?: boolean; negative?: boolean; large?: boolean;
}) {
    const color = positive ? 'text-emerald-600 dark:text-emerald-400'
        : negative ? 'text-red-600 dark:text-red-400'
        : 'text-foreground';
    return (
        <div className="px-5 py-4">
            <p className="mb-1 text-xs text-muted-foreground">{label}</p>
            <p className={`${large ? 'text-2xl' : 'text-xl'} font-semibold tabular-nums ${color}`}>{value}
                <span className="ml-1 text-xs font-normal text-muted-foreground">USDT</span>
            </p>
        </div>
    );
}

// ── Summary card ──────────────────────────────────────────────────────────────

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
                <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">{label}</p>
                <Icon className={`size-4 ${iconColor}`} />
            </div>
            <p className={`${large ? 'text-2xl' : 'text-xl'} font-semibold tabular-nums ${valueColor}`}>
                {value}
                <span className="ml-1 text-xs font-normal text-muted-foreground">{unit}</span>
            </p>
        </div>
    );
}
