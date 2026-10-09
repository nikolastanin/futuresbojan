// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    SCREENER_INDICATORS,
    candlesAgo,
    describeReading,
    describeUniverse,
    fmtPrice,
    fmtSigned,
    fmtTurnover,
    scanAge,
    volatilityText,
} from '../../resources/js/lib/screener.ts';

describe('figures', () => {
    it('always signs a move, so a loss cannot be mistaken for a gain', () => {
        assert.equal(fmtSigned(4.84), '+4.8');
        assert.equal(fmtSigned(-14.2), '-14.2');
        assert.equal(fmtSigned(0), '0.0');
        assert.equal(fmtSigned(-0.0744, 4), '-0.0744');
    });

    it('prints prices with two decimals from 1 up and trimmed decimals below', () => {
        assert.equal(fmtPrice(275.7), '275.70');
        assert.equal(fmtPrice(83121), '83,121.00');
        assert.equal(fmtPrice(0.0123), '0.0123');
    });

    it('shortens a day of turnover', () => {
        assert.equal(fmtTurnover(3_897_244_904), '$3.9B');
        assert.equal(fmtTurnover(110_725_110), '$111M');
        assert.equal(fmtTurnover(17_179_550), '$17.2M');
        assert.equal(fmtTurnover(879_112), '$879K');
    });

    it('says how long ago a scan was made', () => {
        assert.equal(scanAge(1000, 1002), 'just now');
        assert.equal(scanAge(1000, 1042), '42 s ago');
        assert.equal(scanAge(1000, 1000 + 5 * 60 + 10), '5 min ago');
        assert.equal(scanAge(1000, 1000 + 2 * 3600 + 5), '2 h ago');
        // A clock a little behind the server's is not a negative age.
        assert.equal(scanAge(1000, 990), 'just now');
    });

    it('counts candles back from the last closed one', () => {
        assert.equal(candlesAgo(0), 'on the last closed candle');
        assert.equal(candlesAgo(1), '1 candle ago');
        assert.equal(candlesAgo(2), '2 candles ago');
    });
});

describe('the sentence beside a coin', () => {
    it('describes a WaveTrend cross with its level and age, and a pending one as unconfirmed', () => {
        assert.deepEqual(
            describeReading('wt_cross', 'oversold', {
                direction: 'up',
                status: 'confirmed',
                ago: 1,
                level: -61.24,
            }),
            { text: '▲ up-cross at -61.2 · 1 candle ago', pending: false },
        );
        assert.deepEqual(
            describeReading('wt_cross', 'overbought', {
                direction: 'down',
                status: 'confirmed',
                ago: 0,
                level: 58.4,
            }),
            {
                text: '▼ down-cross at 58.4 · last candle',
                pending: false,
            },
        );

        const pending = describeReading('wt_cross', 'oversold', {
            direction: 'up',
            status: 'pending',
            ago: null,
            level: -57,
        });

        assert.equal(pending.pending, true);
        assert.match(pending.text, /^▲ up-cross forming at -57\.0/);
        assert.match(pending.text, /not confirmed until the candle closes$/);
    });

    it('says whether a WaveTrend level has turned, is about to, or is still running', () => {
        const text = (side, turning, wt1) =>
            describeReading('wt_level', side, { wt1, turning });

        assert.equal(
            text('oversold', 'confirmed', -71.3).text,
            'WT1 -71.3 · turning up',
        );
        assert.equal(
            text('oversold', 'pending', -60).text,
            'WT1 -60.0 · turning up (not confirmed)',
        );
        assert.equal(
            text('oversold', 'fading', -60).text,
            'WT1 -60.0 · was turning up, flipping back down',
        );
        assert.equal(
            text('oversold', null, -60).text,
            'WT1 -60.0 · still falling',
        );
        assert.equal(
            text('overbought', 'confirmed', 64).text,
            'WT1 64.0 · turning down',
        );
        assert.equal(
            text('overbought', null, 64).text,
            'WT1 64.0 · still rising',
        );

        // Only a turn that is not yet settled counts as pending.
        assert.equal(text('oversold', 'confirmed', -60).pending, false);
        assert.equal(text('oversold', 'pending', -60).pending, true);
        assert.equal(text('oversold', 'fading', -60).pending, true);
        assert.equal(text('oversold', null, -60).pending, false);
    });

    it('describes RSI, MACD stretch, moves, funding and the 24h range', () => {
        assert.equal(
            describeReading('rsi', 'oversold', { rsi: 18.44 }).text,
            'RSI 18.4',
        );
        assert.equal(
            describeReading('macd', 'oversold', { stretch: -2.36 }).text,
            'MACD -2.4× its usual size',
        );
        assert.equal(
            describeReading('move_24h', 'oversold', { pct: -14.2 }).text,
            '-14.2% in 24h',
        );
        assert.equal(
            describeReading('move_7d', 'overbought', { pct: 31 }).text,
            '+31.0% in 7 days',
        );
        assert.equal(
            describeReading('move_30d', 'oversold', { pct: -55 }).text,
            '-55.0% in 30 days',
        );
        assert.equal(
            describeReading('range_24h', 'oversold', { position_pct: 3.4 })
                .text,
            '3% of its 24h range',
        );
    });

    it('shows funding with enough decimals to see it, and says who pays', () => {
        assert.equal(
            describeReading('funding', 'oversold', { rate_pct: -0.7447 }).text,
            'Funding -0.745% · shorts pay longs',
        );
        assert.equal(
            describeReading('funding', 'overbought', { rate_pct: 0.0312 }).text,
            'Funding +0.0312% · longs pay shorts',
        );
    });

    it('knows every indicator the dropdown offers', () => {
        const readings = {
            wt_cross: {
                direction: 'up',
                status: 'confirmed',
                ago: 0,
                level: -60,
            },
            wt_level: { wt1: -60, turning: null },
            rsi: { rsi: 20 },
            macd: { stretch: -2 },
            move_24h: { pct: -5 },
            move_7d: { pct: -5 },
            move_30d: { pct: -5 },
            funding: { rate_pct: -0.01 },
            range_24h: { position_pct: 2 },
        };

        assert.deepEqual(
            SCREENER_INDICATORS.map((i) => i.key).sort(),
            Object.keys(readings).sort(),
        );

        for (const { key } of SCREENER_INDICATORS) {
            assert.ok(
                describeReading(key, 'oversold', readings[key]).text.length > 0,
            );
        }
    });
});

describe('volatility and the universe', () => {
    it('names the measure used', () => {
        assert.equal(volatilityText({ kind: 'atr', pct: 1.42 }), 'ATR 1.4%');
        assert.equal(
            volatilityText({ kind: 'range', pct: 9.37 }),
            '24h range 9.4%',
        );
        assert.equal(volatilityText({ kind: 'atr', pct: null }), '');
    });

    it('says what a scan covered, and what it could not read', () => {
        assert.equal(
            describeUniverse({
                basis: 'most_traded',
                size: 50,
                scanned: 48,
                missing: 2,
                min_turnover: null,
            }),
            'The 48 most traded coins · 2 coins had no candles to read',
        );
        assert.equal(
            describeUniverse({
                basis: 'most_traded',
                size: 50,
                scanned: 49,
                missing: 1,
                min_turnover: null,
            }),
            'The 49 most traded coins · 1 coin had no candles to read',
        );
        assert.equal(
            describeUniverse({
                basis: 'min_turnover',
                size: 102,
                scanned: 102,
                missing: 0,
                min_turnover: 2_000_000,
            }),
            '102 crypto coins trading at least $2.0M a day',
        );
    });
});
