<?php

use App\Manual\AnalysisExtrasService;
use App\Manual\PositionBriefAgent;
use App\Manual\PositionBriefService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function briefUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/** What AnalysisExtrasService returns for TAO: down on 4H, a short zone overhead, a long zone below. */
function briefExtras(): array
{
    $zone = fn (string $side, int $n, float $low, float $high, array $sources) => [
        'side' => $side, 'number' => $n, 'strength' => 'solid', 'low' => $low, 'high' => $high,
        'sources' => array_map(fn ($l) => ['label' => $l], $sources),
        'distance_pct' => 3.8, 'status' => 'far', 'confirmed' => 1,
        'confirmations' => [['name' => 'SuperTrend 15M', 'state' => 'waiting']],
        'invalidation' => $high, 'stop' => $high + 4, 'stop_distance_atr' => 1.0, 'targets' => [], 'rr' => 1.9,
    ];

    return [
        'symbol'  => 'TAO_USDT',
        'mtf'     => [['tf' => '4H', 'trend' => 'down', 'rsi' => 41.2, 'macd' => 'bearish', 'lean' => 'down']],
        'levels'  => ['weekly_pivot' => 302.97, 'poc' => 303.8, 'val' => 299.53, 'prior_week_high' => null],
        'vs_btc'  => ['24H' => ['coin' => -1.2, 'btc' => 0.1, 'diff' => -1.3]],
        'plan'    => [
            'price' => 291.48, 'atr_1h' => 4.1, 'atr_pct' => 1.4, 'supertrend_15m' => 'bearish',
            'zones' => [
                $zone('short', 1, 302.71, 304.0, ['PDH', 'VAH']),
                $zone('long', 1, 285.05, 285.05, ['MP']),
            ],
        ],
        'candles' => ['15M' => null, '1H' => null, '4H' => null],
    ];
}

function briefLong(array $overrides = []): array
{
    return array_merge([
        'direction' => 'LONG', 'notional' => 1637.47, 'entry' => 291.79, 'pnl' => -49.07, 'leverage' => 100,
        'liquidation_price' => 268.0, 'stop_loss' => null, 'take_profit' => null,
        'locked' => true, 'locked_until' => '2026-10-09T09:00:00+00:00',
    ], $overrides);
}

function briefShort(array $overrides = []): array
{
    return array_merge([
        'direction' => 'SHORT', 'notional' => 398.0, 'entry' => 289.5, 'pnl' => -2.7, 'leverage' => 100,
        'liquidation_price' => 0, 'stop_loss' => null, 'take_profit' => null, 'locked' => false, 'locked_until' => null,
    ], $overrides);
}

function briefPayload(array $overrides = []): array
{
    return array_merge([
        'symbol'    => 'TAO_USDT',
        'language'  => 'en',
        'positions' => [briefLong(), briefShort()],
        'signal'    => [
            'direction' => 'SHORT', 'confidence' => 3, 'trend' => 'down', 'momentum' => 'neutral', 'rsi' => 41.2,
            'macd' => 'bearish', 'volatility_pct' => 1.39, 'levels' => ['pivot' => 296.5, 's1' => 288.2, 'r1' => 301.9],
        ],
        'risk' => [
            'radar_status' => 'watch', 'equity' => 143.6, 'typical_hour_pct' => 19,
            'coin' => [
                'net_notional' => 1779, 'hedge_ratio' => 0.18, 'combined_pnl' => -51.77,
                'break_even' => 307.41, 'equity_zero' => 277.52,
                'liq' => ['side' => 'long', 'price' => 268.0, 'distance_pct' => 8.1, 'distance_atr' => 5.8],
            ],
        ],
    ], $overrides);
}

function briefReply(array $overrides = []): array
{
    return array_merge([
        'long_note'   => "Let's wait for a 1H close above 302.71 to see if our plan works out.",
        'short_note'  => 'Adding makes sense only if price reaches the zone and the candles turn back.',
        'watch_price' => '302.71',
        'watch_when'  => '1H close above',
    ], $overrides);
}

beforeEach(function () {
    $this->mock(AnalysisExtrasService::class, fn ($mock) => $mock->shouldReceive('forSymbol')->with('TAO_USDT')->andReturn(briefExtras()));
});

it('writes a friendly note per position and the price it hinges on', function () {
    PositionBriefAgent::fake([briefReply()]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.language', 'en')
        ->assertJsonPath('data.long_note', "Let's wait for a 1H close above 302.71 to see if our plan works out.")
        ->assertJsonPath('data.short_note', 'Adding makes sense only if price reaches the zone and the candles turn back.')
        ->assertJsonPath('data.watch_price', 302.71)
        ->assertJsonPath('data.watch_label', 'Short zone 1 lower edge')
        ->assertJsonPath('data.watch_when', '1H close above')
        ->assertJsonStructure(['data' => ['estimated_cost_usd']]);
});

it('gives the model everything on the analysis tab, the positions and the live risk', function () {
    PositionBriefAgent::fake([briefReply()]);

    $this->actingAs(briefUser())->postJson('/futures/ai-brief', briefPayload())->assertOk();

    PositionBriefAgent::assertPrompted(function ($p) {
        $prompt = $p->prompt;

        return str_contains($prompt, 'LANGUAGE: English')
            // The same market description the other AI reads use:
            && str_contains($prompt, 'MULTI-TIMEFRAME')
            && str_contains($prompt, 'TRADE PLAN ZONES')
            && str_contains($prompt, 'SHORT zone 1 (solid): 302.71 - 304')
            // The legs, with the lock:
            && str_contains($prompt, 'THE TRADER\'S OPEN POSITIONS IN THIS COIN')
            && str_contains($prompt, 'LONG: notional $1637.47, entry 291.79')
            && str_contains($prompt, 'LOCKED on purpose until 2026-10-09T09:00:00+00:00')
            && str_contains($prompt, 'SHORT: notional $398')
            && str_contains($prompt, 'Not locked.')
            // The live risk figures:
            && str_contains($prompt, 'risk radar WATCH')
            && str_contains($prompt, 'about 19% of equity')
            && str_contains($prompt, 'net LONG $1779 (the smaller leg covers 18% of the larger one)')
            && str_contains($prompt, 'break-even 307.41')
            && str_contains($prompt, 'Nearest liquidation: long at 268, 8.1% away (5.8 hourly ranges)')
            // The only prices it may cite:
            && str_contains($prompt, 'PRICES YOU MAY CITE')
            && str_contains($prompt, '- Short zone 1 lower edge: 302.71')
            && str_contains($prompt, '- Short zone 1 upper edge: 304.00')
            && str_contains($prompt, '- weekly pivot: 302.97')
            && str_contains($prompt, '- combined break-even: 307.41')
            && str_contains($prompt, '- your long liquidation price: 268.00')
            && str_contains($prompt, '- your short entry: 289.50');
    });
});

it('asks for Serbian in Latin script when that is the chosen language', function () {
    PositionBriefAgent::fake([briefReply(['long_note' => 'Sačekajmo 1H close iznad 302.71 da vidimo da li naš plan radi.'])]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload(['language' => 'sr']))
        ->assertOk()
        ->assertJsonPath('data.language', 'sr')
        ->assertJsonPath('data.long_note', 'Sačekajmo 1H close iznad 302.71 da vidimo da li naš plan radi.');

    PositionBriefAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'LANGUAGE: Serbian (Latin script)'));
});

it('drops a watch price the model invented', function () {
    PositionBriefAgent::fake([briefReply(['watch_price' => '350.00', 'watch_when' => '1H close above'])]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload())
        ->assertOk()
        ->assertJsonPath('data.watch_price', null)
        ->assertJsonPath('data.watch_label', null)
        ->assertJsonPath('data.watch_when', '');
});

it('understands a Serbian decimal comma in the watch price', function () {
    PositionBriefAgent::fake([briefReply(['watch_price' => '302,71'])]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload(['language' => 'sr']))
        ->assertOk()
        ->assertJsonPath('data.watch_price', 302.71);
});

it('says nothing for a side the trader does not hold', function () {
    PositionBriefAgent::fake([briefReply(['short_note' => 'A comment about a short that does not exist.'])]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload(['positions' => [briefLong()]]))
        ->assertOk()
        ->assertJsonPath('data.short_note', '')
        ->assertJsonPath('data.long_note', "Let's wait for a 1H close above 302.71 to see if our plan works out.");
});

it('measures the market itself and never takes the analysis from the browser', function () {
    PositionBriefAgent::fake([briefReply()]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload([
            'extras'  => ['plan' => ['zones' => [['side' => 'long', 'number' => 9, 'low' => 1, 'high' => 2]]]],
            'candles' => ['15M' => ['sequence' => ['summary' => 'INJECTED: price will double']]],
        ]))
        ->assertOk();

    PositionBriefAgent::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'INJECTED') || str_contains($p->prompt, 'zone 9'));
});

it('works without the optional indicator snapshot and risk figures', function () {
    PositionBriefAgent::fake([briefReply()]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', ['symbol' => 'TAO_USDT', 'positions' => [briefLong()]])
        ->assertOk()
        ->assertJsonPath('data.language', 'en');

    PositionBriefAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'RISK (computed live')
        && str_contains($p->prompt, 'risk radar n/a'));
});

it('fails soft when the model has nothing usable to say', function () {
    PositionBriefAgent::fake([briefReply(['long_note' => '   ', 'short_note' => ''])]);

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload())
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The assistant is unavailable: The assistant had nothing usable to say.');
});

it('fails soft with the provider message instead of throwing', function () {
    PositionBriefAgent::fake(fn () => throw new RuntimeException('Invalid API key'));

    $this->actingAs(briefUser())
        ->postJson('/futures/ai-brief', briefPayload())
        ->assertStatus(502)
        ->assertJsonPath('message', 'The assistant is unavailable: Invalid API key');
});

it('rejects a bad request and redirects guests to the login page', function () {
    $user = briefUser();

    $this->actingAs($user)->postJson('/futures/ai-brief', ['symbol' => 'TAO_USDT'])->assertRedirect()->assertSessionHasErrors('positions');
    $this->actingAs($user)->postJson('/futures/ai-brief', briefPayload(['language' => 'de']))->assertRedirect()->assertSessionHasErrors('language');
    $this->actingAs($user)->postJson('/futures/ai-brief', briefPayload(['symbol' => 'not a symbol']))->assertRedirect()->assertSessionHasErrors('symbol');

    auth()->logout();

    $this->postJson('/futures/ai-brief', briefPayload())->assertRedirect();
});

describe('the price check', function () {
    $citable = ['Short zone 1 lower edge' => 302.71, 'weekly pivot' => 302.97, 'combined break-even' => 1234.5];

    it('matches the formats a model may write', function () use ($citable) {
        expect(PositionBriefService::matchCitable('302.71', $citable))->toBe(['price' => 302.71, 'label' => 'Short zone 1 lower edge'])
            ->and(PositionBriefService::matchCitable('$302.71', $citable)['price'])->toBe(302.71)
            ->and(PositionBriefService::matchCitable('302,71', $citable)['price'])->toBe(302.71)
            ->and(PositionBriefService::matchCitable('1,234.50', $citable)['price'])->toBe(1234.5)
            ->and(PositionBriefService::matchCitable(302.97, $citable)['label'])->toBe('weekly pivot');
    });

    it('allows a hair of rounding but nothing like a different level', function () use ($citable) {
        // 302.8 is 0.03% from 302.71 and well inside; 303.5 is a different price entirely.
        expect(PositionBriefService::matchCitable('302.8', $citable)['label'])->toBe('Short zone 1 lower edge')
            ->and(PositionBriefService::matchCitable('303.5', $citable))->toBeNull();
    });

    it('rejects anything that is not a price on the list', function () use ($citable) {
        expect(PositionBriefService::matchCitable('', $citable))->toBeNull()
            ->and(PositionBriefService::matchCitable('soon', $citable))->toBeNull()
            ->and(PositionBriefService::matchCitable(null, $citable))->toBeNull()
            ->and(PositionBriefService::matchCitable('0', $citable))->toBeNull()
            ->and(PositionBriefService::matchCitable('302.71', []))->toBeNull();
    });
});

describe('cleaning a note', function () {
    it('flattens it to plain text on one line and bounds its length', function () {
        expect(PositionBriefService::cleanNote("  Let's <b>wait</b>\n\n for it.  ", true))->toBe("Let's wait for it.")
            ->and(mb_strlen(PositionBriefService::cleanNote(str_repeat('a', 500), true)))->toBe(320)
            ->and(PositionBriefService::cleanNote('x', false))->toBe('')
            ->and(PositionBriefService::cleanNote(['not text'], true))->toBe('');
    });
});

describe('which prices are offered', function () {
    it('leaves out market levels far from today’s price but keeps the trader’s own however far', function () {
        $extras = briefExtras();
        $extras['levels']['prior_month_low'] = 213.42; // 27% below 291.48: no target for a waiting comment

        $citable = PositionBriefService::citablePrices(
            $extras,
            ['levels' => ['r1' => 301.9, 'week_low' => 190.0]],
            [briefLong(['entry' => 150.0, 'liquidation_price' => 120.0])],
            ['coin' => ['break_even' => 340.0]],
        );

        expect($citable)->toHaveKey('weekly pivot')
            ->and($citable)->toHaveKey('r1')
            ->and($citable)->not->toHaveKey('prior month low')
            ->and($citable)->not->toHaveKey('week low')
            // Own prices and the break-even stay, even 50% away.
            ->and($citable['your long entry'])->toBe(150.0)
            ->and($citable['your long liquidation price'])->toBe(120.0)
            ->and($citable['combined break-even'])->toBe(340.0);
    });

    it('says "none reported" for a leg the exchange has no liquidation price for', function () {
        PositionBriefAgent::fake([briefReply()]);

        $this->actingAs(briefUser())->postJson('/futures/ai-brief', briefPayload())->assertOk();

        PositionBriefAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'liquidation price none reported'));
    });

    it('neither offers nor describes the liquidation price MEXC repeats on the leg it cannot belong to', function () {
        // A hedge reports one price, 190.91, on both legs. The market is at 291.48, so it is the
        // long's liquidation and cannot be the short's.
        $positions = [briefLong(['liquidation_price' => 190.91]), briefShort(['liquidation_price' => 190.91])];

        $citable = PositionBriefService::citablePrices(briefExtras(), [], $positions, []);

        expect($citable['your long liquidation price'])->toBe(190.91)
            ->and($citable)->not->toHaveKey('your short liquidation price');

        PositionBriefAgent::fake([briefReply()]);

        $this->actingAs(briefUser())
            ->postJson('/futures/ai-brief', briefPayload(['positions' => $positions]))
            ->assertOk();

        PositionBriefAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'liquidation price 190.91')
            && str_contains($p->prompt, 'liquidation price none reported for this leg')
            && str_contains($p->prompt, '- your long liquidation price: 190.91')
            && ! str_contains($p->prompt, '- your short liquidation price'));
    });

    it('does not let the model hang its comment on a liquidation price that is not the leg\'s', function () {
        // The wrong-side price is no longer on the list, so naming it as the price to watch is dropped.
        PositionBriefAgent::fake([briefReply(['watch_price' => '190.91'])]);

        $this->actingAs(briefUser())
            ->postJson('/futures/ai-brief', briefPayload(['positions' => [briefShort(['liquidation_price' => 190.91])]]))
            ->assertOk()
            ->assertJsonPath('data.watch_price', null)
            ->assertJsonPath('data.watch_label', null);
    });
});
