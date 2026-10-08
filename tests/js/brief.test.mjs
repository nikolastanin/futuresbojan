// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    MAX_AGE_MS,
    ageLabel,
    legsChanged,
    staleReason,
} from '../../resources/js/lib/brief.ts';

const long = (notional) => ({ positionType: 1, notional });
const short = (notional) => ({ positionType: 2, notional });

const fresh = (overrides = {}) => ({
    ageMs: 60_000,
    priceThen: 300,
    priceNow: 300,
    atrPct: 1,
    legsThen: [long(2000), short(400)],
    legsNow: [long(2000), short(400)],
    ...overrides,
});

describe('legsChanged', () => {
    it('is false for the same legs, even with price drift in their notional', () => {
        assert.equal(legsChanged([long(2000)], [long(2000)]), false);
        // 1.5% either way is price moving, not the trader adding or reducing.
        assert.equal(
            legsChanged([long(2000), short(400)], [long(2030), short(394)]),
            false,
        );
    });

    it('is true when a leg opens or closes', () => {
        assert.equal(legsChanged([long(2000)], [long(2000), short(400)]), true);
        assert.equal(legsChanged([long(2000), short(400)], [long(2000)]), true);
    });

    it('is true when a leg is replaced by one on the other side', () => {
        assert.equal(legsChanged([long(2000)], [short(2000)]), true);
    });

    it('is true when a leg grows or shrinks by more than a tenth', () => {
        // An add of $100 on a $400 short is a quarter more.
        assert.equal(
            legsChanged([long(2000), short(400)], [long(2000), short(500)]),
            true,
        );
        assert.equal(legsChanged([long(2000)], [long(1700)]), true);
    });

    it('honours a custom tolerance', () => {
        assert.equal(legsChanged([long(2000)], [long(2100)], 0.1), false);
        assert.equal(legsChanged([long(2000)], [long(2100)], 0.04), true);
    });
});

describe('staleReason', () => {
    it('is null while nothing has changed', () => {
        assert.equal(staleReason(fresh()), null);
    });

    it('goes stale when the price has moved an hourly range', () => {
        // 1% hourly range: 3 on a price of 300 is exactly one range.
        assert.equal(staleReason(fresh({ priceNow: 303 })), 'price');
        assert.equal(staleReason(fresh({ priceNow: 297 })), 'price');
        assert.equal(staleReason(fresh({ priceNow: 302 })), null);
    });

    it('scales the price test to the coin’s own range', () => {
        // A 0.4% hourly range (BTC on a quiet day): a 0.5% move is already more than a range.
        assert.equal(
            staleReason(fresh({ atrPct: 0.4, priceNow: 301.5 })),
            'price',
        );
        // A 2% range tolerates the same move.
        assert.equal(staleReason(fresh({ atrPct: 2, priceNow: 301.5 })), null);
    });

    it('uses a one percent range when the coin’s is not known', () => {
        assert.equal(
            staleReason(fresh({ atrPct: null, priceNow: 303 })),
            'price',
        );
        assert.equal(staleReason(fresh({ atrPct: null, priceNow: 301 })), null);
    });

    it('goes stale with age', () => {
        assert.equal(staleReason(fresh({ ageMs: MAX_AGE_MS - 1 })), null);
        assert.equal(staleReason(fresh({ ageMs: MAX_AGE_MS })), 'age');
    });

    it('puts a change in the positions first, then price, then age', () => {
        const everything = fresh({
            ageMs: MAX_AGE_MS * 2,
            priceNow: 320,
            legsNow: [long(2000)],
        });

        assert.equal(staleReason(everything), 'positions');
        assert.equal(
            staleReason({ ...everything, legsNow: everything.legsThen }),
            'price',
        );
        assert.equal(
            staleReason({
                ...everything,
                legsNow: everything.legsThen,
                priceNow: 300,
            }),
            'age',
        );
    });
});

describe('ageLabel', () => {
    it('says it in minutes and hours', () => {
        assert.equal(ageLabel(20_000), 'just now');
        assert.equal(ageLabel(60_000), '1 min ago');
        assert.equal(ageLabel(12 * 60_000 + 40_000), '12 min ago');
        assert.equal(ageLabel(60 * 60_000), '1 h ago');
        assert.equal(ageLabel(150 * 60_000), '2 h ago');
    });

    it('never shows a negative age', () => {
        assert.equal(ageLabel(-5_000), 'just now');
    });
});
