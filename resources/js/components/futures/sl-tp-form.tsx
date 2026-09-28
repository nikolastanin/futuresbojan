import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ActiveSlTp, SlTpPrediction } from '@/types/futures';

interface Props {
    direction: 'LONG' | 'SHORT';
    entryPrice: number;
    prediction: SlTpPrediction | null;
    active?: ActiveSlTp | null;
    /** Estimated $ PnL if the currently-armed take-profit price is hit (price-based, fees not included). */
    expectedTpPnl?: number | null;
    /** Estimated $ PnL if the currently-armed stop-loss price is hit (price-based, fees not included). */
    expectedSlPnl?: number | null;
    /** Position size in "$ PnL per $1 of price move" terms (contracts, or margin*leverage/entryPrice
     * for paper positions) — used to live-estimate PnL for whatever price is currently typed into
     * the SL/TP inputs, not just the already-armed exchange values above. */
    contractsNotional?: number;
    submitting: boolean;
    onSubmit: (values: { stopLoss?: number; takeProfit?: number }) => void;
}

const priceFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 8,
});
const pnlFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const fmt = (n: number) => priceFormatter.format(n);

const fmtPnl = (n: number) => `${n >= 0 ? '+' : ''}${pnlFormatter.format(n)}`;

/** Compact stop-loss / take-profit entry row, shared by real and paper position boxes. */
export function SlTpForm({
    direction,
    entryPrice,
    prediction,
    active,
    expectedTpPnl,
    expectedSlPnl,
    contractsNotional = 0,
    submitting,
    onSubmit,
}: Props) {
    const [sl, setSl] = useState('');
    const [tp, setTp] = useState('');

    const applyPrediction = () => {
        if (!prediction) {
            return;
        }

        setSl(String(prediction.stop_loss));
        setTp(String(prediction.take_profit));
    };

    const submit = () => {
        const stopLoss = sl.trim() !== '' ? Number(sl) : undefined;
        const takeProfit = tp.trim() !== '' ? Number(tp) : undefined;

        if (stopLoss === undefined && takeProfit === undefined) {
            return;
        }

        onSubmit({ stopLoss, takeProfit });
    };

    const hasActive = !!(active?.stop_loss || active?.take_profit);

    const dirSign = direction === 'LONG' ? 1 : -1;

    // Live PnL estimate for whatever price is currently typed into the inputs — distinct
    // from expectedSlPnl/expectedTpPnl above, which only cover the already-armed exchange
    // value.
    const typedSlPrice = sl.trim() !== '' ? Number(sl) : null;
    const typedTpPrice = tp.trim() !== '' ? Number(tp) : null;
    const liveSlPnl =
        typedSlPrice !== null &&
        !isNaN(typedSlPrice) &&
        contractsNotional > 0 &&
        entryPrice > 0
            ? contractsNotional * (typedSlPrice - entryPrice) * dirSign
            : null;
    const liveTpPnl =
        typedTpPrice !== null &&
        !isNaN(typedTpPrice) &&
        contractsNotional > 0 &&
        entryPrice > 0
            ? contractsNotional * (typedTpPrice - entryPrice) * dirSign
            : null;

    return (
        <div className="flex flex-col gap-1">
            {/* What's already armed on the exchange for this position, so a new entry is a
                deliberate replace rather than an accidental duplicate/overlap. */}
            {hasActive && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[10px] text-muted-foreground">
                        Currently set:
                    </span>
                    {active?.stop_loss && (
                        <span className="rounded border border-red-500/50 bg-red-500/10 px-1.5 py-0.5 text-[10px] font-medium text-red-500">
                            SL ${fmt(active.stop_loss)}
                            {expectedSlPnl != null && (
                                <span className="ml-1 text-red-400">
                                    ({fmtPnl(expectedSlPnl)})
                                </span>
                            )}
                        </span>
                    )}
                    {active?.take_profit && (
                        <span className="rounded border border-emerald-500/50 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-500">
                            TP ${fmt(active.take_profit)}
                            {expectedTpPnl != null && (
                                <span className="ml-1 text-emerald-400">
                                    ({fmtPnl(expectedTpPnl)})
                                </span>
                            )}
                        </span>
                    )}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-[10px] text-muted-foreground">SL/TP</span>
                <Input
                    value={sl}
                    onChange={(e) => setSl(e.target.value)}
                    placeholder={
                        active?.stop_loss
                            ? `SL: $${fmt(active.stop_loss)}`
                            : 'Stop-loss'
                    }
                    inputMode="decimal"
                    className="h-7 w-28 text-xs"
                />
                {liveSlPnl != null && (
                    <span
                        className={`text-[10px] font-medium ${liveSlPnl >= 0 ? 'text-emerald-500' : 'text-red-500'}`}
                    >
                        {fmtPnl(liveSlPnl)}
                    </span>
                )}
                <Input
                    value={tp}
                    onChange={(e) => setTp(e.target.value)}
                    placeholder={
                        active?.take_profit
                            ? `TP: $${fmt(active.take_profit)}`
                            : 'Take-profit'
                    }
                    inputMode="decimal"
                    className="h-7 w-28 text-xs"
                />
                {liveTpPnl != null && (
                    <span
                        className={`text-[10px] font-medium ${liveTpPnl >= 0 ? 'text-emerald-500' : 'text-red-500'}`}
                    >
                        {fmtPnl(liveTpPnl)}
                    </span>
                )}
                {prediction && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-1.5 text-[11px]"
                        onClick={applyPrediction}
                    >
                        Use suggested
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    className="h-7 px-2 text-xs"
                    onClick={submit}
                    disabled={submitting}
                >
                    {submitting ? '…' : hasActive ? 'Replace' : 'Set'}
                </Button>
            </div>
        </div>
    );
}
