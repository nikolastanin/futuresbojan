import { Activity, ChevronDown, ChevronUp } from 'lucide-react';
import { useState } from 'react';
import { ReasonList } from '@/components/bot/reason-list';
import { AiRead } from '@/components/futures/ai-read';
import { CandlesCard } from '@/components/futures/candles-card';
import { HedgeBalanceGauge } from '@/components/futures/hedge-balance-gauge';
import { HedgeMathCard } from '@/components/futures/hedge-math-card';
import { LevelsLadder } from '@/components/futures/levels-ladder';
import { MtfGrid } from '@/components/futures/mtf-grid';
import { SearchableSelect } from '@/components/futures/searchable-select';
import { SignalBadgesExtra } from '@/components/futures/signal-badges-extra';
import { StrengthVsBtc } from '@/components/futures/strength-vs-btc';
import { TradePlan } from '@/components/futures/trade-plan';
import { useActiveSymbols } from '@/hooks/use-active-symbols';
import { useAnalysisExtras } from '@/hooks/use-analysis-extras';
import {
    momentumLabel,
    structureLabel,
    trendLabel,
    useSignalPreviews,
} from '@/hooks/use-signal-previews';
import { coinLabel } from '@/types/futures';
import type { Position } from '@/types/futures';

interface Props {
    positions: Position[];
    totalEquity: number;
    /** The coin deliberately picked in the order form (null until one is). */
    orderSymbol: string | null;
    /**
     * Whether the panel is on screen. It stays mounted when its tab is hidden (so an AI read
     * already paid for is not lost), but it stops polling until it is shown again.
     */
    active?: boolean;
}

const COLLAPSED_STORAGE_KEY = 'analysis-panel-collapsed';

// Browser storage can be missing or throw (private windows, blocked site data), so
// the minimised state is a convenience that must never break the panel.
function readCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSED_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeCollapsed(collapsed: boolean): void {
    try {
        localStorage.setItem(COLLAPSED_STORAGE_KEY, collapsed ? '1' : '0');
    } catch {
        // ignore — the toggle still works for this session
    }
}

const fmt = (n: number) =>
    n.toLocaleString('en-US', {
        minimumFractionDigits: n >= 1 ? 2 : 4,
        maximumFractionDigits: n >= 1 ? 2 : 6,
    });

/**
 * Everything the dashboard knows about one coin in a single full-width panel: the
 * indicator read, extra badges, reasons, price levels, and — when that coin has both
 * legs of a hedge open — the hedge gauge with its equity memory and on-click AI read.
 * Follows the coin picked in the order form; picking one here overrides that until
 * the order form's coin changes again. Can be minimised to a one-line summary.
 */
export function AnalysisPanel({
    positions,
    totalEquity,
    orderSymbol,
    active = true,
}: Props) {
    const availableSymbols = useActiveSymbols();
    const [override, setOverride] = useState<{
        symbol: string;
        forOrderSymbol: string | null;
    } | null>(null);
    const [showReasons, setShowReasons] = useState(false);
    const [collapsed, setCollapsed] = useState(readCollapsed);

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        writeCollapsed(next);
    };

    // Coins with an open position, hedged pairs first — the natural fallback when
    // nothing has been picked in the order form yet.
    const heldSymbols = [...new Set(positions.map((p) => p.symbol))];
    const isHedged = (symbol: string) =>
        positions.some((p) => p.symbol === symbol && p.positionType === 1) &&
        positions.some((p) => p.symbol === symbol && p.positionType === 2);
    const fallbackSymbol =
        heldSymbols.find(isHedged) ?? heldSymbols[0] ?? 'BTC_USDT';

    const selected =
        override && override.forOrderSymbol === orderSymbol
            ? override.symbol
            : (orderSymbol ?? fallbackSymbol);

    const select = (symbol: string) =>
        setOverride({ symbol, forOrderSymbol: orderSymbol });

    const signals = useSignalPreviews(active ? [selected] : []);
    const signal = signals[selected];
    const extras = useAnalysisExtras(selected, !collapsed && active);
    const hasSignal = signal && signal !== 'loading' && signal !== 'error';

    const longLeg = positions.find(
        (p) => p.symbol === selected && p.positionType === 1,
    );
    const shortLeg = positions.find(
        (p) => p.symbol === selected && p.positionType === 2,
    );
    const hedged = Boolean(longLeg && shortLeg);

    // For a coin that isn't a hedge pair, the AI read gets the coin snapshot plus the
    // one position the trader holds in it (if any), including its lock — the prompt
    // tells the model to comment on a locked position but never suggest touching it.
    const heldLeg = hedged ? undefined : (longLeg ?? shortLeg);
    const r2 = (n: number) => Math.round(n * 100) / 100;
    const coinAiPayload = hasSignal
        ? {
              symbol: selected,
              price: signal.current_price,
              signal,
              levels: signal.levels,
              extras:
                  typeof extras === 'object'
                      ? {
                            mtf: extras.mtf,
                            levels: extras.levels,
                            vs_btc: extras.vs_btc,
                            plan: extras.plan,
                            candles: extras.candles,
                        }
                      : null,
              position: heldLeg
                  ? {
                        direction: heldLeg.positionType === 1 ? 'LONG' : 'SHORT',
                        notional: r2(heldLeg.positionValue),
                        entry: heldLeg.openAvgPrice,
                        pnl: r2(heldLeg.unrealizedPnl),
                        leverage: heldLeg.leverage,
                        liquidation_price: heldLeg.liquidatePrice,
                        stop_loss: heldLeg.active_sl_tp?.stop_loss ?? null,
                        take_profit: heldLeg.active_sl_tp?.take_profit ?? null,
                        locked: heldLeg.locked,
                        locked_until: heldLeg.lockedUntil,
                    }
                  : null,
          }
        : null;

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-t-2 border-border border-t-violet-500 bg-card p-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <Activity className="size-3.5 text-violet-500" />
                    Analysis
                </p>
                <SearchableSelect
                    value={selected}
                    options={availableSymbols}
                    onChange={select}
                    className="w-40 shrink-0"
                />

                {heldSymbols.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="text-[10px] text-muted-foreground">
                            Open:
                        </span>
                        {heldSymbols.map((symbol) => (
                            <button
                                key={symbol}
                                type="button"
                                onClick={() => select(symbol)}
                                className={`rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors ${
                                    symbol === selected
                                        ? 'border-violet-400 bg-violet-400/10 text-violet-400'
                                        : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                                }`}
                            >
                                {coinLabel(symbol)}
                                {isHedged(symbol) ? ' ⇅' : ''}
                            </button>
                        ))}
                    </div>
                )}

                <button
                    type="button"
                    onClick={toggleCollapsed}
                    className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground"
                    aria-expanded={!collapsed}
                >
                    {collapsed ? 'Expand' : 'Minimise'}
                    {collapsed ? (
                        <ChevronDown className="size-3.5" />
                    ) : (
                        <ChevronUp className="size-3.5" />
                    )}
                </button>
            </div>

            {collapsed ? (
                <p className="text-xs text-muted-foreground">
                    {hasSignal ? (
                        <>
                            <span className="font-semibold text-foreground">
                                {coinLabel(selected)}
                            </span>{' '}
                            ${fmt(signal.current_price)} ·{' '}
                            <span
                                className={
                                    signal.direction === 'LONG'
                                        ? 'text-emerald-500'
                                        : signal.direction === 'SHORT'
                                          ? 'text-red-500'
                                          : ''
                                }
                            >
                                {signal.direction === null
                                    ? 'flat (0)'
                                    : `${signal.direction} (${signal.confidence})`}
                            </span>{' '}
                            · Trend{' '}
                            <span className={trendLabel(signal.trend).color}>
                                {trendLabel(signal.trend).label}
                            </span>{' '}
                            · Momentum{' '}
                            <span
                                className={momentumLabel(signal.momentum).color}
                            >
                                {momentumLabel(signal.momentum).label}
                            </span>
                            {hedged ? ' · hedged ⇅' : ''}
                        </>
                    ) : (
                        `${coinLabel(selected)} — expand to load the read.`
                    )}
                </p>
            ) : (
                <>
                    {signal === undefined || signal === 'loading' ? (
                        <p className="text-xs text-muted-foreground">
                            Loading {coinLabel(selected)}…
                        </p>
                    ) : signal === 'error' ? (
                        <p className="text-xs text-red-500">
                            Couldn&apos;t load the read for{' '}
                            {coinLabel(selected)}.
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-border bg-background px-3 py-2">
                                <Stat
                                    label="Price"
                                    value={`$${fmt(signal.current_price)}`}
                                />
                                <Stat
                                    label="Bot says"
                                    value={
                                        signal.direction === null
                                            ? 'flat (0)'
                                            : `${signal.direction} (${signal.confidence})`
                                    }
                                    className={
                                        signal.direction === 'LONG'
                                            ? 'text-emerald-500'
                                            : signal.direction === 'SHORT'
                                              ? 'text-red-500'
                                              : ''
                                    }
                                />
                                <Stat
                                    label="Trend"
                                    value={trendLabel(signal.trend).label}
                                    className={trendLabel(signal.trend).color}
                                />
                                <Stat
                                    label="Momentum"
                                    value={momentumLabel(signal.momentum).label}
                                    className={
                                        momentumLabel(signal.momentum).color
                                    }
                                />
                                {structureLabel(signal.structure) && (
                                    <Stat
                                        label="Structure"
                                        value={
                                            structureLabel(signal.structure)!
                                                .label
                                        }
                                        className={
                                            structureLabel(signal.structure)!
                                                .color
                                        }
                                    />
                                )}
                                {signal.volatility_pct !== null && (
                                    <Stat
                                        label="Volatility"
                                        value={`${signal.volatility_pct}%`}
                                    />
                                )}
                                {signal.change_24h_pct !== null && (
                                    <Stat
                                        label="24h"
                                        value={`${signal.change_24h_pct >= 0 ? '+' : ''}${signal.change_24h_pct}%`}
                                        className={
                                            signal.change_24h_pct >= 0
                                                ? 'text-emerald-500'
                                                : 'text-red-500'
                                        }
                                    />
                                )}
                                {signal.high_24h !== null &&
                                    signal.low_24h !== null && (
                                        <Stat
                                            label="24h range"
                                            value={`$${fmt(signal.low_24h)} – $${fmt(signal.high_24h)}`}
                                        />
                                    )}
                            </div>

                            <SignalBadgesExtra signal={signal} />
                            <StrengthVsBtc symbol={selected} extras={extras} />
                        </>
                    )}

                    <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
                        <div className="flex min-w-0 flex-col gap-3">
                            {longLeg && shortLeg ? (
                                <HedgeBalanceGauge
                                    key={selected}
                                    long={longLeg}
                                    short={shortLeg}
                                    signal={signal}
                                    totalEquity={totalEquity}
                                    extras={extras}
                                />
                            ) : (
                                <AiRead
                                    key={selected}
                                    payload={coinAiPayload}
                                    hint={
                                        heldLeg
                                            ? `Second opinion on ${coinLabel(selected)} and your ${heldLeg.positionType === 1 ? 'long' : 'short'} — not a forecast.`
                                            : `Second opinion on ${coinLabel(selected)} — not a forecast.`
                                    }
                                />
                            )}

                            {(longLeg || shortLeg) && (
                                <HedgeMathCard
                                    symbol={selected}
                                    legs={positions.filter(
                                        (p) => p.symbol === selected,
                                    )}
                                    totalEquity={totalEquity}
                                    extras={extras}
                                    atrPct={
                                        hasSignal ? signal.volatility_pct : null
                                    }
                                />
                            )}

                            <MtfGrid extras={extras} />

                            {hasSignal && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowReasons((v) => !v)
                                        }
                                        className="flex w-fit items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
                                    >
                                        Why?
                                        {showReasons ? (
                                            <ChevronUp className="size-3" />
                                        ) : (
                                            <ChevronDown className="size-3" />
                                        )}
                                    </button>

                                    {showReasons && (
                                        <ReasonList
                                            reasons={signal.reasons}
                                            className="rounded-md border border-border bg-background px-3 py-2 text-[11px] text-muted-foreground"
                                        />
                                    )}
                                </>
                            )}
                        </div>

                        {hasSignal && signal.levels && (
                            <div className="min-w-0">
                                <LevelsLadder
                                    symbol={selected}
                                    current={signal.current_price}
                                    levels={signal.levels}
                                    extra={
                                        typeof extras === 'object'
                                            ? extras.levels
                                            : null
                                    }
                                />
                            </div>
                        )}
                    </div>

                    <CandlesCard
                        key={selected}
                        symbol={selected}
                        extras={extras}
                        positions={positions.filter(
                            (p) => p.symbol === selected,
                        )}
                    />

                    <TradePlan
                        symbol={selected}
                        extras={extras}
                        current={hasSignal ? signal.current_price : null}
                        hedged={hedged}
                        leverage={(longLeg ?? shortLeg)?.leverage ?? null}
                    />
                </>
            )}
        </div>
    );
}

function Stat({
    label,
    value,
    className = '',
}: {
    label: string;
    value: string;
    className?: string;
}) {
    return (
        <div className="flex min-w-0 flex-col">
            <span className="text-[10px] leading-none text-muted-foreground">
                {label}
            </span>
            <span
                className={`text-xs font-medium text-foreground tabular-nums ${className}`}
            >
                {value}
            </span>
        </div>
    );
}
