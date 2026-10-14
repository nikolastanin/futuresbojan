<?php

use App\Manual\ChartFills;

/** A deal as MEXC lists it, with the fields the docs' example has. */
function chartDeal(array $over = []): array
{
    return $over + [
        'id'           => '9466823344',
        'symbol'       => 'TAO_USDT',
        'side'         => 1,
        'vol'          => 10,
        'price'        => 273.4,
        'fee'          => 0,
        'feeCurrency'  => 'USDT',
        'profit'       => 0,
        'category'     => 1,
        'orderId'      => '739211854408619008',
        'timestamp'    => 1_791_900_000_123,
        'positionMode' => 1,
        'taker'        => true,
    ];
}

it('turns a deal into a fill: seconds, the size in USDT, profit, fee and the order it belongs to', function () {
    $fills = ChartFills::fromDeals([
        chartDeal(['side' => 4, 'vol' => 25, 'price' => 274.12, 'profit' => 1.23456, 'fee' => 0.0123456789, 'orderId' => 77]),
    ], 0.01);

    expect($fills)->toBe([[
        'time'     => 1_791_900_000,
        'side'     => 4,
        'price'    => 274.12,
        'vol'      => 25.0,
        // 25 contracts of 0.01 coin at 274.12
        'notional' => 68.53,
        'profit'   => 1.2346,
        'fee'      => 0.012346,
        'order_id' => '77',
    ]]);
});

it('sorts the fills oldest first, and by order where two share a second', function () {
    $fills = ChartFills::fromDeals([
        chartDeal(['id' => 'c', 'timestamp' => 1_791_900_050_000, 'orderId' => 'b']),
        chartDeal(['id' => 'b', 'timestamp' => 1_791_900_001_999, 'orderId' => 'z']),
        chartDeal(['id' => 'a', 'timestamp' => 1_791_900_001_000, 'orderId' => 'a']),
    ], 1.0);

    expect(array_column($fills, 'order_id'))->toBe(['a', 'z', 'b'])
        ->and(array_column($fills, 'time'))->toBe([1_791_900_001, 1_791_900_001, 1_791_900_050]);
});

it('leaves out rows that cannot be a fill', function () {
    $fills = ChartFills::fromDeals([
        chartDeal(['id' => '1']),
        chartDeal(['id' => '2', 'price' => 0]),
        chartDeal(['id' => '3', 'vol' => 0]),
        chartDeal(['id' => '4', 'side' => 5]),
        chartDeal(['id' => '5', 'side' => 0]),
        chartDeal(['id' => '6', 'timestamp' => 0]),
        'not a row',
        null,
    ], 1.0);

    expect($fills)->toHaveCount(1)
        ->and($fills[0]['side'])->toBe(1);
});

it('counts a deal that is listed twice once', function () {
    // A new fill can shift the pages while they are being read, so the same deal turns up on two.
    $fills = ChartFills::fromDeals([chartDeal(), chartDeal(), chartDeal(['id' => '9466823345'])], 1.0);

    expect($fills)->toHaveCount(2);
});

it('has no amount in USDT without the coin\'s contract size, rather than a guess', function () {
    foreach ([null, 0.0] as $size) {
        expect(ChartFills::fromDeals([chartDeal()], $size)[0]['notional'])->toBeNull();
    }
});

it('reads the numbers whether MEXC sends them as numbers or as text', function () {
    $fills = ChartFills::fromDeals([
        chartDeal(['vol' => '4', 'price' => '100.5', 'profit' => '-0.4', 'timestamp' => '1791900000000', 'side' => '3']),
    ], 0.1);

    expect($fills[0])->toMatchArray(['time' => 1_791_900_000, 'side' => 3, 'price' => 100.5, 'vol' => 4.0, 'notional' => 40.2, 'profit' => -0.4]);
});

it('keeps big order numbers as text, so the browser cannot round them', function () {
    expect(ChartFills::fromDeals([chartDeal(['orderId' => 739211854408619008])], 1.0)[0]['order_id'])->toBe('739211854408619008');
});
