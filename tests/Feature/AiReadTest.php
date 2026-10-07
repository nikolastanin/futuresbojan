<?php

use App\Manual\CoinAdvisorAgent;
use App\Manual\HedgeAdvisorAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function aiUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

function aiPayload(array $overrides = []): array
{
    return array_merge([
        'symbol' => 'TAO_USDT',
        'price'  => 301.12,
        'signal' => [
            'direction' => 'SHORT', 'confidence' => 6, 'trend' => 'down', 'momentum' => 'down',
            'rsi' => 45.2, 'macd' => 'bearish', 'reasons' => ['1H trend DOWN [+2.25 SHORT]'],
        ],
        'levels' => ['r1' => 311.99, 's1' => 296.76, 'pivot' => 303.02],
        'extras' => [
            'mtf'    => [['tf' => '4H', 'trend' => 'up', 'rsi' => 52.8, 'macd' => 'bullish', 'lean' => 'up']],
            'levels' => ['weekly_pivot' => 302.97, 'poc' => 304.6, 'val' => 299.53, 'vah' => 311.23],
            'vs_btc' => ['24H' => ['coin' => 0.21, 'btc' => 0.01, 'diff' => 0.2]],
        ],
    ], $overrides);
}

$hedge = [
    'long_notional' => 1400, 'long_entry' => 304.9, 'long_pnl' => -31.59,
    'short_notional' => 375, 'short_entry' => 303.2, 'short_pnl' => 6.09,
    'combined_pnl' => -25.51, 'short_vs_long_pct' => 27, 'remaining_to_target' => 1025,
    'zone' => 'recovery', 'gauge_suggestion' => 'Good entry — add $100 to short',
];

it('uses the hedge advisor when a hedge pair is sent', function () use ($hedge) {
    HedgeAdvisorAgent::fake([[
        'outlook' => 'bearish', 'action' => 'add_short', 'conviction' => 'low',
        'summary' => 'Trend is down but 4H is up.', 'watch' => 'A 1H close above 303.02 flips it.',
    ]]);

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload(['hedge' => $hedge]))
        ->assertOk()
        ->assertJsonPath('data.kind', 'hedge')
        ->assertJsonPath('data.action', 'add_short');

    HedgeAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, "TRADER'S HEDGE")
        && str_contains($p->prompt, 'MULTI-TIMEFRAME')
        && str_contains($p->prompt, 'Strength vs BTC'));
});

it('uses the coin advisor with no position when there is no hedge', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'neutral', 'stance' => 'wait', 'conviction' => 'low',
        'summary' => 'Timeframes disagree.', 'position_note' => '', 'watch' => 'A 1H close below 296.76 confirms the breakdown.',
    ]]);

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload())
        ->assertOk()
        ->assertJsonPath('data.kind', 'coin')
        ->assertJsonPath('data.stance', 'wait')
        ->assertJsonPath('data.position_note', '');

    CoinAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'None — no open position in this coin')
        && str_contains($p->prompt, 'Higher-timeframe levels'));
});

it('includes a held position, its liquidation price and its lock in the coin prompt', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'bearish', 'stance' => 'short', 'conviction' => 'medium',
        'summary' => 'Down.', 'position_note' => 'Hold.', 'watch' => 'A 1H close above 303.02 flips it.',
    ]]);

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload(['position' => [
            'direction' => 'LONG', 'notional' => 1400, 'entry' => 304.9, 'pnl' => -31.59,
            'leverage' => 100, 'liquidation_price' => 301.8, 'stop_loss' => null, 'take_profit' => 330.0,
            'locked' => true, 'locked_until' => '2026-10-07T09:00:00+00:00',
        ]]))
        ->assertOk()
        ->assertJsonPath('data.position_note', 'Hold.');

    CoinAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'Open LONG')
        && str_contains($p->prompt, 'liquidation price 301.8')
        && str_contains($p->prompt, 'stop-loss: none')
        && str_contains($p->prompt, 'LOCKED on purpose until 2026-10-07T09:00:00+00:00'));
});

it('puts the computed trade plan zones in the prompt so the model refers to them', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'neutral', 'stance' => 'wait', 'conviction' => 'low',
        'summary' => 'x', 'position_note' => '', 'watch' => 'A 1H close above 309.29 flips it.',
    ]]);

    $extras = aiPayload()['extras'];
    $extras['plan'] = [
        'supertrend_15m' => 'bullish', 'atr_1h' => 3.19, 'atr_pct' => 1.05,
        'zones' => [[
            'side' => 'short', 'number' => 1, 'strength' => 'solid', 'low' => 309.29, 'high' => 309.6,
            'sources' => [['label' => 'PDH'], ['label' => 'VAH']],
            'distance_pct' => 1.9, 'status' => 'far', 'confirmed' => 1,
            'confirmations' => [['name' => 'SuperTrend 15M', 'state' => 'waiting']],
            'invalidation' => 309.6, 'stop' => 312.79, 'stop_distance_atr' => 1.0,
            'targets' => [['price' => 303.07, 'label' => 'WP+DP+POC']], 'rr' => 1.95,
        ]],
    ];

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload(['extras' => $extras]))
        ->assertOk();

    CoinAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'TRADE PLAN ZONES')
        && str_contains($p->prompt, 'SHORT zone 1 (solid): 309.29 - 309.6 [PDH+VAH]')
        && str_contains($p->prompt, 'invalidated by a 1H close above 309.6')
        && str_contains($p->prompt, 'targets 303.07; R:R 1.95'));
});

it('falls back to safe values when the model returns something outside the allowed set', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'moon', 'stance' => 'yolo', 'conviction' => 'extreme',
        'summary' => 'x', 'position_note' => 'y', 'watch' => 'z',
    ]]);

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload())
        ->assertOk()
        ->assertJsonPath('data.outlook', 'neutral')
        ->assertJsonPath('data.stance', 'wait')
        ->assertJsonPath('data.conviction', 'low');
});

it('fails soft with the provider message instead of throwing', function () {
    CoinAdvisorAgent::fake(fn () => throw new RuntimeException('Invalid API key'));

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload())
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'AI read failed: Invalid API key');
});

it('requires a symbol, a price and a signal', function () {
    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', ['symbol' => 'TAO_USDT'])
        ->assertRedirect()
        ->assertSessionHasErrors(['price', 'signal']);
});

it('puts a digest of the measured candles in the coin prompt, not the raw candles', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'bearish', 'stance' => 'wait', 'conviction' => 'low',
        'summary' => 'x', 'position_note' => '', 'watch' => 'A 1H close above 303.02 flips it.',
    ]]);

    $extras            = aiPayload()['extras'];
    $extras['candles'] = [
        '15M' => [
            'tf'       => '15M',
            'sequence' => ['summary' => '12 closed 15M candles: 4 up, 8 down (-0.90%); lower highs and lower lows.'],
            'candles'  => [
                ['ago' => 0, 'flags' => [['key' => 'bearish_engulfing', 'bias' => 'bearish', 'label' => "Bearish engulfing — the body swallowed the previous candle's body"]]],
                ['ago' => 1, 'flags' => []],
                ['ago' => 9, 'flags' => [['key' => 'doji', 'bias' => 'neutral', 'label' => 'Doji — too far back to be in the digest']]],
            ],
        ],
        '1H' => null,
        '4H' => null,
    ];

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload(['extras' => $extras]))
        ->assertOk();

    CoinAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'RECENT CANDLES')
        && str_contains($p->prompt, '- 15M: 12 closed 15M candles: 4 up, 8 down')
        && str_contains($p->prompt, '[last closed] Bearish engulfing')
        // Only the last four closed candles are digested, so the old doji is left out.
        && ! str_contains($p->prompt, 'too far back'));
});

it('puts the same candle digest in the hedge prompt', function () use ($hedge) {
    HedgeAdvisorAgent::fake([[
        'outlook' => 'neutral', 'action' => 'hold', 'conviction' => 'low', 'summary' => 'x', 'watch' => 'y',
    ]]);

    $extras            = aiPayload()['extras'];
    $extras['candles'] = ['4H' => [
        'tf' => '4H', 'sequence' => ['summary' => '12 closed 4H candles: 6 up, 6 down (+0.10%); no clear structure.'],
        'candles' => [['ago' => 0, 'flags' => [['key' => 'inside_bar', 'bias' => 'neutral', 'label' => 'Inside bar — compression']]]],
    ]];

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload(['hedge' => $hedge, 'extras' => $extras]))
        ->assertOk();

    HedgeAdvisorAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'RECENT CANDLES')
        && str_contains($p->prompt, '- 4H: 12 closed 4H candles')
        && str_contains($p->prompt, '[last closed] Inside bar'));
});

it('leaves the candle section out when no candles are sent', function () {
    CoinAdvisorAgent::fake([[
        'outlook' => 'neutral', 'stance' => 'wait', 'conviction' => 'low',
        'summary' => 'x', 'position_note' => '', 'watch' => 'y',
    ]]);

    $this->actingAs(aiUser())
        ->postJson('/futures/ai-read', aiPayload())
        ->assertOk();

    CoinAdvisorAgent::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'RECENT CANDLES'));
});
