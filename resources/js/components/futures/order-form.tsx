import { useEffect, useRef, useState } from 'react';
import { nanoid } from 'nanoid';
import { Plus, Trash2, Zap } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SYMBOLS, symbolLabel, type OrderRow } from '@/types/futures';
import { orders as ordersRoute, tickers as tickersRoute } from '@/routes/futures';
import { toast } from 'sonner';

// ─── Fair price hook ──────────────────────────────────────────────────────────

type PriceMap = Record<string, number>;

function useFairPrices(symbols: string[]): PriceMap {
    const [prices, setPrices] = useState<PriceMap>({});
    const symbolsKey = symbols.slice().sort().join(',');

    useEffect(() => {
        if (!symbols.length) return;

        const fetch_ = () => {
            fetch(tickersRoute.url(), { headers: { Accept: 'application/json' } })
                .then(r => r.json())
                .then(json => {
                    const map: PriceMap = {};
                    for (const t of json.data ?? []) {
                        if (symbols.includes(t.symbol)) {
                            map[t.symbol] = parseFloat(t.fairPrice);
                        }
                    }
                    setPrices(prev => ({ ...prev, ...map }));
                })
                .catch(() => {});
        };

        fetch_();
        const id = setInterval(fetch_, 15_000);
        return () => clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [symbolsKey]);

    return prices;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeRow(): OrderRow {
    return {
        id:       nanoid(6),
        symbol:   'BTC_USDT',
        price:    '',
        vol:      '',
        leverage: 100,
        side:     1,
        type:     5,
        openType: 2,
    };
}

const fmt = (n: number, decimals = 2) =>
    new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(n);

// ─── Component ────────────────────────────────────────────────────────────────

interface Props {
    onExecuted: () => void;
}

export function OrderForm({ onExecuted }: Props) {
    const [rows, setRows]       = useState<OrderRow[]>([makeRow()]);
    const [loading, setLoading] = useState(false);

    const symbols  = [...new Set(rows.map(r => r.symbol))];
    const prices   = useFairPrices(symbols);

    const updateRow = (id: string, patch: Partial<OrderRow>) =>
        setRows(prev => prev.map(r => (r.id === id ? { ...r, ...patch } : r)));

    const addRow    = () => setRows(prev => [...prev, makeRow()]);
    const removeRow = (id: string) => setRows(prev => prev.filter(r => r.id !== id));

    const execute = async () => {
        const orders = rows.map(r => ({
            symbol:     r.symbol,
            price:      r.type === 5 ? 0 : parseFloat(r.price),
            marginUsdt: parseFloat(r.vol),
            leverage:   r.leverage,
            side:       r.side,
            type:       r.type,
            openType:   r.openType,
        }));

        const invalid = orders.find(o => isNaN(o.marginUsdt) || o.marginUsdt <= 0);
        if (invalid) {
            toast.error('Fill in USDT amount for all order rows.');
            return;
        }

        setLoading(true);
        try {
            const res = await fetch(ordersRoute.url(), {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ orders }),
            });
            if (res.redirected || res.status === 302 || res.status === 401) {
                toast.error('Session expired — please refresh the page.');
                return;
            }
            const json = await res.json();
            if (json.success) {
                toast.success(`${orders.length} order${orders.length > 1 ? 's' : ''} placed.`);
                setRows([makeRow()]);
                onExecuted();
            } else {
                toast.error(json.message ?? 'Order failed.');
            }
        } catch (e) {
            toast.error('Request failed — check your session and try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
            <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">New Orders</p>

            <div className="flex flex-col gap-2">
                {rows.map((row) => (
                    <OrderRowEditor
                        key={row.id}
                        row={row}
                        fairPrice={prices[row.symbol]}
                        showRemove={rows.length > 1}
                        onChange={patch => updateRow(row.id, patch)}
                        onRemove={() => removeRow(row.id)}
                    />
                ))}
            </div>

            <div className="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    className="gap-1 text-muted-foreground hover:text-foreground"
                    onClick={addRow}
                >
                    <Plus className="size-4" />
                    Add order
                </Button>

                <Button
                    className="ml-auto gap-2 bg-emerald-600 text-white hover:bg-emerald-500"
                    onClick={execute}
                    disabled={loading}
                >
                    <Zap className="size-4" />
                    {loading ? 'Executing…' : `Execute${rows.length > 1 ? ` (${rows.length})` : ''}`}
                </Button>
            </div>
        </div>
    );
}

// ─── Row editor ───────────────────────────────────────────────────────────────

function OrderRowEditor({
    row,
    fairPrice,
    showRemove,
    onChange,
    onRemove,
}: {
    row: OrderRow;
    fairPrice: number | undefined;
    showRemove: boolean;
    onChange: (patch: Partial<OrderRow>) => void;
    onRemove: () => void;
}) {
    const isMarket   = row.type === 5;
    const isLong     = row.side === 1;
    const entryPrice = isMarket ? (fairPrice ?? 0) : (parseFloat(row.price) || 0);
    const marginUsdt = parseFloat(row.vol) || 0;
    const notional   = marginUsdt * row.leverage;
    const liqPrice   = entryPrice > 0 && notional > 0
        ? isLong
            ? entryPrice * (1 - 1 / row.leverage)
            : entryPrice * (1 + 1 / row.leverage)
        : null;

    const ta  = 'px-3 py-1 text-xs font-medium transition-colors';
    const on  = 'bg-accent text-accent-foreground';
    const off = 'text-muted-foreground hover:text-foreground';

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/30 p-2">
            {/* Symbol */}
            <Select value={row.symbol} onValueChange={v => onChange({ symbol: v })}>
                <SelectTrigger className="h-8 w-32 text-sm">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {SYMBOLS.map(s => (
                        <SelectItem key={s} value={s}>{symbolLabel(s)}</SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {/* Market / Limit */}
            <div className="flex overflow-hidden rounded-md border border-border">
                <button className={`${ta} ${isMarket ? on : off}`} onClick={() => onChange({ type: 5 })}>Market</button>
                <button className={`${ta} ${!isMarket ? on : off}`} onClick={() => onChange({ type: 1 })}>Limit</button>
            </div>

            {/* Price (limit only) */}
            {!isMarket && (
                <Input className="h-8 w-28 text-sm" placeholder="Price" value={row.price} onChange={e => onChange({ price: e.target.value })} />
            )}

            {/* USDT margin */}
            <Input className="h-8 w-32 text-sm" placeholder="USDT margin" value={row.vol} onChange={e => onChange({ vol: e.target.value })} />

            {/* Leverage */}
            <div className="flex items-center gap-1">
                <Input className="h-8 w-14 text-center text-sm" value={row.leverage} onChange={e => onChange({ leverage: parseInt(e.target.value) || 1 })} />
                <span className="text-xs text-muted-foreground">lev</span>
            </div>

            {/* Long / Short */}
            <div className="flex overflow-hidden rounded-md border border-border">
                <button className={`px-3 py-1 text-xs font-semibold transition-colors ${isLong ? 'bg-emerald-600 text-white' : off}`} onClick={() => onChange({ side: 1 })}>Long</button>
                <button className={`px-3 py-1 text-xs font-semibold transition-colors ${!isLong ? 'bg-red-600 text-white' : off}`} onClick={() => onChange({ side: 3 })}>Short</button>
            </div>

            {/* ── Inline preview ── */}
            <div className="flex items-center gap-3 rounded-md border border-border bg-background px-3 py-1">
                <span className={`text-xs font-bold ${isLong ? 'text-emerald-500' : 'text-red-500'}`}>
                    {isLong ? 'LONG' : 'SHORT'}
                </span>
                <PreviewStat label="Entry" value={entryPrice > 0 ? `$${fmt(entryPrice)}` : '—'} />
                <PreviewStat label="Notional" value={notional > 0 ? `$${notional.toLocaleString()}` : '—'} />
                <PreviewStat
                    label="Est. liq."
                    value={liqPrice ? `$${fmt(liqPrice)}` : '—'}
                    className={liqPrice ? (isLong ? 'text-red-500' : 'text-emerald-500') : ''}
                />
            </div>

            {showRemove && (
                <button className="ml-auto text-muted-foreground hover:text-destructive transition-colors" onClick={onRemove}>
                    <Trash2 className="size-4" />
                </button>
            )}
        </div>
    );
}

function PreviewStat({ label, value, className = '' }: { label: string; value: string; className?: string }) {
    return (
        <div className="flex shrink-0 flex-col">
            <span className="text-[10px] leading-none text-muted-foreground">{label}</span>
            <span className={`text-xs font-medium tabular-nums text-foreground ${className}`}>{value}</span>
        </div>
    );
}
