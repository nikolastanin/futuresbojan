import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DashboardNotes } from '@/components/futures/dashboard-notes';
import { LessIsMore } from '@/components/futures/less-is-more';
import { LiquidityHunt } from '@/components/futures/liquidity-hunt';
import type { LiquidityHuntEntry } from '@/components/futures/liquidity-hunt';
import { ManualTradingToggle } from '@/components/futures/manual-trading-toggle';
import { OrderForm } from '@/components/futures/order-form';
import { PaperPositions } from '@/components/futures/paper-positions';
import { PositionsList } from '@/components/futures/positions-list';
import { ScalpScanner } from '@/components/futures/scalp-scanner';
import type { ScalpCandidate } from '@/components/futures/scalp-scanner';
import { SummaryBar } from '@/components/futures/summary-bar';
import { TopSignals } from '@/components/futures/top-signals';
import type { TopSignal } from '@/components/futures/top-signals';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';
import {
    account as accountRoute,
    liquidityHunt as liquidityHuntRoute,
    positions as positionsRoute,
    topSignals as topSignalsRoute,
} from '@/routes/futures';
import manual from '@/routes/manual';
import type { AccountAsset, OrderPrefillRequest, PaperPosition, Position } from '@/types/futures';

interface Props {
    account: AccountAsset[];
    positions: Position[];
    manualRealTradingEnabled: boolean;
    paperPositions: PaperPosition[];
    topSignals: TopSignal[];
    liquidityHunt: LiquidityHuntEntry[];
    notes: string;
}

const POLL_INTERVAL = 5_000;
const LIQUIDITY_HUNT_POLL_INTERVAL = 20_000;

export default function Dashboard({
    account: initialAccount,
    positions: initialPositions,
    manualRealTradingEnabled: initialManualRealTradingEnabled,
    paperPositions: initialPaperPositions,
    topSignals: initialTopSignals,
    liquidityHunt: initialLiquidityHunt,
    notes,
}: Props) {
    const [account, setAccount] = useState<AccountAsset[]>(initialAccount);
    const [positions, setPositions] = useState<Position[]>(initialPositions);
    const [paperPositions, setPaperPositions] = useState<PaperPosition[]>(
        initialPaperPositions,
    );
    const [topSignals, setTopSignals] = useState<TopSignal[]>(initialTopSignals);
    const [liquidityHunt, setLiquidityHunt] = useState<LiquidityHuntEntry[]>(
        initialLiquidityHunt,
    );
    const [manualRealTradingEnabled, setManualRealTradingEnabled] = useState(
        initialManualRealTradingEnabled,
    );
    const [orderPrefill, setOrderPrefill] = useState<OrderPrefillRequest | null>(null);
    const [syncing, setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState<Date | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const huntIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const refresh = useCallback(async () => {
        setSyncing(true);

        try {
            const [accRes, posRes, paperRes, topRes] = await Promise.all([
                fetch(accountRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(positionsRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(manual.positions.index.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(topSignalsRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
            ]);
            const [accJson, posJson, paperJson, topJson] = await Promise.all([
                accRes.json(),
                posRes.json(),
                paperRes.json(),
                topRes.json(),
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

            if (topJson.success) {
                setTopSignals(topJson.data);
            }

            setLastSync(new Date());
        } catch {
            // silently ignore poll errors
        } finally {
            setSyncing(false);
        }
    }, []);

    // Liquidity Hunt is intentionally polled separately and slower than the rest of the
    // page — it's a 15M-swing-level read that doesn't need sub-5s freshness, and
    // reordering the list every 5s made it feel jumpy rather than useful.
    const refreshLiquidityHunt = useCallback(async () => {
        try {
            const res = await fetch(liquidityHuntRoute.url(), {
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();

            if (json.success) {
                setLiquidityHunt(json.data);
            }
        } catch {
            // silently ignore poll errors
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

    useEffect(() => {
        huntIntervalRef.current = setInterval(refreshLiquidityHunt, LIQUIDITY_HUNT_POLL_INTERVAL);

        return () => {
            if (huntIntervalRef.current) {
                clearInterval(huntIntervalRef.current);
            }
        };
    }, [refreshLiquidityHunt]);

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

            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                {/* Sync status bar */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-base font-semibold text-foreground sm:text-lg">
                        Futures Dashboard
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

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                    {/* Main column */}
                    <div className="flex min-w-0 flex-1 flex-col gap-4">
                        {/* Manual real-vs-paper toggle — separate from the bot's own setting */}
                        <ManualTradingToggle
                            enabled={manualRealTradingEnabled}
                            onChanged={setManualRealTradingEnabled}
                        />

                        {/* Summary cards */}
                        <SummaryBar account={account} positions={positions} />

                        {/* Order form */}
                        <OrderForm
                            onExecuted={refresh}
                            prefill={orderPrefill}
                            onPrefilled={() => setOrderPrefill(null)}
                        />

                        {/* Less Is More — batch micro positions across top signals */}
                        <LessIsMore onExecuted={refresh} />

                        {/* Simulated (paper) positions — only shown when any exist */}
                        <PaperPositions
                            positions={paperPositions}
                            onRefresh={refresh}
                        />

                        {/* Open positions */}
                        <PositionsList
                            positions={positions}
                            onRefresh={refresh}
                        />
                    </div>

                    {/* Right sidebar */}
                    <div className="flex w-full shrink-0 flex-col gap-4 lg:w-96">
                        <DashboardNotes notes={notes} />
                        <TopSignals signals={topSignals} />
                        <LiquidityHunt
                            entries={liquidityHunt}
                            onOpenOrder={(entry) =>
                                setOrderPrefill({
                                    nonce: Date.now(),
                                    symbol: entry.symbol,
                                    side: entry.direction === 'higher' ? 1 : 3,
                                    price: entry.level,
                                })
                            }
                        />
                        <ScalpScanner
                            onOpenOrder={(c: ScalpCandidate) =>
                                setOrderPrefill({
                                    nonce: Date.now(),
                                    symbol: c.symbol,
                                    side: c.direction === 'LONG' ? 1 : 3,
                                    price: c.price,
                                })
                            }
                        />
                    </div>
                </div>
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
