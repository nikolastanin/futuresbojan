// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    MILESTONES,
    fmtSigned,
    fmtUsdt,
    totalsOf,
} from '../../resources/js/lib/totals.ts';

const leg = (positionType, positionValue, unrealizedPnl) => ({
    positionType,
    positionValue,
    unrealizedPnl,
});

describe('the totals at the top of the dashboard', () => {
    // The book in the trader's screenshot: four longs and five shorts.
    const positions = [
        leg(1, 1000, -55.05),
        leg(1, 800, -18.66),
        leg(1, 913.87, -5.95),
        leg(1, 500, -4.23),
        leg(2, 2000, -4.22),
        leg(2, 1000, 1.76),
        leg(2, 500, -3.44),
        leg(2, 300, 28.92),
        leg(2, 258.11, 0.86),
    ];
    const account = [
        { currency: 'USDT', equity: 189.89 },
        { currency: 'BTC', equity: 5 },
    ];

    it('adds up the long and the short side, and the difference between them', () => {
        const t = totalsOf(account, positions);

        assert.equal(t.longCount, 4);
        assert.equal(t.shortCount, 5);
        assert.equal(t.longValue, 3213.87);
        assert.equal(t.shortValue, 4058.11);
        assert.equal(Number(t.netValue.toFixed(2)), -844.24);
    });

    it('adds up the unrealized profit and loss of every leg', () => {
        // -55.05 - 18.66 - 5.95 - 4.23 - 4.22 + 1.76 - 3.44 + 28.92 + 0.86
        assert.equal(
            Number(totalsOf(account, positions).unrealized.toFixed(2)),
            -60.01,
        );
    });

    it('takes the equity from the USDT row only', () => {
        assert.equal(totalsOf(account, positions).equity, 189.89);
        assert.equal(totalsOf([{ currency: 'BTC', equity: 5 }], []).equity, 0);
        assert.equal(totalsOf([], []).equity, 0);
    });

    it('counts a missing value as nothing, and an empty book as zeros', () => {
        const t = totalsOf(account, [
            leg(1, undefined, null),
            leg(2, null, undefined),
        ]);

        assert.deepEqual(
            [t.longValue, t.shortValue, t.netValue, t.unrealized],
            [0, 0, 0, 0],
        );
        assert.deepEqual([t.longCount, t.shortCount], [1, 1]);
        assert.equal(totalsOf(account, []).unrealized, 0);
    });

    it('ignores a leg that is neither long nor short', () => {
        const t = totalsOf(account, [leg(3, 500, 2)]);

        assert.deepEqual([t.longCount, t.shortCount, t.netValue], [0, 0, 0]);
        // but its profit still counts, as it did on the cards
        assert.equal(t.unrealized, 2);
    });
});

describe('the equity milestones', () => {
    const at = (equity) => totalsOf([{ currency: 'USDT', equity }], []);

    it('points at the first milestone above the equity', () => {
        assert.deepEqual(MILESTONES, [250, 500, 1000, 1500]);
        assert.equal(at(189.89).nextMilestone, 250);
        assert.equal(at(250).nextMilestone, 500);
        assert.equal(at(999.99).nextMilestone, 1000);
    });

    it('measures the progress against the last milestone, and stops at a hundred percent', () => {
        assert.equal(at(0).milestoneProgress, 0);
        assert.equal(Number(at(189.89).milestoneProgress.toFixed(1)), 12.7);
        assert.equal(at(750).milestoneProgress, 50);
        assert.equal(at(1500).milestoneProgress, 100);
        assert.equal(at(4000).milestoneProgress, 100);
    });

    it('says when they are all reached, and then keeps pointing at the last one', () => {
        assert.equal(at(1499.99).allMilestonesReached, false);
        assert.equal(at(1500).allMilestonesReached, true);
        assert.equal(at(1800).nextMilestone, 1500);
    });
});

describe('the figures as text', () => {
    it('shows two decimals and thousands separators', () => {
        assert.equal(fmtUsdt(189.89), '189.89');
        assert.equal(fmtUsdt(1234.5), '1,234.50');
        assert.equal(fmtUsdt(-844.236), '-844.24');
        assert.equal(fmtUsdt(0), '0.00');
    });

    it('puts a plus on a gain and leaves the minus on a loss', () => {
        assert.equal(fmtSigned(28.92), '+28.92');
        assert.equal(fmtSigned(-88.99), '-88.99');
        assert.equal(fmtSigned(0), '0.00');
    });
});
