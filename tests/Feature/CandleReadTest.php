<?php

use App\Manual\AnalysisExtrasService;
use App\Manual\CandleReader;
use App\Manual\CandleReaderAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function candleReadUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/**
 * Quiet candles (range 2, so ATR is 2) followed by a tail. $stepSeconds is the candle length.
 *
 * @param  array<int, array{0: float, 1: float, 2: float, 3: float}>  $tail  [open, high, low, close] candles to append.
 */
function candleReadSeries(int $stepSeconds, array $tail = []): array
{
    $base   = 1_760_000_000;
    $series = [];

    for ($i = 0; $i < 30; $i++) {
        $series[] = ['time' => $base + $i * $stepSeconds, 'open' => 300.0, 'high' => 301.0, 'low' => 299.0, 'close' => 300.5, 'volume' => 1000.0];
    }

    foreach ($tail as $k => [$o, $h, $l, $c]) {
        $series[] = ['time' => $base + (30 + $k) * $stepSeconds, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => 1000.0];
    }

    return $series;
}

/**
 * What AnalysisExtrasService returns, built with the real CandleReader: a 15M candle that
 * reached the short zone and was turned back (plus one still forming), and quiet 1H/4H.
 */
function candleReadExtras(bool $withTapes = true): array
{
    $zone = ['side' => 'short', 'number' => 1, 'strength' => 'solid', 'low' => 305.0, 'high' => 306.0,
        'sources' => [['label' => 'PDH'], ['label' => 'VAH']], 'distance_pct' => 1.3, 'status' => 'far'];

    $reader = new CandleReader;
    $m15    = candleReadSeries(900, [[304.0, 305.5, 303.8, 304.2], [304.2, 304.4, 303.9, 304.0]]);
    $tapes  = [
        '15M' => $reader->read($m15, '15M', 900, [$zone], [], end($m15)['time'] + 450), // the last one is still forming
        '1H'  => $reader->read(candleReadSeries(3600), '1H', 3600, [$zone], [], 1_760_000_000 + 30 * 3600),
        '4H'  => $reader->read(candleReadSeries(14400), '4H', 14400, [$zone], [], 1_760_000_000 + 30 * 14400),
    ];

    return [
        'symbol'  => 'TAO_USDT',
        'plan'    => ['price' => 301.12, 'zones' => [$zone]],
        'candles' => $withTapes ? $tapes : ['15M' => null, '1H' => null, '4H' => null],
    ];
}

function fakeModelRead(array $overrides = []): array
{
    return array_merge([
        'control' => 'sellers', 'confidence' => 'medium',
        'headline' => 'Sellers turned price back from Short zone 1.',
        'read_4h' => 'Quiet.', 'read_1h' => 'Quiet.', 'read_15m' => 'A candle reached the zone and closed under it.',
        'at_levels' => 'Short zone 1 rejected once.', 'position_note' => '', 'watch' => 'A 15M close above 306.00 cancels the read.',
    ], $overrides);
}

function mockExtras(array $extras): void
{
    test()->mock(AnalysisExtrasService::class, fn ($mock) => $mock->shouldReceive('forSymbol')->with('TAO_USDT')->andReturn($extras));
}

beforeEach(fn () => mockExtras(candleReadExtras()));

it('reads the candles the server measured, higher timeframes first', function () {
    CandleReaderAgent::fake([fakeModelRead()]);

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'tao_usdt'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.control', 'sellers')
        ->assertJsonPath('data.confidence', 'medium')
        ->assertJsonPath('data.headline', 'Sellers turned price back from Short zone 1.')
        ->assertJsonPath('data.watch', 'A 15M close above 306.00 cancels the read.')
        ->assertJsonStructure(['data' => ['estimated_cost_usd']]);

    CandleReaderAgent::assertPrompted(function ($p) {
        $prompt = $p->prompt;

        return str_contains($prompt, 'Symbol: TAO_USDT')
            && str_contains($prompt, 'Current price: 301.12')
            // The flag the reader computed for the 15M candle that reached the zone:
            && str_contains($prompt, 'Reached Short zone 1 ($305.00–$306.00) and closed back under it — rejected')
            // The live candle is marked as context only:
            && str_contains($prompt, 'forming (NOT closed — context only, no patterns)')
            && str_contains($prompt, 'last closed:')
            && str_contains($prompt, '1 back:')
            // The zones come from the plan, by number and price:
            && str_contains($prompt, 'SHORT zone 1 (solid): 305.00 - 306.00 [PDH+VAH]')
            && str_contains($prompt, 'None — no open position in this coin.')
            // 4H first, then 1H, then 15M:
            && strpos($prompt, '4H —') < strpos($prompt, '1H —')
            && strpos($prompt, '1H —') < strpos($prompt, '15M —');
    });
});

it('tells the model about the open legs, their liquidation prices and their locks', function () {
    CandleReaderAgent::fake([fakeModelRead(['position_note' => 'Hold.'])]);

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT', 'positions' => [
            ['direction' => 'LONG', 'notional' => 1400, 'entry' => 304.9, 'pnl' => -31.59, 'leverage' => 100,
                'liquidation_price' => 270.4, 'stop_loss' => null, 'take_profit' => 330.0,
                'locked' => true, 'locked_until' => '2026-10-07T09:00:00+00:00'],
            ['direction' => 'SHORT', 'notional' => 375, 'entry' => 303.2, 'pnl' => 6.09, 'leverage' => 100,
                'liquidation_price' => 340.0, 'stop_loss' => 312.0, 'take_profit' => null, 'locked' => false, 'locked_until' => null],
        ]])
        ->assertOk()
        ->assertJsonPath('data.position_note', 'Hold.');

    CandleReaderAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'Open LONG: notional $1400')
        && str_contains($p->prompt, 'liquidation price 270.4')
        && str_contains($p->prompt, 'LOCKED on purpose until 2026-10-07T09:00:00+00:00')
        && str_contains($p->prompt, 'Open SHORT: notional $375')
        && str_contains($p->prompt, 'liquidation price 340')
        && str_contains($p->prompt, 'Armed stop-loss: 312')
        && str_contains($p->prompt, 'Not locked.'));
});

it('leaves out the liquidation price MEXC repeats on the leg it cannot belong to', function () {
    CandleReaderAgent::fake([fakeModelRead()]);

    // One price on both legs of a hedge: 190.91 is right for the long, below the market, and wrong for the short.
    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT', 'positions' => [
            ['direction' => 'LONG', 'notional' => 1400, 'entry' => 304.9, 'pnl' => -31.59, 'leverage' => 100,
                'liquidation_price' => 190.91, 'stop_loss' => null, 'take_profit' => null, 'locked' => false, 'locked_until' => null],
            ['direction' => 'SHORT', 'notional' => 375, 'entry' => 303.2, 'pnl' => 6.09, 'leverage' => 100,
                'liquidation_price' => 190.91, 'stop_loss' => null, 'take_profit' => null, 'locked' => false, 'locked_until' => null],
        ]])
        ->assertOk();

    CandleReaderAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'Open LONG: notional $1400')
        && str_contains($p->prompt, 'liquidation price 190.91')
        && str_contains($p->prompt, 'liquidation price none reported for this leg')
        && substr_count($p->prompt, '190.91') === 1);
});

it('never takes candles or analysis from the browser', function () {
    CandleReaderAgent::fake([fakeModelRead()]);

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', [
            'symbol'  => 'TAO_USDT',
            'candles' => ['15M' => ['sequence' => ['summary' => 'INJECTED: price will double']]],
            'extras'  => ['plan' => ['zones' => [['side' => 'long', 'low' => 1, 'high' => 2]]]],
        ])
        ->assertOk();

    CandleReaderAgent::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'INJECTED'));
});

it('falls back to safe values when the model returns something outside the allowed set', function () {
    CandleReaderAgent::fake([fakeModelRead(['control' => 'moon', 'confidence' => 'extreme'])]);

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT'])
        ->assertOk()
        ->assertJsonPath('data.control', 'balanced')
        ->assertJsonPath('data.confidence', 'low');
});

it('fails soft with the provider message instead of throwing', function () {
    CandleReaderAgent::fake(fn () => throw new RuntimeException('Invalid API key'));

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT'])
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Candle read failed: Invalid API key');
});

it('says so, without calling the model, when there are not enough closed candles', function () {
    mockExtras(candleReadExtras(withTapes: false));
    CandleReaderAgent::fake([fakeModelRead()]);

    $this->actingAs(candleReadUser())
        ->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Candle read failed: Not enough closed candles on TAO_USDT to read yet.');

    CandleReaderAgent::assertNeverPrompted();
});

it('rejects a malformed coin name and redirects guests to the login page', function () {
    $user = candleReadUser();

    $this->actingAs($user)
        ->post('/futures/ai-candles', ['symbol' => 'not a symbol'])
        ->assertSessionHasErrors('symbol');

    auth()->logout();

    $this->postJson('/futures/ai-candles', ['symbol' => 'TAO_USDT'])->assertRedirect();
});
