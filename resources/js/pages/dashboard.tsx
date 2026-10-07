import { Head } from '@inertiajs/react';
import { LineChart, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AnalysisPanel } from '@/components/futures/analysis-panel';
import { GradePill } from '@/components/futures/grade-pill';
import { HedgeInstant } from '@/components/futures/hedge-instant';
import { ManualTradingToggle } from '@/components/futures/manual-trading-toggle';
import { OrderForm } from '@/components/futures/order-form';
import { PaperPositions } from '@/components/futures/paper-positions';
import { PaperSummaryBar } from '@/components/futures/paper-summary-bar';
import { PositionsList } from '@/components/futures/positions-list';
import { PriceAlertWatcher } from '@/components/futures/price-alert-watcher';
import { RiskRadar } from '@/components/futures/risk-radar';
import { SnapshotRecorder } from '@/components/futures/snapshot-recorder';
import { SummaryBar } from '@/components/futures/summary-bar';
import type { TodayPnl } from '@/components/futures/summary-bar';
import { WinningPositions } from '@/components/futures/winning-positions';
import { Toaster } from '@/components/ui/sonner';
import { useSignalPreviews } from '@/hooks/use-signal-previews';
import { dashboard } from '@/routes';
import {
    account as accountRoute,
    positions as positionsRoute,
    todayPnl as todayPnlRoute,
} from '@/routes/futures';
import manual from '@/routes/manual';
import type { AccountAsset, PaperPosition, Position } from '@/types/futures';

interface Props {
    account: AccountAsset[];
    positions: Position[];
    manualRealTradingEnabled: boolean;
    paperPositions: PaperPosition[];
    todayPnl: TodayPnl | null;
}

const POLL_INTERVAL = 5_000;

export default function Dashboard({
    account: initialAccount,
    positions: initialPositions,
    manualRealTradingEnabled: initialManualRealTradingEnabled,
    paperPositions: initialPaperPositions,
    todayPnl: initialTodayPnl,
}: Props) {
    const [account, setAccount] = useState<AccountAsset[]>(initialAccount);
    const [positions, setPositions] = useState<Position[]>(initialPositions);
    const [todayPnl, setTodayPnl] = useState<TodayPnl | null>(initialTodayPnl);
    const [paperPositions, setPaperPositions] = useState<PaperPosition[]>(
        initialPaperPositions,
    );
    const [manualRealTradingEnabled, setManualRealTradingEnabled] = useState(
        initialManualRealTradingEnabled,
    );
    const [orderSymbol, setOrderSymbol] = useState<string | null>(null);
    const [syncing, setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState<Date | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const refresh = useCallback(async () => {
        setSyncing(true);

        try {
            const [accRes, posRes, paperRes, todayPnlRes] = await Promise.all([
                fetch(accountRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(positionsRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(manual.positions.index.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(todayPnlRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
            ]);
            const [accJson, posJson, paperJson, todayPnlJson] =
                await Promise.all([
                    accRes.json(),
                    posRes.json(),
                    paperRes.json(),
                    todayPnlRes.json(),
                ]);

            if (accJson.success) {
                setAccount(accJson.data);
            }

            if (posJson.success) {
                setPositions(posJson.data);
            }

            if (paperJson.success) {
                setPaperPositions(paperJson.data);
            }

            if (todayPnlJson.success) {
                setTodayPnl(todayPnlJson.data);
            }

            setLastSync(new Date());
        } catch {
            // silently ignore poll errors
        } finally {
            setSyncing(false);
        }
    }, []);

    // Start 5-second polling
    useEffect(() => {
        intervalRef.current = setInterval(refresh, POLL_INTERVAL);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [refresh]);

    const totalEquity = account.find((a) => a.currency === 'USDT')?.equity ?? 0;

    // One signal read per held coin, shared by the risk radar (for each coin's volatility)
    // and the position rows, so neither fetches it twice.
    const heldSymbols = [...new Set(positions.map((p) => p.symbol))];
    const signals = useSignalPreviews(heldSymbols);

    const formatTime = (d: Date) =>
        d.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

    return (
        <>
            <Head title="Futures Dashboard" />
            <Toaster position="top-right" richColors />
            <PriceAlertWatcher />
            <SnapshotRecorder symbols={orderSymbol ? [orderSymbol] : []} />

            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                {/* Sync status bar */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="flex items-center gap-2 text-base font-semibold text-foreground sm:text-lg">
                        <LineChart className="size-5 text-emerald-500" />
                        Futures Dashboard
                    </h1>
                    <div className="flex items-center gap-3">
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

                        <GradePill />

                        {/* Manual real-vs-paper toggle, tucked in the corner — separate from
                            the bot's own real-trading setting */}
                        <ManualTradingToggle
                            enabled={manualRealTradingEnabled}
                            onChanged={setManualRealTradingEnabled}
                        />
                    </div>
                </div>

                {/* Winning positions, pinned to the top so a profitable trade can be
                    flash-closed without scrolling — hidden when nothing is in profit */}
                <WinningPositions positions={positions} onRefresh={refresh} />

                {/* Summary cards */}
                <SummaryBar
                    account={account}
                    positions={positions}
                    todayPnl={todayPnl}
                />

                {/* How much the whole account is carrying, and how near liquidation is */}
                <RiskRadar
                    positions={positions}
                    totalEquity={totalEquity}
                    signals={signals}
                />

                {/* Paper trading — hidden while real trading is on, since it's not the
                    money in play right now */}
                {!manualRealTradingEnabled && (
                    <PaperSummaryBar positions={paperPositions} />
                )}

                {/* Everything known about one coin, full width, right above where
                    the order gets placed. Collapsible when it's not needed. */}
                <AnalysisPanel
                    positions={positions}
                    totalEquity={totalEquity}
                    orderSymbol={orderSymbol}
                />

                {/* New Orders, with the instant-hedge shortcut alongside */}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                    <OrderForm
                        onExecuted={refresh}
                        onSymbolChange={setOrderSymbol}
                    />
                    <HedgeInstant onExecuted={refresh} />
                </div>

                {!manualRealTradingEnabled && (
                    <PaperPositions
                        positions={paperPositions}
                        onRefresh={refresh}
                    />
                )}

                {/* Open positions */}
                <PositionsList
                    positions={positions}
                    totalEquity={totalEquity}
                    signals={signals}
                    onRefresh={refresh}
                />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
