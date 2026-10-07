import { ChevronDown, ChevronUp, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useEquityToday } from '@/hooks/use-equity-today';
import type { SignalPreviewMap } from '@/hooks/use-signal-previews';
import {
    RISK_THRESHOLDS,
    coinOf,
    describeRadar,
    equityDay,
    legsFromPositions,
    liquidationSeverity,
    riskRadar,
} from '@/lib/risk-math';
import type { Radar, RadarCoin, Severity } from '@/lib/risk-math';
import type { Position } from '@/types/futures';

interface Props {
    positions: Position[];
    totalEquity: number;
    /** Per-coin signal previews, for each coin's 1H volatility. */
    signals: SignalPreviewMap;
}

const COLLAPSED_STORAGE_KEY = 'risk-radar-collapsed';

// Browser storage can be missing or throw (private windows, blocked site data), so
// the minimised state is a convenience that must never break the card.
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

const STATUS: Record<
    Severity,
    { label: string; pill: string; bar: string; text: string }
> = {
    ok: {
        label: 'OK',
        pill: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-500',
        bar: 'border-t-emerald-500',
        text: 'text-emerald-500',
    },
    watch: {
        label: 'WATCH',
        pill: 'border-amber-500/40 bg-amber-500/10 text-amber-500',
        bar: 'border-t-amber-500',
        text: 'text-amber-500',
    },
    danger: {
        label: 'DANGER',
        pill: 'border-red-500/40 bg-red-500/10 text-red-500',
        bar: 'border-t-red-500',
        text: 'text-red-500',
    },
};

/** Text colour for a single figure that is only worth colouring when it's a concern. */
const SEVERITY_TEXT: Record<Severity, string | undefined> = {
    ok: undefined,
    watch: 'text-amber-500',
    danger: 'text-red-500',
};

/**
 * A coin's hedge state in words. "Hedged" alone would say nothing about an 18% hedge,
 * so a partial hedge states how much of the larger leg the smaller one covers.
 */
function stateLabel(coin: RadarCoin): { label: string; cls: string } {
    switch (coin.state) {
        case 'long':
            return { label: 'Long only', cls: 'text-muted-foreground' };
        case 'short':
            return { label: 'Short only', cls: 'text-muted-foreground' };
        case 'fully_hedged':
            return { label: 'Fully hedged', cls: 'text-emerald-500' };
        default:
            return {
                label: `${Math.round(coin.hedgeRatio * 100)}% hedged`,
                cls: 'text-sky-500',
            };
    }
}

const usd0 = (n: number) =>
    `$${Math.round(Math.abs(n)).toLocaleString('en-US')}`;

const usd2 = (n: number) =>
    `$${Math.abs(n).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const signedUsd2 = (n: number) => `${n >= 0 ? '+' : '−'}${usd2(n)}`;

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * The radar for what is open right now, from what the dashboard already has: each coin's
 * volatility comes from its signal read. A pure function (no fetching), so the card and
 * the dashboard's tab label can both ask for it and always agree.
 */
export function radarFor(
    positions: Position[],
    totalEquity: number,
    signals: SignalPreviewMap,
): Radar {
    const atrPctBySymbol: Record<string, number | null> = {};

    for (const symbol of new Set(positions.map((p) => p.symbol))) {
        const signal = signals[symbol];

        atrPctBySymbol[symbol] =
            signal && signal !== 'loading' && signal !== 'error'
                ? signal.volatility_pct
                : null;
    }

    return riskRadar({
        equity: totalEquity,
        legs: legsFromPositions(positions),
        atrPctBySymbol,
    });
}

/**
 * One glance at how much the whole account is carrying: the book against equity, what a
 * typical hour could do to the net exposure, and how far away the nearest liquidation
 * is — turned into OK / WATCH / DANGER with the reasons spelled out. Straight arithmetic
 * on what the exchange already reports plus each coin's 1H volatility; it measures
 * exposure, it doesn't predict price. The amber and red lines are judgment calls (listed
 * under the card), a prompt to look rather than a verdict.
 */
export function RiskRadar({ positions, totalEquity, signals }: Props) {
    const [collapsed, setCollapsed] = useState(readCollapsed);
    const equityToday = useEquityToday();

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        writeCollapsed(next);
    };

    const radar = radarFor(positions, totalEquity, signals);

    // Nothing open, nothing to watch.
    if (radar.status === 'none') {
        return null;
    }

    const status = STATUS[radar.status];
    const day = equityToday ? equityDay(equityToday, totalEquity) : null;
    const t = RISK_THRESHOLDS;

    const compact = [
        radar.equityMultiple !== null
            ? `book ${radar.equityMultiple.toFixed(1)}× equity`
            : null,
        radar.hourlyRiskUsd !== null && radar.hourlyRiskPct !== null
            ? `a typical hour moves ≈ ${usd2(radar.hourlyRiskUsd)} (${radar.hourlyRiskPct.toFixed(0)}% of equity)`
            : null,
        radar.nearestLiq
            ? `nearest liquidation ${coinOf(radar.nearestLiq.symbol)} ${radar.nearestLiq.side} ${radar.nearestLiq.distancePct.toFixed(1)}% away`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div
            className={`flex flex-col gap-3 rounded-xl border border-t-2 border-border bg-card p-4 ${status.bar}`}
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    <ShieldAlert className={`size-3.5 ${status.text}`} />
                    Risk radar
                </p>
                <span
                    className={`rounded border px-1.5 py-0.5 text-[10px] font-bold tracking-wide ${status.pill}`}
                >
                    {status.label}
                </span>

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
                <Tooltip>
                    <TooltipTrigger asChild>
                        <p className="w-fit cursor-default text-xs text-muted-foreground">
                            {compact ||
                                'Open positions, no liquidation price reported.'}
                        </p>
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        className="max-w-[320px] text-[11px]"
                    >
                        {describeRadar(radar)}
                    </TooltipContent>
                </Tooltip>
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-md border border-border bg-background px-3 py-2.5 sm:grid-cols-3 lg:grid-cols-5">
                        <Stat
                            label="Equity"
                            value={usd2(radar.equity)}
                            hint="Your account's total equity — the cushion everything else is measured against."
                        />
                        <Stat
                            label="Book size"
                            value={usd0(radar.totalNotional)}
                            sub={
                                radar.equityMultiple !== null
                                    ? `${radar.equityMultiple.toFixed(1)}× equity`
                                    : undefined
                            }
                            hint="Every open position's notional added up (longs and shorts both), as a multiple of equity. For context only — it doesn't change the status, because a hedged pair can be big and still carry little risk."
                        />
                        <Stat
                            label="Net exposure"
                            value={usd0(radar.netExposure)}
                            sub={
                                radar.netMultiple !== null
                                    ? `${radar.netMultiple.toFixed(1)}× equity`
                                    : undefined
                            }
                            hint="What is left once each coin's long and short cancel out — the part that actually moves your equity. Coins are added up as if they all moved against you together."
                        />
                        <Stat
                            label="Typical hour"
                            value={
                                radar.hourlyRiskUsd !== null
                                    ? `≈ ${usd2(radar.hourlyRiskUsd)}`
                                    : '—'
                            }
                            sub={
                                radar.hourlyRiskPct !== null
                                    ? `${radar.hourlyRiskPct.toFixed(0)}% of equity${radar.atrComplete ? '' : ' · partial'}`
                                    : 'waiting for volatility'
                            }
                            tone={
                                radar.hourlyRiskPct === null
                                    ? undefined
                                    : radar.hourlyRiskPct >=
                                        t.hourlyRiskDangerPct
                                      ? 'text-red-500'
                                      : radar.hourlyRiskPct >=
                                          t.hourlyRiskWatchPct
                                        ? 'text-amber-500'
                                        : undefined
                            }
                            hint="Net exposure × each coin's 1H average true range. A normal hour's swing, not a worst case — a bad hour can be two or three times that. Hedged pairs cancel out, so only the unhedged part counts."
                        />
                        <Stat
                            label="Nearest liquidation"
                            value={
                                radar.nearestLiq
                                    ? `${radar.nearestLiq.distancePct.toFixed(1)}% away`
                                    : '—'
                            }
                            sub={
                                radar.nearestLiq
                                    ? `${coinOf(radar.nearestLiq.symbol)} ${radar.nearestLiq.side}${radar.nearestLiq.distanceAtr !== null ? ` · ${radar.nearestLiq.distanceAtr.toFixed(1)} hourly ranges` : ''}`
                                    : 'none reported'
                            }
                            tone={
                                radar.nearestLiq
                                    ? SEVERITY_TEXT[
                                          liquidationSeverity(radar.nearestLiq)
                                      ]
                                    : undefined
                            }
                            hint="The exchange's own liquidation price for the closest leg, as a distance from the mark price — and in units of that coin's typical hourly range, which is the better yardstick for how likely a normal hour is to reach it."
                        />
                    </div>

                    {radar.reasons.length > 0 && (
                        <ul
                            className={`flex flex-col gap-0.5 text-[11px] ${status.text}`}
                        >
                            {radar.reasons.map((reason) => (
                                <li key={reason} className="flex gap-1.5">
                                    <span aria-hidden>•</span>
                                    {reason}
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="flex flex-col divide-y divide-border rounded-md border border-border bg-background">
                        {radar.coins.map((coin) => (
                            <CoinRow key={coin.symbol} coin={coin} />
                        ))}
                    </div>

                    {day && (
                        <p className="text-[11px] text-muted-foreground tabular-nums">
                            <span className="font-medium text-foreground">
                                Equity today
                            </span>{' '}
                            since the first reading at{' '}
                            {new Date(equityToday!.first_at).toLocaleTimeString(
                                [],
                                { hour: '2-digit', minute: '2-digit' },
                            )}
                            : {usd2(day.open)} →{' '}
                            <span className="text-foreground">
                                {usd2(day.now)}
                            </span>{' '}
                            <span
                                className={
                                    day.change >= 0
                                        ? 'font-semibold text-emerald-500'
                                        : 'font-semibold text-red-500'
                                }
                            >
                                {signedUsd2(day.change)}
                                {day.changePct !== null
                                    ? ` (${day.changePct >= 0 ? '+' : '−'}${Math.abs(day.changePct).toFixed(1)}%)`
                                    : ''}
                            </span>{' '}
                            · high {usd2(day.high)} · low {usd2(day.low)} ·{' '}
                            {day.drawdownPct < 0.05
                                ? 'at the high'
                                : `${day.drawdownPct.toFixed(1)}% below the high`}
                        </p>
                    )}

                    <p className="text-[10px] leading-snug text-muted-foreground">
                        Amber from: a typical hour ≥ {t.hourlyRiskWatchPct}% of
                        equity, or liquidation within {t.liqWatchAtr} hourly
                        ranges ({t.liqWatchPct}% when volatility is unknown).
                        Red from: a typical hour ≥ {t.hourlyRiskDangerPct}%, or
                        liquidation within {t.liqDangerAtr} ranges (
                        {t.liqDangerPct}%). Book size is shown for context only.
                        Judgment calls, not predictions — a prompt to look.
                    </p>
                </>
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
                        className={`text-sm font-semibold tabular-nums ${tone ?? 'text-foreground'}`}
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

function CoinRow({ coin }: { coin: RadarCoin }) {
    const state = stateLabel(coin);
    const liq = coin.nearestLiq;
    const net = Math.abs(coin.netNotional);

    return (
        <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1 px-3 py-2 text-[11px] tabular-nums">
            <span className="w-12 font-semibold text-foreground">
                {coinOf(coin.symbol)}
            </span>
            <span className={`w-20 font-medium ${state.cls}`}>
                {state.label}
            </span>
            <span className="text-muted-foreground">
                long{' '}
                <span className="text-foreground">
                    {usd0(coin.longNotional)}
                </span>{' '}
                · short{' '}
                <span className="text-foreground">
                    {usd0(coin.shortNotional)}
                </span>
            </span>
            <span className="text-muted-foreground">
                net{' '}
                <span className="text-foreground">
                    {coin.state === 'fully_hedged'
                        ? 'flat'
                        : `${coin.netNotional > 0 ? 'long' : 'short'} ${usd0(net)}`}
                </span>
            </span>
            <span className="text-muted-foreground">
                hour{' '}
                <span className="text-foreground">
                    {coin.hourlyRiskUsd === null
                        ? '—'
                        : `≈ ${usd2(coin.hourlyRiskUsd)}`}
                </span>
                {coin.atrPct !== null && ` (${coin.atrPct.toFixed(2)}%/h)`}
            </span>
            <span
                className={
                    liq
                        ? (SEVERITY_TEXT[liquidationSeverity(liq)] ??
                          'text-muted-foreground')
                        : 'text-muted-foreground'
                }
            >
                {liq
                    ? `${liq.side} liq $${fmtPrice(liq.price)} · ${liq.distancePct.toFixed(1)}% away${liq.distanceAtr !== null ? ` · ${liq.distanceAtr.toFixed(1)} ranges` : ''}${liq.marginMode === 'isolated' ? ' · isolated' : ''}`
                    : 'no liquidation price'}
            </span>
        </div>
    );
}
