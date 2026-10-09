import { Loader2, ScanSearch } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useScreener } from '@/hooks/use-screener';
import type { ScreenerRow } from '@/hooks/use-screener';
import {
    SCREENER_INDICATORS,
    SCREENER_TIMEFRAMES,
    describeReading,
    describeUniverse,
    fmtPrice,
    fmtSigned,
    fmtTurnover,
    scanAge,
    volatilityText,
} from '@/lib/screener';
import type {
    ScreenerIndicator,
    ScreenerSide,
    ScreenerTimeframe,
} from '@/lib/screener';
import { coinLabel } from '@/types/futures';

interface Props {
    /** Open this coin in the Analysis panel below. */
    onPick: (symbol: string) => void;
}

const CHOICE_KEY = 'screener-choice';

interface Choice {
    indicator: ScreenerIndicator;
    tf: ScreenerTimeframe;
}

const DEFAULT_CHOICE: Choice = { indicator: 'wt_cross', tf: '1H' };

// Browser storage can be missing or throw (private windows, blocked site data), so
// remembering the last choice is a convenience that must never break the card.
function readChoice(): Choice {
    try {
        const parsed = JSON.parse(localStorage.getItem(CHOICE_KEY) ?? '{}');
        const indicator = SCREENER_INDICATORS.find(
            (i) => i.key === parsed?.indicator,
        );
        const tf = SCREENER_TIMEFRAMES.find((t) => t === parsed?.tf);

        return {
            indicator: indicator?.key ?? DEFAULT_CHOICE.indicator,
            tf: tf ?? DEFAULT_CHOICE.tf,
        };
    } catch {
        return DEFAULT_CHOICE;
    }
}

function writeChoice(choice: Choice): void {
    try {
        localStorage.setItem(CHOICE_KEY, JSON.stringify(choice));
    } catch {
        // ignore — the choice still holds for this session
    }
}

const SIDE_STYLE: Record<ScreenerSide, { title: string; color: string }> = {
    oversold: { title: 'Oversold', color: 'text-emerald-500' },
    overbought: { title: 'Overbought', color: 'text-red-500' },
};

const SELECT_CLASS =
    'h-8 rounded-md border border-border bg-background px-2 text-xs text-foreground focus:border-violet-400 focus:outline-none';

/**
 * Scans MEXC's crypto perpetuals for the most oversold and most overbought coins on one
 * indicator — WaveTrend, RSI, MACD, or what the exchange already tells us about every coin
 * (moves, funding, position in the 24h range). On click only; the candle indicators read the
 * 50 most traded coins, the exchange-data ones every coin trading at least a couple of
 * million dollars a day, so what is listed is something you could actually trade. An extreme
 * is a place to look, not a signal: a coin can stay oversold for days. Clicking a coin opens
 * it in the Analysis panel.
 */
export function ScreenerCard({ onPick }: Props) {
    const { state, scan } = useScreener();
    const [choice, setChoice] = useState<Choice>(readChoice);
    const [now, setNow] = useState(() => Math.floor(Date.now() / 1000));

    const definition = SCREENER_INDICATORS.find(
        (i) => i.key === choice.indicator,
    );

    // Keeps "12 s ago" honest while a result is on screen.
    useEffect(() => {
        const id = setInterval(
            () => setNow(Math.floor(Date.now() / 1000)),
            15_000,
        );

        return () => clearInterval(id);
    }, []);

    const change = (next: Choice) => {
        setChoice(next);
        writeChoice(next);
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <ScanSearch className="size-3.5 text-violet-500" />
                    Market scan
                </p>

                <select
                    value={choice.indicator}
                    onChange={(e) =>
                        change({
                            ...choice,
                            indicator: e.target.value as ScreenerIndicator,
                        })
                    }
                    aria-label="Indicator"
                    className={`${SELECT_CLASS} max-w-full min-w-0`}
                >
                    <optgroup label="From candles (the 50 most traded coins)">
                        {SCREENER_INDICATORS.filter((i) => i.candles).map(
                            (i) => (
                                <option key={i.key} value={i.key}>
                                    {i.label}
                                </option>
                            ),
                        )}
                    </optgroup>
                    <optgroup label="From the exchange (every coin trading enough)">
                        {SCREENER_INDICATORS.filter((i) => !i.candles).map(
                            (i) => (
                                <option key={i.key} value={i.key}>
                                    {i.label}
                                </option>
                            ),
                        )}
                    </optgroup>
                </select>

                {definition?.candles && (
                    <select
                        value={choice.tf}
                        onChange={(e) =>
                            change({
                                ...choice,
                                tf: e.target.value as ScreenerTimeframe,
                            })
                        }
                        aria-label="Timeframe"
                        className={SELECT_CLASS}
                    >
                        {SCREENER_TIMEFRAMES.map((tf) => (
                            <option key={tf} value={tf}>
                                {tf}
                            </option>
                        ))}
                    </select>
                )}

                <button
                    type="button"
                    onClick={() => scan(choice.indicator, choice.tf)}
                    disabled={state.status === 'loading'}
                    className="flex h-8 items-center gap-1.5 rounded-md border border-violet-400/50 bg-violet-400/10 px-3 text-xs font-medium text-violet-400 transition-colors hover:border-violet-400 hover:bg-violet-400/20 disabled:cursor-wait disabled:opacity-60"
                >
                    {state.status === 'loading' ? (
                        <>
                            <Loader2 className="size-3.5 animate-spin" />
                            Scanning…
                        </>
                    ) : (
                        'Scan'
                    )}
                </button>
            </div>

            {state.status === 'idle' && (
                <p className="text-[11px] text-muted-foreground">
                    Pick an indicator and press Scan. Nothing is read until you
                    do.
                </p>
            )}

            {state.status === 'loading' && (
                <p className="text-[11px] text-muted-foreground">
                    {definition?.candles
                        ? 'Reading candles for the 50 most traded coins — this can take up to half a minute.'
                        : "Reading the exchange's list of coins…"}
                </p>
            )}

            {state.status === 'error' && (
                <p className="text-[11px] text-red-500">{state.message}</p>
            )}

            {state.status === 'done' && (
                <>
                    <div className="flex flex-col gap-0.5">
                        <p className="text-[11px] text-foreground">
                            {state.data.label}
                            {state.data.tf ? ` · ${state.data.tf}` : ''}
                            <span className="text-muted-foreground">
                                {' '}
                                · {describeUniverse(state.data.universe)} ·{' '}
                                {scanAge(state.data.generated_at, now)}
                            </span>
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                            {state.data.rule}
                        </p>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2 lg:items-start">
                        {(['oversold', 'overbought'] as const).map((side) => (
                            <ScanList
                                key={side}
                                side={side}
                                title={state.data.titles[side]}
                                total={state.data.counts[side]}
                                rows={state.data[side]}
                                indicator={state.data.indicator}
                                onPick={onPick}
                            />
                        ))}
                    </div>

                    <p className="text-[10px] leading-snug text-muted-foreground">
                        An extreme is a place to look, not a signal: a coin can
                        stay oversold or overbought for days, and a bounce is
                        never guaranteed. Press a coin to open it in the
                        Analysis panel below.
                    </p>
                </>
            )}
        </div>
    );
}

function ScanList({
    side,
    title,
    total,
    rows,
    indicator,
    onPick,
}: {
    side: ScreenerSide;
    title: string;
    total: number;
    rows: ScreenerRow[];
    indicator: string;
    onPick: (symbol: string) => void;
}) {
    const style = SIDE_STYLE[side];
    // "Oversold" under the heading "OVERSOLD" says nothing twice.
    const subtitle =
        title.toLowerCase() === style.title.toLowerCase() ? '' : `${title} · `;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <p className="flex flex-wrap items-baseline gap-x-2 text-[11px]">
                <span className={`font-semibold uppercase ${style.color}`}>
                    {style.title}
                </span>
                <span className="text-muted-foreground">
                    {subtitle}
                    {total > rows.length
                        ? `top ${rows.length} of ${total}`
                        : `${total} ${total === 1 ? 'coin' : 'coins'}`}
                </span>
            </p>

            {rows.length === 0 && (
                <p className="rounded-md border border-dashed border-border px-2.5 py-2 text-[11px] text-muted-foreground">
                    No coin qualifies right now.
                </p>
            )}

            {rows.map((row, i) => (
                <ScanRow
                    key={row.symbol}
                    row={row}
                    index={i}
                    side={side}
                    indicator={indicator}
                    onPick={onPick}
                />
            ))}
        </div>
    );
}

function ScanRow({
    row,
    index,
    side,
    indicator,
    onPick,
}: {
    row: ScreenerRow;
    index: number;
    side: ScreenerSide;
    indicator: string;
    onPick: (symbol: string) => void;
}) {
    const reading = describeReading(indicator, side, row.reading);
    const volatility = volatilityText(row.volatility);

    return (
        <button
            type="button"
            onClick={() => onPick(row.symbol)}
            title={`Open ${coinLabel(row.symbol)} in the Analysis panel`}
            className="flex w-full flex-col gap-0.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-left transition-colors hover:border-violet-400/60 hover:bg-violet-400/5"
        >
            <span className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span className="flex items-baseline gap-2">
                    <span className="w-4 text-[10px] text-muted-foreground tabular-nums">
                        {index + 1}
                    </span>
                    <span className="text-xs font-semibold text-foreground">
                        {coinLabel(row.symbol)}
                    </span>
                    <span className="text-[11px] text-muted-foreground tabular-nums">
                        ${fmtPrice(row.price)}
                    </span>
                </span>
                <span
                    className={`text-[11px] tabular-nums ${
                        reading.pending ? 'text-amber-500' : 'text-foreground'
                    }`}
                >
                    {reading.text}
                </span>
            </span>
            <span className="flex flex-wrap gap-x-3 pl-6 text-[10px] text-muted-foreground tabular-nums">
                {row.change_24h !== null && (
                    <span>24h {fmtSigned(row.change_24h)}%</span>
                )}
                <span>
                    #{row.rank} · {fmtTurnover(row.turnover)}/day
                </span>
                {volatility && <span>{volatility}</span>}
            </span>
        </button>
    );
}
