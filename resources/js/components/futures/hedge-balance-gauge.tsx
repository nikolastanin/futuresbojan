import { Gauge } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { SignalPreview } from '@/hooks/use-signal-previews';
import { coinLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    long: Position;
    short: Position;
    signal: SignalPreview | 'loading' | 'error' | undefined;
}

// Price move in the long's favor (NOT margin ROI% — at the leverage this app is
// built for, a tiny price wiggle is already triple-digit ROI%, which would trip
// "protect gains" on noise) at which we shift from "help this recover toward
// breakeven" into "the long is sitting on a real gain — add short to lock some
// of it in before a pullback" — the exact behavior confirmed by the user.
const PROFIT_PROTECT_PRICE_MOVE_PCT = 3;

// Once the short leg is within this % of matching the long's live notional, ease
// the suggestion off even if every other signal still favors adding.
const NEAR_TARGET_PCT = 90;

const fmt = (n: number) =>
    new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n);

interface Suggestion {
    label: string;
    color: string;
}

function suggestionFor(
    score: number,
    atTarget: boolean,
    incrementAmount: number,
): Suggestion {
    if (atTarget) {
        return {
            label: 'At target — fully hedged',
            color: 'text-muted-foreground',
        };
    }

    if (score >= 75) {
        return {
            label: `Strong entry — add $${incrementAmount} to short`,
            color: 'text-emerald-500',
        };
    }

    if (score >= 60) {
        return {
            label: `Good entry — add $${incrementAmount} to short`,
            color: 'text-emerald-400',
        };
    }

    if (score >= 40) {
        return {
            label: 'Neutral — small add to short OK',
            color: 'text-amber-500',
        };
    }

    if (score >= 20) {
        return {
            label: 'Hold short — wait for a better entry',
            color: 'text-amber-600',
        };
    }

    return { label: 'Consider reducing short', color: 'text-red-500' };
}

/**
 * A live "should I add to the short leg right now" gauge for a manually-managed
 * hedge pair, built around the exact pattern the user described: adding to short
 * in $100 steps to pull the combined position toward breakeven while it's still
 * underwater, then adding more aggressively once the long is sitting on a real
 * gain — to lock some of that gain in via the hedge before a pullback. The
 * technical badges (trend/momentum/RSI/etc, same SignalEngine as everywhere
 * else) tilt the suggestion within whichever zone is currently active, they
 * don't override it. Purely informational — no button fires an order from here,
 * same as PriceLevels and the signal badges.
 */
export function HedgeBalanceGauge({ long, short, signal }: Props) {
    const hasSignal = signal && signal !== 'loading' && signal !== 'error';

    const longNotional = long.positionValue;
    const shortNotional = short.positionValue;
    const targetNotional = longNotional;
    const remainingToTarget = Math.max(targetNotional - shortNotional, 0);
    const targetProgressPct =
        targetNotional > 0
            ? Math.min((shortNotional / targetNotional) * 100, 100)
            : 0;
    const atTarget = targetProgressPct >= 100;

    const longPnl = long.unrealizedPnl;
    const shortPnl = short.unrealizedPnl;
    const combinedPnl = longPnl + shortPnl;
    // Margin ROI% — shown to the user as a familiar leveraged-PnL figure, but
    // never used to drive the gauge itself (see PROFIT_PROTECT_PRICE_MOVE_PCT).
    const longRoiPct = long.im > 0 ? (longPnl / long.im) * 100 : null;
    const longPriceMovePct =
        long.openAvgPrice > 0
            ? ((long.fairPrice - long.openAvgPrice) / long.openAvgPrice) *
              100 *
              (long.positionType === 1 ? 1 : -1)
            : null;

    const inProtectZone =
        longPriceMovePct !== null &&
        longPriceMovePct >= PROFIT_PROTECT_PRICE_MOVE_PCT;
    const inRecoveryZone = !inProtectZone && combinedPnl < 0;

    // Technical lean: the same LONG/SHORT confidence "Bot says" already scores for
    // this symbol, remapped so a SHORT read pushes the needle up (toward "add")
    // and a LONG read pulls it down (toward "hold/reduce").
    const techBias = hasSignal
        ? signal.direction === 'SHORT'
            ? signal.confidence
            : signal.direction === 'LONG'
              ? -signal.confidence
              : 0
        : 0;

    let score = 50 + techBias * 3;

    if (inProtectZone) {
        score += 20;
    } else if (inRecoveryZone && (!hasSignal || signal.direction !== 'SHORT')) {
        score -= 10;
    }

    if (atTarget) {
        score = Math.min(score, 10);
    } else if (targetProgressPct >= NEAR_TARGET_PCT) {
        score -= 15;
    }

    score = Math.max(0, Math.min(100, score));

    const incrementAmount = Math.min(
        inProtectZone ? 200 : 100,
        Math.max(Math.round(remainingToTarget / 50) * 50, 50),
    );
    const suggestion = suggestionFor(score, atTarget, incrementAmount);

    const zoneDescription = atTarget
        ? 'Short matches the long’s live notional — fully hedged.'
        : inProtectZone
          ? `Price has moved ${longPriceMovePct!.toFixed(1)}% in the long's favor — protect-gains mode, leaning into adds even without a perfect technical entry.`
          : inRecoveryZone
            ? 'Combined PnL is still negative — adds are gated on a technical SHORT read rather than suggested blindly.'
            : 'Combined PnL is near breakeven — the suggestion is mostly technical right now.';

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2.5">
            <div className="flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <Gauge className="size-3.5 text-blue-400" />
                    {coinLabel(long.symbol)} hedge balance
                </p>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span
                            className={`cursor-default text-xs font-bold ${suggestion.color}`}
                        >
                            {suggestion.label}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        className="max-w-[260px] text-[11px]"
                    >
                        {zoneDescription} Technical read:{' '}
                        {hasSignal
                            ? `${signal.direction ?? 'flat'} (${signal.confidence})`
                            : 'loading'}
                        .
                    </TooltipContent>
                </Tooltip>
            </div>

            {/* Gradient needle — red (reduce) through amber (hold) to emerald (add),
                same visual language as the Price Levels strip. */}
            <div className="relative h-2 w-full rounded-full bg-gradient-to-r from-red-500/30 via-amber-500/30 to-emerald-500/30">
                <div
                    className="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 transition-[left] duration-700 ease-out"
                    style={{ left: `${score}%` }}
                >
                    <div className="size-3 rounded-full border-2 border-foreground bg-background" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                <Stat
                    label="Long PnL"
                    value={`${longPnl >= 0 ? '+' : ''}$${fmt(longPnl)}${longRoiPct !== null ? ` (${longRoiPct >= 0 ? '+' : ''}${longRoiPct.toFixed(0)}%)` : ''}`}
                    color={longPnl >= 0 ? 'text-emerald-500' : 'text-red-500'}
                />
                <Stat
                    label="Short PnL"
                    value={`${shortPnl >= 0 ? '+' : ''}$${fmt(shortPnl)}`}
                    color={shortPnl >= 0 ? 'text-emerald-500' : 'text-red-500'}
                />
                <Stat
                    label="Combined"
                    value={`${combinedPnl >= 0 ? '+' : ''}$${fmt(combinedPnl)}`}
                    color={
                        combinedPnl >= 0 ? 'text-emerald-500' : 'text-red-500'
                    }
                />
                <Stat
                    label="Short vs long"
                    value={`${targetProgressPct.toFixed(0)}% ($${fmt(remainingToTarget)} left)`}
                />
            </div>
        </div>
    );
}

function Stat({
    label,
    value,
    color = 'text-foreground',
}: {
    label: string;
    value: string;
    color?: string;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[9px] text-muted-foreground">{label}</span>
            <span className={`text-[11px] font-medium tabular-nums ${color}`}>
                {value}
            </span>
        </div>
    );
}
