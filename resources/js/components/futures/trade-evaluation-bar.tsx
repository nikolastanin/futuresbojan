import type { SlTpPrediction } from '@/types/futures';

interface Props {
    direction: 'LONG' | 'SHORT';
    entryPrice: number;
    currentPrice: number | null;
    prediction: SlTpPrediction | null;
}

/**
 * Compact two-sided progress bar showing how far a position has moved, as a % of
 * price, toward its predicted take-profit (right, green) or stop-loss (left, red).
 * A smaller sibling of the bot's Trade Evaluation panel on the Bot Stats & PNL page —
 * same idea, scaled down for the Dashboard's position boxes.
 */
export function TradeEvaluationBar({ direction, entryPrice, currentPrice, prediction }: Props) {
    if (currentPrice === null || prediction === null || entryPrice <= 0) {
        return (
            <div className="flex min-w-[140px] flex-1 flex-col gap-0.5">
                <p className="text-[10px] text-muted-foreground">No SL/TP prediction available</p>
                <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div className="absolute top-0 left-1/2 h-full w-px -translate-x-1/2 bg-border" />
                </div>
            </div>
        );
    }

    const { stop_loss_pct: slPct, take_profit_pct: tpPct } = prediction;
    const priceMovePct = ((currentPrice - entryPrice) / entryPrice) * (direction === 'LONG' ? 1 : -1) * 100;
    const positive = priceMovePct >= 0;
    const target = positive ? tpPct : slPct;
    const pct = target > 0 ? Math.min(Math.abs(priceMovePct) / target, 1) * 100 : 0;
    const barWidth = pct / 2;

    const textColor = positive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400';
    const barColor = positive ? 'bg-emerald-500' : 'bg-red-500';

    return (
        <div className="flex min-w-[140px] flex-1 flex-col gap-0.5">
            <div className="flex items-center justify-between gap-2">
                <span className={`text-[10px] font-medium ${textColor}`}>
                    {pct.toFixed(0)}% to {positive ? 'TP' : 'SL'}
                </span>
                <span className="text-[10px] text-muted-foreground">
                    SL -{slPct.toFixed(1)}% / TP +{tpPct.toFixed(1)}%
                </span>
            </div>
            <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div className="absolute top-0 left-1/2 h-full w-px -translate-x-1/2 bg-border" />
                <div
                    className={`absolute top-0 h-full rounded-full ${barColor}`}
                    style={{
                        left: positive ? '50%' : `${50 - barWidth}%`,
                        width: `${barWidth}%`,
                    }}
                />
            </div>
        </div>
    );
}
