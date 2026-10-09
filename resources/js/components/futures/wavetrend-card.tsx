import { Waves } from 'lucide-react';
import type {
    AnalysisExtras,
    WaveTrendRead,
    WaveTrendZone,
} from '@/hooks/use-analysis-extras';
import { candlesAgo } from '@/lib/screener';

interface Props {
    extras: AnalysisExtras | 'loading' | 'error';
}

const TIMEFRAMES = ['15M', '1H', '4H'];

const ZONE_STYLE: Record<WaveTrendZone, { label: string; color: string }> = {
    deep_overbought: { label: 'Deeply overbought', color: 'text-red-500' },
    overbought: { label: 'Overbought', color: 'text-red-500' },
    neutral: { label: 'Neutral', color: 'text-muted-foreground' },
    oversold: { label: 'Oversold', color: 'text-emerald-500' },
    deep_oversold: { label: 'Deeply oversold', color: 'text-emerald-500' },
};

const DIRECTION = {
    up: { arrow: '▲', word: 'up', color: 'text-emerald-500' },
    down: { arrow: '▼', word: 'down', color: 'text-red-500' },
};

const num = (n: number) => n.toFixed(1);

/** The clock for a time today; the day too for anything older. */
function clock(unix: number): string {
    const date = new Date(unix * 1000);

    return date.toDateString() === new Date().toDateString()
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleString([], {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

/** One line on whether the timeframes agree about which line is on top, as of their closed candles. */
export function summarizeWaveTrend(reads: (WaveTrendRead | null)[]): {
    label: string;
    color: string;
} | null {
    const known = reads.filter((r): r is WaveTrendRead => r !== null);
    const over = known.filter((r) => r.side === 'above').length;
    const under = known.length - over;

    if (known.length === 0) {
        return null;
    }

    if (over > 0 && under > 0) {
        return {
            label: `Timeframes disagree (${over} over · ${under} under)`,
            color: 'text-amber-500',
        };
    }

    return over > 0
        ? {
              label: `WT1 over WT2 on ${over} of ${known.length}`,
              color: 'text-emerald-500',
          }
        : {
              label: `WT1 under WT2 on ${under} of ${known.length}`,
              color: 'text-red-500',
          };
}

/**
 * The WaveTrend oscillator (WT_CROSS_LB, 10/21, lines at ±53 and ±60 — the same maths as on
 * TradingView) for 15M, 1H and 4H: where WT1 and WT2 are, which zone they are in, which line
 * was on top at the last close, and the latest crosses with the level each happened at. A
 * cross only counts once its candle has closed; one on the open candle is shown apart, as
 * pending, because it can still flip back.
 */
export function WaveTrendCard({ extras }: Props) {
    const reads = typeof extras === 'object' ? (extras.wavetrend ?? {}) : {};
    const summary = summarizeWaveTrend(
        TIMEFRAMES.map((tf) => reads[tf] ?? null),
    );

    return (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-background px-3 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <Waves className="size-3.5 text-violet-400" />
                    WaveTrend
                </p>
                {summary && (
                    <span
                        className={`text-[11px] font-medium ${summary.color}`}
                    >
                        {summary.label}
                    </span>
                )}
            </div>

            {extras === 'loading' && (
                <p className="text-[11px] text-muted-foreground">Loading…</p>
            )}
            {extras === 'error' && (
                <p className="text-[11px] text-red-500">
                    Couldn&apos;t load WaveTrend.
                </p>
            )}

            {typeof extras === 'object' && (
                <div className="flex flex-col gap-1.5">
                    {TIMEFRAMES.map((tf) => (
                        <WaveTrendRow
                            key={tf}
                            tf={tf}
                            read={reads[tf] ?? null}
                        />
                    ))}
                </div>
            )}

            <p className="text-[9px] leading-snug text-muted-foreground">
                WaveTrend 10/21 with lines at ±53 and ±60, the same maths as
                WT_CROSS_LB on TradingView. A cross counts only once its candle
                has closed — the open candle can still flip back.
            </p>
        </div>
    );
}

function WaveTrendRow({
    tf,
    read,
}: {
    tf: string;
    read: WaveTrendRead | null;
}) {
    if (read === null) {
        return (
            <div className="flex items-baseline gap-3 text-[11px]">
                <span className="w-8 font-semibold text-foreground">{tf}</span>
                <span className="text-muted-foreground">
                    Not enough candles yet.
                </span>
            </div>
        );
    }

    const zone = ZONE_STYLE[read.zone];
    const [last, ...before] = read.crosses;
    const pending = read.forming_cross ? DIRECTION[read.forming_cross] : null;

    return (
        <div className="flex gap-3 border-t border-border/60 pt-1.5 first:border-t-0 first:pt-0">
            <span className="w-8 shrink-0 text-[11px] font-semibold text-foreground">
                {tf}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-[11px] tabular-nums">
                    <span className="text-foreground">
                        WT1 {num(read.wt1)} · WT2 {num(read.wt2)}
                        {read.forming && (
                            <span className="ml-1 text-[9px] text-muted-foreground">
                                live
                            </span>
                        )}
                    </span>
                    <span className={zone.color}>{zone.label}</span>
                    {read.to_line && (
                        <span className="text-muted-foreground">
                            {num(read.to_line.distance)}{' '}
                            {read.to_line.line === 'oversold'
                                ? 'above'
                                : 'below'}{' '}
                            the {read.to_line.level} line
                        </span>
                    )}
                    <span
                        className={
                            read.side === 'above'
                                ? 'text-emerald-500'
                                : 'text-red-500'
                        }
                    >
                        {read.side === 'above'
                            ? 'WT1 over WT2'
                            : 'WT1 under WT2'}{' '}
                        <span className="text-[9px] text-muted-foreground">
                            at the last close
                        </span>
                    </span>
                </div>

                {pending && read.forming_cross && (
                    <p className="text-[10px] text-amber-500">
                        {pending.arrow} A cross {pending.word} is forming on the
                        open candle (gap {read.gap > 0 ? '+' : ''}
                        {read.gap.toFixed(2)}) — not confirmed until it closes
                        {read.closes_at ? ` at ${clock(read.closes_at)}` : ''};
                        it can still flip back.
                    </p>
                )}

                <p className="text-[10px] text-muted-foreground">
                    {last ? (
                        <>
                            Last cross:{' '}
                            <span className={DIRECTION[last.direction].color}>
                                {DIRECTION[last.direction].arrow}{' '}
                                {DIRECTION[last.direction].word}
                            </span>{' '}
                            {candlesAgo(last.ago)} ({clock(last.time)}) at{' '}
                            {num(last.level)}
                            {before.length > 0 && (
                                <>
                                    {' '}
                                    · before:{' '}
                                    {before.map((cross, i) => (
                                        <span
                                            key={cross.time}
                                            title={`${clock(cross.time)} (${candlesAgo(cross.ago)})`}
                                        >
                                            {i > 0 ? ' · ' : ''}
                                            <span
                                                className={
                                                    DIRECTION[cross.direction]
                                                        .color
                                                }
                                            >
                                                {
                                                    DIRECTION[cross.direction]
                                                        .arrow
                                                }
                                            </span>{' '}
                                            {num(cross.level)}
                                        </span>
                                    ))}
                                </>
                            )}
                        </>
                    ) : (
                        'No cross in the candles loaded.'
                    )}
                </p>
            </div>
        </div>
    );
}
