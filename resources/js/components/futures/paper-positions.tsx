import { X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { SlTpForm } from '@/components/futures/sl-tp-form';
import { TradeEvaluationBar } from '@/components/futures/trade-evaluation-bar';
import { Button } from '@/components/ui/button';
import manual from '@/routes/manual';
import { coinLabel, symbolLabel } from '@/types/futures';
import type { PaperPosition } from '@/types/futures';

interface Props {
    positions: PaperPosition[];
    onRefresh: () => void;
}

const fmt = (n: number) =>
    new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n);

export function PaperPositions({ positions, onRefresh }: Props) {
    if (positions.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-dashed border-border bg-card p-4">
            <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                Paper Positions ({positions.length}) — simulated, not on MEXC
            </p>

            <div className="flex flex-col gap-2">
                {positions.map((pos) => (
                    <PaperPositionRow
                        key={pos.id}
                        position={pos}
                        onRefresh={onRefresh}
                    />
                ))}
            </div>
        </div>
    );
}

function PaperPositionRow({
    position: pos,
    onRefresh,
}: {
    position: PaperPosition;
    onRefresh: () => void;
}) {
    const [closing, setClosing] = useState(false);
    const [settingSlTp, setSettingSlTp] = useState(false);

    const pnl = pos.unrealized_pnl;
    const pnlPositive = (pnl ?? 0) > 0;
    const pnlNegative = (pnl ?? 0) < 0;
    const pnlColor = pnlPositive
        ? 'text-emerald-500'
        : pnlNegative
          ? 'text-red-500'
          : 'text-muted-foreground';
    const dirColor =
        pos.direction === 'LONG' ? 'text-emerald-500' : 'text-red-500';

    const close = async () => {
        setClosing(true);

        try {
            const res = await fetch(
                manual.positions.close.url({ trade: pos.id }),
                {
                    method: 'POST',
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
                    body: JSON.stringify({}),
                },
            );
            const json = await res.json();

            if (json.success) {
                toast.success(
                    `Closed paper ${pos.direction} ${symbolLabel(pos.symbol)}.`,
                );
                onRefresh();
            } else {
                toast.error(json.message ?? 'Close failed.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setClosing(false);
        }
    };

    const setSlTp = async (values: { stopLoss?: number; takeProfit?: number }) => {
        setSettingSlTp(true);

        try {
            const res = await fetch(
                manual.positions.setSlTp.url({ trade: pos.id }),
                {
                    method: 'POST',
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
                    body: JSON.stringify({
                        ...(values.stopLoss !== undefined ? { stopLoss: values.stopLoss } : {}),
                        ...(values.takeProfit !== undefined ? { takeProfit: values.takeProfit } : {}),
                    }),
                },
            );
            const json = await res.json();

            if (json.success) {
                toast.success(`SL/TP set for ${symbolLabel(pos.symbol)}.`);
                onRefresh();
            } else {
                toast.error(json.message ?? 'Failed to set SL/TP.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setSettingSlTp(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="flex items-center gap-3">
                <div className="flex min-w-[70px] items-center gap-1.5">
                    <span className="font-semibold text-foreground">
                        {coinLabel(pos.symbol)}
                    </span>
                    <span className={`text-xs font-bold ${dirColor}`}>
                        {pos.direction}
                    </span>
                </div>

                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Margin
                    </span>
                    <span className="text-sm text-foreground tabular-nums">
                        ${fmt(pos.margin_usdt)} @ {pos.leverage}×
                    </span>
                </div>

                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Entry
                    </span>
                    <span className="text-sm text-foreground tabular-nums">
                        ${fmt(pos.entry_price)}
                    </span>
                </div>

                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        Current
                    </span>
                    <span className="text-sm text-foreground tabular-nums">
                        {pos.current_price !== null
                            ? `$${fmt(pos.current_price)}`
                            : '—'}
                    </span>
                </div>

                <div className="flex flex-col">
                    <span className="text-[10px] text-muted-foreground">
                        PNL
                    </span>
                    <span
                        className={`text-sm font-semibold tabular-nums ${pnlColor}`}
                    >
                        {pnl !== null
                            ? `${pnlPositive ? '+' : ''}${fmt(pnl)}`
                            : '—'}
                    </span>
                </div>

                {(pos.stop_loss !== null || pos.take_profit !== null) && (
                    <div className="flex flex-col">
                        <span className="text-[10px] text-muted-foreground">
                            SL / TP
                        </span>
                        <span className="text-sm tabular-nums text-foreground">
                            {pos.stop_loss !== null ? fmt(pos.stop_loss) : '—'} / {pos.take_profit !== null ? fmt(pos.take_profit) : '—'}
                        </span>
                    </div>
                )}
            </div>

            <div className="w-full sm:w-auto sm:flex-1">
                <TradeEvaluationBar
                    direction={pos.direction}
                    entryPrice={pos.entry_price}
                    currentPrice={pos.current_price}
                    prediction={pos.sl_tp_prediction}
                />
            </div>

            <div className="w-full">
                <SlTpForm
                    prediction={pos.sl_tp_prediction}
                    active={{ stop_loss: pos.stop_loss, take_profit: pos.take_profit }}
                    submitting={settingSlTp}
                    onSubmit={setSlTp}
                />
            </div>

            <div className="sm:ml-auto">
                <Button
                    size="sm"
                    variant="outline"
                    className="h-8 text-xs"
                    onClick={close}
                    disabled={closing}
                >
                    <X className="mr-1 size-3" />
                    {closing ? '…' : 'Close'}
                </Button>
            </div>
        </div>
    );
}
