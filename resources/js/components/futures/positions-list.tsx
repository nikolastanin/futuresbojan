import { useState } from 'react';
import { ShieldCheck, Zap, X, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { coinLabel, symbolLabel, type Position } from '@/types/futures';
import { closeAll as closeAllRoute, close as closeRoute, flashClose as flashCloseRoute, stopBreakEven as stopBreakEvenRoute } from '@/routes/futures';
import { toast } from 'sonner';

interface Props {
    positions: Position[];
    onRefresh: () => void;
}

export function PositionsList({ positions, onRefresh }: Props) {
    const [closingAll, setClosingAll] = useState(false);

    const closeAll = async () => {
        if (!confirm('Close ALL open positions at market price?')) return;
        setClosingAll(true);
        try {
            const res = await apiFetch(closeAllRoute.url(), 'POST', {});
            if (res.success) {
                toast.success('All positions closed.');
                onRefresh();
            } else {
                toast.error(res.message ?? 'Failed to close all.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setClosingAll(false);
        }
    };

    if (positions.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                No open positions
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                    Open Positions ({positions.length})
                </p>
                <Button
                    variant="destructive"
                    size="sm"
                    className="gap-1 h-7 text-xs"
                    onClick={closeAll}
                    disabled={closingAll}
                >
                    <XCircle className="size-3.5" />
                    {closingAll ? 'Closing…' : 'Master Close All'}
                </Button>
            </div>

            <div className="flex flex-col gap-2">
                {positions.map(pos => (
                    <PositionRow key={pos.positionId} position={pos} onRefresh={onRefresh} />
                ))}
            </div>
        </div>
    );
}

function PositionRow({ position: pos, onRefresh }: { position: Position; onRefresh: () => void }) {
    const [vol, setVol]           = useState('');
    const [closing, setClosing]   = useState(false);
    const [flashing, setFlashing] = useState(false);
    const [stopping, setStopping] = useState(false);

    const pnlPositive = pos.unrealizedPnl > 0;
    const pnlNegative = pos.unrealizedPnl < 0;

    const pnlColor = pnlPositive
        ? 'text-emerald-500'
        : pnlNegative
        ? 'text-red-500'
        : 'text-muted-foreground';

    const dirLabel = pos.positionType === 1 ? 'LONG' : 'SHORT';
    const dirColor = pos.positionType === 1 ? 'text-emerald-500' : 'text-red-500';

    const closeSide = pos.positionType === 1 ? 4 : 2;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    // Convert USDT input → contracts proportionally
    // positionValue = holdVol * contractSize * fairPrice
    // → contractSize * fairPrice = positionValue / holdVol
    // → contracts = floor(usdtInput * holdVol / positionValue)
    const positionValue  = pos.positionValue ?? 0;
    const usdtInput      = parseFloat(vol) || 0;
    const contractsToClose = positionValue > 0 && pos.holdVol > 0
        ? Math.floor(usdtInput * pos.holdVol / positionValue)
        : 0;
    const pctOfPosition  = positionValue > 0 && usdtInput > 0
        ? Math.min(Math.round((usdtInput / positionValue) * 100), 100)
        : 0;

    const closePartial = async () => {
        if (!vol || usdtInput <= 0) {
            toast.error('Enter a USDT amount to close.');
            return;
        }
        if (contractsToClose < 1) {
            toast.error('Amount too small — results in less than 1 contract.');
            return;
        }
        setClosing(true);
        try {
            const res = await apiFetch(closeRoute.url(), 'POST', {
                symbol: pos.symbol,
                side:   closeSide,
                vol:    contractsToClose,
            });
            if (res.success) {
                toast.success(`Closed $${fmt(usdtInput)} (${contractsToClose} contracts) of ${symbolLabel(pos.symbol)}.`);
                setVol('');
                onRefresh();
            } else {
                toast.error(res.message ?? 'Close failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setClosing(false);
        }
    };

    const stopBreakEven = async () => {
        // If USDT input is filled, use that volume; otherwise use full position
        const volToStop = usdtInput > 0 && contractsToClose >= 1 ? contractsToClose : pos.holdVol;
        const label     = usdtInput > 0 && contractsToClose >= 1
            ? `$${fmt(usdtInput)} (${contractsToClose} contracts)`
            : 'full position';

        setStopping(true);
        try {
            const res = await apiFetch(stopBreakEvenRoute.url(), 'POST', {
                symbol:       pos.symbol,
                positionType: pos.positionType,
                vol:          volToStop,
                triggerPrice: pos.openAvgPrice,
            });
            if (res.success) {
                toast.success(`Break-even stop set for ${label} of ${symbolLabel(pos.symbol)} at $${fmt(pos.openAvgPrice)}.`);
            } else {
                toast.error(res.message ?? 'Failed to set stop.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setStopping(false);
        }
    };

    const flashClose = async () => {
        setFlashing(true);
        try {
            const res = await apiFetch(flashCloseRoute.url(), 'POST', {
                symbol:       pos.symbol,
                holdVol:      pos.holdVol,
                positionType: pos.positionType,
            });
            if (res.success) {
                toast.success(`Flash closed ${symbolLabel(pos.symbol)}.`);
                onRefresh();
            } else {
                toast.error(res.message ?? 'Flash close failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setFlashing(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 sm:flex-row sm:flex-wrap sm:items-center">
            {/* Top row on mobile: symbol + stats */}
            <div className="flex items-center gap-3">
                {/* Symbol + direction */}
                <div className="flex items-center gap-1.5 min-w-[70px]">
                    <span className="font-semibold text-foreground">{coinLabel(pos.symbol)}</span>
                    <span className={`text-xs font-bold ${dirColor}`}>{dirLabel}</span>
                </div>

                {/* Position value */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">Position</span>
                    <span className="text-sm text-foreground tabular-nums">
                        {fmt(pos.positionValue ?? pos.holdVol * pos.openAvgPrice)}
                    </span>
                </div>

                {/* Entry price */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">Entry</span>
                    <span className="text-sm tabular-nums text-foreground">${fmt(pos.openAvgPrice)}</span>
                </div>

                {/* Leverage */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">Lev</span>
                    <span className="text-sm text-foreground">{pos.leverage}×</span>
                </div>

                {/* PNL */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">PNL</span>
                    <span className={`text-sm font-semibold tabular-nums ${pnlColor}`}>
                        {pos.unrealizedPnl >= 0 ? '+' : ''}{fmt(pos.unrealizedPnl)}
                    </span>
                </div>

                {/* Liq price — directly from exchange */}
                {pos.liquidatePrice > 0 && (
                    <div className="flex flex-col">
                        <span className="text-[10px] text-muted-foreground">Liq</span>
                        <span className="text-sm tabular-nums text-amber-500">${fmt(pos.liquidatePrice)}</span>
                    </div>
                )}
            </div>

            {/* Partial close controls */}
            <div className="flex items-center gap-2 sm:ml-auto">
                <div className="flex flex-col">
                    <Input
                        className="h-8 w-24 text-sm"
                        placeholder="USDT"
                        value={vol}
                        onChange={e => setVol(e.target.value)}
                    />
                    {usdtInput > 0 && (
                        <span className="mt-0.5 text-[10px] text-muted-foreground tabular-nums">
                            {contractsToClose} contr · {pctOfPosition}%
                        </span>
                    )}
                </div>
                <Button size="sm" variant="outline" className="h-8 text-xs" onClick={closePartial} disabled={closing}>
                    <X className="mr-1 size-3" />
                    {closing ? '…' : 'Close'}
                </Button>
                <Button size="sm" className="h-8 gap-1 bg-red-600 text-xs text-white hover:bg-red-500" onClick={flashClose} disabled={flashing}>
                    <Zap className="size-3" />
                    {flashing ? '…' : 'Flash'}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    className="h-8 gap-1 text-xs border-amber-500/50 text-amber-500 hover:bg-amber-500/10 hover:border-amber-500"
                    onClick={stopBreakEven}
                    disabled={stopping}
                    title={`Set stop loss at entry price $${fmt(pos.openAvgPrice)}${usdtInput > 0 && contractsToClose >= 1 ? ` for ${contractsToClose} contracts` : ' (full position)'}`}
                >
                    <ShieldCheck className="size-3" />
                    {stopping ? '…' : 'BE Stop'}
                </Button>
            </div>
        </div>
    );
}

async function apiFetch(url: string, method: string, body: object): Promise<{ success: boolean; message?: string; data?: unknown }> {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            'Accept':       'application/json',
        },
        body: JSON.stringify(body),
    });
    if (res.redirected || res.status === 302 || res.status === 401) {
        return { success: false, message: 'Session expired — please refresh the page.' };
    }
    return res.json();
}
