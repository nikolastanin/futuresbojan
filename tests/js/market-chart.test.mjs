// Run with `npm run test:js` (Node 22.18+ strips the TypeScript types itself).
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    HIDDEN,
    LEVEL_STYLE,
    POSITION_LINE_STYLE,
    buildLegend,
    candleReadout,
    fmtPrice,
    formatTick,
    formatTime,
    isBuy,
    levelStyle,
    linePoints,
    markCard,
    mergeTail,
    positionLineTitle,
    pricePrecision,
    superTrendPoints,
    tradeDotColors,
    tradeMarks,
    waveTrendCrosses,
} from '../../resources/js/lib/market-chart.ts';

describe('price precision', () => {
    it('shows two decimals for the big coins and more as the price gets smaller', () => {
        assert.deepEqual(pricePrecision(83121), {
            precision: 2,
            minMove: 0.01,
        });
        assert.equal(pricePrecision(275.7).precision, 2);
        assert.equal(pricePrecision(100).precision, 2);
        assert.equal(pricePrecision(99.99).precision, 3);
        assert.equal(pricePrecision(12.8).precision, 3);
        assert.equal(pricePrecision(0.4942).precision, 4);
        assert.equal(pricePrecision(0.05).precision, 5);
        assert.equal(pricePrecision(0.008021).precision, 6);
        assert.deepEqual(pricePrecision(0.00001234), {
            precision: 8,
            minMove: 1e-8,
        });
    });

    it('formats a price with that many decimals and thousands separators', () => {
        assert.equal(fmtPrice(82541.84, 2), '82,541.84');
        assert.equal(fmtPrice(12.8, 3), '12.800');
        assert.equal(fmtPrice(0.00001234, 8), '0.00001234');
    });
});

describe('level styles', () => {
    it("colours the levels as on the trader's chart", () => {
        assert.equal(LEVEL_STYLE.PDH.color, LEVEL_STYLE.PWH.color);
        assert.equal(LEVEL_STYLE.PDL.color, LEVEL_STYLE.PYL.color);
        assert.notEqual(LEVEL_STYLE.PDH.color, LEVEL_STYLE.PDL.color);
        assert.deepEqual(
            [LEVEL_STYLE.DP.color, LEVEL_STYLE.WP.color, LEVEL_STYLE.MP.color]
                .length,
            new Set([
                LEVEL_STYLE.DP.color,
                LEVEL_STYLE.WP.color,
                LEVEL_STYLE.MP.color,
            ]).size,
        );
    });

    it('makes the longer periods heavier, and the previous day a thin dashed line', () => {
        assert.deepEqual(
            [
                LEVEL_STYLE.PDH.width,
                LEVEL_STYLE.PWH.width,
                LEVEL_STYLE.PYH.width,
            ],
            [1, 2, 3],
        );
        assert.equal(LEVEL_STYLE.PDH.dashed, true);
        assert.equal(LEVEL_STYLE.PWH.dashed, false);
    });

    it('falls back on the kind of level for one it does not know', () => {
        const unknown = (kind) =>
            levelStyle({ key: 'XYZ', label: 'XYZ', name: 'x', kind, price: 1 });

        assert.equal(unknown('high').color, LEVEL_STYLE.PDH.color);
        assert.equal(unknown('low').color, LEVEL_STYLE.PDL.color);
        assert.equal(
            levelStyle({
                key: 'DP',
                label: 'DP',
                name: '',
                kind: 'mid',
                price: 1,
            }),
            LEVEL_STYLE.DP,
        );
    });

    it('titles a position line without its price, which the axis tag already shows', () => {
        assert.equal(positionLineTitle('S liq 190.91', 'liquidation'), 'S liq');
        assert.equal(positionLineTitle('BE 309.54', 'break_even'), 'BE');
        assert.equal(positionLineTitle('L 291.31', 'long_entry'), 'L entry');
        assert.equal(positionLineTitle('S 284.47', 'short_entry'), 'S entry');
        assert.equal(positionLineTitle('L SL 280.00', 'stop'), 'L SL');
        assert.equal(positionLineTitle('S TP 1,234.50', 'target'), 'S TP');
        assert.equal(positionLineTitle('watch 285.28', 'watch'), 'watch');
    });

    it('styles every kind of position line the mini chart can produce', () => {
        for (const kind of [
            'long_entry',
            'short_entry',
            'break_even',
            'liquidation',
            'stop',
            'target',
            'watch',
        ]) {
            assert.ok(POSITION_LINE_STYLE[kind], `no style for ${kind}`);
        }
    });
});

describe('SuperTrend lines', () => {
    it('splits the trend into an up line and a down line with gaps, so a flip breaks the line', () => {
        const { up, down } = superTrendPoints([1, 2, 3, 4, 5], {
            line: [null, 10, 11, 20, 21],
            trend: [null, 1, 1, -1, -1],
        });

        // The chart joins points across a gap, in the colour of the point the stretch leaves: the last point before a gap hides the join.
        assert.deepEqual(up, [
            { time: 1 },
            { time: 2, value: 10 },
            { time: 3, value: 11, color: HIDDEN },
            { time: 4 },
            { time: 5 },
        ]);
        assert.deepEqual(down, [
            { time: 1 },
            { time: 2 },
            { time: 3 },
            { time: 4, value: 20 },
            { time: 5, value: 21 },
        ]);
    });

    it('hides the join at every gap, not just the first', () => {
        const { up } = superTrendPoints([1, 2, 3, 4, 5, 6], {
            line: [10, 11, 20, 21, 12, 13],
            trend: [1, 1, -1, -1, 1, 1],
        });

        assert.deepEqual(up, [
            { time: 1, value: 10 },
            { time: 2, value: 11, color: HIDDEN },
            { time: 3 },
            { time: 4 },
            { time: 5, value: 12 },
            { time: 6, value: 13 },
        ]);
    });

    it('treats a missing line or trend as a gap in both', () => {
        const { up, down } = superTrendPoints([1, 2], {
            line: [5, null],
            trend: [null, 1],
        });

        assert.deepEqual(up, [{ time: 1 }, { time: 2 }]);
        assert.deepEqual(down, [{ time: 1 }, { time: 2 }]);
    });

    it('draws a series of values as a line with gaps where there is none', () => {
        assert.deepEqual(linePoints([1, 2, 3], [null, 4.5, 0]), [
            { time: 1 },
            { time: 2, value: 4.5 },
            { time: 3, value: 0 },
        ]);
        assert.deepEqual(linePoints([1, 2], [3]), [
            { time: 1, value: 3 },
            { time: 2 },
        ]);
    });
});

describe('WaveTrend crosses', () => {
    const times = [1, 2, 3, 4, 5, 6];

    it('finds the candles on which WT1 ends up on the other side of WT2, at WT2', () => {
        const crosses = waveTrendCrosses(
            times,
            [null, -10, -8, -4, -6, -7],
            [null, -5, -6, -5, -5.4, -5.5],
        );

        assert.deepEqual(crosses, [
            { time: 4, direction: 'up', level: -5 },
            { time: 5, direction: 'down', level: -5.4 },
        ]);
    });

    it('counts WT1 level with WT2 as below, so a cross needs it strictly over', () => {
        assert.deepEqual(
            waveTrendCrosses([1, 2, 3], [-3, -5, -5], [-4, -5, -6]),
            [
                // -3 over -4 is above; then level at -5 counts as below (a cross down); then -5 over -6 is above again (a cross up).
                { time: 2, direction: 'down', level: -5 },
                { time: 3, direction: 'up', level: -6 },
            ],
        );
    });

    it('skips the warm-up where there are no values, and never crosses on the first candle', () => {
        assert.deepEqual(waveTrendCrosses([1, 2], [null, 5], [null, 3]), []);
        assert.deepEqual(waveTrendCrosses([1], [5], [3]), []);
    });
});

describe('the legend', () => {
    it("reads a candle the way TradingView's header does, with its own move", () => {
        const up = candleReadout(
            {
                time: 1,
                open: 82427.73,
                high: 82660,
                low: 82209.2,
                close: 82541.84,
            },
            2,
        );

        assert.deepEqual(up, {
            open: '82,427.73',
            high: '82,660.00',
            low: '82,209.20',
            close: '82,541.84',
            change: '+114.11 (+0.14%)',
            up: true,
        });
    });

    it('signs a falling candle with a minus', () => {
        const down = candleReadout(
            { time: 1, open: 100, high: 101, low: 94, close: 95 },
            2,
        );

        assert.equal(down.change, '-5.00 (-5.00%)');
        assert.equal(down.up, false);
    });
});

describe('time on the clock', () => {
    // 2026-10-10 06:00 UTC
    const unix = 1_791_612_000;

    it('shows the crosshair time as TradingView does, in any time zone', () => {
        assert.equal(formatTime(unix, 'UTC'), "10 Oct '26 06:00");
        // The trader's own clock, two hours ahead in October.
        assert.equal(formatTime(unix, 'Europe/Belgrade'), "10 Oct '26 08:00");
    });

    it('labels each kind of tick on the time axis', () => {
        assert.equal(formatTick(unix, 'time', 'UTC'), '06:00');
        assert.equal(formatTick(unix, 'day', 'UTC'), '10');
        assert.equal(formatTick(unix, 'month', 'UTC'), 'Oct');
        assert.equal(formatTick(unix, 'year', 'UTC'), '2026');
    });

    it('rolls the day over on the clock it is shown in', () => {
        // 23:30 UTC on the 10th is already the 11th, 01:30, in Belgrade.
        assert.equal(formatTick(1_791_675_000, 'day', 'Europe/Belgrade'), '11');
        assert.equal(formatTick(1_791_675_000, 'day', 'UTC'), '10');
    });
});

describe('keeping the data current', () => {
    const candle = (time, close) => ({
        time,
        open: close - 1,
        high: close + 1,
        low: close - 2,
        close,
    });
    const payload = (candles, line, trend, wt1, wt2, extra = {}) => ({
        symbol: 'BTC_USDT',
        tf: '4H',
        seconds: 14400,
        full: true,
        candles,
        supertrend: { '12_2.5': { line, trend }, '10_3': { line, trend } },
        wavetrend: { wt1, wt2 },
        levels: [],
        price: candles[candles.length - 1].close,
        generated_at: 1,
        ...extra,
    });

    const base = payload(
        [candle(100, 10), candle(200, 11), candle(300, 12)],
        [null, 9, 9.5],
        [null, 1, 1],
        [null, -5, -4],
        [null, -6, -5],
    );

    it('replaces the candle it started from and adds the new ones, cutting every series at the same place', () => {
        const tail = payload(
            [candle(300, 13), candle(400, 14)],
            [9.6, 9.9],
            [1, 1],
            [-3, -2],
            [-4, -3.5],
            {
                levels: [
                    {
                        key: 'PDH',
                        label: 'PDH',
                        name: 'x',
                        kind: 'high',
                        price: 99,
                    },
                ],
                generated_at: 2,
                full: false,
            },
        );

        const merged = mergeTail(base, tail);

        assert.deepEqual(
            merged.candles.map((c) => c.time),
            [100, 200, 300, 400],
        );
        assert.equal(merged.candles[2].close, 13);
        assert.deepEqual(merged.supertrend['12_2.5'].line, [null, 9, 9.6, 9.9]);
        assert.deepEqual(merged.supertrend['10_3'].trend, [null, 1, 1, 1]);
        assert.deepEqual(merged.wavetrend.wt1, [null, -5, -3, -2]);
        assert.deepEqual(merged.wavetrend.wt2, [null, -6, -4, -3.5]);
        assert.equal(merged.price, 14);
        assert.equal(merged.levels[0].price, 99);
        assert.equal(merged.full, true);
        // Nothing the page already had is changed in place.
        assert.equal(base.candles.length, 3);
    });

    it('updates just the forming candle when no new one has opened', () => {
        const tail = payload([candle(300, 12.5)], [9.7], [1], [-3.5], [-4.5], {
            full: false,
        });
        const merged = mergeTail(base, tail);

        assert.deepEqual(
            merged.candles.map((c) => c.close),
            [10, 11, 12.5],
        );
        assert.deepEqual(merged.wavetrend.wt2, [null, -6, -4.5]);
    });

    it('leaves the data alone when the refresh has no candles', () => {
        assert.equal(mergeTail(base, { ...base, candles: [] }), base);
    });

    it('reads the legend for any candle, with each SuperTrend at that candle', () => {
        const legend = buildLegend(base, 2, 2, 'UTC');

        assert.equal(legend.time, "1 Jan '70 00:05");
        assert.equal(legend.readout.close, '12.00');
        assert.deepEqual(
            legend.supertrend.map((s) => [s.label, s.value, s.trend]),
            [
                ['SuperTrend 12 2.5', '9.50', 1],
                ['SuperTrend 10 3', '9.50', 1],
            ],
        );
        assert.equal(buildLegend(base, 0, 2, 'UTC').supertrend[0].value, '');
        assert.equal(buildLegend(base, 9, 2), null);
    });
});

describe('your trades on the chart', () => {
    // Hourly candles from 00:00 UTC on 10 Oct 2026, with the 02:00 one missing.
    const day = 1_791_590_400;
    const hour = 3600;
    const times = [day, day + hour, day + 3 * hour, day + 4 * hour];

    const fill = (over = {}) => ({
        time: day + hour + 60,
        side: 1,
        price: 100,
        vol: 10,
        notional: 10,
        profit: 0,
        fee: 0,
        order_id: 'a',
        ...over,
    });

    it('puts each fill on the candle it happened in', () => {
        const marks = tradeMarks(
            [
                fill({ time: day + hour }), // the very first second of its candle
                fill({ time: day + 2 * hour - 1, side: 3, order_id: 'b' }), // and the very last
                fill({ time: day + 4 * hour + 30, side: 4, order_id: 'c' }),
            ],
            times,
            hour,
        );

        assert.deepEqual(
            marks.map((m) => [m.time, m.side]),
            [
                [day + hour, 1],
                [day + hour, 3],
                [day + 4 * hour, 4],
            ],
        );
    });

    it('draws nothing for a fill before the first candle, in a gap between candles or past the newest one', () => {
        const marks = tradeMarks(
            [
                fill({ time: day - 1 }),
                fill({ time: day + 2 * hour + 5 }), // the 02:00 candle does not exist
                fill({ time: day + 5 * hour }), // the 05:00 candle has not opened yet
            ],
            times,
            hour,
        );

        assert.deepEqual(marks, []);
        assert.deepEqual(tradeMarks([fill()], [], hour), []);
    });

    it('joins the fills of one side in one candle into one dot at their volume-weighted price', () => {
        const [mark, ...rest] = tradeMarks(
            [
                fill({ price: 100, vol: 10, notional: 10, order_id: 'a' }),
                fill({
                    price: 110,
                    vol: 30,
                    notional: 33,
                    order_id: 'a',
                    time: day + hour + 90,
                }),
            ],
            times,
            hour,
        );

        assert.equal(rest.length, 0);
        assert.equal(mark.price, 107.5);
        assert.equal(mark.vol, 40);
        assert.equal(mark.notional, 43);
        // two pieces of one order are one order
        assert.equal(mark.orders, 1);
        assert.equal(mark.first, day + hour + 60);
    });

    it('counts the orders it stands for', () => {
        const [mark] = tradeMarks(
            [
                fill({ order_id: 'a' }),
                fill({ order_id: 'b' }),
                fill({ order_id: 'b' }),
                fill({ order_id: 'c' }),
            ],
            times,
            hour,
        );

        assert.equal(mark.orders, 3);
    });

    it('keeps buys and sells, opens and closes, on their own dots, oldest first', () => {
        const marks = tradeMarks(
            [
                fill({ side: 4, time: day + hour + 500, order_id: 'd' }),
                fill({ side: 3, time: day + hour + 300, order_id: 'c' }),
                fill({ side: 1, time: day + hour + 100, order_id: 'a' }),
                fill({ side: 2, time: day + hour + 200, order_id: 'b' }),
                fill({ side: 1, time: day + 3 * hour, order_id: 'e' }),
            ],
            times,
            hour,
        );

        assert.deepEqual(
            marks.map((m) => [m.time - day, m.side]),
            [
                [hour, 1],
                [hour, 2],
                [hour, 3],
                [hour, 4],
                [3 * hour, 1],
            ],
        );
    });

    it('adds up the profit of a close, and has no size in USDT once one fill has none', () => {
        const [mark] = tradeMarks(
            [
                fill({ side: 4, profit: 1.5, notional: 20 }),
                fill({
                    side: 4,
                    profit: -0.25,
                    notional: null,
                    order_id: 'b',
                }),
            ],
            times,
            hour,
        );

        assert.equal(mark.profit, 1.25);
        assert.equal(mark.notional, null);
    });

    it('falls back to the plain average price when the sizes add up to nothing', () => {
        const [mark] = tradeMarks(
            [
                fill({ price: 10, vol: 0 }),
                fill({ price: 20, vol: 0, order_id: 'b' }),
            ],
            times,
            hour,
        );

        assert.equal(mark.price, 15);
    });

    it('calls opening a long and closing a short a buy, the other two a sell', () => {
        assert.deepEqual([1, 2, 3, 4].map(isBuy), [true, true, false, false]);
    });

    it("draws the dots in a green and a red of their own, not the candles'", () => {
        for (const dark of [true, false]) {
            const { buy, sell, ring } = tradeDotColors(dark);

            // the candles, and the levels, use #22c55e and #ef4444
            assert.notEqual(buy, '#22c55e');
            assert.notEqual(sell, '#ef4444');
            assert.notEqual(buy, sell);
            assert.match(ring, /^#[0-9a-f]{6}$/);
        }

        // a ring that shows against the background: light on the dark theme, dark on the light one
        assert.equal(tradeDotColors(true).ring, '#fafafa');
        assert.equal(tradeDotColors(false).ring, '#18181b');
    });

    describe('the popup over a dot', () => {
        const mark = (over = {}) => ({
            time: day,
            side: 1,
            price: 273.4,
            vol: 10,
            notional: 520,
            profit: 0,
            orders: 1,
            first: day + 14 * hour + 300,
            ...over,
        });

        it('says what kind of order it was, how big and at what price, and when', () => {
            assert.deepEqual(markCard(mark(), 2, 'UTC'), {
                buy: true,
                title: 'Buy · Open long',
                lines: ['$520 at 273.40', "10 Oct '26 14:05"],
            });
            assert.deepEqual(
                markCard(mark({ side: 3, notional: 300 }), 2, 'UTC'),
                {
                    buy: false,
                    title: 'Sell · Open short',
                    lines: ['$300 at 273.40', "10 Oct '26 14:05"],
                },
            );
        });

        it('names all four kinds of order', () => {
            assert.deepEqual(
                [1, 2, 3, 4].map((side) => markCard(mark({ side }), 2).title),
                [
                    'Buy · Open long',
                    'Buy · Close short',
                    'Sell · Open short',
                    'Sell · Close long',
                ],
            );
        });

        it('counts the orders when there are several', () => {
            assert.deepEqual(markCard(mark({ orders: 3 }), 2, 'UTC').lines, [
                '$520 at 273.40',
                "3 orders · 10 Oct '26 14:05",
            ]);
        });

        it('shows what a close made, as a profit or a loss', () => {
            assert.deepEqual(
                markCard(
                    mark({ side: 4, notional: 200, profit: 1.2 }),
                    2,
                    'UTC',
                ).lines,
                ['$200 at 273.40', "10 Oct '26 14:05", 'Profit +$1.20'],
            );
            assert.equal(
                markCard(mark({ side: 2, profit: -0.4 }), 2, 'UTC').lines[2],
                'Loss -$0.40',
            );
        });

        it('leaves out a profit that is nothing, and one on an order that opens', () => {
            assert.equal(
                markCard(mark({ side: 4, profit: 0.004 }), 2, 'UTC').lines
                    .length,
                2,
            );
            assert.equal(
                markCard(mark({ side: 1, profit: 3 }), 2, 'UTC').lines.length,
                2,
            );
        });

        it('shows whole dollars from a hundred up, cents below, and thousands with a comma', () => {
            const size = (notional) =>
                markCard(mark({ notional }), 2, 'UTC').lines[0];

            assert.equal(size(99.5), '$99.5 at 273.40');
            assert.equal(size(12.345), '$12.35 at 273.40');
            assert.equal(size(100.4), '$100 at 273.40');
            assert.equal(size(1234.56), '$1,235 at 273.40');
        });

        it('leaves out the size when it is not known, and rounds the price for the coin', () => {
            assert.equal(
                markCard(mark({ notional: null }), 3, 'UTC').lines[0],
                'At 273.400',
            );
        });

        it('reads the time on the clock of the zone it is given', () => {
            assert.equal(
                markCard(mark(), 2, 'Europe/Belgrade').lines[1],
                "10 Oct '26 16:05",
            );
        });
    });
});
