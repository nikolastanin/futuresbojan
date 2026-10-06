import { Layers } from 'lucide-react';
import type { AnalysisExtras, MtfRow } from '@/hooks/use-analysis-extras';

interface Props {
    extras: AnalysisExtras | 'loading' | 'error';
}

const TREND_STYLE: Record<string, { label: string; color: string }> = {
    up: { label: 'Up', color: 'text-emerald-500' },
    down: { label: 'Down', color: 'text-red-500' },
    sideways: { label: 'Flat', color: 'text-muted-foreground' },
};

const LEAN_STYLE: Record<MtfRow['lean'], { label: string; color: string }> = {
    up: { label: '▲ Up', color: 'text-emerald-500' },
    down: { label: '▼ Down', color: 'text-red-500' },
    mixed: { label: '◆ Mixed', color: 'text-muted-foreground' },
};

function rsiColor(rsi: number | null): string {
    if (rsi === null) {
        return 'text-muted-foreground';
    }

    if (rsi >= 70) {
        return 'text-red-500';
    }

    if (rsi <= 30) {
        return 'text-emerald-500';
    }

    return 'text-foreground';
}

/** One-line read of whether the timeframes agree, fight, or are undecided. */
export function summarizeAlignment(rows: MtfRow[]): {
    label: string;
    color: string;
} {
    const up = rows.filter((r) => r.lean === 'up').length;
    const down = rows.filter((r) => r.lean === 'down').length;

    if (up > 0 && down > 0) {
        return {
            label: `Timeframes disagree (${up} up · ${down} down)`,
            color: 'text-amber-500',
        };
    }

    if (up > 0) {
        return {
            label: `${up} of ${rows.length} lean up, none down`,
            color: 'text-emerald-500',
        };
    }

    if (down > 0) {
        return {
            label: `${down} of ${rows.length} lean down, none up`,
            color: 'text-red-500',
        };
    }

    return { label: 'No timeframe has a clear lean', color: 'text-muted-foreground' };
}

/**
 * Trend, RSI and MACD for 5M through 1D in one small table, with a combined lean per
 * timeframe (trend and MACD each vote up or down) — so you can see at a glance
 * whether the short timeframes are fighting the long ones, which is the conflict the
 * single "Bot says" score hides.
 */
export function MtfGrid({ extras }: Props) {
    return (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-background px-3 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <Layers className="size-3.5 text-violet-400" />
                    Timeframes
                </p>
                {typeof extras === 'object' && (
                    <span
                        className={`text-[11px] font-medium ${summarizeAlignment(extras.mtf).color}`}
                    >
                        {summarizeAlignment(extras.mtf).label}
                    </span>
                )}
            </div>

            {extras === 'loading' && (
                <p className="text-[11px] text-muted-foreground">Loading…</p>
            )}
            {extras === 'error' && (
                <p className="text-[11px] text-red-500">
                    Couldn&apos;t load the timeframe grid.
                </p>
            )}

            {typeof extras === 'object' && (
                <div className="grid grid-cols-[auto_1fr_1fr_1fr_1fr] gap-x-4 gap-y-1 text-[11px] tabular-nums">
                    {['TF', 'Trend', 'RSI', 'MACD', 'Lean'].map((h) => (
                        <span key={h} className="text-[9px] text-muted-foreground">
                            {h}
                        </span>
                    ))}
                    {extras.mtf.map((row) => (
                        <MtfRowCells key={row.tf} row={row} />
                    ))}
                </div>
            )}
        </div>
    );
}

function MtfRowCells({ row }: { row: MtfRow }) {
    const trend = TREND_STYLE[row.trend] ?? {
        label: '—',
        color: 'text-muted-foreground',
    };
    const lean = LEAN_STYLE[row.lean];

    return (
        <>
            <span className="font-semibold text-foreground">{row.tf}</span>
            <span className={trend.color}>{trend.label}</span>
            <span className={rsiColor(row.rsi)}>
                {row.rsi === null ? '—' : row.rsi.toFixed(1)}
            </span>
            <span
                className={
                    row.macd === 'bullish'
                        ? 'text-emerald-500'
                        : row.macd === 'bearish'
                          ? 'text-red-500'
                          : 'text-muted-foreground'
                }
            >
                {row.macd === null
                    ? '—'
                    : row.macd === 'bullish'
                      ? 'Bullish'
                      : row.macd === 'bearish'
                        ? 'Bearish'
                        : 'Flat'}
            </span>
            <span className={`font-medium ${lean.color}`}>{lean.label}</span>
        </>
    );
}
