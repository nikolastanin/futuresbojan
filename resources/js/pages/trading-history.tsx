import { useCallback, useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { Toaster } from '@/components/ui/sonner';
import { tradingHistory } from '@/routes';
import { coinLabel, symbolLabel } from '@/types/futures';

interface FilledOrder {
    orderId:      string | number;
    symbol:       string;
    side:         number;   // 1=open long, 2=close short, 3=open short, 4=close long
    dealAvgPrice: number;
    dealVol:      number;
    vol:          number;
    profit:       number;
    takerFee:     number;
    makerFee:     number;
    createTime:   number;
    updateTime:   number;
}

interface Props {
    orders: FilledOrder[];
}

const POLL_INTERVAL = 5_000;

const SIDE_LABEL: Record<number, { label: string; color: string }> = {
    1: { label: 'Open Long',   color: 'text-emerald-500' },
    2: { label: 'Close Short', color: 'text-emerald-500' },
    3: { label: 'Open Short',  color: 'text-red-500' },
    4: { label: 'Close Long',  color: 'text-red-500' },
};

export default function TradingHistory({ orders: initialOrders }: Props) {
    const [orders,   setOrders]  = useState<FilledOrder[]>(initialOrders);
    const [syncing,  setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState<Date | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const refresh = useCallback(async () => {
        setSyncing(true);
        try {
            const res  = await fetch(tradingHistory.url(), { headers: { Accept: 'application/json' } });
            const json = await res.json();
            if (json.props?.orders) setOrders(json.props.orders);
            setLastSync(new Date());
        } catch {
            // silently ignore
        } finally {
            setSyncing(false);
        }
    }, []);

    useEffect(() => {
        intervalRef.current = setInterval(refresh, POLL_INTERVAL);
        return () => { if (intervalRef.current) clearInterval(intervalRef.current); };
    }, [refresh]);

    const fmt = (n: number, d = 2) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }).format(n);

    const formatTime = (d: Date) =>
        d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    const formatDate = (ms: number) => {
        const d = new Date(ms);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    };

    return (
        <>
            <Head title="Trading History" />
            <Toaster position="top-right" richColors />

            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-base font-semibold text-foreground sm:text-lg">Trading History</h1>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <RefreshCw className={`size-3 ${syncing ? 'animate-spin text-emerald-500' : ''}`} />
                        {lastSync ? `Synced ${formatTime(lastSync)}` : 'Syncing…'}
                        <span className="opacity-50">· auto 5s</span>
                        <button
                            onClick={refresh}
                            disabled={syncing}
                            className="ml-1 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:opacity-40"
                        >
                            Sync now
                        </button>
                    </div>
                </div>

                {/* Table */}
                <div className="rounded-xl border border-border bg-card">
                    {orders.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">No filled orders found.</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border text-[11px] uppercase tracking-widest text-muted-foreground">
                                        <th className="px-4 py-3 text-left">Pair</th>
                                        <th className="px-4 py-3 text-left">Side</th>
                                        <th className="px-4 py-3 text-right">Avg Price</th>
                                        <th className="px-4 py-3 text-right">Volume</th>
                                        <th className="px-4 py-3 text-right">PNL</th>
                                        <th className="px-4 py-3 text-right">Fee</th>
                                        <th className="px-4 py-3 text-right">Time</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {orders.map(order => {
                                        const side    = SIDE_LABEL[order.side] ?? { label: `Side ${order.side}`, color: 'text-foreground' };
                                        const pnlPos  = order.profit > 0;
                                        const pnlNeg  = order.profit < 0;
                                        const fee     = (order.takerFee ?? 0) + (order.makerFee ?? 0);

                                        return (
                                            <tr key={order.orderId} className="hover:bg-muted/30 transition-colors">
                                                <td className="px-4 py-3">
                                                    <span className="font-semibold text-foreground">{coinLabel(order.symbol)}</span>
                                                    <span className="ml-1 text-[10px] text-muted-foreground">/USDT</span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`text-xs font-semibold ${side.color}`}>{side.label}</span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-foreground">
                                                    ${fmt(order.dealAvgPrice)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-foreground">
                                                    {fmt(order.dealVol, 0)}
                                                </td>
                                                <td className={`px-4 py-3 text-right tabular-nums font-semibold ${pnlPos ? 'text-emerald-500' : pnlNeg ? 'text-red-500' : 'text-muted-foreground'}`}>
                                                    {order.profit !== 0 ? `${pnlPos ? '+' : ''}${fmt(order.profit)}` : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                                    {fee !== 0 ? `-${fmt(Math.abs(fee))}` : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-[11px] text-muted-foreground">
                                                    {formatDate(order.updateTime)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

TradingHistory.layout = {
    breadcrumbs: [{ title: 'Trading History', href: tradingHistory.url() }],
};
