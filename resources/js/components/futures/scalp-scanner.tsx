import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { scalpScan as scalpScanRoute } from '@/routes/futures';
import { coinLabel } from '@/types/futures';

export interface ScalpCandidate {
    symbol: string;
    direction: 'LONG' | 'SHORT';
    strength: number;
    matched_on: string[];
    rsi: number;
    macd_histogram: number;
    macd_stretch_atr: number;
    price: number;
    timeframe: string;
}

interface Props {
    /** Called with the candidate when its Long/Short button is clicked. */
    onOpenOrder?: (candidate: ScalpCandidate) => void;
}

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * On-demand scan of the top-100 coin pool for RSI/MACD-extreme readings on the 15M
 * timeframe — candidates for a quick mean-reversion scalp (a stretched move likely
 * due for a bounce or pullback). Never auto-polls: a full scan touches ~100 coins'
 * worth of candle data, so it's triggered manually via "Scan Now".
 */
export function ScalpScanner({ onOpenOrder }: Props) {
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<ScalpCandidate[] | null>(null);

    const scan = async () => {
        setLoading(true);

        try {
            const res = await fetch(scalpScanRoute.url(), {
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();

            setResults(json.success ? json.data : []);
        } catch {
            setResults([]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                    Scalp Scanner
                </p>
                <Button
                    type="button"
                    size="sm"
                    className="h-7 text-xs"
                    onClick={scan}
                    disabled={loading}
                >
                    {loading ? 'Scanning…' : 'Scan Now'}
                </Button>
            </div>
            <p className="text-[11px] text-muted-foreground">
                Scans the top 100 coins on the 15M timeframe for RSI and/or MACD at an
                extreme — a stretched move likely due for a quick bounce or pullback.
                Informational only; a scan takes a few seconds to run.
            </p>

            {results === null ? (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    Click "Scan Now" to check for scalp setups.
                </p>
            ) : results.length === 0 ? (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    No extreme RSI/MACD readings right now.
                </p>
            ) : (
                <ol className="flex flex-col gap-1.5">
                    {results.map((c, i) => {
                        const isLong = c.direction === 'LONG';

                        return (
                            <li
                                key={`${c.symbol}-${i}`}
                                className="flex flex-col gap-1 rounded-md border border-border bg-muted/30 px-2.5 py-2"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="min-w-0 flex-1 text-sm font-semibold text-foreground">
                                        {coinLabel(c.symbol)}
                                    </span>
                                    <span
                                        className={`shrink-0 text-xs font-bold ${isLong ? 'text-emerald-500' : 'text-red-500'}`}
                                    >
                                        {c.direction}
                                    </span>
                                    <span
                                        className="shrink-0 rounded-full bg-background px-1.5 py-0.5 text-[10px] font-bold text-foreground"
                                        title="Confirmed by RSI, MACD, or both"
                                    >
                                        {c.matched_on.join('+')}
                                    </span>
                                </div>

                                <div className="text-[11px] text-muted-foreground">
                                    RSI {c.rsi} · MACD stretch {c.macd_stretch_atr}x ATR · $
                                    {fmtPrice(c.price)}
                                </div>

                                {onOpenOrder && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        className={`h-7 w-fit text-xs ${
                                            isLong
                                                ? 'bg-emerald-600 text-white hover:bg-emerald-500'
                                                : 'bg-red-600 text-white hover:bg-red-500'
                                        }`}
                                        onClick={() => onOpenOrder(c)}
                                    >
                                        {isLong ? 'Long' : 'Short'} @ ${fmtPrice(c.price)}
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
