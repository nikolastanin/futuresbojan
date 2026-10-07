/**
 * Position-risk and hedge maths for a manually managed long + short pair.
 *
 * Pure functions with no imports, so they can be unit-tested straight from Node
 * (`npm run test:js`) and reused anywhere. Everything is derived from what the
 * exchange already reports for each leg (size, mark price, unrealized PnL,
 * liquidation price) plus the account's equity — nothing here predicts price.
 *
 * Core identity: with a long of `longQty` coins and a short of `shortQty` coins, the
 * combined PnL moves by `netQty * (price change)` where `netQty = longQty - shortQty`.
 * So from today's combined PnL and the current mark price:
 *
 *     combinedPnl(price) = combinedPnl(mark) + netQty * (price - mark)
 *
 * Using the exchange's own current PnL as the anchor keeps these numbers consistent
 * with what the dashboard already shows (fees and all) instead of recomputing PnL
 * from entry prices. When the legs are equal in size, `netQty` is ~0 and the combined
 * PnL is frozen, whatever price does.
 */

/** The fields of an exchange position this module needs (structurally satisfied by `Position`). */
export interface PositionLike {
    symbol: string;
    /** 1 = long, 2 = short. */
    positionType: 1 | 2;
    /** Notional in USDT at the mark price. */
    positionValue: number;
    openAvgPrice: number;
    /** Mark ("fair") price. */
    fairPrice: number;
    /** 0 when the exchange reports none. */
    liquidatePrice: number;
    leverage: number;
    unrealizedPnl: number;
    /** 1 = isolated, 2 = cross. */
    openType: 1 | 2;
}

export type Side = 'long' | 'short';

export interface Leg {
    symbol: string;
    side: Side;
    /** Size in coins, derived from notional / mark. */
    qty: number;
    notional: number;
    entry: number;
    mark: number;
    liquidationPrice: number | null;
    leverage: number;
    /** The exchange's unrealized PnL for this leg (net of its estimated fees). */
    pnl: number;
    marginMode: 'cross' | 'isolated';
}

/** Two leg sizes within this fraction of each other count as a full hedge. */
export const FULL_HEDGE_TOLERANCE = 0.005;

export const coinOf = (symbol: string): string => symbol.split('_')[0];

/** A figure the exchange didn't send (or sent as NaN) counts as zero rather than poisoning every sum. */
const finite = (n: number | null | undefined): number =>
    typeof n === 'number' && Number.isFinite(n) ? n : 0;

export function legsFromPositions(positions: PositionLike[]): Leg[] {
    return positions
        .map((p): Leg => {
            const mark = finite(p.fairPrice);
            const notional = finite(p.positionValue);
            const liquidationPrice = finite(p.liquidatePrice);

            return {
                symbol: p.symbol,
                side: p.positionType === 1 ? 'long' : 'short',
                qty: mark > 0 ? notional / mark : 0,
                notional,
                entry: finite(p.openAvgPrice),
                mark,
                liquidationPrice:
                    liquidationPrice > 0 ? liquidationPrice : null,
                leverage: finite(p.leverage),
                pnl: finite(p.unrealizedPnl),
                marginMode: p.openType === 1 ? 'isolated' : 'cross',
            };
        })
        .filter((leg) => leg.qty > 0);
}

// ─── One coin: exposure and hedge ────────────────────────────────────────────

export type HedgeState = 'long' | 'short' | 'hedged' | 'fully_hedged';

export interface Exposure {
    symbol: string;
    mark: number;
    longQty: number;
    shortQty: number;
    /** Positive = net long, negative = net short, in coins. */
    netQty: number;
    longNotional: number;
    shortNotional: number;
    /** Signed net exposure in USDT at the mark: positive = net long. */
    netNotional: number;
    /** How much of the larger leg the smaller one covers: 0 with one side only, 1 with equal legs. */
    hedgeRatio: number;
    /** Sum of the legs' unrealized PnL. */
    combinedPnl: number;
    state: HedgeState;
    /** Every leg is cross-margin (so one equity buffer backs the whole position). */
    allCross: boolean;
    legs: Leg[];
}

export function exposureBySymbol(legs: Leg[]): Exposure[] {
    const bySymbol = new Map<string, Leg[]>();

    for (const leg of legs) {
        bySymbol.set(leg.symbol, [...(bySymbol.get(leg.symbol) ?? []), leg]);
    }

    return [...bySymbol.entries()].map(([symbol, group]) => {
        const sum = (side: Side, field: 'qty' | 'notional') =>
            group
                .filter((l) => l.side === side)
                .reduce((total, l) => total + l[field], 0);

        const longQty = sum('long', 'qty');
        const shortQty = sum('short', 'qty');
        const netQty = longQty - shortQty;
        const mark = group.find((l) => l.mark > 0)?.mark ?? 0;
        const larger = Math.max(longQty, shortQty);

        const state: HedgeState =
            longQty > 0 && shortQty > 0
                ? Math.abs(netQty) <=
                  FULL_HEDGE_TOLERANCE * Math.max(longQty, shortQty)
                    ? 'fully_hedged'
                    : 'hedged'
                : longQty > 0
                  ? 'long'
                  : 'short';

        return {
            symbol,
            mark,
            longQty,
            shortQty,
            netQty,
            longNotional: sum('long', 'notional'),
            shortNotional: sum('short', 'notional'),
            netNotional: netQty * mark,
            hedgeRatio: larger > 0 ? Math.min(longQty, shortQty) / larger : 0,
            combinedPnl: group.reduce((total, l) => total + l.pnl, 0),
            state,
            allCross: group.every((l) => l.marginMode === 'cross'),
            legs: group,
        };
    });
}

/** The combined PnL if price were `price` (anchored on today's PnL at the mark). */
export function pnlAt(e: Exposure, price: number): number {
    return e.combinedPnl + e.netQty * (price - e.mark);
}

/** The price at which the combined PnL is zero, or null when there isn't one (fully hedged, or it would be below zero). */
export function breakEvenPrice(e: Exposure): number | null {
    if (e.state === 'fully_hedged' || e.netQty === 0) {
        return null;
    }

    const price = e.mark - e.combinedPnl / e.netQty;

    return Number.isFinite(price) && price > 0 ? price : null;
}

/**
 * The price at which account equity would reach zero if this coin were the only
 * exposure and every leg is cross-margined — a rough ceiling on how far price can
 * go, since the exchange liquidates earlier (maintenance margin) and other coins
 * share the same equity. Null for isolated legs, a full hedge, or no equity.
 */
export function equityZeroPrice(e: Exposure, equity: number): number | null {
    if (
        !e.allCross ||
        e.state === 'fully_hedged' ||
        e.netQty === 0 ||
        equity <= 0
    ) {
        return null;
    }

    const price = e.mark - equity / e.netQty;

    return Number.isFinite(price) && price > 0 ? price : null;
}

/** Adding to this side reduces the net exposure: short when net long, long when net short. Null when already flat. */
export function reducingSide(e: Exposure): Side | null {
    if (e.state === 'fully_hedged' || e.netQty === 0) {
        return null;
    }

    return e.netQty > 0 ? 'short' : 'long';
}

/**
 * What a typical hour (the 1H average true range) moves a net exposure of `netQty`
 * coins at `mark`, in USDT. Null when the coin's volatility isn't known.
 */
export function typicalHourUsd(
    netQty: number,
    mark: number,
    atrPct: number | null | undefined,
): number | null {
    return atrPct && atrPct > 0
        ? (Math.abs(netQty) * mark * atrPct) / 100
        : null;
}

export interface AddResult {
    /** The side being added. */
    side: Side;
    price: number;
    addedQty: number;
    /** Combined PnL if price gets to `price`, before the add (a fill at `price` adds no PnL of its own). */
    pnlAtPrice: number;
    newNetQty: number;
    /**
     * The net exposure in USDT just before and just after the add, both valued at
     * `price` (the moment the add happens, like the PnL and break-even beside them) and
     * signed like Exposure.netNotional, so the two differ by exactly the amount added.
     */
    netNotionalBefore: number;
    netNotionalAfter: number;
    /** A typical hour's move on that net exposure before and after; null when volatility is unknown. */
    hourBeforeUsd: number | null;
    hourAfterUsd: number | null;
    /** Where the combined PnL breaks even after the add; null when it ends fully hedged or has no valid price. */
    breakEven: number | null;
    /** The add brings the legs to equal size. */
    fullyHedged: boolean;
    /** The add overshoots, flipping the net exposure to the other side. */
    overHedged: boolean;
    /** The PnL the combination freezes at, when fully hedged. */
    lockedPnl: number | null;
}

/**
 * What adding `notional` USDT to the side that reduces the net exposure, filled at
 * `price`, would do. Ignores fees and the slippage of the fill. `atrPct` (1H ATR as a
 * % of price) is only needed for the typical-hour figures.
 */
export function addToReduce(
    e: Exposure,
    price: number,
    notional: number,
    atrPct?: number | null,
): AddResult | null {
    const side = reducingSide(e);

    if (side === null || price <= 0 || notional <= 0) {
        return null;
    }

    const addedQty = notional / price;
    const newLong = e.longQty + (side === 'long' ? addedQty : 0);
    const newShort = e.shortQty + (side === 'short' ? addedQty : 0);
    const newNet = newLong - newShort;
    const pnlAtPrice = pnlAt(e, price);

    const fullyHedged =
        Math.abs(newNet) <= FULL_HEDGE_TOLERANCE * Math.max(newLong, newShort);

    const rawBreakEven = fullyHedged ? null : price - pnlAtPrice / newNet;
    const breakEven =
        rawBreakEven !== null &&
        Number.isFinite(rawBreakEven) &&
        rawBreakEven > 0
            ? rawBreakEven
            : null;

    return {
        side,
        price,
        addedQty,
        pnlAtPrice,
        newNetQty: newNet,
        netNotionalBefore: e.netQty * price,
        netNotionalAfter: newNet * price,
        hourBeforeUsd: typicalHourUsd(e.netQty, price, atrPct),
        hourAfterUsd: typicalHourUsd(newNet, price, atrPct),
        breakEven,
        fullyHedged,
        overHedged: !fullyHedged && Math.sign(newNet) !== Math.sign(e.netQty),
        lockedPnl: fullyHedged ? pnlAtPrice : null,
    };
}

export interface ZoneInput {
    label: string;
    side: Side;
    /** The price that has to be reached to enter the zone (its near edge). */
    price: number;
}

export interface WhatIfRow extends ZoneInput {
    /** Combined PnL if price reaches the zone — also what a full hedge completed there would freeze. */
    pnlIfReached: number;
    /** The effect of adding on the reducing side there; null for zones on the other side. */
    add: AddResult | null;
}

/** One row per zone, highest price first. */
export function whatIfRows(
    e: Exposure,
    zones: ZoneInput[],
    addNotional: number,
    atrPct?: number | null,
): WhatIfRow[] {
    const reduce = reducingSide(e);

    return [...zones]
        .sort((a, b) => b.price - a.price)
        .map((zone) => ({
            ...zone,
            pnlIfReached: pnlAt(e, zone.price),
            add:
                zone.side === reduce
                    ? addToReduce(e, zone.price, addNotional, atrPct)
                    : null,
        }));
}

export interface ScenarioRow {
    /** Move in units of the 1H ATR. */
    atrMultiple: number;
    price: number;
    combinedPnl: number;
    equityAfter: number;
    equityChangePct: number;
    wipedOut: boolean;
}

/** Equity after price moves of ±1 and ±2 hourly ranges (counting this coin's exposure only). */
export function scenarios(
    e: Exposure,
    equity: number,
    atrPct: number | null | undefined,
    multiples: number[] = [-2, -1, 1, 2],
): ScenarioRow[] {
    if (!atrPct || atrPct <= 0 || e.mark <= 0 || equity <= 0) {
        return [];
    }

    return multiples
        .map((atrMultiple) => {
            const price = e.mark * (1 + (atrMultiple * atrPct) / 100);
            const equityAfter = equity + e.netQty * (price - e.mark);

            return {
                atrMultiple,
                price,
                combinedPnl: pnlAt(e, price),
                equityAfter,
                equityChangePct: ((equityAfter - equity) / equity) * 100,
                wipedOut: equityAfter <= 0,
            };
        })
        .filter((row) => row.price > 0);
}

// ─── The whole account: risk radar ───────────────────────────────────────────

export interface LiqInfo {
    symbol: string;
    side: Side;
    price: number;
    distancePct: number;
    /** Distance in 1H ATRs, when the coin's volatility is known. */
    distanceAtr: number | null;
    marginMode: 'cross' | 'isolated';
}

/** How far the exchange's liquidation price is from the mark, in % and in hourly ranges. */
export function liquidationInfo(
    leg: Leg,
    atrPct: number | null | undefined,
): LiqInfo | null {
    if (leg.liquidationPrice === null || leg.mark <= 0) {
        return null;
    }

    const distancePct =
        leg.side === 'long'
            ? ((leg.mark - leg.liquidationPrice) / leg.mark) * 100
            : ((leg.liquidationPrice - leg.mark) / leg.mark) * 100;

    // A liquidation price on the wrong side of the mark is not a usable distance.
    if (!(distancePct > 0)) {
        return null;
    }

    return {
        symbol: leg.symbol,
        side: leg.side,
        price: leg.liquidationPrice,
        distancePct,
        distanceAtr: atrPct && atrPct > 0 ? distancePct / atrPct : null,
        marginMode: leg.marginMode,
    };
}

/**
 * When the radar turns amber or red. These are judgment calls, not market facts —
 * a prompt to look, not a prediction — and are exported so the UI can say so.
 *
 * Both measures are volatility-aware and about the *net* position. The gross size of
 * the book is deliberately not a trigger: a hedged pair can be many times equity and
 * carry little risk, and a scalper's normal book sits well above any round-number
 * multiple, which would leave the badge amber all the time.
 */
export const RISK_THRESHOLDS = {
    /** A typical hourly move on the net exposure, as % of equity. */
    hourlyRiskWatchPct: 15,
    hourlyRiskDangerPct: 30,
    /** Distance to the nearest liquidation price, in hourly ranges. */
    liqWatchAtr: 4,
    liqDangerAtr: 2,
    /** Used instead when the volatility isn't known: distance in %. */
    liqWatchPct: 3,
    liqDangerPct: 1.5,
} as const;

export type RadarStatus = 'none' | 'ok' | 'watch' | 'danger';

export type Severity = Exclude<RadarStatus, 'none'>;

/** How worrying one liquidation distance is: in hourly ranges when known, else in plain %. */
export function liquidationSeverity(liq: LiqInfo): Severity {
    const t = RISK_THRESHOLDS;

    if (liq.distanceAtr !== null) {
        return liq.distanceAtr < t.liqDangerAtr
            ? 'danger'
            : liq.distanceAtr < t.liqWatchAtr
              ? 'watch'
              : 'ok';
    }

    return liq.distancePct < t.liqDangerPct
        ? 'danger'
        : liq.distancePct < t.liqWatchPct
          ? 'watch'
          : 'ok';
}

export interface RadarCoin {
    symbol: string;
    longNotional: number;
    shortNotional: number;
    /** Signed: positive = net long. */
    netNotional: number;
    /** How much of the larger leg the smaller one covers (0 to 1). */
    hedgeRatio: number;
    atrPct: number | null;
    /** A typical hourly move on this coin's net exposure, in USDT. */
    hourlyRiskUsd: number | null;
    nearestLiq: LiqInfo | null;
    state: HedgeState;
}

export interface Radar {
    status: RadarStatus;
    reasons: string[];
    equity: number;
    totalNotional: number;
    equityMultiple: number | null;
    /** Sum of each coin's absolute net exposure (assumes the coins move against you together). */
    netExposure: number;
    netMultiple: number | null;
    hourlyRiskUsd: number | null;
    hourlyRiskPct: number | null;
    /** Every coin's volatility was known, so hourlyRiskUsd covers the whole net exposure. */
    atrComplete: boolean;
    nearestLiq: LiqInfo | null;
    coins: RadarCoin[];
}

export function riskRadar(opts: {
    equity: number;
    legs: Leg[];
    /** 1H ATR as % of price, by symbol. */
    atrPctBySymbol: Record<string, number | null | undefined>;
}): Radar {
    const { equity, legs, atrPctBySymbol } = opts;
    const exposures = exposureBySymbol(legs);

    const coins: RadarCoin[] = exposures.map((e) => {
        const raw = atrPctBySymbol[e.symbol];
        const atrPct = raw && raw > 0 ? raw : null;

        const liqs = e.legs
            .map((leg) => liquidationInfo(leg, atrPct))
            .filter((l): l is LiqInfo => l !== null);

        return {
            symbol: e.symbol,
            longNotional: e.longNotional,
            shortNotional: e.shortNotional,
            netNotional: e.netNotional,
            hedgeRatio: e.hedgeRatio,
            atrPct,
            hourlyRiskUsd: typicalHourUsd(e.netQty, e.mark, atrPct),
            nearestLiq: nearestLiquidation(liqs),
            state: e.state,
        };
    });

    const totalNotional = coins.reduce(
        (t, c) => t + c.longNotional + c.shortNotional,
        0,
    );
    const netExposure = coins.reduce((t, c) => t + Math.abs(c.netNotional), 0);
    const known = coins.filter((c) => c.hourlyRiskUsd !== null);
    const hourlyRiskUsd =
        known.length === 0
            ? null
            : known.reduce((t, c) => t + (c.hourlyRiskUsd ?? 0), 0);
    const nearestLiq = nearestLiquidation(
        coins.map((c) => c.nearestLiq).filter((l): l is LiqInfo => l !== null),
    );

    const radar: Radar = {
        status: coins.length === 0 ? 'none' : 'ok',
        reasons: [],
        equity,
        totalNotional,
        equityMultiple: equity > 0 ? totalNotional / equity : null,
        netExposure,
        netMultiple: equity > 0 ? netExposure / equity : null,
        hourlyRiskUsd,
        hourlyRiskPct:
            hourlyRiskUsd !== null && equity > 0
                ? (hourlyRiskUsd / equity) * 100
                : null,
        atrComplete: coins.length > 0 && known.length === coins.length,
        nearestLiq,
        coins,
    };

    return { ...radar, ...assess(radar) };
}

function nearestLiquidation(liqs: LiqInfo[]): LiqInfo | null {
    if (liqs.length === 0) {
        return null;
    }

    // Distances in hourly ranges are only comparable when every one has them.
    const byAtr = liqs.every((l) => l.distanceAtr !== null);

    return [...liqs].sort((a, b) =>
        byAtr
            ? (a.distanceAtr ?? 0) - (b.distanceAtr ?? 0)
            : a.distancePct - b.distancePct,
    )[0];
}

function assess(r: Radar): { status: RadarStatus; reasons: string[] } {
    if (r.coins.length === 0) {
        return { status: 'none', reasons: [] };
    }

    const t = RISK_THRESHOLDS;
    const danger: string[] = [];
    const watch: string[] = [];

    // Open positions with no equity almost always means the account figure hasn't
    // loaded, not a wiped-out account (that would have been liquidated), so this asks
    // for a look rather than sounding the alarm.
    if (r.equity <= 0) {
        watch.push(
            'Equity is not available, so the checks against equity are skipped.',
        );
    }

    if (r.hourlyRiskPct !== null) {
        const text = `A typical hourly move on the net exposure is ${r.hourlyRiskPct.toFixed(0)}% of equity.`;

        if (r.hourlyRiskPct >= t.hourlyRiskDangerPct) {
            danger.push(text);
        } else if (r.hourlyRiskPct >= t.hourlyRiskWatchPct) {
            watch.push(text);
        }
    }

    if (r.nearestLiq) {
        const l = r.nearestLiq;
        const severity = liquidationSeverity(l);
        const text = `${coinOf(l.symbol)} ${l.side} liquidation is ${l.distancePct.toFixed(1)}% away${l.distanceAtr !== null ? ` (${l.distanceAtr.toFixed(1)} hourly ranges)` : ''}.`;

        if (severity === 'danger') {
            danger.push(text);
        } else if (severity === 'watch') {
            watch.push(text);
        }
    }

    if (danger.length > 0) {
        return { status: 'danger', reasons: [...danger, ...watch] };
    }

    return { status: watch.length > 0 ? 'watch' : 'ok', reasons: watch };
}

// ─── The day so far ──────────────────────────────────────────────────────────

export interface EquityDay {
    open: number;
    /** Never below the live equity, so a fresh high shows immediately. */
    high: number;
    low: number;
    now: number;
    change: number;
    changePct: number | null;
    /** How far below the day's high equity is now, as % of that high. */
    drawdownPct: number;
}

/**
 * The day's equity range joined with the live equity. The recorded high and low can be
 * minutes old, so the live figure is folded in rather than trusting the last reading.
 */
export function equityDay(
    recorded: { open: number; high: number; low: number },
    now: number,
): EquityDay {
    const high = Math.max(recorded.high, now);

    return {
        open: recorded.open,
        high,
        low: Math.min(recorded.low, now),
        now,
        change: now - recorded.open,
        changePct:
            recorded.open > 0
                ? ((now - recorded.open) / recorded.open) * 100
                : null,
        drawdownPct: high > 0 ? ((high - now) / high) * 100 : 0,
    };
}

// ─── Plain-English summaries ─────────────────────────────────────────────────

const usd = (n: number): string =>
    `$${Math.abs(n).toLocaleString('en-US', {
        minimumFractionDigits: Math.abs(n) < 100 ? 2 : 0,
        maximumFractionDigits: Math.abs(n) < 100 ? 2 : 0,
    })}`;

const signedUsd = (n: number): string => `${n < 0 ? '−' : '+'}${usd(n)}`;

const price = (n: number): string =>
    `$${n >= 1 ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : n.toPrecision(4)}`;

export function describeRadar(r: Radar): string {
    if (r.status === 'none') {
        return 'No open positions.';
    }

    const parts = [
        r.equityMultiple !== null
            ? `Notional ${usd(r.totalNotional)} is ${r.equityMultiple.toFixed(1)}× your equity; net exposure ${usd(r.netExposure)} (${(r.netMultiple ?? 0).toFixed(1)}×).`
            : `Notional ${usd(r.totalNotional)}; net exposure ${usd(r.netExposure)}.`,
    ];

    if (r.hourlyRiskUsd !== null && r.hourlyRiskPct !== null) {
        parts.push(
            `A typical hourly move on the net exposure is about ${usd(r.hourlyRiskUsd)} — ${r.hourlyRiskPct.toFixed(0)}% of your equity${r.atrComplete ? '' : ' (some coins have no volatility read yet)'}.`,
        );
    }

    if (r.nearestLiq) {
        const l = r.nearestLiq;

        parts.push(
            `Nearest liquidation: ${coinOf(l.symbol)} ${l.side}, ${l.distancePct.toFixed(1)}% away${l.distanceAtr !== null ? ` (${l.distanceAtr.toFixed(1)} hourly ranges)` : ''}.`,
        );
    }

    return parts.join(' ');
}

/** One or two sentences on where a coin's hedge stands. */
export function describeHedge(e: Exposure, atrPct?: number | null): string {
    const coin = coinOf(e.symbol);

    if (e.state === 'fully_hedged') {
        return `Fully hedged: the combined PnL is frozen at ${signedUsd(e.combinedPnl)} whatever ${coin} does (apart from funding, fees and the margin buffer).`;
    }

    const side = e.netQty > 0 ? 'long' : 'short';
    const qty = Math.abs(e.netQty);
    const net = `Net ${side} ${qty >= 1 ? qty.toFixed(2) : qty.toPrecision(3)} ${coin} (${usd(e.netNotional)}).`;
    const be = breakEvenPrice(e);

    if (be === null) {
        return `${net} Combined PnL ${signedUsd(e.combinedPnl)}; there is no positive price at which it breaks even.`;
    }

    const distancePct = ((be - e.mark) / e.mark) * 100;
    const atrNote =
        atrPct && atrPct > 0
            ? `, about ${(Math.abs(distancePct) / atrPct).toFixed(1)} hourly ranges`
            : '';

    return `${net} Combined PnL ${signedUsd(e.combinedPnl)}; it breaks even at ${price(be)} (${distancePct >= 0 ? '+' : '−'}${Math.abs(distancePct).toFixed(1)}% from here${atrNote}).`;
}
