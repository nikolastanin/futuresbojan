import { ArrowLeftRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { SearchableSelect } from '@/components/futures/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    momentumLabel,
    structureLabel,
    trendLabel,
    useSignalPreviews,
} from '@/hooks/use-signal-previews';
import {
    orders as ordersRoute,
    symbols as symbolsRoute,
} from '@/routes/futures';
import { coinLabel } from '@/types/futures';

const fmt = (n: number, decimals = 2) =>
    new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(n);

interface Props {
    onExecuted: () => void;
}

// A tiny fallback in case the live /futures/symbols fetch fails — mirrors New Orders'
// own fallback list.
const FALLBACK_SYMBOLS = [
    'BTC_USDT',
    'ETH_USDT',
    'SOL_USDT',
    'BNB_USDT',
    'XRP_USDT',
];

function useActiveSymbols(): string[] {
    const [symbols, setSymbols] = useState<string[]>(FALLBACK_SYMBOLS);

    useEffect(() => {
        fetch(symbolsRoute.url(), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((json) => {
                if (
                    json.success &&
                    Array.isArray(json.data) &&
                    json.data.length > 0
                ) {
                    setSymbols(json.data);
                }
            })
            .catch(() => {});
    }, []);

    return symbols;
}

const LEVERAGE_PICKS = [10, 20, 50, 100];

/**
 * One click opens both a LONG and a SHORT market position on the same coin at
 * once, with identical USDT margin and leverage on each leg — fully hedged
 * (net-flat) from the moment both fill. Reuses the same /futures/orders endpoint
 * as New Orders (it already accepts a batch of independent order rows), so both
 * legs get the same real/paper handling, fees, and risk path as a normal order.
 */
export function HedgeInstant({ onExecuted }: Props) {
    const [symbol, setSymbol] = useState('BTC_USDT');
    const [margin, setMargin] = useState('5');
    const [leverage, setLeverage] = useState(100);
    const [riskUsd, setRiskUsd] = useState('');
    const [riskSlPct, setRiskSlPct] = useState('');
    const [loading, setLoading] = useState(false);
    const availableSymbols = useActiveSymbols();
    const signals = useSignalPreviews([symbol]);
    const signal = signals[symbol];
    const hasSignal = signal && signal !== 'loading' && signal !== 'error';

    // Same calculator as New Orders: "I'm okay losing $X per leg if SL (Y% away)
    // hits" -> back-computes the per-leg margin instead of guessing a round amount.
    const riskUsdNum = parseFloat(riskUsd);
    const riskSlPctNum = parseFloat(riskSlPct);
    const riskComputedMargin =
        riskUsdNum > 0 && riskSlPctNum > 0 && leverage > 0
            ? riskUsdNum / ((riskSlPctNum / 100) * leverage)
            : null;

    const execute = async () => {
        const marginUsdt = parseFloat(margin);

        if (isNaN(marginUsdt) || marginUsdt <= 0) {
            toast.error('Enter a valid USDT margin amount.');

            return;
        }

        setLoading(true);

        try {
            const leg = (side: 1 | 3) => ({
                symbol,
                price: 0,
                marginUsdt,
                leverage,
                side,
                type: 5, // market
                openType: 2, // cross
            });

            const res = await fetch(ordersRoute.url(), {
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
                body: JSON.stringify({ orders: [leg(1), leg(3)] }),
            });

            if (res.redirected || res.status === 302 || res.status === 401) {
                toast.error('Session expired — please refresh the page.');

                return;
            }

            const json = await res.json();

            if (json.success) {
                toast.success(
                    `Hedge opened: ${coinLabel(symbol)} LONG + SHORT ($${margin} · ${leverage}x each).`,
                );
                setMargin('5');
                onExecuted();
            } else {
                toast.error(json.message ?? 'Hedge failed.');
            }
        } catch {
            toast.error('Request failed — check your session and try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-t-2 border-border border-t-teal-500 bg-card p-4">
            <div>
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <ArrowLeftRight className="size-3.5 text-teal-500" />
                    Hedge Instant
                </p>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                    Opens a LONG and a SHORT market position on the same coin at
                    once — same USDT margin and leverage on both legs, net-flat
                    from the moment they fill.
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">
                        Coin
                    </label>
                    <SearchableSelect
                        value={symbol}
                        options={availableSymbols}
                        onChange={setSymbol}
                        className="w-36"
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">
                        USDT margin (each leg)
                    </label>
                    <Input
                        className="h-8 w-28 text-sm"
                        value={margin}
                        onChange={(e) => setMargin(e.target.value)}
                        inputMode="decimal"
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">
                        Risk $ / SL %
                    </label>
                    <div className="flex items-center gap-1">
                        <Input
                            className="h-8 w-16 text-sm"
                            placeholder="Risk $"
                            value={riskUsd}
                            onChange={(e) => setRiskUsd(e.target.value)}
                            inputMode="decimal"
                        />
                        <Input
                            className="h-8 w-14 text-sm"
                            placeholder="SL %"
                            value={riskSlPct}
                            onChange={(e) => setRiskSlPct(e.target.value)}
                            inputMode="decimal"
                        />
                        <button
                            type="button"
                            disabled={riskComputedMargin === null}
                            onClick={() =>
                                riskComputedMargin !== null &&
                                setMargin(
                                    String(
                                        Number(riskComputedMargin.toFixed(4)),
                                    ),
                                )
                            }
                            className="rounded border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:opacity-40"
                        >
                            Use
                        </button>
                    </div>
                    {riskComputedMargin !== null && (
                        <span className="text-[10px] text-muted-foreground">
                            → ${fmt(riskComputedMargin, 4)} margin/leg
                        </span>
                    )}
                </div>
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">
                        Leverage
                    </label>
                    <div className="flex flex-col gap-1">
                        <Input
                            className="h-8 w-16 text-center text-sm"
                            value={leverage}
                            onChange={(e) =>
                                setLeverage(parseInt(e.target.value, 10) || 1)
                            }
                        />
                        <div className="flex gap-1">
                            {LEVERAGE_PICKS.map((lev) => (
                                <button
                                    key={lev}
                                    type="button"
                                    onClick={() => setLeverage(lev)}
                                    className={`rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors ${
                                        leverage === lev
                                            ? 'border-teal-500 bg-teal-500/10 text-teal-500'
                                            : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                                    }`}
                                >
                                    {lev}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                <Button
                    className="h-8 gap-1.5 bg-teal-600 text-white hover:bg-teal-500"
                    onClick={execute}
                    disabled={loading}
                >
                    <ArrowLeftRight className="size-3.5" />
                    {loading ? 'Opening…' : 'Open Hedge'}
                </Button>
            </div>

            {/* Read on the selected coin — same indicators the bot uses, just labeled —
                so you're not purely going by feel once both legs are open. */}
            {hasSignal && (
                <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-background px-3 py-1.5 text-xs">
                    <span className="text-muted-foreground">
                        {coinLabel(symbol)}:
                    </span>
                    <span className={trendLabel(signal.trend).color}>
                        Trend {trendLabel(signal.trend).label}
                    </span>
                    <span className={momentumLabel(signal.momentum).color}>
                        Momentum {momentumLabel(signal.momentum).label}
                    </span>
                    {structureLabel(signal.structure) && (
                        <span
                            className={structureLabel(signal.structure)!.color}
                        >
                            {structureLabel(signal.structure)!.label}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
