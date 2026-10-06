<?php

use App\Manual\TradePlanBuilder;

function planZone(array $plan, string $id): array
{
    foreach ($plan['zones'] as $zone) {
        if ($zone['id'] === $id) {
            return $zone;
        }
    }

    throw new RuntimeException("No zone {$id} in plan: ".implode(',', array_column($plan['zones'], 'id')));
}

$bearishMtf = [
    ['tf' => '15M', 'macd' => 'bearish', 'lean' => 'down'],
    ['tf' => '4H', 'macd' => 'bearish', 'lean' => 'down'],
];

describe('zones', function () use ($bearishMtf) {
    it('stacks levels within 0.2% into one strong zone below price as a long candidate', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(105.0, ['A' => 100.0, 'B' => 100.1, 'C' => 100.15, 'R' => 110.0], 2.0, $bearishMtf, null);

        $zone = planZone($plan, 'long-1');

        expect($zone['strength'])->toBe('strong')
            ->and($zone['side'])->toBe('long')
            ->and($zone['low'])->toBe(100.0)
            ->and($zone['high'])->toBe(100.15)
            ->and($zone['sources'])->toHaveCount(3);
    });

    it('treats a lone level as a weak zone and two stacked levels as solid', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['PDH' => 104.0, 'R1' => 108.0, 'R2' => 108.1], 2.0, $bearishMtf, null);

        expect(planZone($plan, 'short-1')['strength'])->toBe('weak')
            ->and(planZone($plan, 'short-2')['strength'])->toBe('solid');
    });

    it('never makes a zone out of a moving average alone, but lets it join a stack', function () use ($bearishMtf) {
        $alone = (new TradePlanBuilder)->build(100.0, ['EMA10' => 98.0, 'PDH' => 110.0], 2.0, $bearishMtf, null);

        expect(array_column($alone['zones'], 'side'))->toBe(['short']);

        $stacked = (new TradePlanBuilder)->build(100.0, ['EMA20' => 98.0, 'PDL' => 98.1], 2.0, $bearishMtf, null);
        $zone    = planZone($stacked, 'long-1');

        expect($zone['strength'])->toBe('solid')
            ->and(array_column($zone['sources'], 'label'))->toBe(['EMA20', 'PDL']);
    });

    it('does not offer zones further than 10% away as entries, but still uses them as targets', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 97.0, 'FAR' => 150.0], 1.0, $bearishMtf, null);

        expect($plan['zones'])->toHaveCount(1);
        expect(planZone($plan, 'long-1')['targets'][0]['price'])->toBe(150.0);
    });

    it('skips a zone sitting right next to one already chosen on the same side', function () use ($bearishMtf) {
        // 105 and 105.4 are more than 0.2% apart (separate zones) but under half an ATR
        // apart here (1.0), so they are the same area; 110 is the real second zone.
        $plan = (new TradePlanBuilder)->build(100.0, ['A' => 105.0, 'B' => 105.4, 'C' => 110.0], 2.0, $bearishMtf, null);

        $shorts = array_values(array_filter($plan['zones'], fn ($z) => $z['side'] === 'short'));

        expect(array_column($shorts, 'low'))->toBe([105.0, 110.0]);
    });

    it('returns no zones without levels or a usable price', function () use ($bearishMtf) {
        $builder = new TradePlanBuilder;

        expect($builder->build(100.0, [], 2.0, $bearishMtf, null)['zones'])->toBe([])
            ->and($builder->build(0.0, ['A' => 1.0], 2.0, $bearishMtf, null)['zones'])->toBe([]);
    });
});

describe('trade math', function () use ($bearishMtf) {
    it('puts invalidation at the far edge and the stop one ATR beyond it', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(105.0, ['A' => 100.0, 'B' => 100.1, 'C' => 100.15, 'R' => 110.0], 2.0, $bearishMtf, null);
        $zone = planZone($plan, 'long-1');

        expect($zone['invalidation'])->toBe(100.0)
            ->and($zone['stop'])->toBe(98.0)
            ->and($zone['stop_distance_atr'])->toBe(1.04);
    });

    it('targets the next zone beyond and computes risk/reward from the zone middle', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(105.0, ['A' => 100.0, 'B' => 100.1, 'C' => 100.15, 'R' => 110.0], 2.0, $bearishMtf, null);
        $zone = planZone($plan, 'long-1');

        // Entry is the zone middle (100.075); stop is 98; target is the lower edge of 110.
        expect($zone['targets'][0]['price'])->toBe(110.0)
            ->and($zone['rr'])->toEqualWithDelta((110 - 100.075) / (100.075 - 98.0), 0.01);
    });

    it('mirrors the maths for a short, targeting the upper edge of support below', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['R1' => 105.0, 'R2' => 105.1, 'S1' => 94.9, 'S2' => 95.0], 2.0, $bearishMtf, null);
        $zone = planZone($plan, 'short-1');

        expect($zone['invalidation'])->toBe(105.1)
            ->and($zone['stop'])->toBe(107.1)
            ->and($zone['targets'][0]['price'])->toBe(95.0)
            ->and($zone['rr'])->toEqualWithDelta((105.05 - 95.0) / (107.1 - 105.05), 0.01);
    });

    it('warns when there is no further zone to target', function () use ($bearishMtf) {
        $lone = (new TradePlanBuilder)->build(105.0, ['A' => 100.0], 2.0, $bearishMtf, null);

        expect(planZone($lone, 'long-1')['warnings'][0])->toContain('No further zone')
            ->and(planZone($lone, 'long-1')['targets'])->toBe([])
            ->and(planZone($lone, 'long-1')['rr'])->toBeNull();
    });

    it('never reports a risk/reward below 1, because targets must clear the same ATR the stop does', function () use ($bearishMtf) {
        // A target just beyond the minimum gap is the worst case for the ratio.
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 97.0, 'T' => 99.0], 1.0, $bearishMtf, null);

        foreach ($plan['zones'] as $zone) {
            if ($zone['rr'] !== null) {
                expect($zone['rr'])->toBeGreaterThanOrEqual(1.0);
            }
        }
    });
});

describe('summary', function () use ($bearishMtf) {
    it('says there is no plan when there are no zones', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, [], 2.0, $bearishMtf, 'bearish');

        expect($plan['summary'])->toContain('no plan to watch');
    });

    it('names the only confirmed setup, with its strength and where it is', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0, 'R' => 105.0], 2.0, $bearishMtf, 'bearish');

        expect($plan['summary'])->toBe('Short zone 1 is the only confirmed setup (weak, 5% above).');
    });

    it('groups several confirmed zones on one side and points at the nearer one', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0, 'R1' => 105.0, 'R2' => 108.5], 2.0, $bearishMtf, 'bearish');

        expect($plan['summary'])->toBe('Short zones 1 and 2 are confirmed setups; the nearer is Short zone 1 (weak, 5% above).');
    });

    it('reports the closest zone and what is still waiting when none is fully confirmed', function () use ($bearishMtf) {
        // Bullish SuperTrend against bearish MACD and 4H: the short zone is 2/3, the long zone 1/3.
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0, 'R' => 105.0], 2.0, $bearishMtf, 'bullish');

        expect($plan['summary'])->toBe('No zone is fully confirmed; Short zone 1 is closest at 2/3 (SuperTrend 15M still waiting).');
    });

    it('says nothing is confirmed when no zone gets even two checks', function () {
        $mtf  = [['tf' => '15M', 'macd' => 'bullish', 'lean' => 'up'], ['tf' => '4H', 'macd' => 'bullish', 'lean' => 'up']];
        $plan = (new TradePlanBuilder)->build(100.0, ['R' => 105.0], 2.0, $mtf, 'bullish');

        expect($plan['summary'])->toBe('No zone is confirmed: the short-term picture does not back any of them yet.');
    });

    it('adds the zone price is next to, with how much of it is confirmed', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 99.0, 'R' => 110.0], 2.0, $bearishMtf, 'bullish');

        expect($plan['summary'])->toContain('No zone is fully confirmed; Short zone 1 is closest at 2/3')
            ->and($plan['summary'])->toContain('Long zone 1 is within one hourly ATR, but only 1/3 confirmed.');
    });

    it('does not repeat the nearest zone when it is the confirmed one already named', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 99.0], 2.0, [['tf' => '15M', 'macd' => 'bullish', 'lean' => 'up'], ['tf' => '4H', 'macd' => 'bullish', 'lean' => 'up']], 'bullish');

        expect($plan['summary'])->toBe('Long zone 1 is the only confirmed setup (weak, 1% below).');
    });

    it('admits when the confirmations are not available instead of guessing', function () {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0], 2.0, [], null);

        expect($plan['summary'])->toContain('not available yet');
    });
});

describe('status and confirmations', function () use ($bearishMtf) {
    it('marks a zone in_zone, near or far from price', function () use ($bearishMtf) {
        $builder = new TradePlanBuilder;

        $inZone = $builder->build(100.0, ['A' => 99.9, 'B' => 100.05], 2.0, $bearishMtf, null);
        $near   = $builder->build(100.0, ['A' => 98.5], 2.0, $bearishMtf, null);
        $far    = $builder->build(100.0, ['A' => 95.0], 2.0, $bearishMtf, null);

        expect($inZone['zones'][0]['status'])->toBe('in_zone')
            ->and($near['zones'][0]['status'])->toBe('near')
            ->and($far['zones'][0]['status'])->toBe('far');
    });

    it('confirms a zone only when SuperTrend, 15M MACD and the 4H backdrop all point its way', function () use ($bearishMtf) {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0, 'R' => 105.0], 2.0, $bearishMtf, 'bearish');

        // Everything is bearish: confirms the short zone, leaves the long waiting.
        expect(planZone($plan, 'short-1')['confirmed'])->toBe(3)
            ->and(planZone($plan, 'long-1')['confirmed'])->toBe(0)
            ->and(array_column(planZone($plan, 'long-1')['confirmations'], 'state'))->toBe(['waiting', 'waiting', 'waiting']);
    });

    it('reports unknown rather than guessing when an input is missing', function () {
        $plan = (new TradePlanBuilder)->build(100.0, ['S' => 95.0], 2.0, [], null);

        expect(array_column(planZone($plan, 'long-1')['confirmations'], 'state'))->toBe(['unknown', 'unknown', 'unknown']);
    });
});
