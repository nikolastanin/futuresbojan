import {
    Calendar as CalendarIcon,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { pnlCalendar as pnlCalendarRoute } from '@/routes/futures';
import { coinLabel } from '@/types/futures';

interface PnlCalendarPosition {
    symbol: string;
    pnl: number;
    closedAt: number;
}

interface PnlCalendarDay {
    date: string; // YYYY-MM-DD, UTC
    realized: number;
    realizedWon: number;
    realizedLost: number;
    wonCount: number;
    lostCount: number;
    positions: PnlCalendarPosition[];
}

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const fmt = (n: number) =>
    new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n);

function monthLabel(year: number, month: number): string {
    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * The classic "trading calendar" — one cell per day of the month, colored by that
 * day's realized PNL. Fetches on demand per month (not part of the page's initial
 * load) since a full month of closed positions is a meaningfully bigger MEXC query
 * than the recent-days tracker above it.
 */
export function PnlCalendar() {
    const now = new Date();
    const [year, setYear] = useState(now.getUTCFullYear());
    const [month, setMonth] = useState(now.getUTCMonth() + 1); // 1-12
    const [days, setDays] = useState<PnlCalendarDay[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<PnlCalendarDay | null>(null);

    useEffect(() => {
        // Reacting to year/month changing by kicking off a fetch, not state derivable
        // during render — the same "external trigger" shape as OrderForm's prefill effect.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        setSelected(null);

        fetch(`${pnlCalendarRoute.url()}?year=${year}&month=${month}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((json) => {
                if (json.success) {
                    setDays(json.data);
                }
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [year, month]);

    const goPrev = () => {
        if (month === 1) {
            setYear((y) => y - 1);
            setMonth(12);
        } else {
            setMonth((m) => m - 1);
        }
    };

    const goNext = () => {
        if (month === 12) {
            setYear((y) => y + 1);
            setMonth(1);
        } else {
            setMonth((m) => m + 1);
        }
    };

    // Monday-first grid: JS getUTCDay() is 0=Sunday, shift so Monday=0.
    const firstWeekday = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
    const leadingEmpty = (firstWeekday + 6) % 7;

    const monthTotal = (days ?? []).reduce((sum, d) => sum + d.realized, 0);

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-1.5 text-base font-semibold text-foreground sm:text-lg">
                    <CalendarIcon className="size-4 text-emerald-500" />
                    PNL Calendar
                </h2>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={goPrev}
                        className="rounded border border-border p-1 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ChevronLeft className="size-4" />
                    </button>
                    <span className="w-32 text-center text-sm font-medium text-foreground">
                        {monthLabel(year, month)}
                    </span>
                    <button
                        type="button"
                        onClick={goNext}
                        className="rounded border border-border p-1 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                </div>
                {days && (
                    <span
                        className={`text-sm font-bold tabular-nums ${
                            monthTotal > 0
                                ? 'text-emerald-500'
                                : monthTotal < 0
                                  ? 'text-red-500'
                                  : 'text-muted-foreground'
                        }`}
                    >
                        {monthTotal >= 0 ? '+' : ''}
                        {fmt(monthTotal)}
                    </span>
                )}
            </div>

            <div className="grid grid-cols-7 gap-1 text-center text-[10px] text-muted-foreground">
                {WEEKDAYS.map((w) => (
                    <div key={w}>{w}</div>
                ))}
            </div>

            <div className="grid grid-cols-7 gap-1">
                {Array.from({ length: leadingEmpty }).map((_, i) => (
                    <div key={`empty-${i}`} />
                ))}
                {(days ?? []).map((d) => {
                    const dayNum = parseInt(d.date.slice(8, 10), 10);
                    const hasActivity = d.wonCount + d.lostCount > 0;
                    const isSelected = selected?.date === d.date;

                    return (
                        <button
                            type="button"
                            key={d.date}
                            disabled={!hasActivity}
                            onClick={() =>
                                setSelected((s) =>
                                    s?.date === d.date ? null : d,
                                )
                            }
                            className={`flex flex-col items-center justify-center gap-0.5 rounded-md border px-1 py-1.5 text-[10px] transition-colors ${
                                isSelected
                                    ? 'border-foreground'
                                    : !hasActivity
                                      ? 'border-border bg-muted/20'
                                      : d.realized > 0
                                        ? 'border-emerald-500/40 bg-emerald-500/10 hover:border-emerald-500'
                                        : 'border-red-500/40 bg-red-500/10 hover:border-red-500'
                            }`}
                        >
                            <span className="text-muted-foreground">
                                {dayNum}
                            </span>
                            {hasActivity && (
                                <span
                                    className={`font-bold tabular-nums ${
                                        d.realized > 0
                                            ? 'text-emerald-500'
                                            : d.realized < 0
                                              ? 'text-red-500'
                                              : 'text-muted-foreground'
                                    }`}
                                >
                                    {d.realized >= 0 ? '+' : ''}
                                    {fmt(d.realized)}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {loading && (
                <p className="text-center text-[11px] text-muted-foreground">
                    Loading…
                </p>
            )}

            {selected && (
                <div className="flex flex-col gap-1.5 rounded-md border border-border bg-background p-2.5">
                    <div className="flex items-center justify-between text-xs">
                        <span className="font-semibold text-foreground">
                            {selected.date}
                        </span>
                        <span className="text-muted-foreground">
                            <span className="font-semibold text-emerald-500">
                                Won +{fmt(selected.realizedWon)} (
                                {selected.wonCount})
                            </span>
                            {' · '}
                            <span className="font-semibold text-red-500">
                                Lost {fmt(selected.realizedLost)} (
                                {selected.lostCount})
                            </span>
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {selected.positions.map((p, i) => (
                            <span
                                key={`${p.symbol}-${i}`}
                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                    p.pnl > 0
                                        ? 'bg-emerald-500/10 text-emerald-500'
                                        : 'bg-red-500/10 text-red-500'
                                }`}
                            >
                                {coinLabel(p.symbol)} {p.pnl > 0 ? '+' : ''}
                                {fmt(p.pnl)}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
