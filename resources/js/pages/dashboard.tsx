import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ManualTradingToggle } from '@/components/futures/manual-trading-toggle';
import { OrderForm } from '@/components/futures/order-form';
import { PaperPositions } from '@/components/futures/paper-positions';
import { PositionsList } from '@/components/futures/positions-list';
import { SummaryBar } from '@/components/futures/summary-bar';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';
import {
    account as accountRoute,
    positions as positionsRoute,
} from '@/routes/futures';
import manual from '@/routes/manual';
import type { AccountAsset, PaperPosition, Position } from '@/types/futures';

interface Props {
    account: AccountAsset[];
    positions: Position[];
    manualRealTradingEnabled: boolean;
    paperPositions: PaperPosition[];
}

const POLL_INTERVAL = 5_000;

export default function Dashboard({
    account: initialAccount,
    positions: initialPositions,
    manualRealTradingEnabled: initialManualRealTradingEnabled,
    paperPositions: initialPaperPositions,
}: Props) {
    const [account, setAccount] = useState<AccountAsset[]>(initialAccount);
    const [positions, setPositions] = useState<Position[]>(initialPositions);
    const [paperPositions, setPaperPositions] = useState<PaperPosition[]>(
        initialPaperPositions,
    );
    const [manualRealTradingEnabled, setManualRealTradingEnabled] = useState(
        initialManualRealTradingEnabled,
    );
    const [syncing, setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState<Date | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const refresh = useCallback(async () => {
        setSyncing(true);

        try {
            const [accRes, posRes, paperRes] = await Promise.all([
                fetch(accountRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(positionsRoute.url(), {
                    headers: { Accept: 'application/json' },
                }),
                fetch(manual.positions.index.url(), {
                    headers: { Accept: 'application/json' },
                }),
            ]);
            const [accJson, posJson, paperJson] = await Promise.all([
                accRes.json(),
                posRes.json(),
                paperRes.json(),
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

                {/* Manual real-vs-paper toggle — separate from the bot's own setting */}
                <ManualTradingToggle
                    enabled={manualRealTradingEnabled}
                    onChanged={setManualRealTradingEnabled}
                />

                {/* Summary cards */}
                <SummaryBar account={account} positions={positions} />

                {/* Order form */}
                <OrderForm onExecuted={refresh} />

                {/* Simulated (paper) positions — only shown when any exist */}
                <PaperPositions
                    positions={paperPositions}
                    onRefresh={refresh}
                />

                {/* Open positions */}
                <PositionsList positions={positions} onRefresh={refresh} />
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
