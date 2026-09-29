import {
    Anchor,
    ListTree,
    ShieldCheck,
    Star,
    Zap,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PriceLevels } from '@/components/futures/price-levels';
import { ScalingLadder } from '@/components/futures/scaling-ladder';
import { SignalBadgesExtra } from '@/components/futures/signal-badges-extra';
import { SlTpForm } from '@/components/futures/sl-tp-form';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    momentumLabel,
    structureLabel,
    trendLabel,
    useSignalPreviews,
} from '@/hooks/use-signal-previews';
import type { SignalPreview } from '@/hooks/use-signal-previews';
import {
    closeAll as closeAllRoute,
    close as closeRoute,
    flashClose as flashCloseRoute,
    orders as ordersRoute,
    setSlTp as setSlTpRoute,
    stopBreakEven as stopBreakEvenRoute,
} from '@/routes/futures';
import positionLocks from '@/routes/futures/position-locks';
import { coinLabel, symbolLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    positions: Position[];
    onRefresh: () => void;
}

const LOCK_DURATIONS = [1, 4, 8, 24, 48];

/** "23h 41m left" / "12m left" — refreshes passively on each poll, no separate ticker. */
function formatRemaining(lockedUntil: string): string {
    const ms = new Date(lockedUntil).getTime() - Date.now();

    if (ms <= 0) {
        return 'expiring…';
    }

    const totalMinutes = Math.ceil(ms / 60_000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return hours > 0 ? `${hours}h ${minutes}m left` : `${minutes}m left`;
}

/** A rough "how long should this reasonably take" lock suggestion: the remaining
 * distance to the ATR-based take-profit, divided by the current 1H ATR (volatility
 * as % of price per hour), gives an estimated hours-to-target — snapped to the
 * nearest preset duration on a log scale since the presets themselves are roughly
 * logarithmic. Returns null whenever either input is missing (no SL/TP prediction
 * yet, or the signal preview hasn't loaded) rather than guessing. */
function suggestLockHours(
    remainingToTpPct: number | null,
    volatilityPctPerHour: number | null,
): number | null {
    if (
        remainingToTpPct === null ||
        volatilityPctPerHour === null ||
        volatilityPctPerHour <= 0
    ) {
        return null;
    }

    const rawHours = remainingToTpPct / volatilityPctPerHour;

    return LOCK_DURATIONS.reduce((closest, h) =>
        Math.abs(Math.log(rawHours / h)) < Math.abs(Math.log(rawHours / closest))
            ? h
            : closest,
    );
}

export function PositionsList({ positions, onRefresh }: Props) {
    const [closingAll, setClosingAll] = useState(false);
    const symbols = [...new Set(positions.map((p) => p.symbol))];
    const signals = useSignalPreviews(symbols);

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
        <div className="flex flex-col gap-3 rounded-xl border border-t-2 border-border border-t-blue-500 bg-card p-4">
            <div className="flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <ListTree className="size-3.5 text-blue-500" />
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
                        signal={signals[pos.symbol]}
                        onRefresh={onRefresh}
                    />
                ))}
            </div>
        </div>
    );
}

function PositionRow({
    position: pos,
    signal,
    onRefresh,
}: {
    position: Position;
    signal: SignalPreview | 'loading' | 'error' | undefined;
    onRefresh: () => void;
}) {
    const hasSignal = signal && signal !== 'loading' && signal !== 'error';
    const [flashing, setFlashing] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [adding, setAdding] = useState<number | null>(null);
    const [reducing, setReducing] = useState<number | null>(null);
    const [settingSlTp, setSettingSlTp] = useState(false);
    const [togglingLock, setTogglingLock] = useState(false);
    const [showLockMenu, setShowLockMenu] = useState(false);
    const lockMenuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!showLockMenu) {
            return;
        }

        const onClickOutside = (e: MouseEvent) => {
            if (
                lockMenuRef.current &&
                !lockMenuRef.current.contains(e.target as Node)
            ) {
                setShowLockMenu(false);
            }
        };

        document.addEventListener('mousedown', onClickOutside);

        return () => document.removeEventListener('mousedown', onClickOutside);
    }, [showLockMenu]);

    // Suggested lock duration: distance from *current* price to the ATR take-profit
    // (not the entry-relative take_profit_pct, since price has likely moved since
    // entry) divided by current 1H volatility — see suggestLockHours() for the math.
    const currentPrice = hasSignal ? signal.current_price : null;
    const tpPrice = pos.sl_tp_prediction?.take_profit ?? null;
    const remainingToTpPct =
        tpPrice !== null && currentPrice !== null && currentPrice > 0
            ? (Math.abs(tpPrice - currentPrice) / currentPrice) * 100
            : null;
    const suggestedLockHours = suggestLockHours(
        remainingToTpPct,
        hasSignal ? signal.volatility_pct : null,
    );

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
    const contractsNotional =
        pos.fairPrice > 0 ? positionValue / pos.fairPrice : 0;
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

    // hours=null when locking means indefinite (the original anchor behavior); when
    // called on an already-locked position, hours is ignored server-side and this
    // always unlocks early — same endpoint, same toggle semantic as before.
    const lockFor = async (hours: number | null) => {
        setTogglingLock(true);
        setShowLockMenu(false);

        try {
            const res = await apiFetch(positionLocks.toggle.url(), 'POST', {
                symbol: pos.symbol,
                positionType: pos.positionType,
                ...(hours !== null ? { hours } : {}),
            });

            if (res.success) {
                if (res.locked) {
                    toast.success(
                        hours !== null
                            ? `Locked ${dirLabel} ${symbolLabel(pos.symbol)} for ${hours}h — nothing can touch it until then.`
                            : `Anchored ${dirLabel} ${symbolLabel(pos.symbol)} indefinitely — unanchor manually to release.`,
                    );
                } else {
                    toast.success(
                        `Unlocked ${dirLabel} ${symbolLabel(pos.symbol)}.`,
                    );
                }

                onRefresh();
            } else {
                toast.error(res.message ?? 'Failed to toggle lock.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setTogglingLock(false);
        }
    };

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

    // Anchored positions collapse to just enough to identify and unanchor them —
    // everything else (stats, SL/TP, Reduce/Flash/Add) is hidden while locked, since
    // the whole point of anchoring is "don't show me anything to touch here".
    if (pos.locked) {
        return (
            <div className="flex items-center gap-2 rounded-lg border border-amber-500/50 bg-amber-500/5 px-3 py-2">
                <button
                    type="button"
                    onClick={() => lockFor(null)}
                    disabled={togglingLock}
                    title="Locked — click to release early and restore full controls"
                    className="flex items-center justify-center rounded bg-amber-500/20 p-0.5 text-amber-500 transition-colors hover:bg-amber-500/30 disabled:opacity-50"
                >
                    <Anchor className="size-3.5" fill="currentColor" />
                </button>
                <span className="font-semibold text-foreground">
                    {coinLabel(pos.symbol)}
                </span>
                <span className={`text-xs font-bold ${dirColor}`}>
                    {dirLabel}
                </span>
                <span className="text-[11px] text-amber-500">
                    {pos.lockedUntil
                        ? formatRemaining(pos.lockedUntil)
                        : 'Locked indefinitely'}
                </span>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 sm:flex-row sm:flex-wrap sm:items-center">
            {/* Top row on mobile: symbol + stats */}
            <div className="flex items-center gap-3">
                {/* Symbol + direction + anchor lock */}
                <div className="relative flex min-w-[70px] items-center gap-1.5">
                    <span className="font-semibold text-foreground">
                        {coinLabel(pos.symbol)}
                    </span>
                    <span className={`text-xs font-bold ${dirColor}`}>
                        {dirLabel}
                    </span>
                    <button
                        type="button"
                        onClick={() => setShowLockMenu((v) => !v)}
                        disabled={togglingLock}
                        title="Lock this position to collapse it and block adds/reduce/flash"
                        className="flex items-center justify-center rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                    >
                        <Anchor className="size-3.5" />
                    </button>

                    {showLockMenu && (
                        <div
                            ref={lockMenuRef}
                            className="absolute top-full left-0 z-10 mt-1 flex w-40 flex-col gap-1 rounded-md border border-border bg-card p-1.5 shadow-lg"
                        >
                            <p className="px-1 text-[10px] text-muted-foreground">
                                Lock for…
                                {suggestedLockHours !== null &&
                                    ' ★ suggested'}
                            </p>
                            {LOCK_DURATIONS.map((h) => {
                                const isSuggested = h === suggestedLockHours;
                                const button = (
                                    <button
                                        type="button"
                                        onClick={() => lockFor(h)}
                                        className={`flex w-full items-center justify-between rounded px-2 py-1 text-left text-[11px] transition-colors hover:bg-amber-500/10 hover:text-amber-500 ${
                                            isSuggested
                                                ? 'bg-amber-500/10 font-semibold text-amber-500'
                                                : 'text-foreground'
                                        }`}
                                    >
                                        <span>{h}h</span>
                                        {isSuggested && (
                                            <Star
                                                className="size-3"
                                                fill="currentColor"
                                            />
                                        )}
                                    </button>
                                );

                                if (!isSuggested) {
                                    return <div key={h}>{button}</div>;
                                }

                                return (
                                    <Tooltip key={h}>
                                        <TooltipTrigger asChild>
                                            {button}
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="right"
                                            className="max-w-[200px] text-[11px]"
                                        >
                                            Suggested: your ATR take-profit is
                                            ~
                                            {remainingToTpPct !== null
                                                ? remainingToTpPct.toFixed(2)
                                                : '?'}
                                            % away and 1H volatility is
                                            running ~
                                            {hasSignal
                                                ? signal.volatility_pct
                                                : '?'}
                                            %/hr — roughly {h}h to get there
                                            at that pace.
                                        </TooltipContent>
                                    </Tooltip>
                                );
                            })}
                            <button
                                type="button"
                                onClick={() => lockFor(null)}
                                className="rounded px-2 py-1 text-left text-[11px] text-foreground transition-colors hover:bg-amber-500/10 hover:text-amber-500"
                            >
                                Indefinitely
                            </button>
                        </div>
                    )}
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

            {/* Trend/Momentum/Structure read for this coin — same indicators the bot
                scores on, just labeled, so a hedge's two legs can be compared by more
                than gut feel when deciding which one to add to. */}
            {hasSignal && (
                <div className="flex flex-wrap items-center gap-2 text-[11px]">
                    <span className={trendLabel(signal.trend).color}>
                        Trend {trendLabel(signal.trend).label}
                    </span>
                    <span className="text-muted-foreground">·</span>
                    <span className={momentumLabel(signal.momentum).color}>
                        Momentum {momentumLabel(signal.momentum).label}
                    </span>
                    {structureLabel(signal.structure) && (
                        <>
                            <span className="text-muted-foreground">·</span>
                            <span
                                className={
                                    structureLabel(signal.structure)!.color
                                }
                            >
                                {structureLabel(signal.structure)!.label}
                            </span>
                        </>
                    )}
                    {signal.volatility_pct !== null && (
                        <>
                            <span className="text-muted-foreground">·</span>
                            <span className="text-muted-foreground">
                                Volatility {signal.volatility_pct}%
                            </span>
                        </>
                    )}
                    {signal.change_24h_pct !== null && (
                        <>
                            <span className="text-muted-foreground">·</span>
                            <span
                                className={
                                    signal.change_24h_pct >= 0
                                        ? 'text-emerald-500'
                                        : 'text-red-500'
                                }
                            >
                                24h {signal.change_24h_pct >= 0 ? '+' : ''}
                                {signal.change_24h_pct}%
                            </span>
                        </>
                    )}
                </div>
            )}

            {hasSignal && <SignalBadgesExtra signal={signal} />}

            {hasSignal && signal.levels && (
                <PriceLevels
                    current={signal.current_price}
                    levels={signal.levels}
                />
            )}

            <ScalingLadder
                direction={dirLabel}
                currentPrice={pos.fairPrice}
                onAdd={addToPosition}
                onReduce={reduceByAmount}
                addBusy={adding !== null}
                reduceBusy={reducing !== null}
            />

            {/* Interactive SL/TP slider + entry — drag a dot or type a price to place SL/TP
                trigger orders on MEXC for this position */}
            <div className="w-full">
                <SlTpForm
                    direction={dirLabel}
                    entryPrice={pos.openAvgPrice}
                    prediction={pos.sl_tp_prediction}
                    active={pos.active_sl_tp}
                    expectedTpPnl={expectedTpPnl}
                    expectedSlPnl={expectedSlPnl}
                    contractsNotional={contractsNotional}
                    submitting={settingSlTp}
                    onSubmit={setSlTp}
                />
            </div>

            {/* Reduce + Flash + BE Stop */}
            <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                <span className="text-[10px] text-muted-foreground">
                    Reduce
                </span>
                {[0.1, 0.2, 0.3, 0.5, 0.7, 1, 2, 4].map((amt) => (
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
                {[0.1, 0.2, 0.3, 0.5, 0.7, 1, 2, 3, 5].map((amt) => (
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
): Promise<{
    success: boolean;
    message?: string;
    data?: unknown;
    locked?: boolean;
    lockedUntil?: string | null;
}> {
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
