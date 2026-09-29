import { ChevronDown, ChevronUp } from 'lucide-react';
import { useState } from 'react';

interface Props {
    direction: 'LONG' | 'SHORT';
    currentPrice: number;
    onAdd: (amount: number) => void;
    onReduce: (amount: number) => void;
    addBusy?: boolean;
    reduceBusy?: boolean;
}

interface Rung {
    zone: 'add' | 'reduce';
    price: number;
    pct: number;
    amounts: number[];
}

type Row = Rung | { zone: 'now'; price: number };

// Distances (% from current price) and the $ amount choices offered at each — each
// tier's own set grows with distance (same "add/take more the further it moves"
// shape as a classic DCA ladder), but every tier now offers a small/medium/large
// pick instead of one fixed amount.
const DISTANCES_PCT = [2, 5, 10, 15, 20];
const AMOUNT_OPTIONS: number[][] = [
    [0.3, 0.5, 1],
    [0.5, 1, 2],
    [1, 2, 3],
    [2, 3, 5],
    [3, 5, 10],
];

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * A pre-planned symmetric ladder of add/reduce zones around the current price —
 * "if it moves this far, here's how much to add or take off" instead of deciding
 * reactively in the moment. Which side is "add" flips with direction: for a LONG,
 * dips below current are add (dollar-cost-averaging) zones and rallies above are
 * reduce (profit-taking) zones; for a SHORT it's mirrored (rallies against you are
 * the add zone, dips in your favor are reduce).
 *
 * Purely a planning aid — clicking an amount fires the same Add/Reduce market order
 * immediately via the handlers already used by the quick-amount buttons below, it
 * does NOT place a pending order that waits for that price to be hit.
 */
export function ScalingLadder({
    direction,
    currentPrice,
    onAdd,
    onReduce,
    addBusy,
    reduceBusy,
}: Props) {
    const [expanded, setExpanded] = useState(false);
    const isLong = direction === 'LONG';

    const rungs: Rung[] = DISTANCES_PCT.flatMap((pct, i) => {
        const amounts = AMOUNT_OPTIONS[i];
        const belowPrice = currentPrice * (1 - pct / 100);
        const abovePrice = currentPrice * (1 + pct / 100);

        return isLong
            ? [
                  {
                      zone: 'add' as const,
                      price: belowPrice,
                      pct: -pct,
                      amounts,
                  },
                  { zone: 'reduce' as const, price: abovePrice, pct, amounts },
              ]
            : [
                  {
                      zone: 'reduce' as const,
                      price: belowPrice,
                      pct: -pct,
                      amounts,
                  },
                  { zone: 'add' as const, price: abovePrice, pct, amounts },
              ];
    });

    const nowRow: Row = { zone: 'now', price: currentPrice };
    const rows: Row[] = [...rungs, nowRow].sort((a, b) => b.price - a.price);

    return (
        <div className="flex flex-col gap-1.5">
            <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="flex w-fit items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
            >
                Scaling Plan
                {expanded ? (
                    <ChevronUp className="size-3" />
                ) : (
                    <ChevronDown className="size-3" />
                )}
            </button>
            {expanded && (
                <div className="flex flex-col gap-1.5 rounded-md border border-border bg-background p-2">
                    <p className="text-[10px] text-muted-foreground">
                        Click an amount to execute now at market — it doesn't
                        wait for that price to be hit.
                    </p>
                    <div className="flex flex-col overflow-hidden rounded">
                        {rows.map((r, i) =>
                            r.zone === 'now' ? (
                                <div
                                    key="now"
                                    className="flex items-center justify-between gap-2 bg-foreground/10 px-1.5 py-1 text-[11px] font-bold text-foreground"
                                >
                                    <span>NOW</span>
                                    <span className="tabular-nums">
                                        ${fmtPrice(r.price)}
                                    </span>
                                </div>
                            ) : (
                                <div
                                    key={i}
                                    className="flex flex-col gap-1 px-1.5 py-1 text-[11px] odd:bg-muted/20"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span
                                            className={`font-semibold ${
                                                r.zone === 'add'
                                                    ? 'text-emerald-500'
                                                    : 'text-amber-500'
                                            }`}
                                        >
                                            {r.zone === 'add'
                                                ? 'Add'
                                                : 'Reduce'}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            ${fmtPrice(r.price)} (
                                            {r.pct > 0 ? '+' : ''}
                                            {r.pct}%)
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {r.amounts.map((amt) => (
                                            <button
                                                key={amt}
                                                type="button"
                                                disabled={
                                                    r.zone === 'add'
                                                        ? addBusy
                                                        : reduceBusy
                                                }
                                                onClick={() =>
                                                    r.zone === 'add'
                                                        ? onAdd(amt)
                                                        : onReduce(amt)
                                                }
                                                className={`shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors disabled:opacity-40 ${
                                                    r.zone === 'add'
                                                        ? 'border-emerald-500/50 text-emerald-500 hover:bg-emerald-500/10'
                                                        : 'border-amber-500/50 text-amber-500 hover:bg-amber-500/10'
                                                }`}
                                            >
                                                ${amt}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
