<?php

use App\Manual\LegLiquidation;

// The market is at 300 in every case below.

describe('which liquidation price is a leg\'s own', function () {
    it('takes a long\'s below the price and a short\'s above it', function () {
        expect(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => 268.0], 300.0))->toBe(268.0)
            ->and(LegLiquidation::of(['direction' => 'SHORT', 'liquidation_price' => 331.5], 300.0))->toBe(331.5);
    });

    it('leaves out one on the wrong side, such as the single price MEXC repeats on both legs of a hedge', function () {
        // Both legs report 190.91: below the market, so the long's and not the short's.
        expect(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => 190.91], 300.0))->toBe(190.91)
            ->and(LegLiquidation::of(['direction' => 'SHORT', 'liquidation_price' => 190.91], 300.0))->toBeNull()
            ->and(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => 331.5], 300.0))->toBeNull();
    });

    it('does not count a liquidation price at the market as a distance', function () {
        expect(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => 300.0], 300.0))->toBeNull()
            ->and(LegLiquidation::of(['direction' => 'SHORT', 'liquidation_price' => 300.0], 300.0))->toBeNull();
    });

    it('reads the direction in any case', function () {
        expect(LegLiquidation::of(['direction' => 'long', 'liquidation_price' => 268.0], 300.0))->toBe(268.0)
            ->and(LegLiquidation::of(['direction' => 'short', 'liquidation_price' => 268.0], 300.0))->toBeNull();
    });

    it('has none when the exchange reports none or something that is not a price', function () {
        foreach ([0, 0.0, -5, null, '', 'n/a', []] as $reported) {
            expect(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => $reported], 300.0))->toBeNull();
        }

        expect(LegLiquidation::of(['direction' => 'LONG'], 300.0))->toBeNull();
    });

    it('accepts a numeric string, as a form may send', function () {
        expect(LegLiquidation::of(['direction' => 'LONG', 'liquidation_price' => '268.5'], 300.0))->toBe(268.5);
    });

    it('takes any positive figure as it comes when there is no price or no known direction to judge by', function () {
        expect(LegLiquidation::of(['direction' => 'SHORT', 'liquidation_price' => 190.91], null))->toBe(190.91)
            ->and(LegLiquidation::of(['direction' => 'SHORT', 'liquidation_price' => 190.91], 0.0))->toBe(190.91)
            ->and(LegLiquidation::of(['direction' => 'SIDEWAYS', 'liquidation_price' => 190.91], 300.0))->toBe(190.91)
            ->and(LegLiquidation::of(['liquidation_price' => 190.91], 300.0))->toBe(190.91);
    });
});

describe('the figure in a prompt', function () {
    it('prints the price as it came', function () {
        expect(LegLiquidation::text(['direction' => 'LONG', 'liquidation_price' => 268.4], 300.0))->toBe('268.4')
            ->and(LegLiquidation::text(['direction' => 'LONG', 'liquidation_price' => 268.0], 300.0))->toBe('268');
    });

    it('says plainly that the leg has none, rather than printing a price that is not its own', function () {
        expect(LegLiquidation::text(['direction' => 'SHORT', 'liquidation_price' => 190.91], 300.0))->toBe('none reported for this leg')
            ->and(LegLiquidation::text(['direction' => 'LONG', 'liquidation_price' => 0], 300.0))->toBe('none reported for this leg');
    });
});
