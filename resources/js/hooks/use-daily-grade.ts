import { useCallback, useEffect, useState } from 'react';
import { dailyGrade as dailyGradeRoute } from '@/routes/futures';

// The trader's grade for one UTC day (the same day convention as the PnL calendar):
// computed server-side from MEXC's closed trades plus the decisions the app logged.

export type GradeTone = 'warn' | 'info' | 'good';

export interface GradeFlag {
    tone: GradeTone;
    text: string;
}

export interface GradeComponent {
    name: 'result' | 'risk' | 'patience' | 'process';
    weight: number;
    /** Null when there was nothing to measure for this component. */
    score: number | null;
    detail: Record<string, number | null>;
}

export interface GradeTrade {
    symbol: string;
    direction: 'LONG' | 'SHORT' | null;
    pnl: number;
    hold_minutes: number | null;
    closed_at: number;
    hasty: boolean;
}

export interface GradeStats {
    trades: number;
    wins: number;
    losses: number;
    net_pnl: number;
    win_rate: number | null;
    avg_hold_minutes: number | null;
    entries: number;
    reduces: number;
    locks: number;
    early_unlocks: number;
    blocked_attempts: number;
}

export interface DailyGrade {
    date: string;
    score: number | null;
    letter: 'A' | 'B' | 'C' | 'D' | 'F' | null;
    partial: boolean;
    missing: string[];
    components: GradeComponent[];
    flags: GradeFlag[];
    stats: GradeStats;
    trades: GradeTrade[];
}

export interface GradeTrendDay {
    date: string;
    score: number | null;
    letter: DailyGrade['letter'];
    partial: boolean;
    trades: number;
    net_pnl: number;
}

interface State {
    grade: DailyGrade | null;
    trend: GradeTrendDay[];
    error: string | null;
    loading: boolean;
}

/** Today's date in UTC — the grade, like the PnL calendar, buckets by UTC day. */
export const todayUtc = (): string => new Date().toISOString().slice(0, 10);

/** Moves a Y-m-d date by whole days, in UTC. */
export function shiftDate(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

/**
 * Loads the grade for `date` (plus the last week as a trend). Re-fetches when the date
 * changes, on demand via `reload`, and — if `refreshMs` is given — on that interval.
 */
export function useDailyGrade(date: string, refreshMs?: number) {
    const [state, setState] = useState<State>({
        grade: null,
        trend: [],
        error: null,
        loading: true,
    });

    const load = useCallback(async () => {
        try {
            const res = await fetch(
                `${dailyGradeRoute.url()}?date=${encodeURIComponent(date)}`,
                { headers: { Accept: 'application/json' } },
            );
            const json = await res.json().catch(() => null);

            if (res.ok && json?.success) {
                setState({
                    grade: json.data.grade,
                    trend: json.data.trend,
                    error: null,
                    loading: false,
                });
            } else {
                setState((prev) => ({
                    ...prev,
                    error: json?.message ?? 'Could not load the grade.',
                    loading: false,
                }));
            }
        } catch {
            setState((prev) => ({
                ...prev,
                error: 'Network error.',
                loading: false,
            }));
        }
    }, [date]);

    useEffect(() => {
        // Fetch-on-dependency-change: load() only sets state after its await resolves, not
        // synchronously, but the rule can't see through the async function.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        load();

        if (!refreshMs) {
            return;
        }

        const id = setInterval(load, refreshMs);

        return () => clearInterval(id);
    }, [load, refreshMs]);

    return { ...state, reload: load };
}
