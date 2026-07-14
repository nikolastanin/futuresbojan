import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ActiveSlTp, SlTpPrediction } from '@/types/futures';

interface Props {
    direction: 'LONG' | 'SHORT';
    entryPrice: number;
    currentPrice: number | null;
    prediction: SlTpPrediction | null;
    active?: ActiveSlTp | null;
    /** Estimated $ PnL if the currently-armed take-profit price is hit (price-based, fees not included). */
    expectedTpPnl?: number | null;
    /** Estimated $ PnL if the currently-armed stop-loss price is hit (price-based, fees not included). */
    expectedSlPnl?: number | null;
    submitting: boolean;
    onSubmit: (values: { stopLoss?: number; takeProfit?: number }) => void;
}

// Reused across calls — Intl.NumberFormat construction isn't free, and fmt() runs on
// every animation frame while a slider handle is being dragged.
const priceFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 8 });
const pnlFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmt = (n: number) => priceFormatter.format(n);

const fmtPnl = (n: number) => `${n >= 0 ? '+' : ''}${pnlFormatter.format(n)}`;

const signedPct = (n: number) => `${n >= 0 ? '+' : ''}${n.toFixed(1)}%`;

type Handle = 'sl' | 'tp';

/** Compact stop-loss / take-profit entry row, shared by real and paper position boxes. */
export function SlTpForm({
    direction,
    entryPrice,
    currentPrice,
    prediction,
    active,
    expectedTpPnl,
    expectedSlPnl,
    submitting,
    onSubmit,
}: Props) {
    const [sl, setSl] = useState('');
    const [tp, setTp] = useState('');
    const [dragging, setDragging] = useState<Handle | null>(null);
    const barRef = useRef<HTMLDivElement>(null);
    const rafRef = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            if (rafRef.current !== null) {
                cancelAnimationFrame(rafRef.current);
            }
        };
    }, []);

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

    // Direction-normalized price move: positive always means "toward TP", negative
    // always means "toward SL", regardless of LONG/SHORT — matches the % shown in
    // the "Currently set" badges and lets the slider math ignore direction entirely.
    const dirSign = direction === 'LONG' ? 1 : -1;
    const toNormPct = (price: number) => (entryPrice > 0 ? ((price - entryPrice) / entryPrice) * dirSign * 100 : 0);
    const toPrice = (normPct: number) => entryPrice * (1 + (dirSign * normPct) / 100);
    const priceDecimals = entryPrice >= 100 ? 2 : entryPrice >= 1 ? 4 : 8;

    const slPrice = sl.trim() !== '' ? Number(sl) : (active?.stop_loss ?? prediction?.stop_loss ?? null);
    const tpPrice = tp.trim() !== '' ? Number(tp) : (active?.take_profit ?? prediction?.take_profit ?? null);

    const slNormPct = slPrice !== null ? toNormPct(slPrice) : -(prediction?.stop_loss_pct ?? 2);
    const tpNormPct = tpPrice !== null ? toNormPct(tpPrice) : (prediction?.take_profit_pct ?? 2);
    const curNormPct = currentPrice !== null ? toNormPct(currentPrice) : 0;

    const maxPct = Math.max(Math.abs(slNormPct), Math.abs(tpNormPct), Math.abs(curNormPct), 0.5) * 1.25;

    const clampPos = (p: number) => Math.min(Math.max(p, 2), 98);
    const posFor = (normPct: number) => clampPos(50 + (normPct / maxPct) * 50);
    const slPos = posFor(slNormPct);
    const tpPos = posFor(tpNormPct);
    const curPos = currentPrice !== null ? posFor(curNormPct) : null;

    const applyNormPct = (which: Handle, raw: number) => {
        const eps = Math.max(maxPct * 0.01, 0.05);
        const clamped =
            which === 'sl'
                ? Math.min(Math.max(raw, -maxPct), tpNormPct - eps)
                : Math.max(Math.min(raw, maxPct), slNormPct + eps);
        const price = Number(toPrice(clamped).toFixed(priceDecimals));

        if (which === 'sl') {
            setSl(String(price));
        } else {
            setTp(String(price));
        }
    };

    const normPctFromClientX = (clientX: number) => {
        const rect = barRef.current?.getBoundingClientRect();

        if (!rect || rect.width === 0) {
            return 0;
        }

        const frac = Math.min(Math.max((clientX - rect.left) / rect.width, 0), 1);

        return (frac - 0.5) * 2 * maxPct;
    };

    const handlePointerDown = (which: Handle) => (e: React.PointerEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();

        try {
            e.currentTarget.setPointerCapture(e.pointerId);
        } catch {
            // Some environments reject capture for a pointer id they don't track as active;
            // dragging still works via the move handler below, just without capture.
        }

        setDragging(which);
        applyNormPct(which, normPctFromClientX(e.clientX));
    };

    // requestAnimationFrame-throttled: pointermove can fire well over 60 times/sec, far
    // more often than the UI needs to repaint, so we coalesce to one update per frame.
    const handlePointerMove = (which: Handle) => (e: React.PointerEvent<HTMLDivElement>) => {
        if (dragging !== which) {
            return;
        }

        const clientX = e.clientX;

        if (rafRef.current !== null) {
            cancelAnimationFrame(rafRef.current);
        }

        rafRef.current = requestAnimationFrame(() => {
            rafRef.current = null;
            applyNormPct(which, normPctFromClientX(clientX));
        });
    };

    const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
        if (rafRef.current !== null) {
            cancelAnimationFrame(rafRef.current);
            rafRef.current = null;

            // Flush immediately so release doesn't land a frame behind the actual pointer.
            if (dragging) {
                applyNormPct(dragging, normPctFromClientX(e.clientX));
            }
        }

        try {
            e.currentTarget.releasePointerCapture(e.pointerId);
        } catch {
            // No-op if capture was never acquired (see handlePointerDown).
        }

        setDragging(null);
    };

    const handleKeyDown = (which: Handle) => (e: React.KeyboardEvent) => {
        const step = maxPct * (e.shiftKey ? 0.05 : 0.01);
        const current = which === 'sl' ? slNormPct : tpNormPct;

        if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
            e.preventDefault();
            applyNormPct(which, current - step);
        } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
            e.preventDefault();
            applyNormPct(which, current + step);
        }
    };

    const progressPositive = curNormPct >= 0;
    const progressTarget = progressPositive ? tpNormPct : Math.abs(slNormPct);
    const progressPct = progressTarget > 0 ? Math.min(Math.abs(curNormPct) / progressTarget, 1) * 100 : 0;
    const progressColor = progressPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400';

    const sliderEnabled = entryPrice > 0;

    return (
        <div className="flex flex-col gap-1">
            {/* Interactive SL/TP slider — drag either dot to set the full-position stop-loss
                or take-profit. Track coordinates are direction-normalized (right = toward TP,
                left = toward SL) so LONG and SHORT positions share the same drag logic. */}
            <div className="flex flex-col gap-0.5">
                <div className="flex items-center justify-between gap-2">
                    {currentPrice !== null ? (
                        <span className={`text-[10px] font-medium ${progressColor}`}>
                            {progressPct.toFixed(0)}% to {progressPositive ? 'TP' : 'SL'}
                        </span>
                    ) : (
                        <span className="text-[10px] text-muted-foreground">Drag dots to set SL/TP</span>
                    )}
                    <span className="text-[10px] text-muted-foreground">
                        SL {signedPct(slNormPct)} / TP {signedPct(tpNormPct)}
                    </span>
                </div>

                <div
                    ref={barRef}
                    className={`relative h-5 w-full ${sliderEnabled ? 'touch-none select-none' : ''}`}
                >
                    <div className="absolute top-1/2 h-1.5 w-full -translate-y-1/2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="absolute top-0 h-full bg-red-500/30"
                            style={{ left: `${Math.min(slPos, 50)}%`, width: `${Math.max(50 - slPos, 0)}%` }}
                        />
                        <div
                            className="absolute top-0 h-full bg-emerald-500/30"
                            style={{ left: '50%', width: `${Math.max(tpPos - 50, 0)}%` }}
                        />
                        <div className="absolute top-0 left-1/2 h-full w-px -translate-x-1/2 bg-border" />
                    </div>

                    {curPos !== null && (
                        <div
                            className="absolute top-1/2 h-3 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-foreground/60"
                            style={{ left: `${curPos}%` }}
                        />
                    )}

                    {sliderEnabled && (
                        <>
                            <div
                                role="slider"
                                aria-label="Stop-loss"
                                aria-valuenow={Number(slPrice?.toFixed(priceDecimals) ?? 0)}
                                tabIndex={0}
                                onPointerDown={handlePointerDown('sl')}
                                onPointerMove={handlePointerMove('sl')}
                                onPointerUp={handlePointerUp}
                                onKeyDown={handleKeyDown('sl')}
                                className="absolute top-1/2 flex size-4 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize touch-none items-center justify-center rounded-full border-2 border-red-500 bg-background shadow focus:outline-none focus:ring-2 focus:ring-red-500/50"
                                style={{ left: `${slPos}%` }}
                            >
                                {dragging === 'sl' && slPrice !== null && (
                                    <span className="absolute -top-6 whitespace-nowrap rounded bg-red-500 px-1.5 py-0.5 text-[10px] font-medium text-white">
                                        ${fmt(slPrice)}
                                    </span>
                                )}
                            </div>
                            <div
                                role="slider"
                                aria-label="Take-profit"
                                aria-valuenow={Number(tpPrice?.toFixed(priceDecimals) ?? 0)}
                                tabIndex={0}
                                onPointerDown={handlePointerDown('tp')}
                                onPointerMove={handlePointerMove('tp')}
                                onPointerUp={handlePointerUp}
                                onKeyDown={handleKeyDown('tp')}
                                className="absolute top-1/2 flex size-4 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize touch-none items-center justify-center rounded-full border-2 border-emerald-500 bg-background shadow focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                                style={{ left: `${tpPos}%` }}
                            >
                                {dragging === 'tp' && tpPrice !== null && (
                                    <span className="absolute -top-6 whitespace-nowrap rounded bg-emerald-500 px-1.5 py-0.5 text-[10px] font-medium text-white">
                                        ${fmt(tpPrice)}
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* What's already armed on the exchange for this position, so a new entry is a
                deliberate replace rather than an accidental duplicate/overlap. */}
            {hasActive && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[10px] text-muted-foreground">Currently set:</span>
                    {active?.stop_loss && (
                        <span className="rounded border border-red-500/50 bg-red-500/10 px-1.5 py-0.5 text-[10px] font-medium text-red-500">
                            SL ${fmt(active.stop_loss)}
                            {expectedSlPnl != null && (
                                <span className="ml-1 text-red-400">({fmtPnl(expectedSlPnl)})</span>
                            )}
                        </span>
                    )}
                    {active?.take_profit && (
                        <span className="rounded border border-emerald-500/50 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-500">
                            TP ${fmt(active.take_profit)}
                            {expectedTpPnl != null && (
                                <span className="ml-1 text-emerald-400">({fmtPnl(expectedTpPnl)})</span>
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
                    placeholder={active?.stop_loss ? `SL: $${fmt(active.stop_loss)}` : 'Stop-loss'}
                    inputMode="decimal"
                    className="h-7 w-28 text-xs"
                />
                <Input
                    value={tp}
                    onChange={(e) => setTp(e.target.value)}
                    placeholder={active?.take_profit ? `TP: $${fmt(active.take_profit)}` : 'Take-profit'}
                    inputMode="decimal"
                    className="h-7 w-28 text-xs"
                />
                {prediction && (
                    <Button type="button" variant="ghost" size="sm" className="h-7 px-1.5 text-[11px]" onClick={applyPrediction}>
                        Use suggested
                    </Button>
                )}
                <Button type="button" size="sm" className="h-7 px-2 text-xs" onClick={submit} disabled={submitting}>
                    {submitting ? '…' : hasActive ? 'Replace' : 'Set'}
                </Button>
            </div>
        </div>
    );
}
