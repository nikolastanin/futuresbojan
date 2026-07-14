import { ShieldCheck, Zap, XCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { SlTpForm } from '@/components/futures/sl-tp-form';
import { TradeEvaluationBar } from '@/components/futures/trade-evaluation-bar';
import { Button } from '@/components/ui/button';
import {
    closeAll as closeAllRoute,
    close as closeRoute,
    flashClose as flashCloseRoute,
    orders as ordersRoute,
    setSlTp as setSlTpRoute,
    stopBreakEven as stopBreakEvenRoute,
} from '@/routes/futures';
import { coinLabel, symbolLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    positions: Position[];
    onRefresh: () => void;
}

export function PositionsList({ positions, onRefresh }: Props) {
    const [closingAll, setClosingAll] = useState(false);

    const closeAll = async () => {
        if (!confirm('Close ALL open positions at market price?')) {
            return;
        }

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
                <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    Open Positions ({positions.length})
                </p>
                <Button
                    variant="destructive"
                    size="sm"
                    className="h-7 gap-1 text-xs"
                    onClick={closeAll}
                    disabled={closingAll}
                >
                    <XCircle className="size-3.5" />
                    {closingAll ? 'Closing…' : 'Master Close All'}
                </Button>
            </div>

            <div className="flex flex-col gap-2">
                {positions.map((pos) => (
                    <PositionRow
                        key={pos.positionId}
                        position={pos}
                        onRefresh={onRefresh}
                    />
                ))}
            </div>
        </div>
    );
}

function PositionRow({
    position: pos,
    onRefresh,
}: {
    position: Position;
    onRefresh: () => void;
}) {
    const [flashing, setFlashing] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [adding, setAdding] = useState<number | null>(null);
    const [reducing, setReducing] = useState<number | null>(null);
    const [settingSlTp, setSettingSlTp] = useState(false);

    const pnlPositive = pos.unrealizedPnl > 0;
    const pnlNegative = pos.unrealizedPnl < 0;

    const pnlColor = pnlPositive
        ? 'text-emerald-500'
        : pnlNegative
          ? 'text-red-500'
          : 'text-muted-foreground';

    const dirLabel = pos.positionType === 1 ? 'LONG' : 'SHORT';
    const dirColor =
        pos.positionType === 1 ? 'text-emerald-500' : 'text-red-500';

    const closeSide = pos.positionType === 1 ? 4 : 2;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(n);

    // positionValue = holdVol * contractSize * fairPrice, used to convert a target USDT
    // amount into contracts for the Reduce buttons: contracts = amount * holdVol / positionValue
    const positionValue = pos.positionValue ?? 0;

    // holdVol * contractSize is price-independent, so positionValue / fairPrice recovers it —
    // lets us project PnL at the armed take-profit price without needing contractSize itself.
    const contractsNotional = pos.fairPrice > 0 ? positionValue / pos.fairPrice : 0;
    const expectedTpPnl =
        pos.active_sl_tp?.take_profit && contractsNotional > 0
            ? contractsNotional *
              (pos.active_sl_tp.take_profit - pos.openAvgPrice) *
              (pos.positionType === 1 ? 1 : -1)
            : null;
    const expectedSlPnl =
        pos.active_sl_tp?.stop_loss && contractsNotional > 0
            ? contractsNotional *
              (pos.active_sl_tp.stop_loss - pos.openAvgPrice) *
              (pos.positionType === 1 ? 1 : -1)
            : null;

    const stopBreakEven = async () => {
        setStopping(true);

        try {
            const res = await apiFetch(stopBreakEvenRoute.url(), 'POST', {
                symbol: pos.symbol,
                positionType: pos.positionType,
                vol: pos.holdVol,
                triggerPrice: pos.openAvgPrice,
            });

            if (res.success) {
                toast.success(
                    `Break-even stop set for full position of ${symbolLabel(pos.symbol)} at $${fmt(pos.openAvgPrice)}.`,
                );
            } else {
                toast.error(res.message ?? 'Failed to set stop.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setStopping(false);
        }
    };

    const setSlTp = async (values: {
        stopLoss?: number;
        takeProfit?: number;
    }) => {
        setSettingSlTp(true);

        try {
            const res = await apiFetch(setSlTpRoute.url(), 'POST', {
                symbol: pos.symbol,
                positionType: pos.positionType,
                vol: pos.holdVol,
                ...(values.stopLoss !== undefined
                    ? { stopLoss: values.stopLoss }
                    : {}),
                ...(values.takeProfit !== undefined
                    ? { takeProfit: values.takeProfit }
                    : {}),
            });

            if (res.success) {
                toast.success(`SL/TP set for ${symbolLabel(pos.symbol)}.`);
            } else {
                toast.error(res.message ?? 'Failed to set SL/TP.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setSettingSlTp(false);
        }
    };

    const flashClose = async () => {
        setFlashing(true);

        try {
            const res = await apiFetch(flashCloseRoute.url(), 'POST', {
                symbol: pos.symbol,
                holdVol: pos.holdVol,
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

    const addToPosition = async (amount: number) => {
        setAdding(amount);

        try {
            const res = await apiFetch(ordersRoute.url(), 'POST', {
                orders: [
                    {
                        symbol: pos.symbol,
                        price: 0,
                        marginUsdt: amount,
                        leverage: pos.leverage,
                        side: pos.positionType === 1 ? 1 : 3,
                        type: 5,
                        openType: 2,
                    },
                ],
            });

            if (res.success) {
                toast.success(
                    `Added $${amount} to ${dirLabel} ${symbolLabel(pos.symbol)}.`,
                );
                onRefresh();
            } else {
                toast.error(res.message ?? 'Add failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setAdding(null);
        }
    };

    const reduceByAmount = async (amount: number) => {
        // Same basis as Add: amount is margin, not notional — multiply by leverage
        // so e.g. $1 removes the same $100 of notional that $1 Add would have added.
        const notionalToReduce = amount * pos.leverage;
        const contracts =
            positionValue > 0 && pos.holdVol > 0
                ? Math.min(
                      Math.floor(
                          (notionalToReduce * pos.holdVol) / positionValue,
                      ),
                      pos.holdVol,
                  )
                : 0;

        if (contracts < 1) {
            toast.error('Amount too small — results in less than 1 contract.');

            return;
        }

        setReducing(amount);

        try {
            const res = await apiFetch(closeRoute.url(), 'POST', {
                symbol: pos.symbol,
                side: closeSide,
                vol: contracts,
            });

            if (res.success) {
                toast.success(
                    `Reduced ${symbolLabel(pos.symbol)} by $${amount} (${contracts} contracts).`,
                );
                onRefresh();
            } else {
                toast.error(res.message ?? 'Reduce failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setReducing(null);
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 sm:flex-row sm:flex-wrap sm:items-center">
            {/* Top row on mobile: symbol + stats */}
            <div className="flex items-center gap-3">
                {/* Symbol + direction */}
                <div className="flex min-w-[70px] items-center gap-1.5">
                    <span className="font-semibold text-foreground">
                        {coinLabel(pos.symbol)}
                    </span>
                    <span className={`text-xs font-bold ${dirColor}`}>
                        {dirLabel}
                    </span>
                </div>

                {/* Position value */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Position
                    </span>
                    <span className="text-sm text-foreground tabular-nums">
                        {fmt(
                            pos.positionValue ?? pos.holdVol * pos.openAvgPrice,
                        )}
                    </span>
                </div>

                {/* Entry price */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Entry
                    </span>
                    <span className="text-sm text-foreground tabular-nums">
                        ${fmt(pos.openAvgPrice)}
                    </span>
                </div>

                {/* Leverage */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Lev
                    </span>
                    <span className="text-sm text-foreground">
                        {pos.leverage}×
                    </span>
                </div>

                {/* PNL */}
                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        PNL
                    </span>
                    <span
                        className={`text-sm font-semibold tabular-nums ${pnlColor}`}
                    >
                        {pos.unrealizedPnl >= 0 ? '+' : ''}
                        {fmt(pos.unrealizedPnl)}
                    </span>
                </div>

                {/* Liq price — directly from exchange */}
                {pos.liquidatePrice > 0 && (
                    <div className="flex flex-col">
                        <span className="text-[10px] text-muted-foreground">
                            Liq
                        </span>
                        <span className="text-sm text-amber-500 tabular-nums">
                            ${fmt(pos.liquidatePrice)}
                        </span>
                    </div>
                )}
            </div>

            {/* Trade evaluation — smaller version of the bot's evaluation bar */}
            <div className="w-full sm:w-auto sm:flex-1">
                <TradeEvaluationBar
                    direction={dirLabel}
                    entryPrice={pos.openAvgPrice}
                    currentPrice={pos.fairPrice ?? null}
                    prediction={pos.sl_tp_prediction}
                />
            </div>

            {/* Enter and place SL/TP trigger orders on MEXC for this position */}
            <div className="w-full">
                <SlTpForm
                    prediction={pos.sl_tp_prediction}
                    active={pos.active_sl_tp}
                    expectedTpPnl={expectedTpPnl}
                    expectedSlPnl={expectedSlPnl}
                    submitting={settingSlTp}
                    onSubmit={setSlTp}
                />
            </div>

            {/* Reduce + Flash + BE Stop */}
            <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                <span className="text-[10px] text-muted-foreground">
                    Reduce
                </span>
                {[0.5, 1, 2, 4].map((amt) => (
                    <button
                        key={amt}
                        type="button"
                        onClick={() => reduceByAmount(amt)}
                        disabled={reducing !== null}
                        className="rounded border border-amber-500/50 px-2 py-1 text-[11px] font-medium text-amber-500 transition-colors hover:bg-amber-500/10 disabled:opacity-50"
                    >
                        {reducing === amt ? '…' : `$${amt}`}
                    </button>
                ))}
                <Button
                    size="sm"
                    className="h-8 gap-1 bg-red-600 text-xs text-white hover:bg-red-500"
                    onClick={flashClose}
                    disabled={flashing}
                >
                    <Zap className="size-3" />
                    {flashing ? '…' : 'Flash'}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    className="h-8 gap-1 border-amber-500/50 text-xs text-amber-500 hover:border-amber-500 hover:bg-amber-500/10"
                    onClick={stopBreakEven}
                    disabled={stopping}
                    title={`Set stop loss at entry price $${fmt(pos.openAvgPrice)} (full position)`}
                >
                    <ShieldCheck className="size-3" />
                    {stopping ? '…' : 'BE Stop'}
                </Button>
            </div>

            {/* Quick add (market order) */}
            <div className="flex w-full flex-wrap items-center gap-1">
                <span className="mr-1 text-[10px] text-muted-foreground">
                    Add
                </span>
                {[1, 2, 3, 5].map((amt) => (
                    <button
                        key={amt}
                        type="button"
                        onClick={() => addToPosition(amt)}
                        disabled={adding !== null}
                        className="rounded border border-emerald-500/50 px-2 py-1 text-[11px] font-medium text-emerald-500 transition-colors hover:bg-emerald-500/10 disabled:opacity-50"
                    >
                        {adding === amt ? '…' : `$${amt}`}
                    </button>
                ))}
            </div>
        </div>
    );
}

async function apiFetch(
    url: string,
    method: string,
    body: object,
): Promise<{ success: boolean; message?: string; data?: unknown }> {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement
                )?.content ?? '',
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (res.redirected || res.status === 302 || res.status === 401) {
        return {
            success: false,
            message: 'Session expired — please refresh the page.',
        };
    }

    return res.json();
}
