import { Zap } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { flashClose as flashCloseRoute } from '@/routes/futures';
import { coinLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    positions: Position[];
    onRefresh: () => void;
}

const fmt = (n: number) =>
    new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

/**
 * Quick-close shortcuts for currently-winning real positions, pinned to the top of
 * the Dashboard so a profitable trade can be flash-closed without scrolling down to
 * the full open positions list. Hidden entirely when nothing is in profit.
 */
export function WinningPositions({ positions, onRefresh }: Props) {
    const [closingSymbol, setClosingSymbol] = useState<string | null>(null);

    const winners = [...positions]
        .filter((p) => (p.unrealizedPnl ?? 0) > 0)
        .sort((a, b) => (b.unrealizedPnl ?? 0) - (a.unrealizedPnl ?? 0));

    if (winners.length === 0) {
        return null;
    }

    const flashClose = async (pos: Position) => {
        setClosingSymbol(pos.symbol);

        try {
            const res = await fetch(flashCloseRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    symbol: pos.symbol,
                    holdVol: pos.holdVol,
                    positionType: pos.positionType,
                }),
            });

            if (res.redirected || res.status === 302 || res.status === 401) {
                toast.error('Session expired — please refresh the page.');

                return;
            }

            const json = await res.json();

            if (json.success) {
                toast.success(`Flash closed ${coinLabel(pos.symbol)}.`);
                onRefresh();
            } else {
                toast.error(json.message ?? 'Flash close failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setClosingSymbol(null);
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-4 py-3">
            <p className="text-xs font-semibold uppercase tracking-widest text-emerald-500">
                In Profit — Quick Close
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {winners.map((p) => (
                    <div
                        key={p.positionId}
                        className="flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-card px-2.5 py-1.5"
                    >
                        <span className="text-sm font-semibold text-foreground">{coinLabel(p.symbol)}</span>
                        <span className="text-sm font-semibold tabular-nums text-emerald-500">
                            +{fmt(p.unrealizedPnl ?? 0)}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            className="h-6 gap-1 bg-red-600 px-2 text-[11px] text-white hover:bg-red-500"
                            onClick={() => flashClose(p)}
                            disabled={closingSymbol === p.symbol}
                        >
                            <Zap className="size-3" />
                            {closingSymbol === p.symbol ? '…' : 'Flash'}
                        </Button>
                    </div>
                ))}
            </div>
        </div>
    );
}
