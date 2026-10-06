import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    GraduationCap,
    Info,
    RefreshCw,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { shiftDate, todayUtc, useDailyGrade } from '@/hooks/use-daily-grade';
import type {
    DailyGrade,
    GradeComponent,
    GradeTone,
} from '@/hooks/use-daily-grade';
import { coach as coachRoute } from '@/routes/futures/daily-grade';
import { coinLabel } from '@/types/futures';

export const LETTER_STYLE: Record<string, string> = {
    A: 'border-emerald-500/60 bg-emerald-500/10 text-emerald-500',
    B: 'border-lime-500/60 bg-lime-500/10 text-lime-500',
    C: 'border-amber-500/60 bg-amber-500/10 text-amber-500',
    D: 'border-orange-500/60 bg-orange-500/10 text-orange-500',
    F: 'border-red-500/60 bg-red-500/10 text-red-500',
};

const BAR_STYLE = (score: number) =>
    score >= 80
        ? 'bg-emerald-500'
        : score >= 60
          ? 'bg-amber-500'
          : 'bg-red-500';

const COMPONENT_META: Record<
    GradeComponent['name'],
    { label: string; hint: string; missing: string }
> = {
    result: {
        label: 'Result',
        hint: 'Profit factor of the day’s closed trades. Weighted lightest — a good decision can lose money.',
        missing: 'No closed trades.',
    },
    risk: {
        label: 'Risk',
        hint: 'Average win against average loss, and whether any single loss was outsized.',
        missing: 'No closed trades.',
    },
    patience: {
        label: 'Patience',
        hint: 'Trades closed within minutes, over-trading, locks released early, and attempts to touch a locked position. Leans toward the weakest part.',
        missing: 'No closed trades or locks.',
    },
    process: {
        label: 'Process',
        hint: 'Whether each entry was at a confirmed plan zone and with the 4H backdrop. Only exists for entries made after decision logging began.',
        missing: 'No logged entries yet.',
    },
};

const TONE_ICON: Record<GradeTone, { icon: typeof Info; cls: string }> = {
    warn: { icon: AlertTriangle, cls: 'text-amber-500' },
    info: { icon: Info, cls: 'text-muted-foreground' },
    good: { icon: CheckCircle2, cls: 'text-emerald-500' },
};

interface Coach {
    headline: string;
    went_well: string;
    cost_you: string;
    focus_tomorrow: string;
    estimated_cost_usd: number;
}

const fmtMoney = (n: number) =>
    `${n >= 0 ? '+' : '−'}$${Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const fmtHold = (minutes: number | null) => {
    if (minutes === null) {
        return '—';
    }

    if (minutes < 60) {
        return `${Math.round(minutes)}m`;
    }

    if (minutes < 1440) {
        return `${(minutes / 60).toFixed(1)}h`;
    }

    return `${(minutes / 1440).toFixed(1)}d`;
};

const shortDay = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
        weekday: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });

/**
 * The trader's mark for a day: a letter and score built from how the day was traded, not
 * just what it earned — Result, Risk, Patience and Process, each shown with its weight
 * and what drove it. The AI review only explains the grade; it can't change it.
 * Days are UTC, matching the PnL calendar.
 */
export function DailyGradeCard() {
    const today = todayUtc();
    const [date, setDate] = useState(today);
    const [showTrades, setShowTrades] = useState(false);
    const { grade, trend, error, loading, reload } = useDailyGrade(date);

    const stale = grade === null || grade.date !== date;

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-t-2 border-border border-t-violet-500 bg-card p-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <GraduationCap className="size-3.5 text-violet-500" />
                    Trader grade
                </p>

                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => setDate((d) => shiftDate(d, -1))}
                        aria-label="Previous day"
                        className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <ChevronLeft className="size-4" />
                    </button>
                    <span className="min-w-[8.5rem] text-center text-xs font-medium text-foreground tabular-nums">
                        {date === today ? 'Today · ' : ''}
                        {new Date(`${date}T00:00:00Z`).toLocaleDateString(
                            'en-US',
                            {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric',
                                timeZone: 'UTC',
                            },
                        )}
                    </span>
                    <button
                        type="button"
                        onClick={() => setDate((d) => shiftDate(d, 1))}
                        disabled={date >= today}
                        aria-label="Next day"
                        className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-30"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                </div>

                <button
                    type="button"
                    onClick={reload}
                    aria-label="Refresh the grade"
                    className="text-muted-foreground hover:text-foreground"
                >
                    <RefreshCw
                        className={`size-3 ${loading ? 'animate-spin' : ''}`}
                    />
                </button>

                {trend.length > 0 && (
                    <div className="ml-auto flex items-center gap-1">
                        {trend.map((day) => (
                            <Tooltip key={day.date}>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        onClick={() => setDate(day.date)}
                                        className={`flex min-w-9 flex-col items-center rounded border px-1 py-0.5 text-[9px] leading-tight transition-colors ${
                                            day.date === date
                                                ? 'border-violet-400 bg-violet-400/10'
                                                : 'border-border hover:border-foreground/30'
                                        }`}
                                    >
                                        <span className="text-muted-foreground">
                                            {shortDay(day.date)}
                                        </span>
                                        <span
                                            className={`text-xs font-bold ${
                                                day.letter
                                                    ? (LETTER_STYLE[
                                                          day.letter
                                                      ].split(' ')[2] ?? '')
                                                    : 'text-muted-foreground'
                                            }`}
                                        >
                                            {day.letter ?? '—'}
                                        </span>
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="bottom"
                                    className="text-[11px]"
                                >
                                    {day.score === null
                                        ? 'Nothing to grade'
                                        : `${day.score}/100${day.partial ? ' (partial)' : ''}`}{' '}
                                    · {day.trades} trade
                                    {day.trades === 1 ? '' : 's'} ·{' '}
                                    {fmtMoney(day.net_pnl)}
                                </TooltipContent>
                            </Tooltip>
                        ))}
                    </div>
                )}
            </div>

            {error && stale && (
                <p className="text-xs text-red-500">{error}</p>
            )}

            {stale && !error && (
                <p className="text-xs text-muted-foreground">Loading…</p>
            )}

            {!stale && grade && <GradeBody grade={grade} showTrades={showTrades} setShowTrades={setShowTrades} />}
        </div>
    );
}

function GradeBody({
    grade,
    showTrades,
    setShowTrades,
}: {
    grade: DailyGrade;
    showTrades: boolean;
    setShowTrades: (fn: (v: boolean) => boolean) => void;
}) {
    if (grade.score === null) {
        return (
            <p className="text-xs text-muted-foreground">
                Nothing to grade for this day — no closed trades and no logged
                decisions.
            </p>
        );
    }

    const s = grade.stats;

    return (
        <>
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3">
                <div className="flex items-center gap-3">
                    <span
                        className={`flex size-16 items-center justify-center rounded-xl border-2 text-4xl font-black ${LETTER_STYLE[grade.letter ?? 'F']}`}
                    >
                        {grade.letter}
                    </span>
                    <div className="flex flex-col">
                        <span className="text-xl font-semibold text-foreground tabular-nums">
                            {grade.score}
                            <span className="text-sm font-normal text-muted-foreground">
                                /100
                            </span>
                        </span>
                        {grade.partial && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="w-fit cursor-default rounded border border-amber-500/40 bg-amber-500/10 px-1.5 py-px text-[9px] font-semibold text-amber-500">
                                        PARTIAL
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="bottom"
                                    className="max-w-[240px] text-[11px]"
                                >
                                    No data for{' '}
                                    {grade.missing.join(', ')}. The grade is
                                    built from the components that did have
                                    data, so it is not a full verdict.
                                </TooltipContent>
                            </Tooltip>
                        )}
                    </div>
                </div>

                <div className="grid min-w-[16rem] flex-1 gap-1.5 sm:grid-cols-2">
                    {grade.components.map((c) => (
                        <ComponentBar key={c.name} c={c} />
                    ))}
                </div>
            </div>

            {grade.flags.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {grade.flags.map((flag) => {
                        const { icon: Icon, cls } = TONE_ICON[flag.tone];

                        return (
                            <li
                                key={flag.text}
                                className="flex items-start gap-1.5 text-[11px] text-foreground"
                            >
                                <Icon
                                    className={`mt-px size-3 shrink-0 ${cls}`}
                                />
                                {flag.text}
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="flex flex-wrap gap-x-6 gap-y-1.5 rounded-md border border-border bg-background px-3 py-2">
                <Stat label="Closed trades" value={`${s.trades}`} />
                <Stat
                    label="Win rate"
                    value={s.win_rate === null ? '—' : `${s.win_rate}%`}
                />
                <Stat
                    label="Net PnL"
                    value={fmtMoney(s.net_pnl)}
                    cls={s.net_pnl >= 0 ? 'text-emerald-500' : 'text-red-500'}
                />
                <Stat label="Avg hold" value={fmtHold(s.avg_hold_minutes)} />
                <Stat label="Entries logged" value={`${s.entries}`} />
                <Stat label="Locks set" value={`${s.locks}`} />
            </div>

            {grade.trades.length > 0 && (
                <div className="flex flex-col gap-1">
                    <button
                        type="button"
                        onClick={() => setShowTrades((v) => !v)}
                        className="flex w-fit items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
                    >
                        Closed trades ({grade.trades.length})
                        {showTrades ? (
                            <ChevronUp className="size-3" />
                        ) : (
                            <ChevronDown className="size-3" />
                        )}
                    </button>

                    {showTrades && (
                        <div className="flex flex-col divide-y divide-border rounded-md border border-border bg-background">
                            {grade.trades.map((t) => (
                                <div
                                    key={`${t.symbol}-${t.closed_at}`}
                                    className="flex items-center gap-3 px-3 py-1.5 text-[11px]"
                                >
                                    <span className="w-14 font-semibold text-foreground">
                                        {coinLabel(t.symbol)}
                                    </span>
                                    <span
                                        className={
                                            t.direction === 'LONG'
                                                ? 'text-emerald-500'
                                                : 'text-red-500'
                                        }
                                    >
                                        {t.direction ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        held {fmtHold(t.hold_minutes)}
                                    </span>
                                    {t.hasty && (
                                        <span className="rounded border border-amber-500/40 bg-amber-500/10 px-1 text-[9px] font-semibold text-amber-500">
                                            HASTY
                                        </span>
                                    )}
                                    <span
                                        className={`ml-auto font-medium tabular-nums ${t.pnl >= 0 ? 'text-emerald-500' : 'text-red-500'}`}
                                    >
                                        {fmtMoney(t.pnl)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <CoachReview key={grade.date} date={grade.date} />
        </>
    );
}

function ComponentBar({ c }: { c: GradeComponent }) {
    const meta = COMPONENT_META[c.name];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div className="flex cursor-default flex-col gap-0.5">
                    <div className="flex items-baseline justify-between text-[11px]">
                        <span className="font-medium text-foreground">
                            {meta.label}{' '}
                            <span className="text-[9px] font-normal text-muted-foreground">
                                ×{c.weight}
                            </span>
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {c.score === null ? meta.missing : c.score}
                        </span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        {c.score !== null && (
                            <div
                                className={`h-full rounded-full ${BAR_STYLE(c.score)}`}
                                style={{ width: `${c.score}%` }}
                            />
                        )}
                    </div>
                </div>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-[260px] text-[11px]">
                {meta.hint}
            </TooltipContent>
        </Tooltip>
    );
}

function Stat({
    label,
    value,
    cls = 'text-foreground',
}: {
    label: string;
    value: string;
    cls?: string;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[10px] leading-none text-muted-foreground">
                {label}
            </span>
            <span className={`text-xs font-medium tabular-nums ${cls}`}>
                {value}
            </span>
        </div>
    );
}

type CoachState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'done'; review: Coach };

/** On-click AI review: explains the grade and names one thing to work on; never changes it. */
function CoachReview({ date }: { date: string }) {
    const [state, setState] = useState<CoachState>({ status: 'idle' });

    const run = async () => {
        setState({ status: 'loading' });

        const csrfToken =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '';

        try {
            const res = await fetch(coachRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ date }),
            });
            const json = await res.json().catch(() => null);

            setState(
                res.ok && json?.success
                    ? { status: 'done', review: json.data }
                    : {
                          status: 'error',
                          message:
                              json?.message ??
                              (res.status === 429
                                  ? 'Too many reviews — wait a minute.'
                                  : 'Review failed.'),
                      },
            );
        } catch {
            setState({ status: 'error', message: 'Network error.' });
        }
    };

    const loading = state.status === 'loading';

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={run}
                    disabled={loading}
                    className="flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground transition-colors hover:border-violet-400/60 hover:text-violet-400 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Sparkles
                        className={`size-3 text-violet-400 ${loading ? 'animate-pulse' : ''}`}
                    />
                    {loading
                        ? 'Reviewing…'
                        : state.status === 'done'
                          ? 'Re-run AI review'
                          : 'AI review'}
                </button>
                {state.status === 'idle' && (
                    <span className="text-[10px] text-muted-foreground">
                        A coach’s read of this day — it explains the grade, it
                        doesn’t change it.
                    </span>
                )}
                {state.status === 'error' && (
                    <span className="text-[11px] text-red-500">
                        {state.message}
                    </span>
                )}
            </div>

            {state.status === 'done' && (
                <div className="flex flex-col gap-1.5 rounded-md border border-violet-400/30 bg-violet-400/5 px-3 py-2 text-[11px]">
                    <p className="text-xs font-semibold text-foreground">
                        {state.review.headline}
                    </p>
                    <p className="text-foreground">
                        <span className="font-semibold text-emerald-500">
                            Went well:
                        </span>{' '}
                        {state.review.went_well}
                    </p>
                    <p className="text-foreground">
                        <span className="font-semibold text-amber-500">
                            Cost you:
                        </span>{' '}
                        {state.review.cost_you}
                    </p>
                    <p className="text-foreground">
                        <span className="font-semibold text-violet-400">
                            Tomorrow:
                        </span>{' '}
                        {state.review.focus_tomorrow}
                    </p>
                    <p className="text-[9px] text-muted-foreground">
                        AI coach (DeepSeek) — informational · ~$
                        {state.review.estimated_cost_usd.toFixed(4)}
                    </p>
                </div>
            )}
        </div>
    );
}
