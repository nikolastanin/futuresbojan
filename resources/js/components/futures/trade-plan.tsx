import {
    Bell,
    BellRing,
    ChevronDown,
    ChevronUp,
    Check,
    CircleDashed,
    HelpCircle,
    Map as MapIcon,
} from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type {
    AnalysisExtras,
    PlanConfirmation,
    PlanZone,
} from '@/hooks/use-analysis-extras';
import {
    addAlert,
    findActiveAlert,
    removeAlert,
    requestAlertNotifications,
    usePriceAlerts,
} from '@/lib/price-alerts';
import { coinLabel } from '@/types/futures';

interface Props {
    symbol: string;
    extras: AnalysisExtras | 'loading' | 'error';
    /** Live price (the plan's own price can be up to a minute old). */
    current: number | null;
    hedged: boolean;
    /** Leverage of a position held in this coin, if any; the stop is weighed against it. */
    leverage: number | null;
}

// The order form's default, and what this account trades at — used only to weigh a
// stop against liquidation when no position in this coin tells us the real figure.
const DEFAULT_LEVERAGE = 100;

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

const STRENGTH_STYLE: Record<PlanZone['strength'], { label: string; cls: string }> = {
    strong: { label: 'STRONG', cls: 'border-amber-400/60 bg-amber-400/10 text-amber-400' },
    solid: { label: 'SOLID', cls: 'border-sky-400/50 bg-sky-400/10 text-sky-400' },
    weak: { label: 'WEAK', cls: 'border-border bg-muted/30 text-muted-foreground' },
};

const STATUS_LABEL: Record<PlanZone['status'], { label: string; cls: string }> = {
    in_zone: { label: 'Price is in the zone', cls: 'text-emerald-500' },
    near: { label: 'Within 1 hourly ATR', cls: 'text-amber-500' },
    far: { label: 'Not close yet', cls: 'text-muted-foreground' },
};

/** The price that has to be reached for price to enter the zone: its near edge. */
const entryEdge = (zone: PlanZone) => (zone.side === 'short' ? zone.low : zone.high);

/**
 * A two-sided map of zones to watch, computed on the server from the same levels the
 * ladder shows: levels stacked within 0.2% form a zone, rated by how many sources
 * agree. Each zone lists what has to confirm, where the idea is invalidated, an
 * illustrative stop (a full hourly ATR beyond it), the next zones as targets, and the
 * risk/reward. Zones to watch, not entry signals — nothing here places an order.
 */
export function TradePlan({ symbol, extras, current, hedged, leverage }: Props) {
    const alerts = usePriceAlerts();
    const [collapsed, setCollapsed] = useState(false);

    if (extras === 'loading') {
        return (
            <p className="text-[11px] text-muted-foreground">
                Building the trade plan…
            </p>
        );
    }

    if (extras === 'error') {
        return (
            <p className="text-[11px] text-red-500">
                Couldn&apos;t build the trade plan.
            </p>
        );
    }

    const { plan } = extras;
    const price = current ?? plan.price;
    const shorts = plan.zones
        .filter((z) => z.side === 'short')
        .sort((a, b) => b.low - a.low);
    const longs = plan.zones
        .filter((z) => z.side === 'long')
        .sort((a, b) => b.high - a.high);

    const alertFor = (zone: PlanZone) =>
        findActiveAlert(alerts, symbol, entryEdge(zone));

    const toggleZoneAlert = (zone: PlanZone) => {
        const existing = alertFor(zone);

        if (existing) {
            removeAlert(existing.id);

            return;
        }

        requestAlertNotifications();
        addAlert({
            symbol,
            price: entryEdge(zone),
            direction: entryEdge(zone) > price ? 'above' : 'below',
            label: `Plan: ${zone.side.toUpperCase()} zone ${zone.number} entry`,
        });
    };

    const alertAll = () => {
        requestAlertNotifications();

        for (const zone of plan.zones) {
            if (!alertFor(zone)) {
                addAlert({
                    symbol,
                    price: entryEdge(zone),
                    direction: entryEdge(zone) > price ? 'above' : 'below',
                    label: `Plan: ${zone.side.toUpperCase()} zone ${zone.number} entry`,
                });
            }
        }
    };

    const allAlerted =
        plan.zones.length > 0 && plan.zones.every((z) => alertFor(z));

    return (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-background p-3">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    <MapIcon className="size-3.5 text-violet-400" />
                    Trade plan · {coinLabel(symbol)}
                </p>
                <span className="text-[10px] text-muted-foreground">
                    1H ATR{' '}
                    {plan.atr_pct !== null ? `${plan.atr_pct}%` : 'n/a'} ·
                    SuperTrend 15M{' '}
                    <span
                        className={
                            plan.supertrend_15m === 'bullish'
                                ? 'text-emerald-500'
                                : plan.supertrend_15m === 'bearish'
                                  ? 'text-red-500'
                                  : ''
                        }
                    >
                        {plan.supertrend_15m ?? 'n/a'}
                    </span>
                </span>

                <div className="ml-auto flex items-center gap-3">
                    {plan.zones.length > 0 && !collapsed && (
                        <button
                            type="button"
                            onClick={alertAll}
                            disabled={allAlerted}
                            className="flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:opacity-50"
                        >
                            <Bell className="size-3" />
                            {allAlerted ? 'All zones alerted' : 'Alert all zones'}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={() => setCollapsed((v) => !v)}
                        className="flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
                    >
                        {collapsed ? 'Show' : 'Hide'}
                        {collapsed ? (
                            <ChevronDown className="size-3" />
                        ) : (
                            <ChevronUp className="size-3" />
                        )}
                    </button>
                </div>
            </div>

            {!collapsed && (
                <>
                    {plan.zones.length === 0 ? (
                        <p className="text-[11px] text-muted-foreground">
                            No level stacks near current price to build a plan
                            from.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {hedged && (
                                <p className="text-[10px] text-muted-foreground">
                                    Hedge view: short zones are candidate areas
                                    to add to the short; long zones are
                                    candidate areas to trim it.
                                </p>
                            )}

                            {shorts.map((zone) => (
                                <ZoneCard
                                    key={zone.id}
                                    zone={zone}
                                    leverage={leverage ?? DEFAULT_LEVERAGE}
                                    leverageKnown={leverage !== null}
                                    alerted={alertFor(zone) !== undefined}
                                    onToggleAlert={() => toggleZoneAlert(zone)}
                                />
                            ))}

                            <div className="flex items-center justify-between rounded bg-foreground/10 px-2 py-1 text-[11px] font-bold text-foreground">
                                <span>NOW</span>
                                <span className="tabular-nums">
                                    ${fmtPrice(price)}
                                </span>
                            </div>

                            {longs.map((zone) => (
                                <ZoneCard
                                    key={zone.id}
                                    zone={zone}
                                    leverage={leverage ?? DEFAULT_LEVERAGE}
                                    leverageKnown={leverage !== null}
                                    alerted={alertFor(zone) !== undefined}
                                    onToggleAlert={() => toggleZoneAlert(zone)}
                                />
                            ))}
                        </div>
                    )}

                    <p className="text-[9px] text-muted-foreground">
                        Zones to watch, not entry signals — computed from the
                        levels above, so they can&apos;t include a price the
                        data doesn&apos;t have. Stops are illustrative.
                    </p>
                </>
            )}
        </div>
    );
}

function ConfirmationChip({ c }: { c: PlanConfirmation }) {
    const meta =
        c.state === 'confirmed'
            ? { icon: <Check className="size-3" />, cls: 'text-emerald-500' }
            : c.state === 'waiting'
              ? {
                    icon: <CircleDashed className="size-3" />,
                    cls: 'text-amber-500',
                }
              : {
                    icon: <HelpCircle className="size-3" />,
                    cls: 'text-muted-foreground',
                };

    return (
        <span
            className={`flex items-center gap-1 text-[10px] ${meta.cls}`}
            title={`${c.name}: ${c.detail}`}
        >
            {meta.icon}
            {c.name}
            <span className="text-muted-foreground">
                ({c.state === 'waiting' ? `waiting — ${c.detail}` : c.state})
            </span>
        </span>
    );
}

function ZoneCard({
    zone,
    leverage,
    leverageKnown,
    alerted,
    onToggleAlert,
}: {
    zone: PlanZone;
    leverage: number;
    leverageKnown: boolean;
    alerted: boolean;
    onToggleAlert: () => void;
}) {
    const isShort = zone.side === 'short';
    const strength = STRENGTH_STYLE[zone.strength];
    const status = STATUS_LABEL[zone.status];
    const beyond = isShort ? 'above' : 'below';
    const range =
        zone.low === zone.high
            ? `$${fmtPrice(zone.low)}`
            : `$${fmtPrice(zone.low)} – $${fmtPrice(zone.high)}`;

    // Distance to liquidation is roughly 100 / leverage percent; a stop further away
    // than that never gets the chance to trigger.
    const liquidationPct = 100 / leverage;
    const stopBeyondLiquidation =
        zone.stop_distance_pct !== null &&
        zone.stop_distance_pct >= liquidationPct * 0.9;

    return (
        <div
            className={`flex flex-col gap-1.5 rounded-md border px-2.5 py-2 text-[11px] ${
                isShort
                    ? 'border-red-500/30 bg-red-500/[0.03]'
                    : 'border-emerald-500/30 bg-emerald-500/[0.03]'
            }`}
        >
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <span
                    className={`font-bold ${isShort ? 'text-red-500' : 'text-emerald-500'}`}
                >
                    {isShort ? 'SHORT' : 'LONG'} zone {zone.number}
                </span>
                <span
                    className={`rounded border px-1.5 py-px text-[9px] font-semibold ${strength.cls}`}
                >
                    {strength.label}
                </span>
                <span className="font-semibold text-foreground tabular-nums">
                    {range}
                </span>
                <span className="text-muted-foreground tabular-nums">
                    {zone.distance_pct === 0
                        ? ''
                        : `${zone.distance_pct}% ${isShort ? 'above' : 'below'}`}
                </span>
                <span className={status.cls}>{status.label}</span>

                <button
                    type="button"
                    onClick={onToggleAlert}
                    title={
                        alerted
                            ? 'Remove the alert for this zone'
                            : 'Alert me when price reaches this zone'
                    }
                    className={`ml-auto rounded p-0.5 transition-colors hover:bg-muted ${
                        alerted
                            ? 'text-amber-400'
                            : 'text-muted-foreground hover:text-foreground'
                    }`}
                >
                    {alerted ? (
                        <BellRing className="size-3.5" />
                    ) : (
                        <Bell className="size-3.5" />
                    )}
                </button>
            </div>

            <div className="flex flex-wrap items-center gap-1">
                {zone.sources.map((s) => (
                    <Tooltip key={s.label}>
                        <TooltipTrigger asChild>
                            <span className="cursor-default rounded border border-border bg-background px-1.5 py-px text-[10px] font-medium text-foreground">
                                {s.label}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" className="text-[11px]">
                            {s.label} ${fmtPrice(s.price)}
                        </TooltipContent>
                    </Tooltip>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span className="text-[10px] font-semibold text-muted-foreground">
                    Confirmed {zone.confirmed}/{zone.confirmations.length}
                </span>
                {zone.confirmations.map((c) => (
                    <ConfirmationChip key={c.name} c={c} />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-muted-foreground">
                <span>
                    Invalidated by a{' '}
                    <span className="font-medium text-foreground">
                        1H close {beyond} ${fmtPrice(zone.invalidation)}
                    </span>
                </span>
                <span>
                    Stop (illustrative){' '}
                    <span className="font-medium text-foreground tabular-nums">
                        ${fmtPrice(zone.stop)}
                    </span>
                    {zone.stop_distance_atr !== null &&
                        zone.stop_distance_pct !== null &&
                        ` · ${zone.stop_distance_atr}× ATR · ${zone.stop_distance_pct}% from entry`}
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-muted-foreground">
                {zone.targets.length > 0 ? (
                    zone.targets.map((t, i) => (
                        <span key={`${t.price}-${i}`}>
                            TP{i + 1}{' '}
                            <span className="font-medium text-foreground tabular-nums">
                                ${fmtPrice(t.price)}
                            </span>{' '}
                            ({t.label})
                        </span>
                    ))
                ) : (
                    <span>No further zone to use as a target</span>
                )}
                {zone.rr !== null && (
                    <span>
                        R:R to TP1{' '}
                        <span className="font-semibold text-foreground">
                            {zone.rr}
                        </span>
                    </span>
                )}
            </div>

            {stopBeyondLiquidation && (
                <p className="text-[10px] text-amber-500">
                    At {leverage}x{leverageKnown ? '' : ' (this account’s usual)'}{' '}
                    liquidation is roughly {liquidationPct.toFixed(2)}% away —
                    before this {zone.stop_distance_pct}% stop. Size or
                    leverage has to leave room for it.
                </p>
            )}

            {zone.warnings.map((w) => (
                <p key={w} className="text-[10px] text-amber-500">
                    {w}
                </p>
            ))}
        </div>
    );
}
