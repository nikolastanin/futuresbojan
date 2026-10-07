import { Calculator } from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { AnalysisExtras } from '@/hooks/use-analysis-extras';
import {
    breakEvenPrice,
    coinOf,
    describeHedge,
    equityZeroPrice,
    exposureBySymbol,
    legsFromPositions,
    reducingSide,
    scenarios,
    whatIfRows,
} from '@/lib/risk-math';
import type { AddResult, ScenarioRow, ZoneInput } from '@/lib/risk-math';
import type { Position } from '@/types/futures';

interface Props {
    symbol: string;
    /** The open legs in this coin (one or both sides). */
    legs: Position[];
    totalEquity: number;
    /** Supplies the plan zones for the what-if table. */
    extras: AnalysisExtras | 'loading' | 'error';
    /** 1H average true range as a % of price, when known. */
    atrPct: number | null;
}

// The step the trader builds a hedge in, plus a couple of bigger ones.
const ADD_STEPS = [100, 200, 500];

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

const usd = (n: number) =>
    `$${Math.abs(n).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const usd0 = (n: number) =>
    `$${Math.round(Math.abs(n)).toLocaleString('en-US')}`;

const signedUsd = (n: number) => `${n >= 0 ? '+' : '−'}${usd(n)}`;

const signedPct = (n: number) =>
    `${n >= 0 ? '+' : '−'}${Math.abs(n).toFixed(1)}%`;

const pnlColor = (n: number) => (n >= 0 ? 'text-emerald-500' : 'text-red-500');

/**
 * What an add on the reducing side does, as short pieces for one line of the table: the
 * benefit (less net exposure, a smaller typical hour) next to its cost (where the
 * break-even ends up). Showing only the break-even would make every add look like a
 * step backwards, because with fewer coins riding the price a bounce repairs a loss
 * more slowly. All of it is valued at the zone's own price, the moment the add happens.
 */
function addFragments(add: AddResult): { text: string; cls: string }[] {
    if (add.fullyHedged) {
        return [
            {
                text: `completes the hedge — net exposure goes flat and the PnL locks at ${signedUsd(add.lockedPnl ?? 0)}`,
                cls: 'text-emerald-500',
            },
        ];
    }

    const direction = (n: number) => (n > 0 ? 'long' : 'short');
    const before = add.netNotionalBefore;
    const after = add.netNotionalAfter;

    const fragments = [
        {
            text: add.overHedged
                ? `net ${direction(before)} ${usd0(before)} → ${direction(after)} ${usd0(after)} (overshoots)`
                : `net ${direction(before)} ${usd0(before)} → ${usd0(after)}`,
            cls: add.overHedged ? 'text-amber-500' : 'text-foreground',
        },
    ];

    if (add.hourBeforeUsd !== null && add.hourAfterUsd !== null) {
        fragments.push({
            text: `typical hour ≈ ${usd(add.hourBeforeUsd)} → ${usd(add.hourAfterUsd)}`,
            cls: 'text-foreground',
        });
    }

    if (add.breakEven === null) {
        fragments.push({
            text: 'no break-even above zero',
            cls: 'text-muted-foreground',
        });
    } else {
        const cushion = ((add.breakEven - add.price) / add.price) * 100;

        fragments.push({
            text: `break-even $${fmtPrice(add.breakEven)} (${Math.abs(cushion).toFixed(1)}% ${cushion >= 0 ? 'above' : 'below'} the zone)`,
            cls: 'text-foreground',
        });
    }

    return fragments;
}

/**
 * The arithmetic of the position in one coin, from the open legs and the mark price:
 * net exposure, the combined PnL, where it breaks even, where equity would run out,
 * what adding to the reducing side would do at each plan zone, and what a one or two
 * hour move would do to equity. No forecast anywhere — it answers "if price goes
 * there, where do I stand?". Fees and funding are not included, and the equity figures
 * count this coin alone.
 */
export function HedgeMathCard({
    symbol,
    legs,
    totalEquity,
    extras,
    atrPct,
}: Props) {
    const [addUsd, setAddUsd] = useState(ADD_STEPS[0]);
    const exposure = exposureBySymbol(legsFromPositions(legs))[0];

    if (!exposure) {
        return null;
    }

    const coin = coinOf(symbol);
    const flat = exposure.state === 'fully_hedged';
    const side = reducingSide(exposure);
    const breakEven = breakEvenPrice(exposure);
    const equityZero = equityZeroPrice(exposure, totalEquity);
    const netQty = Math.abs(exposure.netQty);
    const pctFromMark = (price: number) =>
        ((price - exposure.mark) / exposure.mark) * 100;

    const zones: ZoneInput[] =
        typeof extras === 'object'
            ? extras.plan.zones.map((z) => ({
                  label: `${z.side === 'long' ? 'Long' : 'Short'} zone ${z.number}`,
                  side: z.side,
                  // The edge price has to reach for the zone to be entered.
                  price: z.side === 'short' ? z.low : z.high,
              }))
            : [];

    const rows = whatIfRows(exposure, zones, addUsd, atrPct);
    const moves = scenarios(exposure, totalEquity, atrPct);

    return (
        <div className="flex flex-col gap-2.5 rounded-md border border-border bg-background px-3 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <p className="flex cursor-default items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <Calculator className="size-3.5 text-sky-400" />
                            {coin} hedge math
                        </p>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        className="max-w-[280px] text-[11px]"
                    >
                        Straight arithmetic from your open legs and the mark
                        price — no forecast. Everything is anchored on the
                        exchange&apos;s own PnL figures, so it moves with them.
                        Funding and fees from here on are not included.
                    </TooltipContent>
                </Tooltip>
            </div>

            <p className="text-[11px] leading-snug text-muted-foreground">
                {describeHedge(exposure, atrPct)}
            </p>

            <div className="grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                <Stat
                    label="Net exposure"
                    value={
                        flat
                            ? 'Flat'
                            : `${exposure.netQty > 0 ? 'Long' : 'Short'} ${netQty >= 1 ? netQty.toFixed(2) : netQty.toPrecision(3)} ${coin}`
                    }
                    sub={flat ? 'legs cancel out' : usd0(exposure.netNotional)}
                    hint="Long coins minus short coins. This is the part of the position that still moves with price."
                />
                <Stat
                    label="Combined PnL"
                    value={signedUsd(exposure.combinedPnl)}
                    tone={pnlColor(exposure.combinedPnl)}
                    sub={
                        flat
                            ? 'frozen while fully hedged'
                            : 'both legs, as reported'
                    }
                    hint="The two legs' unrealized PnL added together."
                />
                <Stat
                    label="Break-even"
                    value={breakEven === null ? '—' : `$${fmtPrice(breakEven)}`}
                    sub={
                        breakEven === null
                            ? flat
                                ? 'none while fully hedged'
                                : 'none above zero'
                            : `${signedPct(pctFromMark(breakEven))} from here`
                    }
                    hint="The price at which the combined PnL of the legs is exactly zero, holding everything else fixed."
                />
                <Stat
                    label="Equity hits zero"
                    value={
                        equityZero === null ? '—' : `$${fmtPrice(equityZero)}`
                    }
                    sub={
                        equityZero !== null
                            ? `${signedPct(pctFromMark(equityZero))} from here`
                            : flat
                              ? 'none while fully hedged'
                              : !exposure.allCross
                                ? 'isolated margin'
                                : 'no equity'
                    }
                    tone={equityZero === null ? undefined : 'text-amber-500'}
                    hint="If this were your only exposure and every leg is cross-margined, your account equity would reach zero at this price. The exchange liquidates earlier (maintenance margin), and other coins draw on the same equity — treat it as an outer limit, not a safe distance."
                />
            </div>

            {!flat && side !== null && (
                <p className="text-[11px] text-muted-foreground">
                    Fully hedging this takes another{' '}
                    <span className="font-medium text-foreground">
                        {usd0(exposure.netNotional)}
                    </span>{' '}
                    on the {side} side
                    {exposure.state === 'hedged'
                        ? ` (about ${Math.max(1, Math.round(Math.abs(exposure.netNotional) / ADD_STEPS[0]))} steps of ${usd0(ADD_STEPS[0])})`
                        : ''}
                    .
                </p>
            )}

            {flat ? (
                <p className="text-[11px] text-muted-foreground">
                    The legs are the same size, so price no longer changes the
                    combined PnL — what moves it now is funding, fees, and
                    whichever leg you close first.
                </p>
            ) : (
                <>
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                If price gets to a plan zone
                            </span>
                            {side !== null && (
                                <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
                                    · and I add
                                    {ADD_STEPS.map((step) => (
                                        <button
                                            key={step}
                                            type="button"
                                            onClick={() => setAddUsd(step)}
                                            className={`rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors ${
                                                step === addUsd
                                                    ? 'border-sky-400 bg-sky-400/10 text-sky-400'
                                                    : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                                            }`}
                                        >
                                            ${step}
                                        </button>
                                    ))}
                                    of {side} there
                                </span>
                            )}
                        </div>

                        {extras === 'loading' && (
                            <p className="text-[11px] text-muted-foreground">
                                Loading the plan zones…
                            </p>
                        )}
                        {extras === 'error' && (
                            <p className="text-[11px] text-red-500">
                                Couldn&apos;t load the plan zones.
                            </p>
                        )}
                        {typeof extras === 'object' && rows.length === 0 && (
                            <p className="text-[11px] text-muted-foreground">
                                No plan zones near price right now.
                            </p>
                        )}

                        {rows.length > 0 && (
                            <div className="flex flex-col divide-y divide-border rounded-md border border-border">
                                {rows.map((row) => {
                                    const fragments = row.add
                                        ? addFragments(row.add)
                                        : null;

                                    return (
                                        <div
                                            key={`${row.side}-${row.label}`}
                                            className="flex flex-col gap-0.5 px-2.5 py-1.5 text-[11px] tabular-nums"
                                        >
                                            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-0.5">
                                                <span
                                                    className={`w-28 font-medium ${row.side === 'short' ? 'text-red-400' : 'text-emerald-400'}`}
                                                >
                                                    {row.label}
                                                </span>
                                                <span className="text-foreground">
                                                    ${fmtPrice(row.price)}{' '}
                                                    <span className="text-muted-foreground">
                                                        (
                                                        {signedPct(
                                                            pctFromMark(
                                                                row.price,
                                                            ),
                                                        )}
                                                        )
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground">
                                                    PnL there{' '}
                                                    <span
                                                        className={`font-medium ${pnlColor(row.pnlIfReached)}`}
                                                    >
                                                        {signedUsd(
                                                            row.pnlIfReached,
                                                        )}
                                                    </span>
                                                </span>
                                            </div>
                                            {fragments && (
                                                <div className="flex gap-x-4">
                                                    <span className="w-28 shrink-0 text-muted-foreground">
                                                        after the add:
                                                    </span>
                                                    <div className="flex flex-wrap items-baseline gap-x-4 gap-y-0.5">
                                                        {fragments.map((f) => (
                                                            <span
                                                                key={f.text}
                                                                className={
                                                                    f.cls
                                                                }
                                                            >
                                                                {f.text}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {moves.length > 0 && (
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                If only {coin} moves by hourly ranges (1 range ={' '}
                                {atrPct?.toFixed(2)}%)
                            </span>
                            <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                                {moves.map((m) => (
                                    <ScenarioCell
                                        key={m.atrMultiple}
                                        move={m}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function ScenarioCell({ move }: { move: ScenarioRow }) {
    return (
        <div
            className={`flex flex-col rounded border px-2 py-1 text-[11px] tabular-nums ${
                move.wipedOut
                    ? 'border-red-500/50 bg-red-500/10'
                    : 'border-border bg-muted/20'
            }`}
        >
            <span className="text-[9px] text-muted-foreground">
                {move.atrMultiple > 0 ? '+' : '−'}
                {Math.abs(move.atrMultiple)} range
                {Math.abs(move.atrMultiple) === 1 ? '' : 's'} · $
                {fmtPrice(move.price)}
            </span>
            {move.wipedOut ? (
                <span className="font-semibold text-red-500">
                    equity wiped out
                </span>
            ) : (
                <span className="text-foreground">
                    equity {usd(move.equityAfter)}{' '}
                    <span className={pnlColor(move.equityChangePct)}>
                        ({signedPct(move.equityChangePct)})
                    </span>
                </span>
            )}
        </div>
    );
}

function Stat({
    label,
    value,
    sub,
    hint,
    tone,
}: {
    label: string;
    value: string;
    sub?: string;
    hint: string;
    tone?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div className="flex min-w-0 cursor-default flex-col">
                    <span className="text-[10px] leading-none text-muted-foreground">
                        {label}
                    </span>
                    <span
                        className={`text-xs font-semibold tabular-nums ${tone ?? 'text-foreground'}`}
                    >
                        {value}
                    </span>
                    {sub && (
                        <span className="text-[10px] text-muted-foreground tabular-nums">
                            {sub}
                        </span>
                    )}
                </div>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-[260px] text-[11px]">
                {hint}
            </TooltipContent>
        </Tooltip>
    );
}
