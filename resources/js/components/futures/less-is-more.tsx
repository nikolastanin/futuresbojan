import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { lessIsMore as lessIsMoreRoute } from '@/routes/futures';
import { coinLabel } from '@/types/futures';

interface BatchResult {
    symbol: string;
    direction: 'LONG' | 'SHORT';
    confidence_score?: number;
    nominal_usdt?: number;
    entry_price?: number;
    take_profit?: number;
    status: 'opened' | 'failed';
    message?: string;
}

interface Props {
    onExecuted: () => void;
}

const DEFAULT_COUNT = 5;
const DEFAULT_TP_PERCENT = 1.5;

/**
 * "Less Is More": one click opens a batch of small market-entry positions (one
 * per coin, $100-200 nominal each at fixed 100x) across the bot's current
 * top-confidence signals, each with a TP-only exit — no SL. Fully manual,
 * one-shot; never runs on a loop.
 */
export function LessIsMore({ onExecuted }: Props) {
    const [count, setCount] = useState(String(DEFAULT_COUNT));
    const [tpPercent, setTpPercent] = useState(String(DEFAULT_TP_PERCENT));
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<BatchResult[] | null>(null);

    const run = async () => {
        const parsedCount = parseInt(count, 10);
        const parsedTp = parseFloat(tpPercent);

        if (isNaN(parsedCount) || parsedCount < 1 || parsedCount > 20) {
            toast.error('Positions must be between 1 and 20.');

            return;
        }

        if (isNaN(parsedTp) || parsedTp < 0.1 || parsedTp > 20) {
            toast.error('TP % must be between 0.1 and 20.');

            return;
        }

        setLoading(true);
        setResults(null);

        try {
            const res = await fetch(lessIsMoreRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ count: parsedCount, tpPercent: parsedTp }),
            });

            if (res.redirected || res.status === 302 || res.status === 401) {
                toast.error('Session expired — please refresh the page.');

                return;
            }

            const json = await res.json();

            if (json.success) {
                setResults(json.data);
                toast.success(`Opened ${json.opened} of ${json.requested} micro positions.`);
                onExecuted();
            } else {
                toast.error(json.message ?? 'Failed to open micro positions.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <div>
                <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                    Less Is More
                </p>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                    Open several $100–200 micro positions at once across the bot's top
                    signals — small fees, close TP.
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">Positions (1–20)</label>
                    <Input
                        className="h-8 w-20 text-sm"
                        value={count}
                        onChange={(e) => setCount(e.target.value)}
                        inputMode="numeric"
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] text-muted-foreground">TP %</label>
                    <Input
                        className="h-8 w-20 text-sm"
                        value={tpPercent}
                        onChange={(e) => setTpPercent(e.target.value)}
                        inputMode="decimal"
                    />
                </div>
                <Button
                    className="h-8 gap-1.5 bg-emerald-600 text-white hover:bg-emerald-500"
                    onClick={run}
                    disabled={loading}
                >
                    <Sparkles className="size-3.5" />
                    {loading ? 'Opening…' : `Open ${count || 0} Positions`}
                </Button>
            </div>

            {results && (
                <div className="flex flex-col gap-1 border-t border-border pt-2">
                    {results.map((r, i) => (
                        <div
                            key={`${r.symbol}-${i}`}
                            className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2 py-1 text-[11px]"
                        >
                            <span className="w-16 shrink-0 font-semibold text-foreground">
                                {coinLabel(r.symbol)}
                            </span>
                            {r.status === 'opened' ? (
                                <>
                                    <span
                                        className={`shrink-0 font-bold ${r.direction === 'LONG' ? 'text-emerald-500' : 'text-red-500'}`}
                                    >
                                        {r.direction}
                                    </span>
                                    <span className="text-muted-foreground">
                                        ${r.nominal_usdt} · TP {r.take_profit}
                                    </span>
                                </>
                            ) : (
                                <span className="truncate text-red-500">{r.message ?? 'Failed'}</span>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
