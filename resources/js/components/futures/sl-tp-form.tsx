import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SlTpPrediction } from '@/types/futures';

interface Props {
    prediction: SlTpPrediction | null;
    submitting: boolean;
    onSubmit: (values: { stopLoss?: number; takeProfit?: number }) => void;
}

/** Compact stop-loss / take-profit entry row, shared by real and paper position boxes. */
export function SlTpForm({ prediction, submitting, onSubmit }: Props) {
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

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-[10px] text-muted-foreground">SL/TP</span>
            <Input
                value={sl}
                onChange={(e) => setSl(e.target.value)}
                placeholder="Stop-loss"
                inputMode="decimal"
                className="h-7 w-24 text-xs"
            />
            <Input
                value={tp}
                onChange={(e) => setTp(e.target.value)}
                placeholder="Take-profit"
                inputMode="decimal"
                className="h-7 w-24 text-xs"
            />
            {prediction && (
                <Button type="button" variant="ghost" size="sm" className="h-7 px-1.5 text-[11px]" onClick={applyPrediction}>
                    Use suggested
                </Button>
            )}
            <Button type="button" size="sm" className="h-7 px-2 text-xs" onClick={submit} disabled={submitting}>
                {submitting ? '…' : 'Set'}
            </Button>
        </div>
    );
}
