import { MiniChart } from '@/components/futures/mini-chart';
import type { Candle, ChartLine } from '@/lib/mini-chart';
import {
    breakEvenPrice,
    exposureBySymbol,
    legsFromPositions,
} from '@/lib/risk-math';
import { coinLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    symbol: string;
    /** Every open leg in this coin (one, or both sides of a hedge). */
    legs: Position[];
    tf: string;
    /** The coin's candles; 'error' if they could not be had, undefined while loading. */
    candles: Candle[] | 'error' | undefined;
    /** The price the assistant's comment is waiting on, while that comment still holds. */
    watch?: ChartWatch | null;
}

/** The price the assistant is waiting on, and what it is waiting to see there. */
export interface ChartWatch {
    price: number;
    label: string | null;
    when: string;
}

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * The trader's own prices for one coin, as lines to lay over the candles: each leg's
 * entry, liquidation price and armed stop/target, plus the pair's combined break-even
 * when both sides are open.
 */
export function chartLines(
    legs: Position[],
    price: number | null,
    watch: ChartWatch | null = null,
): ChartLine[] {
    const lines: ChartLine[] = [];

    const away = (target: number) =>
        price !== null && price > 0
            ? ` — ${(((target - price) / price) * 100).toFixed(1)}% from here`
            : '';

    for (const leg of legs) {
        const long = leg.positionType === 1;
        const side = long ? 'L' : 'S';
        const name = long ? 'long' : 'short';

        lines.push({
            kind: long ? 'long_entry' : 'short_entry',
            price: leg.openAvgPrice,
            label: `${side} ${fmtPrice(leg.openAvgPrice)}`,
            title: `Your ${name} entry, ${fmtPrice(leg.openAvgPrice)}${away(leg.openAvgPrice)}`,
        });

        if (leg.liquidatePrice > 0) {
            lines.push({
                kind: 'liquidation',
                price: leg.liquidatePrice,
                label: `${side} liq ${fmtPrice(leg.liquidatePrice)}`,
                title: `Liquidation price of your ${name}, ${fmtPrice(leg.liquidatePrice)}${away(leg.liquidatePrice)} (MEXC's own figure)`,
            });
        }

        if (leg.active_sl_tp?.stop_loss) {
            lines.push({
                kind: 'stop',
                price: leg.active_sl_tp.stop_loss,
                label: `${side} SL ${fmtPrice(leg.active_sl_tp.stop_loss)}`,
                title: `Armed stop-loss on your ${name}, ${fmtPrice(leg.active_sl_tp.stop_loss)}${away(leg.active_sl_tp.stop_loss)}`,
            });
        }

        if (leg.active_sl_tp?.take_profit) {
            lines.push({
                kind: 'target',
                price: leg.active_sl_tp.take_profit,
                label: `${side} TP ${fmtPrice(leg.active_sl_tp.take_profit)}`,
                title: `Armed take-profit on your ${name}, ${fmtPrice(leg.active_sl_tp.take_profit)}${away(leg.active_sl_tp.take_profit)}`,
            });
        }
    }

    // Only a pair has a break-even worth a line of its own; one leg's is just its entry.
    const exposure = exposureBySymbol(legsFromPositions(legs))[0];
    const breakEven =
        exposure && exposure.longQty > 0 && exposure.shortQty > 0
            ? breakEvenPrice(exposure)
            : null;

    if (breakEven !== null) {
        lines.push({
            kind: 'break_even',
            price: breakEven,
            label: `BE ${fmtPrice(breakEven)}`,
            title: `Combined break-even, ${fmtPrice(breakEven)}${away(breakEven)}: the price at which the long and the short together are exactly at zero`,
        });
    }

    if (watch) {
        lines.push({
            kind: 'watch',
            price: watch.price,
            label: `watch ${fmtPrice(watch.price)}`,
            title: `What the assistant is waiting on: ${watch.when ? `${watch.when} ` : ''}${fmtPrice(watch.price)}${watch.label ? ` (${watch.label})` : ''}${away(watch.price)}`,
        });
    }

    return lines;
}

/**
 * The small chart above one coin's position rows: candles with your entries, the pair's
 * break-even, liquidation prices and armed stops drawn over them. Read-only — a glance at
 * where price is against your own numbers, not a charting tool.
 */
export function PositionChart({ symbol, legs, tf, candles, watch }: Props) {
    const price = legs.find((leg) => leg.fairPrice > 0)?.fairPrice ?? null;

    return (
        <div className="flex flex-col gap-1 rounded-lg border border-border bg-background px-2.5 py-2">
            <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                <span>
                    <span className="font-semibold text-foreground">
                        {coinLabel(symbol)}
                    </span>{' '}
                    · {tf} candles
                </span>
                <span className="hidden sm:inline">
                    L/S your long/short entry · BE combined break-even · liq
                    liquidation
                    {watch ? ' · watch what the assistant waits on' : ''}
                </span>
            </div>

            {candles === undefined && (
                <p className="py-6 text-center text-[11px] text-muted-foreground">
                    Loading the chart…
                </p>
            )}
            {candles === 'error' && (
                <p className="py-6 text-center text-[11px] text-red-500">
                    Couldn&apos;t load the chart for {coinLabel(symbol)}.
                </p>
            )}
            {Array.isArray(candles) && (
                <MiniChart
                    candles={candles}
                    lines={chartLines(legs, price, watch)}
                    price={price}
                    tf={tf}
                />
            )}
        </div>
    );
}
