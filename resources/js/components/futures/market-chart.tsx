import {
    CandlestickSeries,
    ColorType,
    CrosshairMode,
    LineSeries,
    LineStyle,
    TickMarkType,
    createChart,
    createSeriesMarkers,
} from 'lightweight-charts';
import type {
    IChartApi,
    IPriceLine,
    ISeriesApi,
    ISeriesMarkersPluginApi,
    LineWidth,
    Logical,
    MouseEventParams,
    Time,
    UTCTimestamp,
} from 'lightweight-charts';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import {
    POSITION_LINE_STYLE,
    SUPERTREND_SETTINGS,
    buildLegend,
    fmtPrice,
    formatTick,
    formatTime,
    isBuy,
    labelledMarks,
    levelStyle,
    linePoints,
    markColor,
    markText,
    mergeTail,
    positionLineTitle,
    pricePrecision,
    superTrendPoints,
    tradeMarks,
    waveTrendCrosses,
} from '@/lib/market-chart';
import type {
    ChartFill,
    ChartPayload,
    ChartTimeframe,
    Legend,
    LinePoint,
    TradeMark,
} from '@/lib/market-chart';
import type { ChartLine } from '@/lib/mini-chart';
import {
    marketChart as marketChartRoute,
    marketChartTrades as marketChartTradesRoute,
} from '@/routes/futures';
import { coinLabel } from '@/types/futures';

interface Props {
    symbol: string;
    tf: ChartTimeframe;
    showLevels: boolean;
    /** Which SuperTrend settings to draw, by the server's name for them ("12_2.5"). */
    supertrend: Record<string, boolean>;
    showWaveTrend: boolean;
    /** Your own lines (entries, break-even, liquidation, SL/TP) for this coin. */
    positionLines: ChartLine[];
    /** An arrow on the chart for each of your completed orders in this coin. */
    showTrades: boolean;
}

/** Seconds between refreshes of the live candle. */
const POLL_SECONDS = 10;

/** Seconds between looks at your completed orders: they come from your exchange account, not the market feed. */
const TRADES_POLL_SECONDS = 30;

/** Candles in view when a chart opens; the rest is a scroll or a zoom away. */
const FIRST_VIEW_CANDLES = 170;

const FONT = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

const UP = '#22c55e';
const DOWN = '#ef4444';

/** Colours of the two themes. The chart's own background is transparent, so the card behind it shows through. */
function palette(dark: boolean) {
    return dark
        ? {
              text: '#a1a1aa',
              grid: '#27272a',
              crosshair: '#71717a',
              label: '#3f3f46',
          }
        : {
              text: '#52525b',
              grid: '#e4e4e7',
              crosshair: '#a1a1aa',
              label: '#71717a',
          };
}

/** Follows the app's light/dark switch, which is a class on the root element. */
function useIsDark(): boolean {
    return useSyncExternalStore(
        (notify) => {
            const observer = new MutationObserver(notify);

            observer.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class'],
            });

            return () => observer.disconnect();
        },
        () => document.documentElement.classList.contains('dark'),
        () => true,
    );
}

const tickKind = (type: TickMarkType) =>
    type === TickMarkType.Year
        ? 'year'
        : type === TickMarkType.Month
          ? 'month'
          : type === TickMarkType.DayOfMonth
            ? 'day'
            : 'time';

const asTime = (unix: number) => unix as UTCTimestamp;

/** Everything drawn on the chart, kept outside React state: it is the chart library's, not the page's. */
interface Drawn {
    api: IChartApi;
    candles: ISeriesApi<'Candlestick'>;
    data: ChartPayload | null;
    precision: number;
    superTrend: Record<
        string,
        { up: ISeriesApi<'Line'>; down: ISeriesApi<'Line'> }
    >;
    wave: {
        wt1: ISeriesApi<'Line'>;
        wt2: ISeriesApi<'Line'>;
        markers: ISeriesMarkersPluginApi<Time>;
    } | null;
    levelLines: IPriceLine[];
    positionLines: IPriceLine[];
    /** Whether the level lines are switched on, for a refresh that finds the levels have moved on. */
    showLevels: boolean;
    hoverTime: number | null;
    /** Your completed orders in this coin as MEXC listed them, and the arrows drawn from them. */
    fills: ChartFill[];
    marks: TradeMark[];
    tradeMarkers: ISeriesMarkersPluginApi<Time>;
    /** Which arrows have their tag written, as drawn (see labelledMarks): redrawn only when a zoom changes it. */
    labelKey: string;
}

/** The note under the figures when your trades could not be read, kept with the coin and timeframe it was for. */
type TradeNote = { key: string; message: string };

type Status =
    | { key: string; state: 'ready' }
    | { key: string; state: 'error'; message: string };

/**
 * A full-size candlestick chart of one coin, drawn with TradingView's open-source
 * lightweight-charts: the key levels, SuperTrend, your own position lines and, in a pane
 * underneath, WaveTrend. The last candle follows the market (a refresh every few seconds asks
 * the server for just the newest candles). Everything the numbers say is worked out on the
 * server and in lib/market-chart; this component only hands it to the chart.
 */
export function MarketChart({
    symbol,
    tf,
    showLevels,
    supertrend,
    showWaveTrend,
    positionLines,
    showTrades,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const drawn = useRef<Drawn | null>(null);
    const dark = useIsDark();
    const [status, setStatus] = useState<Status | null>(null);
    const [tradeNote, setTradeNote] = useState<TradeNote | null>(null);
    const [legend, setLegend] = useState<Legend | null>(null);
    // Bumped each time new data lands, so the effects that draw from it run again.
    const [version, setVersion] = useState(0);

    const key = `${symbol}:${tf}`;
    const state = status?.key === key ? status.state : 'loading';

    // The chart itself: made once, removed on the way out.
    useEffect(() => {
        const el = containerRef.current;

        if (!el) {
            return;
        }

        const colors = palette(
            document.documentElement.classList.contains('dark'),
        );
        const api = createChart(el, {
            autoSize: true,
            layout: {
                background: { type: ColorType.Solid, color: 'transparent' },
                textColor: colors.text,
                fontSize: 11,
                fontFamily: FONT,
                panes: { separatorColor: colors.grid },
            },
            grid: {
                vertLines: { visible: false },
                horzLines: { color: colors.grid },
            },
            crosshair: {
                mode: CrosshairMode.Normal,
                vertLine: {
                    color: colors.crosshair,
                    style: LineStyle.Dashed,
                    labelBackgroundColor: colors.label,
                },
                horzLine: {
                    color: colors.crosshair,
                    style: LineStyle.Dashed,
                    labelBackgroundColor: colors.label,
                },
            },
            rightPriceScale: {
                borderColor: colors.grid,
                scaleMargins: { top: 0.06, bottom: 0.06 },
            },
            timeScale: {
                borderColor: colors.grid,
                timeVisible: true,
                secondsVisible: false,
                rightOffset: 8,
                barSpacing: 7,
                tickMarkFormatter: (time: Time, type: TickMarkType) =>
                    formatTick(Number(time), tickKind(type)),
            },
            localization: {
                timeFormatter: (time: Time) => formatTime(Number(time)),
            },
        });

        const candles = api.addSeries(CandlestickSeries, {
            upColor: UP,
            downColor: DOWN,
            wickUpColor: UP,
            wickDownColor: DOWN,
            borderVisible: false,
        });

        const handle: Drawn = {
            api,
            candles,
            data: null,
            precision: 2,
            superTrend: {},
            wave: null,
            levelLines: [],
            positionLines: [],
            showLevels: true,
            hoverTime: null,
            fills: [],
            marks: [],
            tradeMarkers: createSeriesMarkers(candles, []),
            labelKey: '',
        };

        drawn.current = handle;

        // The figures at the top follow the crosshair; away from the chart they show the newest candle.
        const onMove = (param: MouseEventParams<Time>) => {
            const time = param.time === undefined ? null : Number(param.time);

            if (time === handle.hoverTime || !handle.data) {
                return;
            }

            handle.hoverTime = time;
            setLegend(legendFor(handle));
        };

        api.subscribeCrosshairMove(onMove);

        // Zooming and scrolling change how much room the tags on your arrows have, so some come and go.
        const onZoom = () => {
            if (
                handle.marks.length > 0 &&
                tagsThatFit(handle).join() !== handle.labelKey
            ) {
                drawTradeMarks(handle);
            }
        };

        api.timeScale().subscribeVisibleLogicalRangeChange(onZoom);

        return () => {
            api.unsubscribeCrosshairMove(onMove);
            api.timeScale().unsubscribeVisibleLogicalRangeChange(onZoom);
            api.remove();
            drawn.current = null;
        };
    }, []);

    // The theme: the colours the library draws with are set in code, so they follow the class.
    useEffect(() => {
        const colors = palette(dark);

        drawn.current?.api.applyOptions({
            layout: {
                textColor: colors.text,
                panes: { separatorColor: colors.grid },
            },
            grid: { horzLines: { color: colors.grid } },
            rightPriceScale: { borderColor: colors.grid },
            timeScale: { borderColor: colors.grid },
            crosshair: {
                vertLine: {
                    color: colors.crosshair,
                    labelBackgroundColor: colors.label,
                },
                horzLine: {
                    color: colors.crosshair,
                    labelBackgroundColor: colors.label,
                },
            },
        });
    }, [dark]);

    // The data: a full load when the coin or the timeframe changes, then a small refresh every few seconds.
    useEffect(() => {
        const handle = drawn.current;

        if (!handle) {
            return;
        }

        let cancelled = false;
        let timer: ReturnType<typeof setInterval> | undefined;

        const fetchChart = async (since?: number): Promise<ChartPayload> => {
            const res = await fetch(
                `${marketChartRoute.url()}?symbol=${encodeURIComponent(symbol)}&tf=${tf}${since ? `&since=${since}` : ''}`,
                { headers: { Accept: 'application/json' } },
            );
            const json = await res.json().catch(() => null);

            if (!res.ok || !json?.success) {
                throw new Error(
                    json?.message ??
                        (res.status === 429
                            ? 'Too many requests — wait a moment.'
                            : 'The chart could not be loaded.'),
                );
            }

            return json.data;
        };

        const refresh = async () => {
            const data = handle.data;

            if (!data || document.visibilityState !== 'visible') {
                return;
            }

            try {
                const last = data.candles[data.candles.length - 1];
                const tail = await fetchChart(last.time);

                if (cancelled || handle.data !== data) {
                    return;
                }

                applyTail(handle, tail);
                setLegend(legendFor(handle));
            } catch {
                // keep showing what is there; the next refresh tries again
            }
        };

        // A new coin or timeframe: nothing of the old one stays on the chart.
        handle.data = null;
        handle.hoverTime = null;
        handle.fills = [];
        drawTradeMarks(handle);
        handle.candles.setData([]);
        clearIndicators(handle);

        fetchChart()
            .then((full) => {
                if (cancelled) {
                    return;
                }

                const { precision, minMove } = pricePrecision(full.price);

                handle.precision = precision;
                // A custom formatter, so the axis and the crosshair read "82,541.84" like the legend does.
                handle.candles.applyOptions({
                    priceFormat: {
                        type: 'custom',
                        formatter: (price: number) =>
                            fmtPrice(price, precision),
                        minMove,
                    },
                });
                handle.data = full;
                handle.candles.setData(
                    full.candles.map((c) => ({ ...c, time: asTime(c.time) })),
                );
                // The price axis fits the new candles. Dragging it switches the automatic fit off,
                // and the old coin's range would leave this coin off screen.
                handle.candles.priceScale().applyOptions({ autoScale: true });

                const n = full.candles.length;

                handle.api.timeScale().setVisibleLogicalRange({
                    from: Math.max(0, n - FIRST_VIEW_CANDLES),
                    to: n + 8,
                });

                setLegend(legendFor(handle));
                setStatus({ key, state: 'ready' });
                setVersion((v) => v + 1);

                timer = setInterval(refresh, POLL_SECONDS * 1000);
            })
            .catch((error: Error) => {
                if (!cancelled) {
                    setStatus({
                        key,
                        state: 'error',
                        message: error.message,
                    });
                }
            });

        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, [symbol, tf, key]);

    // SuperTrend, WaveTrend and the levels, drawn from the data that is there and redrawn as switches change.
    useEffect(() => {
        const handle = drawn.current;

        if (!handle?.data) {
            return;
        }

        handle.showLevels = showLevels;

        syncSuperTrend(handle, supertrend);
        syncWaveTrend(handle, showWaveTrend);
        syncLevels(handle, showLevels);
    }, [version, supertrend, showWaveTrend, showLevels]);

    // Your own lines.
    useEffect(() => {
        const handle = drawn.current;

        if (handle) {
            syncPositionLines(handle, positionLines);
        }
    }, [positionLines, version]);

    // Your completed orders as arrows: read once the candles are there and every half minute after.
    useEffect(() => {
        const handle = drawn.current;

        if (!handle) {
            return;
        }

        if (!showTrades || state !== 'ready' || !handle.data) {
            handle.fills = [];
            drawTradeMarks(handle);

            return;
        }

        let cancelled = false;

        const read = async () => {
            const data = handle.data;

            if (!data) {
                return;
            }

            try {
                const res = await fetch(
                    `${marketChartTradesRoute.url()}?symbol=${encodeURIComponent(symbol)}&since=${data.candles[0].time}`,
                    { headers: { Accept: 'application/json' } },
                );
                const json = await res.json().catch(() => null);

                if (!res.ok || !json?.success) {
                    throw new Error(
                        json?.message ??
                            (res.status === 429
                                ? 'Too many requests — wait a moment.'
                                : 'They could not be loaded.'),
                    );
                }

                if (cancelled || handle.data !== data) {
                    return;
                }

                handle.fills = json.data.fills;
                drawTradeMarks(handle);
                setTradeNote(null);
                setLegend(legendFor(handle));
            } catch (error) {
                if (!cancelled) {
                    setTradeNote({ key, message: (error as Error).message });
                }
            }
        };

        void read();

        const timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void read();
            }
        }, TRADES_POLL_SECONDS * 1000);

        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, [showTrades, state, symbol, key]);

    return (
        <div className="relative h-full w-full">
            <div ref={containerRef} className="absolute inset-0" />

            <ChartLegend
                symbol={symbol}
                tf={tf}
                legend={state === 'ready' ? legend : null}
                supertrend={supertrend}
                showTrades={showTrades}
                tradeNote={tradeNote?.key === key ? tradeNote.message : null}
            />

            {state === 'loading' && (
                <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-xs text-muted-foreground">
                    Loading {coinLabel(symbol)}…
                </p>
            )}
            {status?.key === key && status.state === 'error' && (
                <p className="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-xs text-red-500">
                    {status.message}
                </p>
            )}
        </div>
    );
}

/** The coin, the timeframe and the figures of one candle at the top left of the chart, with what you did on it. */
function ChartLegend({
    symbol,
    tf,
    legend,
    supertrend,
    showTrades,
    tradeNote,
}: {
    symbol: string;
    tf: ChartTimeframe;
    legend: Legend | null;
    supertrend: Record<string, boolean>;
    showTrades: boolean;
    /** Why your trades could not be read, if they could not. */
    tradeNote: string | null;
}) {
    const tone = legend?.readout.up ? 'text-emerald-500' : 'text-red-500';

    return (
        <div className="pointer-events-none absolute top-2 left-3 z-10 flex max-w-[calc(100%-6rem)] flex-col gap-0.5 rounded bg-card/70 px-1.5 py-0.5 text-[11px] tabular-nums backdrop-blur-sm">
            <p className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <span className="text-xs font-semibold text-foreground">
                    {coinLabel(symbol)} · {tf}
                </span>
                {legend && (
                    <>
                        <span className="text-muted-foreground">
                            {legend.time}
                        </span>
                        <span className={tone}>
                            <span className="text-muted-foreground">O</span>
                            {legend.readout.open}{' '}
                            <span className="text-muted-foreground">H</span>
                            {legend.readout.high}{' '}
                            <span className="text-muted-foreground">L</span>
                            {legend.readout.low}{' '}
                            <span className="text-muted-foreground">C</span>
                            {legend.readout.close} {legend.readout.change}
                        </span>
                    </>
                )}
            </p>

            {legend?.supertrend
                .filter((s) => s.value !== '' && supertrend[s.name])
                .map((s) => (
                    <p
                        key={s.name}
                        className="flex gap-2 text-muted-foreground"
                    >
                        <span>{s.label}</span>
                        <span
                            className={
                                s.trend === 1
                                    ? 'text-emerald-500'
                                    : 'text-red-500'
                            }
                        >
                            {s.value}
                        </span>
                    </p>
                ))}

            {showTrades &&
                legend?.trades.map((trade, i) => (
                    <p
                        key={`${i}:${trade.text}`}
                        className={
                            trade.buy ? 'text-emerald-500' : 'text-red-500'
                        }
                    >
                        {trade.buy ? '▲' : '▼'} {trade.text}
                    </p>
                ))}

            {showTrades && tradeNote && (
                <p className="text-amber-500">Your trades: {tradeNote}</p>
            )}
        </div>
    );
}

// ─── Drawing ────────────────────────────────────────────────────────────────

/** The figures for the candle under the crosshair, or the newest candle while the crosshair is away. */
function legendFor(handle: Drawn): Legend | null {
    const data = handle.data;

    if (!data) {
        return null;
    }

    const index =
        handle.hoverTime === null
            ? data.candles.length - 1
            : data.candles.findIndex((c) => c.time === handle.hoverTime);

    return index === -1
        ? null
        : buildLegend(data, index, handle.precision, undefined, handle.marks);
}

/** Which arrows have room for their tag right now, at the chart's present zoom and scroll (see labelledMarks). */
function tagsThatFit(handle: Drawn): boolean[] {
    const scale = handle.api.timeScale();
    const first = scale.logicalToCoordinate(0 as Logical);
    const second = scale.logicalToCoordinate(1 as Logical);

    // Before the chart has a size there is nothing to measure: assume the default spacing and no edges.
    if (first === null || second === null || second <= first) {
        return labelledMarks(handle.marks, 7);
    }

    return labelledMarks(handle.marks, second - first, {
        origin: first,
        width: scale.width(),
    });
}

/**
 * Your fills as arrows at the price they filled: a buy points up from under that price in green,
 * a sell points down from over it in red. Each is tagged with what it did ("Open L $520") as far
 * as the tags have room.
 */
function drawTradeMarks(handle: Drawn): void {
    const data = handle.data;

    handle.marks = data
        ? tradeMarks(
              handle.fills,
              data.candles.map((c) => c.time),
              data.seconds,
          )
        : [];

    const labelled = tagsThatFit(handle);

    handle.labelKey = labelled.join();
    handle.tradeMarkers.setMarkers(
        handle.marks.map((mark, i) => ({
            time: asTime(mark.time),
            position: isBuy(mark.side)
                ? ('atPriceBottom' as const)
                : ('atPriceTop' as const),
            price: mark.price,
            shape: isBuy(mark.side)
                ? ('arrowUp' as const)
                : ('arrowDown' as const),
            color: markColor(mark.side),
            ...(labelled[i] ? { text: markText(mark) } : {}),
            size: 1,
        })),
    );
}

/** A new full or partial result: draw the changed candles and indicator values, and keep the merged data. */
function applyTail(handle: Drawn, tail: ChartPayload): void {
    if (!handle.data || tail.candles.length === 0) {
        return;
    }

    const merged = mergeTail(handle.data, tail);
    const times = tail.candles.map((c) => c.time);

    for (const c of tail.candles) {
        handle.candles.update({ ...c, time: asTime(c.time) });
    }

    // SuperTrend is redrawn whole: a flip makes the last point before the new gap hide its join, and that point is already on the chart.
    for (const [name, lines] of Object.entries(handle.superTrend)) {
        const points = superTrendPoints(
            merged.candles.map((c) => c.time),
            merged.supertrend[name],
        );

        lines.up.setData(points.up.map(seriesPoint));
        lines.down.setData(points.down.map(seriesPoint));
    }

    if (handle.wave) {
        linePoints(times, tail.wavetrend.wt1).forEach((p) =>
            handle.wave!.wt1.update(seriesPoint(p)),
        );
        linePoints(times, tail.wavetrend.wt2).forEach((p) =>
            handle.wave!.wt2.update(seriesPoint(p)),
        );
        setCrossMarkers(handle, merged);
    }

    const levelsMoved =
        JSON.stringify(handle.data.levels) !== JSON.stringify(merged.levels);

    handle.data = merged;

    // The day, week or month rolled over: the levels are new.
    if (levelsMoved && handle.showLevels) {
        syncLevels(handle, true);
    }
}

/** Takes the indicator lines off the chart (the next coin or timeframe draws its own). */
function clearIndicators(handle: Drawn): void {
    for (const lines of Object.values(handle.superTrend)) {
        handle.api.removeSeries(lines.up);
        handle.api.removeSeries(lines.down);
    }

    handle.superTrend = {};

    if (handle.wave) {
        handle.api.removeSeries(handle.wave.wt1);
        handle.api.removeSeries(handle.wave.wt2);
        handle.wave = null;
    }
}

/** A point as the chart library takes it: a gap has no value, and a point may carry its own colour. */
const seriesPoint = (p: LinePoint) =>
    p.value === undefined
        ? { time: asTime(p.time) }
        : p.color
          ? { time: asTime(p.time), value: p.value, color: p.color }
          : { time: asTime(p.time), value: p.value };

function lineSeriesFor(
    handle: Drawn,
    color: string,
    width: LineWidth,
    pane = 0,
) {
    return handle.api.addSeries(
        LineSeries,
        {
            color,
            lineWidth: width,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
        },
        pane,
    );
}

/** One up line and one down line per SuperTrend switched on; the ones switched off are taken away. */
function syncSuperTrend(handle: Drawn, wanted: Record<string, boolean>): void {
    const data = handle.data!;
    const times = data.candles.map((c) => c.time);

    for (const { name } of SUPERTREND_SETTINGS) {
        const on = wanted[name] === true && data.supertrend[name] !== undefined;
        const existing = handle.superTrend[name];

        if (!on && existing) {
            handle.api.removeSeries(existing.up);
            handle.api.removeSeries(existing.down);
            delete handle.superTrend[name];
        }

        if (on && !existing) {
            // The 12/2.5 on the trader's chart is the bold one; 10/3 sits behind it, finer.
            const bold = name === SUPERTREND_SETTINGS[0].name;
            const up = lineSeriesFor(
                handle,
                bold ? UP : '#16a34a99',
                bold ? 2 : 1,
            );
            const down = lineSeriesFor(
                handle,
                bold ? '#b45151' : '#ef444499',
                bold ? 2 : 1,
            );
            const points = superTrendPoints(times, data.supertrend[name]);

            up.setData(points.up.map(seriesPoint));
            down.setData(points.down.map(seriesPoint));
            handle.superTrend[name] = { up, down };
        }
    }
}

const waveColors = { wt1: '#22c55e', wt2: '#ef4444' };

function setCrossMarkers(handle: Drawn, data: ChartPayload): void {
    const times = data.candles.map((c) => c.time);

    handle.wave?.markers.setMarkers(
        waveTrendCrosses(times, data.wavetrend.wt1, data.wavetrend.wt2).map(
            (cross) => ({
                time: asTime(cross.time),
                position: 'inBar' as const,
                shape: 'circle' as const,
                color: cross.direction === 'up' ? '#4ade80' : '#f87171',
                size: 1,
            }),
        ),
    );
}

/** The WaveTrend pane under the candles: WT1 and WT2, the ±53 and ±60 lines, and a dot at every cross. */
function syncWaveTrend(handle: Drawn, on: boolean): void {
    const data = handle.data!;

    if (!on && handle.wave) {
        handle.api.removeSeries(handle.wave.wt1);
        handle.api.removeSeries(handle.wave.wt2);
        handle.wave = null;
    }

    if (on && !handle.wave) {
        const times = data.candles.map((c) => c.time);
        const wt1 = handle.api.addSeries(
            LineSeries,
            {
                color: waveColors.wt1,
                lineWidth: 2,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
            },
            1,
        );
        const wt2 = handle.api.addSeries(
            LineSeries,
            {
                color: waveColors.wt2,
                lineWidth: 1,
                lineStyle: LineStyle.Dashed,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
            },
            1,
        );

        wt1.setData(linePoints(times, data.wavetrend.wt1).map(seriesPoint));
        wt2.setData(linePoints(times, data.wavetrend.wt2).map(seriesPoint));

        for (const [price, color, style] of [
            [60, '#ef4444', LineStyle.Solid],
            [53, '#ef444499', LineStyle.Dashed],
            [0, '#71717a', LineStyle.Solid],
            [-53, '#22c55e99', LineStyle.Dashed],
            [-60, '#22c55e', LineStyle.Solid],
        ] as const) {
            wt1.createPriceLine({
                price,
                color,
                lineWidth: 1,
                lineStyle: style,
                axisLabelVisible: false,
                title: '',
            });
        }

        handle.wave = { wt1, wt2, markers: createSeriesMarkers(wt2, []) };
        setCrossMarkers(handle, data);
        handle.api.panes()[1]?.setHeight(170);
    }
}

/** The previous day/week/month/year lines, with a tag on the price axis for each. */
function syncLevels(handle: Drawn, on: boolean): void {
    handle.levelLines.forEach((line) => handle.candles.removePriceLine(line));
    handle.levelLines = [];

    if (!on) {
        return;
    }

    for (const level of handle.data!.levels) {
        const style = levelStyle(level);

        handle.levelLines.push(
            handle.candles.createPriceLine({
                price: level.price,
                color: style.color,
                lineWidth: style.width,
                lineStyle: style.dashed ? LineStyle.Dashed : LineStyle.Solid,
                axisLabelVisible: true,
                title: level.label,
            }),
        );
    }
}

/** Entries, break-even, liquidation and SL/TP of the open legs in this coin. */
function syncPositionLines(handle: Drawn, lines: ChartLine[]): void {
    handle.positionLines.forEach((line) =>
        handle.candles.removePriceLine(line),
    );
    handle.positionLines = [];

    for (const line of lines) {
        const style = POSITION_LINE_STYLE[line.kind];

        if (!style) {
            continue;
        }

        handle.positionLines.push(
            handle.candles.createPriceLine({
                price: line.price,
                color: style.color,
                lineWidth: style.width,
                lineStyle: style.dashed ? LineStyle.Dashed : LineStyle.Solid,
                axisLabelVisible: true,
                title: positionLineTitle(line.label, line.kind),
            }),
        );
    }
}
