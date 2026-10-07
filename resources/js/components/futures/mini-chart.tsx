import { useLayoutEffect, useRef, useState } from 'react';
import {
    TIMEFRAME_SECONDS,
    candleLayout,
    fitScale,
    placement,
    spreadLabels,
    withLivePrice,
    yFor,
} from '@/lib/mini-chart';
import type { Candle, ChartLine, LineKind } from '@/lib/mini-chart';

interface Props {
    candles: Candle[];
    /** The trader's own lines (entries, break-even, liquidation, stops). */
    lines: ChartLine[];
    /** The live mark price: folded into the last candle and drawn as the price tag. */
    price: number | null;
    tf: string;
    height?: number;
}

/** Room above the highest and below the lowest price, inside the box. */
const PAD_Y = 8;

/** Smallest gap between two gutter labels, so none sit on another. */
const LABEL_GAP = 11;

const LINE_STYLE: Record<
    LineKind,
    { stroke: string; fill: string; dash?: string; width: number }
> = {
    long_entry: {
        stroke: 'stroke-emerald-500',
        fill: 'fill-emerald-500',
        dash: '4 3',
        width: 1,
    },
    short_entry: {
        stroke: 'stroke-red-500',
        fill: 'fill-red-500',
        dash: '4 3',
        width: 1,
    },
    break_even: {
        stroke: 'stroke-amber-500',
        fill: 'fill-amber-500',
        dash: '2 3',
        width: 1,
    },
    liquidation: {
        stroke: 'stroke-red-500',
        fill: 'fill-red-500',
        dash: '1 3',
        width: 1.6,
    },
    stop: {
        stroke: 'stroke-orange-400',
        fill: 'fill-orange-400',
        dash: '6 2',
        width: 1,
    },
    target: {
        stroke: 'stroke-sky-400',
        fill: 'fill-sky-400',
        dash: '6 2',
        width: 1,
    },
    price: { stroke: 'stroke-foreground', fill: 'fill-foreground', width: 1 },
};

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

const nowSeconds = () => Math.floor(Date.now() / 1000);

/**
 * A small candlestick chart drawn straight to SVG — no charting library. It shows the
 * last stretch of candles with the trader's own prices laid over them. The scale follows
 * the candles; a line far from them (a distant liquidation price) is not allowed to
 * flatten them and is pinned to the edge with an arrow instead.
 */
export function MiniChart({ candles, lines, price, tf, height = 118 }: Props) {
    const boxRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);

    // Measure before the first paint (so there is no empty frame, and it works even when
    // the page is not being drawn), then follow resizes.
    useLayoutEffect(() => {
        const box = boxRef.current;

        if (!box) {
            return;
        }

        // The box's width can only be read from the DOM after it has rendered.
        setWidth(Math.floor(box.getBoundingClientRect().width));

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.floor(entry.contentRect.width)),
        );

        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    const live = withLivePrice(
        candles,
        price,
        TIMEFRAME_SECONDS[tf] ?? 900,
        nowSeconds(),
    );
    const allLines: ChartLine[] = [
        ...lines,
        ...(price !== null
            ? [
                  {
                      kind: 'price' as const,
                      price,
                      label: fmtPrice(price),
                      title: `Live price ${fmtPrice(price)}`,
                  },
              ]
            : []),
    ];
    const scale = fitScale(
        live,
        lines.map((line) => line.price),
    );

    // A narrow phone gets a slimmer label gutter.
    const gutter = width < 480 ? 78 : 96;
    const chartWidth = Math.max(width - gutter, 0);
    const innerHeight = height - PAD_Y * 2;

    return (
        <div ref={boxRef} className="w-full">
            {width > 0 && scale && live.length > 0 && (
                <ChartSvg
                    candles={live}
                    lines={allLines}
                    scale={scale}
                    tf={tf}
                    width={width}
                    height={height}
                    chartWidth={chartWidth}
                    innerHeight={innerHeight}
                />
            )}
        </div>
    );
}

function ChartSvg({
    candles,
    lines,
    scale,
    tf,
    width,
    height,
    chartWidth,
    innerHeight,
}: {
    candles: Candle[];
    lines: ChartLine[];
    scale: { min: number; max: number };
    tf: string;
    width: number;
    height: number;
    chartWidth: number;
    innerHeight: number;
}) {
    const y = (value: number) => yFor(value, scale, PAD_Y, innerHeight);
    const { step, body } = candleLayout(candles.length, chartWidth);

    // Where each line sits, and which are off the drawn range (pinned to an edge instead).
    const placed = lines.map((line) => {
        const where = placement(line.price, scale);
        const wantedY =
            where === 'above'
                ? PAD_Y
                : where === 'below'
                  ? height - PAD_Y
                  : y(line.price);

        return { line, where, wantedY };
    });

    const labelYs = spreadLabels(
        placed.map((p) => p.wantedY),
        LABEL_GAP,
        PAD_Y,
        height - PAD_Y,
    );

    return (
        <svg
            width={width}
            height={height}
            role="img"
            aria-label={`${candles.length} ${tf} candles with your entries and liquidation prices`}
            className="block"
        >
            {/* The trader's lines, behind the candles */}
            {placed.map(({ line, where, wantedY }) => {
                if (where !== 'on' || line.kind === 'price') {
                    return null;
                }

                const style = LINE_STYLE[line.kind];

                return (
                    <line
                        key={`${line.kind}-${line.label}`}
                        x1={0}
                        x2={chartWidth}
                        y1={wantedY}
                        y2={wantedY}
                        className={style.stroke}
                        strokeWidth={style.width}
                        strokeDasharray={style.dash}
                        opacity={0.85}
                    />
                );
            })}

            {/* Candles */}
            {candles.map((c, i) => {
                const x = i * step + step / 2;
                const up = c.close >= c.open;
                const color = up
                    ? 'fill-emerald-500 stroke-emerald-500'
                    : 'fill-red-500 stroke-red-500';
                const top = y(Math.max(c.open, c.close));
                const bodyHeight = Math.max(
                    1,
                    Math.abs(y(c.open) - y(c.close)),
                );
                const forming = i === candles.length - 1;

                return (
                    <g
                        key={c.time}
                        className={color}
                        opacity={forming ? 0.85 : 1}
                    >
                        <title>
                            {`${new Date(c.time * 1000).toLocaleString([], {
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                            })} — O ${fmtPrice(c.open)} H ${fmtPrice(c.high)} L ${fmtPrice(c.low)} C ${fmtPrice(c.close)}${forming ? ' (still forming)' : ''}`}
                        </title>
                        <line
                            x1={x}
                            x2={x}
                            y1={y(c.high)}
                            y2={y(c.low)}
                            strokeWidth={1}
                        />
                        <rect
                            x={x - body / 2}
                            y={top}
                            width={body}
                            height={bodyHeight}
                            strokeWidth={0}
                        />
                    </g>
                );
            })}

            {/* Labels in the gutter, with a short connector when one had to move to clear another */}
            {placed.map(({ line, where, wantedY }, i) => {
                const style = LINE_STYLE[line.kind];
                const labelY = labelYs[i];
                const arrow =
                    where === 'above' ? '▲ ' : where === 'below' ? '▼ ' : '';

                if (line.kind === 'price') {
                    return (
                        <g key="price">
                            <title>{line.title}</title>
                            <line
                                x1={0}
                                x2={chartWidth}
                                y1={wantedY}
                                y2={wantedY}
                                className={style.stroke}
                                strokeWidth={0.75}
                                opacity={0.55}
                            />
                            <rect
                                x={chartWidth + 2}
                                y={labelY - 6.5}
                                width={width - chartWidth - 4}
                                height={13}
                                rx={2}
                                className="fill-foreground"
                            />
                            <text
                                x={chartWidth + 6}
                                y={labelY}
                                dominantBaseline="central"
                                fontSize={9.5}
                                fontWeight={600}
                                className="fill-background tabular-nums"
                            >
                                {line.label}
                            </text>
                        </g>
                    );
                }

                return (
                    <g key={`${line.kind}-${line.label}`}>
                        <title>{line.title}</title>
                        {Math.abs(labelY - wantedY) > 2 && where === 'on' && (
                            <line
                                x1={chartWidth}
                                x2={chartWidth + 4}
                                y1={wantedY}
                                y2={labelY}
                                className={style.stroke}
                                strokeWidth={0.75}
                                opacity={0.6}
                            />
                        )}
                        <text
                            x={chartWidth + 6}
                            y={labelY}
                            dominantBaseline="central"
                            fontSize={9}
                            className={`${style.fill} tabular-nums`}
                        >
                            {arrow}
                            {line.label}
                        </text>
                    </g>
                );
            })}
        </svg>
    );
}
