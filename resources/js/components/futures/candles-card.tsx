import {
    CandlestickChart,
    ChevronDown,
    ChevronUp,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import type {
    AnalysisExtras,
    CandleFlag,
    CandleRow,
    CandleTape,
} from '@/hooks/use-analysis-extras';
import { aiCandles as aiCandlesRoute } from '@/routes/futures';
import type { Position } from '@/types/futures';

interface Props {
    symbol: string;
    extras: AnalysisExtras | 'loading' | 'error';
    /** The trader's open legs in this coin; sent with the AI read so it can say what the candles mean for them. */
    positions: Position[];
}

/** The AI's reading of the measured candles, as returned by POST /futures/ai-candles. */
interface CandleRead {
    control: 'buyers' | 'sellers' | 'balanced';
    confidence: 'low' | 'medium' | 'high';
    headline: string;
    read_4h: string;
    read_1h: string;
    read_15m: string;
    at_levels: string;
    position_note: string;
    watch: string;
    estimated_cost_usd: number;
}

type ReadState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'done'; read: CandleRead; at: Date };

const TIMEFRAMES = ['15M', '1H', '4H'];

/** Candles listed before "Show all" — the newest ones are what matters. */
const SHOWN_BY_DEFAULT = 8;

const FLAG_STYLE: Record<CandleFlag['bias'], string> = {
    bullish: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400',
    bearish: 'border-red-500/40 bg-red-500/10 text-red-400',
    neutral: 'border-border bg-muted/30 text-muted-foreground',
};

const CONTROL_META: Record<
    CandleRead['control'],
    { label: string; cls: string }
> = {
    buyers: { label: 'Buyers', cls: 'text-emerald-500' },
    sellers: { label: 'Sellers', cls: 'text-red-500' },
    balanced: { label: 'Balanced', cls: 'text-amber-500' },
};

const DIRECTION_META: Record<
    CandleRow['direction'],
    { arrow: string; cls: string; bar: string }
> = {
    up: { arrow: '▲', cls: 'text-emerald-500', bar: 'bg-emerald-500' },
    down: { arrow: '▼', cls: 'text-red-500', bar: 'bg-red-500' },
    flat: {
        arrow: '■',
        cls: 'text-muted-foreground',
        bar: 'bg-muted-foreground',
    },
};

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/** 15M candles span only hours, so the clock is enough; slower ones need the day too. */
function candleTime(unix: number, tf: string): string {
    const date = new Date(unix * 1000);

    return tf === '15M'
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleString([], {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

const round2 = (n: number) => Math.round(n * 100) / 100;

const legPayload = (p: Position) => ({
    direction: p.positionType === 1 ? 'LONG' : 'SHORT',
    notional: round2(p.positionValue),
    entry: p.openAvgPrice,
    pnl: round2(p.unrealizedPnl),
    leverage: p.leverage,
    liquidation_price: p.liquidatePrice,
    stop_loss: p.active_sl_tp?.stop_loss ?? null,
    take_profit: p.active_sl_tp?.take_profit ?? null,
    locked: p.locked,
    locked_until: p.lockedUntil,
});

/**
 * What the latest candles did, measured and labelled by the server: how big each one
 * was against the ATR, where its body and wicks sat, how much volume came with it, and
 * plain-language flags (engulfing, long wicks, sweeps, breakouts, how it treated your
 * zones and levels). Patterns are only called on closed candles; the live candle is
 * shown but marked as still forming. The on-click AI read gets exactly these measured
 * facts and nothing else — it explains them, it doesn't see candles for itself.
 */
export function CandlesCard({ symbol, extras, positions }: Props) {
    const [tf, setTf] = useState('15M');
    const [showAll, setShowAll] = useState(false);
    const [read, setRead] = useState<ReadState>({ status: 'idle' });

    const tape: CandleTape | null | undefined =
        typeof extras === 'object' ? extras.candles?.[tf] : undefined;

    const runRead = async () => {
        setRead({ status: 'loading' });

        const csrfToken =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '';

        try {
            const res = await fetch(aiCandlesRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    symbol,
                    positions: positions.map(legPayload),
                }),
            });
            const json = await res.json().catch(() => null);

            if (res.ok && json?.success) {
                setRead({ status: 'done', read: json.data, at: new Date() });
            } else {
                setRead({
                    status: 'error',
                    message:
                        json?.message ??
                        (res.status === 429
                            ? 'Too many AI reads — wait a minute.'
                            : 'Candle read failed.'),
                });
            }
        } catch {
            setRead({ status: 'error', message: 'Network error.' });
        }
    };

    const loading = read.status === 'loading';
    const rows = tape
        ? [...(tape.forming ? [tape.forming] : []), ...tape.candles]
        : [];
    const visibleCount = showAll
        ? rows.length
        : SHOWN_BY_DEFAULT + (tape?.forming ? 1 : 0);

    return (
        <div className="flex flex-col gap-2.5 rounded-md border border-border bg-background px-3 py-2.5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <CandlestickChart className="size-3.5 text-amber-400" />
                    Candles
                </p>

                <div className="flex items-center gap-1">
                    {TIMEFRAMES.map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => {
                                setTf(t);
                                setShowAll(false);
                            }}
                            className={`rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors ${
                                t === tf
                                    ? 'border-amber-400 bg-amber-400/10 text-amber-400'
                                    : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                            }`}
                        >
                            {t}
                        </button>
                    ))}
                </div>

                <div className="ml-auto flex items-center gap-2">
                    {read.status === 'idle' && (
                        <span className="hidden text-[10px] text-muted-foreground sm:inline">
                            On-click — measured candles, not a forecast.
                        </span>
                    )}
                    {read.status === 'error' && (
                        <span className="text-[11px] text-red-500">
                            {read.message}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={runRead}
                        disabled={typeof extras !== 'object' || loading}
                        className="flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground transition-colors hover:border-violet-400/60 hover:text-violet-400 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Sparkles
                            className={`size-3 text-violet-400 ${loading ? 'animate-pulse' : ''}`}
                        />
                        {loading
                            ? 'Reading…'
                            : read.status === 'done'
                              ? 'Read again'
                              : 'Read the candles'}
                    </button>
                </div>
            </div>

            {read.status === 'done' && (
                <ReadBox read={read.read} at={read.at} />
            )}

            {extras === 'loading' && (
                <p className="text-[11px] text-muted-foreground">
                    Measuring the candles…
                </p>
            )}
            {extras === 'error' && (
                <p className="text-[11px] text-red-500">
                    Couldn&apos;t load the candles.
                </p>
            )}
            {typeof extras === 'object' && !tape && (
                <p className="text-[11px] text-muted-foreground">
                    Not enough closed {tf} candles on this coin yet.
                </p>
            )}

            {tape && (
                <>
                    <p className="text-[11px] leading-snug text-muted-foreground">
                        {tape.sequence.summary}{' '}
                        <span className="whitespace-nowrap">
                            1 ATR on {tf} = ${fmtPrice(tape.atr)}
                            {tape.atr_pct !== null
                                ? ` (${tape.atr_pct}% of price)`
                                : ''}
                            .
                        </span>
                    </p>

                    <div className="flex flex-col divide-y divide-border rounded-md border border-border">
                        {rows.slice(0, visibleCount).map((row) => (
                            <CandleRowView key={row.time} row={row} tf={tf} />
                        ))}
                    </div>

                    {rows.length > visibleCount || showAll ? (
                        <button
                            type="button"
                            onClick={() => setShowAll((v) => !v)}
                            className="flex w-fit items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
                        >
                            {showAll
                                ? 'Show fewer'
                                : `Show all ${tape.candles.length}`}
                            {showAll ? (
                                <ChevronUp className="size-3" />
                            ) : (
                                <ChevronDown className="size-3" />
                            )}
                        </button>
                    ) : null}

                    <p className="text-[10px] leading-snug text-muted-foreground">
                        Patterns are only called on closed candles — the live
                        candle can change its mind every few seconds. A pattern
                        on its own means little; it matters most at one of your
                        zones or levels, with volume, and once the next candle
                        confirms it. Everything here describes what already
                        happened.
                    </p>
                </>
            )}
        </div>
    );
}

/** A tiny picture of a candle's SHAPE: wicks against body, as shares of its own range (no price scale). */
function CandleGlyph({ row }: { row: CandleRow }) {
    const bar = DIRECTION_META[row.direction].bar;

    return (
        <div
            className="flex h-7 w-2.5 shrink-0 flex-col items-center overflow-hidden"
            aria-hidden
        >
            <div
                className={`w-px ${bar}`}
                style={{ height: `${row.upper_wick_pct}%` }}
            />
            <div
                className={`w-2 rounded-[1px] ${bar} ${row.closed ? '' : 'opacity-60'}`}
                style={{ height: `${Math.max(row.body_pct, 8)}%` }}
            />
            <div
                className={`w-px ${bar}`}
                style={{ height: `${row.lower_wick_pct}%` }}
            />
        </div>
    );
}

function CandleRowView({ row, tf }: { row: CandleRow; tf: string }) {
    const direction = DIRECTION_META[row.direction];
    const label = !row.closed
        ? 'forming'
        : row.ago === 0
          ? 'last closed'
          : `${row.ago} back`;

    return (
        <div
            className={`flex gap-2.5 px-2.5 py-1.5 text-[11px] tabular-nums ${
                row.closed
                    ? ''
                    : 'border-l-2 border-dashed border-amber-400/60 bg-amber-400/5'
            }`}
        >
            <CandleGlyph row={row} />

            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-baseline gap-x-4 gap-y-0.5">
                    <span className="w-[4.5rem] font-medium text-foreground">
                        {label}
                    </span>
                    <span className="w-24 text-muted-foreground">
                        {candleTime(row.time, tf)}
                    </span>
                    <span className={`w-12 font-medium ${direction.cls}`}>
                        {direction.arrow} {row.direction}
                    </span>
                    <span className="text-muted-foreground">
                        range{' '}
                        <span className="text-foreground">
                            {row.range_atr.toFixed(1)}×
                        </span>{' '}
                        ATR
                    </span>
                    <span className="text-muted-foreground">
                        body {row.body_pct}% · wicks ↑{row.upper_wick_pct}% ↓
                        {row.lower_wick_pct}%
                    </span>
                    {row.volume_ratio !== null && (
                        <span
                            className={
                                row.volume_ratio >= 2
                                    ? 'font-medium text-sky-400'
                                    : 'text-muted-foreground'
                            }
                        >
                            vol {row.volume_ratio.toFixed(1)}×
                        </span>
                    )}
                </div>

                {row.flags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {row.flags.map((flag) => (
                            <span
                                key={flag.key + flag.label}
                                className={`rounded border px-1.5 py-0.5 text-[10px] leading-snug ${FLAG_STYLE[flag.bias]}`}
                            >
                                {flag.label}
                            </span>
                        ))}
                    </div>
                )}

                {!row.closed && (
                    <span className="text-[10px] text-muted-foreground">
                        Not closed yet — no patterns are called on it, and it
                        isn&apos;t a signal.
                    </span>
                )}
            </div>
        </div>
    );
}

function ReadBox({ read, at }: { read: CandleRead; at: Date }) {
    const control = CONTROL_META[read.control];

    const sections: { label: string; text: string }[] = [
        { label: '4H', text: read.read_4h },
        { label: '1H', text: read.read_1h },
        { label: '15M', text: read.read_15m },
        { label: 'At your levels', text: read.at_levels },
        { label: 'Your position', text: read.position_note },
        { label: 'Watch', text: read.watch },
    ].filter((s) => s.text.trim() !== '');

    return (
        <div className="flex flex-col gap-1.5 rounded-md border border-violet-400/30 bg-violet-400/5 px-2.5 py-2 text-[11px]">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-muted-foreground">
                    In control{' '}
                    <span className={`font-semibold ${control.cls}`}>
                        {control.label}
                    </span>
                </span>
                <span className="text-muted-foreground">
                    Confidence{' '}
                    <span className="font-semibold text-foreground capitalize">
                        {read.confidence}
                    </span>
                </span>
            </div>

            <p className="font-medium text-foreground">{read.headline}</p>

            {sections.map((s) => (
                <p key={s.label} className="text-muted-foreground">
                    <span className="font-semibold text-foreground">
                        {s.label}:
                    </span>{' '}
                    {s.text}
                </p>
            ))}

            <p className="text-[9px] text-muted-foreground">
                AI candle read (DeepSeek) of the candles above — informational,
                not a forecast · ~${read.estimated_cost_usd.toFixed(4)} ·{' '}
                {at.toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                })}
            </p>
        </div>
    );
}
