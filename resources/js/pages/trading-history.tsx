import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { tradingHistory } from '@/routes';
import { coinLabel } from '@/types/futures';

interface FilledOrder {
    orderId: string | number;
    symbol: string;
    side: number; // 1=open long, 2=close short, 3=open short, 4=close long
    dealAvgPrice: number;
    dealVol: number;
    vol: number;
    profit: number;
    takerFee: number;
    makerFee: number;
    createTime: number;
    updateTime: number;
}

interface BotTrade {
    id: number;
    symbol: string;
    direction: string;
    mode: string;
    status: string;
    entry_price: number;
    exit_price: number | null;
    net_profit_usdt: number | null;
    fee_usdt: number | null;
    confidence_score: number;
    close_reason: string | null;
    opened_at: string;
    closed_at: string | null;
}

interface PnlDayPosition {
    symbol: string;
    pnl: number;
    closedAt: number;
}

interface PnlDay {
    date: string; // YYYY-MM-DD, UTC
    realized: number;
    realizedWon: number;
    realizedLost: number;
    wonCount: number;
    lostCount: number;
    positions: PnlDayPosition[];
}

interface Props {
    orders: FilledOrder[];
    botTrades: BotTrade[];
    pnlHistory: PnlDay[];
}

const POLL_INTERVAL = 5_000;

const SIDE_LABEL: Record<number, { label: string; color: string }> = {
    1: { label: 'Open Long', color: 'text-emerald-500' },
    2: { label: 'Close Short', color: 'text-emerald-500' },
    3: { label: 'Open Short', color: 'text-red-500' },
    4: { label: 'Close Long', color: 'text-red-500' },
};

type Tab = 'opened' | 'closed';
type BotTab = 'open' | 'closed';

export default function TradingHistory({
    orders: initialOrders,
    botTrades: initialBotTrades,
    pnlHistory: initialPnlHistory,
}: Props) {
    const [orders, setOrders] = useState<FilledOrder[]>(initialOrders);
    const [tab, setTab] = useState<Tab>('closed');
    const [botTrades, setBotTrades] = useState<BotTrade[]>(initialBotTrades);
    const [botTab, setBotTab] = useState<BotTab>('open');
    const [pnlHistory, setPnlHistory] = useState<PnlDay[]>(initialPnlHistory);
    const [syncing, setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState<Date | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // sides 1 & 3 = open actions; sides 2 & 4 = close actions
    const visibleOrders = orders.filter((o) =>
        tab === 'opened' ? [1, 3].includes(o.side) : [2, 4].includes(o.side),
    );

    const visibleBotTrades = botTrades.filter((t) => t.status === botTab);

    const refresh = useCallback(async () => {
        setSyncing(true);

        try {
            const res = await fetch(tradingHistory.url(), {
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();

            if (json.props?.orders) {
                setOrders(json.props.orders);
            }

            if (json.props?.botTrades) {
                setBotTrades(json.props.botTrades);
            }

            if (json.props?.pnlHistory) {
                setPnlHistory(json.props.pnlHistory);
            }

            setLastSync(new Date());
        } catch {
            // silently ignore
        } finally {
            setSyncing(false);
        }
    }, []);

    useEffect(() => {
        intervalRef.current = setInterval(refresh, POLL_INTERVAL);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [refresh]);

    const fmt = (n: number, d = 2) =>
        new Intl.NumberFormat('en-US', {
            minimumFractionDigits: d,
            maximumFractionDigits: d,
        }).format(n);

    const formatTime = (d: Date) =>
        d.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

    const formatDate = (ms: number | string) => {
        const d = new Date(ms);

        return (
            d.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            }) +
            ' ' +
            d.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
            })
        );
    };

    // pnlHistory buckets by UTC calendar day, so compare against UTC "today"/"yesterday"
    // rather than the browser's local date to avoid an off-by-one near midnight.
    const formatDayLabel = (dateStr: string) => {
        const now = new Date();
        const utcToday = now.toISOString().slice(0, 10);
        const utcYesterday = new Date(now.getTime() - 86_400_000)
            .toISOString()
            .slice(0, 10);

        if (dateStr === utcToday) {
            return 'Today';
        }

        if (dateStr === utcYesterday) {
            return 'Yesterday';
        }

        return new Date(`${dateStr}T00:00:00Z`).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <>
            <Head title="Trading History" />
            <Toaster position="top-right" richColors />

            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-base font-semibold text-foreground sm:text-lg">
                        Trading History
                    </h1>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <RefreshCw
                            className={`size-3 ${syncing ? 'animate-spin text-emerald-500' : ''}`}
                        />
                        {lastSync
                            ? `Synced ${formatTime(lastSync)}`
                            : 'Syncing…'}
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

                {/* Daily PNL tracker */}
                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
                    <h2 className="text-base font-semibold text-foreground sm:text-lg">
                        Daily PNL
                    </h2>
                    <div className="flex flex-col divide-y divide-border">
                        {pnlHistory.map((day) => (
                            <div
                                key={day.date}
                                className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="w-24 shrink-0 text-sm font-semibold text-foreground">
                                        {formatDayLabel(day.date)}
                                    </span>
                                    <span
                                        className={`text-sm font-bold tabular-nums ${
                                            day.realized > 0
                                                ? 'text-emerald-500'
                                                : day.realized < 0
                                                  ? 'text-red-500'
                                                  : 'text-muted-foreground'
                                        }`}
                                    >
                                        {day.realized > 0 ? '+' : ''}
                                        {fmt(day.realized)}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        <span className="font-semibold text-emerald-500">
                                            Won +{fmt(day.realizedWon)} (
                                            {day.wonCount})
                                        </span>
                                        {' · '}
                                        <span className="font-semibold text-red-500">
                                            Lost {fmt(day.realizedLost)} (
                                            {day.lostCount})
                                        </span>
                                    </span>
                                </div>

                                {day.positions.length === 0 ? (
                                    <p className="pl-24 text-xs text-muted-foreground">
                                        No closed positions.
                                    </p>
                                ) : (
                                    <div className="flex flex-wrap gap-1.5 pl-24">
                                        {day.positions.map((p, i) => (
                                            <span
                                                key={`${p.symbol}-${i}`}
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                    p.pnl > 0
                                                        ? 'bg-emerald-500/10 text-emerald-500'
                                                        : 'bg-red-500/10 text-red-500'
                                                }`}
                                            >
                                                {coinLabel(p.symbol)}{' '}
                                                {p.pnl > 0 ? '+' : ''}
                                                {fmt(p.pnl)}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex w-fit gap-1 rounded-lg border border-border bg-muted/40 p-1">
                    {(['closed', 'opened'] as Tab[]).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`rounded-md px-4 py-1.5 text-xs font-semibold capitalize transition-colors ${
                                tab === t
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t === 'closed'
                                ? 'Closed Positions'
                                : 'Opened Positions'}
                            <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                {
                                    orders.filter((o) =>
                                        t === 'opened'
                                            ? [1, 3].includes(o.side)
                                            : [2, 4].includes(o.side),
                                    ).length
                                }
                            </span>
                        </button>
                    ))}
                </div>

                {/* Table */}
                <div className="rounded-xl border border-border bg-card">
                    {visibleOrders.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            No {tab} orders found.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border text-[11px] tracking-widest text-muted-foreground uppercase">
                                        <th className="px-4 py-3 text-left">
                                            Pair
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Side
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Avg Price
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Volume
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            PNL
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Fee
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Time
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {visibleOrders.map((order) => {
                                        const side = SIDE_LABEL[order.side] ?? {
                                            label: `Side ${order.side}`,
                                            color: 'text-foreground',
                                        };
                                        const pnlPos = order.profit > 0;
                                        const pnlNeg = order.profit < 0;
                                        const fee =
                                            (order.takerFee ?? 0) +
                                            (order.makerFee ?? 0);

                                        return (
                                            <tr
                                                key={order.orderId}
                                                className="transition-colors hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3">
                                                    <span className="font-semibold text-foreground">
                                                        {coinLabel(
                                                            order.symbol,
                                                        )}
                                                    </span>
                                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                                        /USDT
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`text-xs font-semibold ${side.color}`}
                                                    >
                                                        {side.label}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right text-foreground tabular-nums">
                                                    ${fmt(order.dealAvgPrice)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-foreground tabular-nums">
                                                    {fmt(order.dealVol, 0)}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-semibold tabular-nums ${pnlPos ? 'text-emerald-500' : pnlNeg ? 'text-red-500' : 'text-muted-foreground'}`}
                                                >
                                                    {order.profit !== 0
                                                        ? `${pnlPos ? '+' : ''}${fmt(order.profit)}`
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-muted-foreground tabular-nums">
                                                    {fee !== 0
                                                        ? `-${fmt(Math.abs(fee))}`
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-[11px] text-muted-foreground">
                                                    {formatDate(
                                                        order.updateTime,
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Bot trades — paper trades never touch the exchange, so this is the
                    only place their status/PnL is visible. Real bot trades also show
                    up above as raw fills; this gives a per-trade (not per-fill) view. */}
                <div className="flex flex-wrap items-center justify-between gap-2 pt-2">
                    <h2 className="text-base font-semibold text-foreground sm:text-lg">
                        Bot Trades
                    </h2>
                </div>

                <div className="flex w-fit gap-1 rounded-lg border border-border bg-muted/40 p-1">
                    {(['open', 'closed'] as BotTab[]).map((t) => (
                        <button
                            key={t}
                            onClick={() => setBotTab(t)}
                            className={`rounded-md px-4 py-1.5 text-xs font-semibold capitalize transition-colors ${
                                botTab === t
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t === 'open' ? 'Open' : 'Closed'}
                            <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                {
                                    botTrades.filter((bt) => bt.status === t)
                                        .length
                                }
                            </span>
                        </button>
                    ))}
                </div>

                <div className="rounded-xl border border-border bg-card">
                    {visibleBotTrades.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            No {botTab} bot trades found.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border text-[11px] tracking-widest text-muted-foreground uppercase">
                                        <th className="px-4 py-3 text-left">
                                            Pair
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Direction
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Mode
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Entry
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Exit
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Net PnL
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Fee
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Close reason
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Time
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {visibleBotTrades.map((t) => {
                                        const pnl = t.net_profit_usdt;
                                        const pnlPos = (pnl ?? 0) > 0;
                                        const pnlNeg = (pnl ?? 0) < 0;

                                        return (
                                            <tr
                                                key={t.id}
                                                className="transition-colors hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3">
                                                    <span className="font-semibold text-foreground">
                                                        {coinLabel(t.symbol)}
                                                    </span>
                                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                                        /USDT
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`text-xs font-semibold ${t.direction === 'LONG' ? 'text-emerald-500' : 'text-red-500'}`}
                                                    >
                                                        {t.direction}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`text-xs font-semibold ${t.mode === 'real' ? 'text-red-500' : 'text-muted-foreground'}`}
                                                    >
                                                        {t.mode}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right text-foreground tabular-nums">
                                                    ${fmt(t.entry_price, 4)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-foreground tabular-nums">
                                                    {t.exit_price !== null
                                                        ? `$${fmt(t.exit_price, 4)}`
                                                        : '—'}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-semibold tabular-nums ${pnlPos ? 'text-emerald-500' : pnlNeg ? 'text-red-500' : 'text-muted-foreground'}`}
                                                >
                                                    {pnl !== null
                                                        ? `${pnlPos ? '+' : ''}${fmt(pnl)}`
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-muted-foreground tabular-nums">
                                                    {t.fee_usdt
                                                        ? `-${fmt(Math.abs(t.fee_usdt))}`
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {t.close_reason ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-[11px] text-muted-foreground">
                                                    {formatDate(
                                                        t.closed_at ??
                                                            t.opened_at,
                                                    )}
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
