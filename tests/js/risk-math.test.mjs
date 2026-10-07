// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    addToReduce,
    breakEvenPrice,
    coinOf,
    describeHedge,
    describeRadar,
    equityDay,
    equityZeroPrice,
    exposureBySymbol,
    legsFromPositions,
    liquidationInfo,
    liquidationSeverity,
    pnlAt,
    reducingSide,
    riskRadar,
    scenarios,
    whatIfRows,
} from '../../resources/js/lib/risk-math.ts';

const near = (actual, expected, epsilon = 1e-6) =>
    assert.ok(
        Math.abs(actual - expected) <= epsilon,
        `expected ${actual} to be within ${epsilon} of ${expected}`,
    );

const position = (overrides = {}) => ({
    symbol: 'TAO_USDT',
    positionType: 1,
    positionValue: 1500,
    openAvgPrice: 305,
    fairPrice: 300,
    liquidatePrice: 0,
    leverage: 100,
    unrealizedPnl: -25,
    openType: 2,
    ...overrides,
});

const shortPosition = (overrides = {}) =>
    position({
        positionType: 2,
        positionValue: 600,
        openAvgPrice: 302,
        unrealizedPnl: 4,
        ...overrides,
    });

const exposureOf = (positions) =>
    exposureBySymbol(legsFromPositions(positions))[0];

// Mark 300: long 5 coins (PnL -25) and short 2 coins (PnL +4) => combined -21, net long 3 coins.
const hedged = () => exposureOf([position(), shortPosition()]);

describe('legsFromPositions', () => {
    it('derives coin quantity from notional and mark price', () => {
        const [long, short] = legsFromPositions([position(), shortPosition()]);

        assert.equal(long.side, 'long');
        near(long.qty, 5);
        assert.equal(short.side, 'short');
        near(short.qty, 2);
    });

    it('treats a zero liquidation price as unknown', () => {
        const [leg] = legsFromPositions([position({ liquidatePrice: 0 })]);
        assert.equal(leg.liquidationPrice, null);

        const [withPrice] = legsFromPositions([
            position({ liquidatePrice: 250 }),
        ]);
        assert.equal(withPrice.liquidationPrice, 250);
    });

    it('counts a missing or NaN figure as zero instead of poisoning the sums', () => {
        const [leg] = legsFromPositions([
            position({ unrealizedPnl: undefined, openAvgPrice: Number.NaN }),
        ]);

        assert.equal(leg.pnl, 0);
        assert.equal(leg.entry, 0);
        near(exposureBySymbol([leg])[0].combinedPnl, 0);
    });

    it('maps the margin mode and drops empty legs', () => {
        const legs = legsFromPositions([
            position({ openType: 1 }),
            position({ symbol: 'ETH_USDT', positionValue: 0 }),
        ]);

        assert.equal(legs.length, 1);
        assert.equal(legs[0].marginMode, 'isolated');
    });
});

describe('exposureBySymbol', () => {
    it('combines the legs of one coin', () => {
        const e = hedged();

        near(e.longQty, 5);
        near(e.shortQty, 2);
        near(e.netQty, 3);
        near(e.netNotional, 900);
        near(e.longNotional, 1500);
        near(e.shortNotional, 600);
        near(e.combinedPnl, -21);
        assert.equal(e.state, 'hedged');
        assert.equal(e.allCross, true);
    });

    it('keeps separate coins apart', () => {
        const result = exposureBySymbol(
            legsFromPositions([position(), position({ symbol: 'ETH_USDT' })]),
        );

        assert.deepEqual(result.map((e) => e.symbol).sort(), [
            'ETH_USDT',
            'TAO_USDT',
        ]);
    });

    it('labels the hedge state', () => {
        assert.equal(exposureOf([position()]).state, 'long');
        assert.equal(exposureOf([shortPosition()]).state, 'short');
        assert.equal(hedged().state, 'hedged');
        assert.equal(
            exposureOf([position(), shortPosition({ positionValue: 1500 })])
                .state,
            'fully_hedged',
        );
    });

    it('counts legs within the tolerance as a full hedge', () => {
        // 0.3% apart is inside the 0.5% tolerance; 2% apart is not.
        assert.equal(
            exposureOf([position(), shortPosition({ positionValue: 1495.5 })])
                .state,
            'fully_hedged',
        );
        assert.equal(
            exposureOf([position(), shortPosition({ positionValue: 1470 })])
                .state,
            'hedged',
        );
    });

    it('is only all-cross when every leg is cross-margined', () => {
        assert.equal(
            exposureOf([position(), shortPosition({ openType: 1 })]).allCross,
            false,
        );
    });
});

describe('pnlAt and breakEvenPrice', () => {
    it('anchors on the current combined PnL', () => {
        const e = hedged();

        near(pnlAt(e, 300), -21);
        near(pnlAt(e, 310), 9);
        near(pnlAt(e, 290), -51);
    });

    it('finds the price where the combination breaks even', () => {
        const e = hedged();
        const be = breakEvenPrice(e);

        near(be, 307);
        near(pnlAt(e, be), 0);
    });

    it('works for a net short too', () => {
        const e = exposureOf([
            position({ positionValue: 300, unrealizedPnl: 0 }),
            shortPosition({ positionValue: 900, unrealizedPnl: 10 }),
        ]);

        near(e.netQty, -2);
        near(breakEvenPrice(e), 305);
        near(pnlAt(e, 305), 0);
    });

    it('freezes the PnL when fully hedged and has no break-even', () => {
        const e = exposureOf([
            position(),
            shortPosition({ positionValue: 1500, unrealizedPnl: 10 }),
        ]);

        assert.equal(breakEvenPrice(e), null);
        near(pnlAt(e, 250), pnlAt(e, 350));
    });

    it('returns null when the break-even would be below zero', () => {
        const e = exposureOf([
            position({ positionValue: 300, unrealizedPnl: 400 }),
        ]);

        assert.equal(breakEvenPrice(e), null);
    });
});

describe('equityZeroPrice', () => {
    it('is the price that would use up all the equity', () => {
        near(equityZeroPrice(hedged(), 40), 300 - 40 / 3);
    });

    it('points upward for a net short', () => {
        const e = exposureOf([
            position({ positionValue: 300, unrealizedPnl: 0 }),
            shortPosition({ positionValue: 900, unrealizedPnl: 10 }),
        ]);

        near(equityZeroPrice(e, 40), 320);
    });

    it('is unknown for isolated legs, a full hedge, or no equity', () => {
        assert.equal(
            equityZeroPrice(exposureOf([position({ openType: 1 })]), 40),
            null,
        );
        assert.equal(
            equityZeroPrice(
                exposureOf([
                    position(),
                    shortPosition({ positionValue: 1500 }),
                ]),
                40,
            ),
            null,
        );
        assert.equal(equityZeroPrice(hedged(), 0), null);
    });
});

describe('reducingSide', () => {
    it('is the opposite of the net exposure', () => {
        assert.equal(reducingSide(hedged()), 'short');
        assert.equal(
            reducingSide(
                exposureOf([
                    position({ positionValue: 300 }),
                    shortPosition({ positionValue: 900 }),
                ]),
            ),
            'long',
        );
    });

    it('is null when there is nothing to reduce', () => {
        assert.equal(
            reducingSide(
                exposureOf([
                    position(),
                    shortPosition({ positionValue: 1500 }),
                ]),
            ),
            null,
        );
    });
});

describe('addToReduce', () => {
    it('moves the break-even when adding to the reducing side', () => {
        // +$620 short at 310 is 2 more coins: short 4 vs long 5, net long 1.
        const result = addToReduce(hedged(), 310, 620);

        assert.equal(result.side, 'short');
        near(result.addedQty, 2);
        near(result.pnlAtPrice, 9);
        near(result.newNetQty, 1);
        near(result.newNetNotional, 310);
        near(result.breakEven, 301);
        assert.equal(result.fullyHedged, false);
        assert.equal(result.overHedged, false);
    });

    it('locks the PnL when the add completes the hedge', () => {
        // +$930 short at 310 is 3 coins: short 5 vs long 5.
        const result = addToReduce(hedged(), 310, 930);

        assert.equal(result.fullyHedged, true);
        assert.equal(result.breakEven, null);
        near(result.lockedPnl, 9);
    });

    it('flags an add that flips the net exposure', () => {
        const net = exposureOf([
            position({ positionValue: 300, unrealizedPnl: 0 }),
            shortPosition({ positionValue: 900, unrealizedPnl: 10 }),
        ]);
        // Net short 2 coins; adding long at 290 for $870 is 3 coins => net long 1.
        const result = addToReduce(net, 290, 870);

        assert.equal(result.side, 'long');
        assert.equal(result.overHedged, true);
        near(result.newNetQty, 1);
        near(pnlAt(net, 290), 30);
        near(result.breakEven, 260);
    });

    it('does nothing sensible for a fully hedged coin or bad input', () => {
        const flat = exposureOf([
            position(),
            shortPosition({ positionValue: 1500 }),
        ]);

        assert.equal(addToReduce(flat, 300, 100), null);
        assert.equal(addToReduce(hedged(), 0, 100), null);
        assert.equal(addToReduce(hedged(), 300, 0), null);
    });
});

describe('whatIfRows', () => {
    const zones = [
        { label: 'Short zone 1', side: 'short', price: 310 },
        { label: 'Long zone 1', side: 'long', price: 290 },
        { label: 'Short zone 2', side: 'short', price: 320 },
    ];

    it('lists the highest price first', () => {
        const rows = whatIfRows(hedged(), zones, 100);

        assert.deepEqual(
            rows.map((r) => r.price),
            [320, 310, 290],
        );
    });

    it('prices the add only for zones on the reducing side', () => {
        const rows = whatIfRows(hedged(), zones, 100);
        const byLabel = Object.fromEntries(rows.map((r) => [r.label, r]));

        assert.notEqual(byLabel['Short zone 1'].add, null);
        assert.notEqual(byLabel['Short zone 2'].add, null);
        assert.equal(byLabel['Long zone 1'].add, null);
        near(byLabel['Short zone 1'].pnlIfReached, 9);
        near(byLabel['Long zone 1'].pnlIfReached, -51);
    });
});

describe('scenarios', () => {
    it('applies hourly-range moves to equity', () => {
        const rows = scenarios(hedged(), 40, 1);
        const at = (multiple) => rows.find((r) => r.atrMultiple === multiple);

        assert.equal(rows.length, 4);
        near(at(1).price, 303);
        near(at(1).equityAfter, 49);
        near(at(1).equityChangePct, 22.5);
        near(at(-2).price, 294);
        near(at(-2).equityAfter, 22);
        near(at(-2).equityChangePct, -45);
    });

    it('marks a wipe-out', () => {
        const rows = scenarios(hedged(), 40, 5);

        assert.equal(rows.find((r) => r.atrMultiple === -2).wipedOut, true);
        assert.equal(rows.find((r) => r.atrMultiple === 1).wipedOut, false);
    });

    it('is empty without volatility or equity', () => {
        assert.deepEqual(scenarios(hedged(), 40, null), []);
        assert.deepEqual(scenarios(hedged(), 40, 0), []);
        assert.deepEqual(scenarios(hedged(), 0, 1), []);
    });
});

describe('liquidationInfo', () => {
    const legWith = (overrides) => ({
        ...legsFromPositions([position(overrides)])[0],
    });

    it('measures a long below the mark in % and hourly ranges', () => {
        const info = liquidationInfo(legWith({ liquidatePrice: 285 }), 1);

        near(info.distancePct, 5);
        near(info.distanceAtr, 5);
    });

    it('measures a short above the mark', () => {
        const info = liquidationInfo(
            legsFromPositions([shortPosition({ liquidatePrice: 306 })])[0],
            null,
        );

        near(info.distancePct, 2);
        assert.equal(info.distanceAtr, null);
    });

    it('is unknown without a price or when it is on the wrong side of the mark', () => {
        assert.equal(liquidationInfo(legWith({ liquidatePrice: 0 }), 1), null);
        assert.equal(
            liquidationInfo(legWith({ liquidatePrice: 320 }), 1),
            null,
        );
    });

    it('grades a distance in hourly ranges when known, in percent otherwise', () => {
        const severity = (liquidatePrice, atrPct) =>
            liquidationSeverity(
                liquidationInfo(legWith({ liquidatePrice }), atrPct),
            );

        // 1% hourly range: 1.67% away is 1.7 ranges (red), 3.33% is 3.3 (amber), 10% is 10 (fine).
        assert.equal(severity(295, 1), 'danger');
        assert.equal(severity(290, 1), 'watch');
        assert.equal(severity(270, 1), 'ok');

        // No volatility known: under 1.5% is red, under 3% is amber.
        assert.equal(severity(297, null), 'danger');
        assert.equal(severity(294, null), 'watch');
        assert.equal(severity(285, null), 'ok');
    });
});

describe('riskRadar', () => {
    const radar = (overrides = {}) => {
        const { positions, ...rest } = overrides;

        return riskRadar({
            equity: 300,
            legs: legsFromPositions(
                positions ?? [
                    position({ positionValue: 1400, liquidatePrice: 250 }),
                    shortPosition({ positionValue: 600, liquidatePrice: 400 }),
                ],
            ),
            atrPctBySymbol: { TAO_USDT: 1 },
            ...rest,
        });
    };

    it('is "none" with no positions', () => {
        const r = riskRadar({ equity: 100, legs: [], atrPctBySymbol: {} });

        assert.equal(r.status, 'none');
        assert.equal(describeRadar(r), 'No open positions.');
    });

    it('sizes the book against equity', () => {
        const r = radar();

        near(r.totalNotional, 2000);
        near(r.equityMultiple, 2000 / 300);
        near(r.netExposure, 800);
        near(r.hourlyRiskUsd, 8);
        near(r.hourlyRiskPct, (8 / 300) * 100);
        assert.equal(r.atrComplete, true);
        assert.equal(r.status, 'ok');
        assert.deepEqual(r.reasons, []);
    });

    it('finds the nearest liquidation across legs', () => {
        const r = radar();

        assert.equal(r.nearestLiq.side, 'long');
        near(r.nearestLiq.price, 250);
        near(r.nearestLiq.distancePct, (50 / 300) * 100);
    });

    it('goes amber when a liquidation price is within four hourly ranges', () => {
        const r = radar({
            positions: [position({ positionValue: 1400, liquidatePrice: 290 })],
            equity: 1000,
        });

        // 3.33% away with a 1% hourly range is 3.3 ranges.
        assert.equal(r.status, 'watch');
        assert.match(r.reasons.join(' '), /liquidation is 3\.3% away/);
    });

    it('goes red when a liquidation price is within two hourly ranges', () => {
        const r = radar({
            positions: [position({ positionValue: 1400, liquidatePrice: 295 })],
            equity: 1000,
        });

        assert.equal(r.status, 'danger');
    });

    it('goes amber, then red, on the hourly move relative to equity', () => {
        // Net exposure $800: a 2% hourly range is $16 = 16% of $100.
        const watch = radar({
            equity: 100,
            atrPctBySymbol: { TAO_USDT: 2 },
            positions: [position({ positionValue: 800 })],
        });
        assert.equal(watch.status, 'watch');

        // 4% is $32 = 32% of $100.
        const danger = radar({
            equity: 100,
            atrPctBySymbol: { TAO_USDT: 4 },
            positions: [position({ positionValue: 800 })],
        });
        assert.equal(danger.status, 'danger');
    });

    it('goes amber when the book is ten times equity', () => {
        const r = radar({ equity: 150 });

        assert.ok(r.equityMultiple >= 10);
        assert.equal(r.status, 'watch');
        assert.match(r.reasons.join(' '), /× your equity/);
    });

    it('falls back to a percentage threshold without volatility', () => {
        const near2 = radar({
            atrPctBySymbol: {},
            equity: 1000,
            positions: [position({ positionValue: 1400, liquidatePrice: 294 })],
        });
        assert.equal(near2.status, 'watch');
        assert.equal(near2.atrComplete, false);
        assert.equal(near2.hourlyRiskUsd, null);

        const near1 = radar({
            atrPctBySymbol: {},
            equity: 1000,
            positions: [position({ positionValue: 1400, liquidatePrice: 297 })],
        });
        assert.equal(near1.status, 'danger');
    });

    it('does not count a hedged pair as risk', () => {
        const r = radar({
            equity: 1000,
            positions: [
                position({ positionValue: 1500, liquidatePrice: 0 }),
                shortPosition({ positionValue: 1500, liquidatePrice: 0 }),
            ],
        });

        near(r.netExposure, 0);
        near(r.hourlyRiskUsd, 0);
        assert.equal(r.status, 'ok');
    });

    it('asks for a look, rather than sounding the alarm, when equity has not loaded', () => {
        const r = radar({ equity: 0 });

        assert.equal(r.status, 'watch');
        assert.equal(r.equityMultiple, null);
        assert.equal(r.hourlyRiskPct, null);
        assert.match(r.reasons.join(' '), /Equity is not available/);
    });

    it('describes the book in plain English', () => {
        const text = describeRadar(radar());

        assert.match(text, /6\.7× your equity/);
        assert.match(text, /Nearest liquidation: TAO long/);
    });
});

describe('describeHedge', () => {
    it('explains a partial hedge with its break-even', () => {
        const text = describeHedge(hedged(), 1);

        assert.match(text, /Net long 3\.00 TAO/);
        assert.match(text, /−\$21\.00/);
        assert.match(text, /breaks even at \$307\.00/);
        assert.match(text, /hourly ranges/);
    });

    it('says a full hedge freezes the PnL', () => {
        const flat = exposureOf([
            position(),
            shortPosition({ positionValue: 1500, unrealizedPnl: 10 }),
        ]);

        assert.match(describeHedge(flat), /Fully hedged/);
    });
});

describe('equityDay', () => {
    const recorded = { open: 100, high: 120, low: 90 };

    it('measures the day against the live equity', () => {
        const day = equityDay(recorded, 95);

        near(day.change, -5);
        near(day.changePct, -5);
        near(day.high, 120);
        near(day.low, 90);
        // 25 below the 120 high.
        near(day.drawdownPct, (25 / 120) * 100);
    });

    it('lets a live equity beyond the recorded range extend it', () => {
        const up = equityDay(recorded, 130);

        near(up.high, 130);
        near(up.drawdownPct, 0);

        const down = equityDay(recorded, 80);

        near(down.low, 80);
    });

    it('has no percentage change when the day opened at zero', () => {
        assert.equal(
            equityDay({ open: 0, high: 0, low: 0 }, 5).changePct,
            null,
        );
    });
});

describe('coinOf', () => {
    it('strips the quote currency', () => {
        assert.equal(coinOf('TAO_USDT'), 'TAO');
    });
});
