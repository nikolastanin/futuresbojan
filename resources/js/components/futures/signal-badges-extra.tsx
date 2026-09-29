import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    candlePatternBadge,
    dominanceBadge,
    fairValueGapBadge,
    macdBadge,
    rsiBadge,
    volumeTrendBadge,
    waveTrendDivergenceBadge,
} from '@/hooks/use-signal-previews';
import type { SignalPreview } from '@/hooks/use-signal-previews';

/** A second tier of read-outs beyond Trend/Momentum/Structure/Volatility/24h — RSI, MACD,
 * volume, and USDT dominance are always-on gauges; candle pattern, fair value gap, and
 * WaveTrend divergence only show up when that indicator actually has something to say
 * (same spirit as Structure). Rendered as pills so the row can grow without reflowing
 * the rest of the form — hover one for what it means. */
export function SignalBadgesExtra({ signal }: { signal: SignalPreview }) {
    const badges = [
        rsiBadge(signal.rsi),
        macdBadge(signal.macd),
        volumeTrendBadge(signal.volume_trend),
        dominanceBadge(signal.dominance),
        candlePatternBadge(signal.candle_pattern),
        fairValueGapBadge(signal.fair_value_gap),
        waveTrendDivergenceBadge(signal.wavetrend_divergence),
    ].filter((badge): badge is NonNullable<typeof badge> => badge !== null);

    if (badges.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1">
            {badges.map((badge) => (
                <Tooltip key={badge.label}>
                    <TooltipTrigger asChild>
                        <Badge
                            variant="outline"
                            className={`cursor-default border-border bg-background text-[10px] font-medium ${badge.color}`}
                        >
                            {badge.label}
                        </Badge>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        className="max-w-[240px] text-[11px]"
                    >
                        {badge.description}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
