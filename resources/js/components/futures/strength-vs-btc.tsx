import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { AnalysisExtras } from '@/hooks/use-analysis-extras';
import { coinLabel } from '@/types/futures';

interface Props {
    symbol: string;
    extras: AnalysisExtras | 'loading' | 'error';
}

const fmtPct = (n: number) => `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;

/**
 * Whether the coin is leading or lagging BTC over the last 1H / 4H / 24H — its own %
 * move minus BTC's over the same window. A hedge on an alt is partly a bet on that
 * alt's strength relative to the market, which the coin's own indicators don't show.
 * Renders nothing for BTC itself or until the data is there.
 */
export function StrengthVsBtc({ symbol, extras }: Props) {
    if (typeof extras !== 'object' || extras.vs_btc === null) {
        return null;
    }

    const windows = Object.entries(extras.vs_btc);
    const diffs = windows
        .map(([, w]) => w.diff)
        .filter((d): d is number => d !== null);

    if (diffs.length === 0) {
        return null;
    }

    const verdict = diffs.every((d) => d > 0)
        ? { label: 'Leading BTC', color: 'text-emerald-500' }
        : diffs.every((d) => d < 0)
          ? { label: 'Lagging BTC', color: 'text-red-500' }
          : { label: 'Mixed vs BTC', color: 'text-muted-foreground' };

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
            <span className={`font-semibold ${verdict.color}`}>
                {verdict.label}
            </span>
            {windows.map(([label, w]) => (
                <Tooltip key={label}>
                    <TooltipTrigger asChild>
                        <span
                            className={`cursor-default tabular-nums ${
                                w.diff === null
                                    ? 'text-muted-foreground'
                                    : w.diff >= 0
                                      ? 'text-emerald-500'
                                      : 'text-red-500'
                            }`}
                        >
                            {label} {w.diff === null ? '—' : fmtPct(w.diff)}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        className="max-w-[240px] text-[11px]"
                    >
                        Over the last {label}, {coinLabel(symbol)} moved{' '}
                        {w.coin === null ? 'n/a' : fmtPct(w.coin)} and BTC moved{' '}
                        {w.btc === null ? 'n/a' : fmtPct(w.btc)}. The number
                        shown is the difference: positive means{' '}
                        {coinLabel(symbol)} is outperforming BTC.
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
