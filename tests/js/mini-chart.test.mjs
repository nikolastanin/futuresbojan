// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    MAX_CANDLES,
    candleLayout,
    chartShape,
    fitScale,
    placement,
    spreadLabels,
    withLivePrice,
    yFor,
} from '../../resources/js/lib/mini-chart.ts';

const near = (actual, expected, epsilon = 1e-9) =>
    assert.ok(
        Math.abs(actual - expected) <= epsilon,
        `expected ${actual} to be within ${epsilon} of ${expected}`,
    );

const candle = (time, open, high, low, close) => ({
    time,
    open,
    high,
    low,
    close,
});

// Three 15M candles; the last one (time 1800) is the one still forming.
const series = () => [
    candle(0, 100, 101, 99, 100.5),
    candle(900, 100.5, 102, 100, 101),
    candle(1800, 101, 101.5, 100.5, 101.2),
];

describe('withLivePrice', () => {
    it('moves the forming candle with the live price', () => {
        const result = withLivePrice(series(), 101.4, 900, 2000);

        assert.equal(result.length, 3);
        near(result[2].close, 101.4);
        // 101.4 is inside the candle's range, so high and low stay.
        near(result[2].high, 101.5);
        near(result[2].low, 100.5);
    });

    it('widens the forming candle when the price leaves its range', () => {
        const up = withLivePrice(series(), 102.5, 900, 2000);
        const down = withLivePrice(series(), 99.8, 900, 2000);

        near(up[2].high, 102.5);
        near(up[2].close, 102.5);
        near(down[2].low, 99.8);
        near(down[2].close, 99.8);
    });

    it('opens a new candle when the last one has already ended', () => {
        // The last candle covered 1800-2700; at 2750 the next period has begun.
        const result = withLivePrice(series(), 101.9, 900, 2750);

        assert.equal(result.length, 4);
        assert.equal(result[3].time, 2700);
        near(result[3].open, 101.2);
        near(result[3].close, 101.9);
        near(result[3].high, 101.9);
        near(result[3].low, 101.2);
    });

    it('never changes the input and ignores a missing price', () => {
        const original = series();
        const copy = JSON.parse(JSON.stringify(original));

        withLivePrice(original, 105, 900, 2000);

        assert.deepEqual(original, copy);
        assert.equal(withLivePrice(original, null, 900, 2000), original);
        assert.equal(withLivePrice(original, 0, 900, 2000), original);
        assert.deepEqual(withLivePrice([], 100, 900, 2000), []);
    });
});

describe('fitScale', () => {
    const candles = () => [
        candle(0, 100, 110, 100, 105),
        candle(1, 105, 108, 102, 104),
    ];

    it('covers the candles with a little padding', () => {
        const scale = fitScale(candles(), []);

        // Range 100-110, padded by 6% of 10.
        near(scale.min, 99.4);
        near(scale.max, 110.6);
    });

    it('stretches to take in a line close to the action', () => {
        // 96 is 4 below the low: within 0.75 of the 10-wide range.
        const scale = fitScale(candles(), [96]);

        assert.ok(scale.min < 96);
        near(scale.max, 110 + 0.06 * 14);
    });

    it('leaves a far-away line off the scale instead of flattening the candles', () => {
        // 85 is 15 below the low, far beyond 0.75 of the range.
        const scale = fitScale(candles(), [85]);

        near(scale.min, 99.4);
        assert.equal(placement(85, scale), 'below');
    });

    it('treats lines on both sides independently', () => {
        const scale = fitScale(candles(), [96, 114, 140]);

        assert.ok(scale.min < 96);
        assert.ok(scale.max > 114);
        assert.ok(scale.max < 140);
    });

    it('still gives a flat series a window', () => {
        const scale = fitScale([candle(0, 100, 100, 100, 100)], []);

        assert.ok(scale.max > scale.min);
        assert.ok(scale.min < 100 && scale.max > 100);
    });

    it('has no scale without candles', () => {
        assert.equal(fitScale([], [100]), null);
    });
});

describe('yFor and placement', () => {
    const scale = { min: 90, max: 110 };

    it('puts the top of the scale at the top and the bottom at the bottom', () => {
        near(yFor(110, scale, 6, 100), 6);
        near(yFor(90, scale, 6, 100), 106);
        near(yFor(100, scale, 6, 100), 56);
    });

    it('does not clamp: an off-scale price lands outside the box', () => {
        assert.ok(yFor(120, scale, 0, 100) < 0);
        assert.ok(yFor(80, scale, 0, 100) > 100);
    });

    it('says where a price sits against the scale', () => {
        assert.equal(placement(100, scale), 'on');
        assert.equal(placement(90, scale), 'on');
        assert.equal(placement(110.01, scale), 'above');
        assert.equal(placement(89.99, scale), 'below');
    });
});

describe('spreadLabels', () => {
    it('leaves labels that are already apart where they are', () => {
        assert.deepEqual(spreadLabels([20, 50, 90], 12, 0, 120), [20, 50, 90]);
    });

    it('pushes a label down when it would sit on the one above', () => {
        assert.deepEqual(spreadLabels([40, 44], 12, 0, 120), [40, 52]);
    });

    it('keeps the answers in the order of the input, whatever the order of the lines', () => {
        // The second line is the higher one on the page.
        assert.deepEqual(spreadLabels([80, 40, 44], 12, 0, 120), [80, 40, 52]);
    });

    it('pulls a crowd at the bottom back up inside the box, keeping the gaps', () => {
        const placed = spreadLabels([110, 112, 114], 12, 0, 120);

        assert.deepEqual(placed, [96, 108, 120]);
        assert.ok(Math.max(...placed) <= 120);
    });

    it('does not let a label start above the top', () => {
        assert.deepEqual(spreadLabels([-30, 2], 12, 5, 120), [5, 17]);
    });

    it('copes with nothing to place', () => {
        assert.deepEqual(spreadLabels([], 12, 0, 100), []);
    });
});

describe('chartShape', () => {
    it('keeps the compact chart on a phone: 60 candles in a short box, small type', () => {
        assert.deepEqual(chartShape(331), {
            gutter: 78,
            roomy: false,
            height: 118,
            count: 60,
        });
    });

    it('copes with a chart that has not been measured yet', () => {
        assert.deepEqual(chartShape(0), {
            gutter: 78,
            roomy: false,
            height: 118,
            count: 60,
        });
    });

    it('widens the label column and enlarges the type as room grows', () => {
        assert.equal(chartShape(479).gutter, 78);
        assert.equal(chartShape(480).gutter, 96);
        assert.equal(chartShape(759).gutter, 96);
        assert.equal(chartShape(760).gutter, 112);
        assert.equal(chartShape(759).roomy, false);
        assert.equal(chartShape(760).roomy, true);
    });

    it('shows more candles on a wider chart instead of spreading the same ones apart', () => {
        // Plot widths: 600 - 96 = 504, 1056 - 112 = 944, 1531 - 112 = 1419.
        assert.equal(chartShape(600).count, 72);
        assert.equal(chartShape(1056).count, 134);
        assert.equal(chartShape(1531).count, MAX_CANDLES);
        assert.equal(chartShape(4000).count, MAX_CANDLES);
    });

    it('grows taller with its width, within limits', () => {
        assert.equal(chartShape(600).height, 118);
        assert.equal(chartShape(1056).height, 157);
        assert.equal(chartShape(1531).height, 190);
        assert.equal(chartShape(4000).height, 190);
    });

    it("from a phone's width up, never packs the candles tighter than a phone does or spreads them far apart", () => {
        for (let width = 330; width <= 2400; width += 10) {
            const { gutter, count } = chartShape(width);
            const { step } = candleLayout(count, width - gutter);

            assert.ok(
                step >= 4 && step <= 12,
                `a ${width}px chart gives each candle ${step}px`,
            );
        }
    });
});

describe('candleLayout', () => {
    it('gives each candle an equal slot and a body inside it', () => {
        const { step, body } = candleLayout(60, 600);

        near(step, 10);
        near(body, 6.2);
    });

    it('keeps bodies visible when crowded and sensible when roomy', () => {
        assert.equal(candleLayout(60, 30).body, 1);
        assert.equal(candleLayout(5, 600).body, 9);
        assert.deepEqual(candleLayout(0, 600), { step: 0, body: 1 });
    });
});
