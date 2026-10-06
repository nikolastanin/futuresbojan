<?php

use App\Manual\TradeEventLogger;

function ctxZone(string $side, string $status, int $confirmed, float $distance = 0.5, int $number = 1): array
{
    return [
        'side' => $side, 'number' => $number, 'strength' => 'solid', 'low' => 99.0, 'high' => 100.0,
        'distance_pct' => $distance, 'status' => $status, 'confirmed' => $confirmed, 'confirmations' => [[], [], []],
    ];
}

function ctxExtras(array $zones, ?string $lean4h = 'up'): array
{
    return [
        'mtf'  => $lean4h === null ? [] : [['tf' => '4H', 'lean' => $lean4h]],
        'plan' => ['price' => 100.0, 'supertrend_15m' => 'bullish', 'summary' => 's', 'zones' => $zones],
    ];
}

describe('entryContext', function () {
    it('calls an entry near a fully confirmed zone on its side a confirmed zone', function () {
        $ctx = TradeEventLogger::entryContext('LONG', ctxExtras([ctxZone('long', 'near', 3)]));

        expect($ctx['zone_quality'])->toBe('confirmed_zone')
            ->and($ctx['zone']['confirmed'])->toBe(3)
            ->and($ctx['zone']['total'])->toBe(3);
    });

    it('calls it unconfirmed when the zone is near but not fully confirmed', function () {
        $ctx = TradeEventLogger::entryContext('LONG', ctxExtras([ctxZone('long', 'in_zone', 1)]));

        expect($ctx['zone_quality'])->toBe('unconfirmed_zone');
    });

    it('calls it no zone when the only zone is far away or on the wrong side', function () {
        $far   = TradeEventLogger::entryContext('LONG', ctxExtras([ctxZone('long', 'far', 3)]));
        $wrong = TradeEventLogger::entryContext('LONG', ctxExtras([ctxZone('short', 'near', 3)]));

        expect($far['zone_quality'])->toBe('no_zone')
            ->and($far['zone'])->toBeNull()
            ->and($wrong['zone_quality'])->toBe('no_zone');
    });

    it('uses the nearest qualifying zone', function () {
        $ctx = TradeEventLogger::entryContext('SHORT', ctxExtras([
            ctxZone('short', 'near', 0, 0.9, 2),
            ctxZone('short', 'near', 3, 0.2, 1),
        ]));

        expect($ctx['zone']['number'])->toBe(1)->and($ctx['zone_quality'])->toBe('confirmed_zone');
    });

    it('works out whether the entry went with or against the 4H backdrop', function () {
        $with    = TradeEventLogger::entryContext('LONG', ctxExtras([], 'up'));
        $against = TradeEventLogger::entryContext('SHORT', ctxExtras([], 'up'));
        $mixed   = TradeEventLogger::entryContext('LONG', ctxExtras([], 'mixed'));
        $missing = TradeEventLogger::entryContext('LONG', ctxExtras([], null));

        expect($with['with_higher_tf'])->toBeTrue()
            ->and($against['with_higher_tf'])->toBeFalse()
            ->and($mixed['with_higher_tf'])->toBeNull()
            ->and($missing['with_higher_tf'])->toBeNull();
    });

    it('copes with an analysis result that has no plan at all', function () {
        $ctx = TradeEventLogger::entryContext('LONG', []);

        expect($ctx['zone_quality'])->toBe('no_zone')->and($ctx['with_higher_tf'])->toBeNull();
    });
});
