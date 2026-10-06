<?php

use App\Manual\AnalysisExtrasService;
use App\Manual\DayCoachAgent;
use App\Manual\ManualTradingConfig;
use App\Models\PositionLock;
use App\Models\TradeEvent;
use App\Models\User;
use App\Services\MexcFuturesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function gradeUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/** A trade that closed at $time (UTC) on the test day. */
function closedAt(string $time, float $pnl, float $hold): array
{
    $closed = Carbon::parse("2026-10-06 {$time}", 'UTC')->getTimestampMs();

    return [
        'symbol' => 'TAO_USDT', 'direction' => 'LONG', 'pnl' => $pnl, 'leverage' => 100,
        'opened_at' => $closed - (int) ($hold * 60000), 'closed_at' => $closed, 'hold_minutes' => $hold,
    ];
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-06 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

describe('decision logging', function () {
    it('logs a lock, an early unlock of a timed lock, and an unlock of an indefinite one', function () {
        $user = gradeUser();

        // Timed lock, then released with time still on the clock.
        $this->actingAs($user)->postJson('/futures/position-locks/toggle', ['symbol' => 'TAO_USDT', 'positionType' => 1, 'hours' => 4])->assertOk();
        Carbon::setTestNow('2026-10-06 13:00:00');
        $this->actingAs($user)->postJson('/futures/position-locks/toggle', ['symbol' => 'TAO_USDT', 'positionType' => 1])->assertOk();

        // Indefinite lock, then released.
        $this->actingAs($user)->postJson('/futures/position-locks/toggle', ['symbol' => 'TAO_USDT', 'positionType' => 2])->assertOk();
        $this->actingAs($user)->postJson('/futures/position-locks/toggle', ['symbol' => 'TAO_USDT', 'positionType' => 2])->assertOk();

        expect(TradeEvent::orderBy('id')->pluck('type')->all())->toBe(['lock', 'unlock_early', 'lock', 'unlock']);

        $early = TradeEvent::where('type', 'unlock_early')->first();

        expect($early->direction)->toBe('LONG')
            ->and($early->details['minutes_remaining'])->toBe(180);
    });

    it('logs an attempt to touch a locked position', function () {
        PositionLock::create(['symbol' => 'TAO_USDT', 'position_type' => 1, 'locked_until' => null]);

        $this->actingAs(gradeUser())
            ->postJson('/futures/flash-close', ['symbol' => 'TAO_USDT', 'holdVol' => 10, 'positionType' => 1])
            ->assertStatus(422);

        $event = TradeEvent::first();

        expect($event->type)->toBe('blocked_attempt')
            ->and($event->symbol)->toBe('TAO_USDT')
            ->and($event->direction)->toBe('LONG');
    });

    it('logs a real entry and attaches what the analysis said once the response is sent', function () {
        ManualTradingConfig::setRealTradingEnabled(true);

        $this->mock(MexcFuturesService::class, function ($mock) {
            $mock->shouldReceive('getTickerMap')->andReturn(['TAO_USDT' => 300.0]);
            $mock->shouldReceive('getContractSizeMap')->andReturn(['TAO_USDT' => 0.01]);
            $mock->shouldReceive('placeOrder')->once()->andReturn(['orderId' => 1]);
        });

        $this->mock(AnalysisExtrasService::class, function ($mock) {
            $mock->shouldReceive('forSymbol')->with('TAO_USDT')->andReturn([
                'mtf'  => [['tf' => '4H', 'lean' => 'up']],
                'plan' => [
                    'price' => 300.0, 'supertrend_15m' => 'bullish', 'summary' => 'Long zone 1 is the only confirmed setup.',
                    'zones' => [[
                        'side' => 'long', 'number' => 1, 'strength' => 'strong', 'low' => 298.0, 'high' => 299.5,
                        'distance_pct' => 0.17, 'status' => 'near', 'confirmed' => 3,
                        'confirmations' => [[], [], []],
                    ]],
                ],
            ]);
        });

        $this->actingAs(gradeUser())
            ->postJson('/futures/orders', ['orders' => [[
                'symbol' => 'TAO_USDT', 'price' => 0, 'marginUsdt' => 1, 'leverage' => 100, 'side' => 1, 'type' => 5, 'openType' => 2,
            ]]])
            ->assertOk();

        $event = TradeEvent::first();

        expect($event->type)->toBe('entry')
            ->and($event->direction)->toBe('LONG')
            ->and($event->details['leverage'])->toBe(100)
            ->and($event->context['zone_quality'])->toBe('confirmed_zone')
            ->and($event->context['with_higher_tf'])->toBeTrue()
            ->and($event->context['zone']['strength'])->toBe('strong');
    });

    it('logs a reduce order but takes no analysis snapshot for it', function () {
        ManualTradingConfig::setRealTradingEnabled(true);

        $this->mock(MexcFuturesService::class, function ($mock) {
            $mock->shouldReceive('getTickerMap')->andReturn(['TAO_USDT' => 300.0]);
            $mock->shouldReceive('getContractSizeMap')->andReturn(['TAO_USDT' => 0.01]);
            $mock->shouldReceive('placeOrder')->andReturn(['orderId' => 2]);
        });

        $this->mock(AnalysisExtrasService::class, fn ($mock) => $mock->shouldNotReceive('forSymbol'));

        $this->actingAs(gradeUser())
            ->postJson('/futures/orders', ['orders' => [[
                'symbol' => 'TAO_USDT', 'price' => 0, 'marginUsdt' => 1, 'leverage' => 100, 'side' => 2, 'type' => 5, 'openType' => 2,
            ]]])
            ->assertOk();

        $event = TradeEvent::first();

        expect($event->type)->toBe('reduce')->and($event->direction)->toBe('SHORT')->and($event->context)->toBeNull();
    });

    it('does not log an order that failed', function () {
        ManualTradingConfig::setRealTradingEnabled(true);

        $this->mock(MexcFuturesService::class, function ($mock) {
            $mock->shouldReceive('getTickerMap')->andReturn(['TAO_USDT' => 300.0]);
            $mock->shouldReceive('getContractSizeMap')->andReturn(['TAO_USDT' => 0.01]);
            $mock->shouldReceive('placeOrder')->andThrow(new RuntimeException('Insufficient margin'));
        });

        $this->actingAs(gradeUser())
            ->postJson('/futures/orders', ['orders' => [[
                'symbol' => 'TAO_USDT', 'price' => 0, 'marginUsdt' => 1, 'leverage' => 100, 'side' => 1, 'type' => 5, 'openType' => 2,
            ]]])
            ->assertStatus(500);

        expect(TradeEvent::count())->toBe(0);
    });

    it('never lets a logging failure break the action it was logging', function () {
        Schema::drop('trade_events');

        $this->actingAs(gradeUser())
            ->postJson('/futures/position-locks/toggle', ['symbol' => 'TAO_USDT', 'positionType' => 1])
            ->assertOk()
            ->assertJsonPath('locked', true);

        expect(PositionLock::count())->toBe(1);
    });
});

describe('the daily grade endpoint', function () {
    it('grades the day from MEXC trades and logged decisions, with a 7-day trend', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([
            closedAt('09:00:00', 20.0, 120.0),
            closedAt('10:00:00', -10.0, 5.0),
        ]));

        TradeEvent::create(['type' => 'lock', 'symbol' => 'TAO_USDT', 'direction' => 'LONG', 'occurred_at' => '2026-10-06 08:00:00']);

        $response = $this->actingAs(gradeUser())->getJson('/futures/daily-grade')->assertOk();

        $response->assertJsonPath('data.grade.date', '2026-10-06')
            ->assertJsonPath('data.grade.stats.trades', 2)
            ->assertJsonPath('data.grade.stats.net_pnl', 10)
            ->assertJsonPath('data.grade.stats.locks', 1);

        expect($response->json('data.grade.score'))->toBeInt()
            ->and($response->json('data.trend'))->toHaveCount(7)
            ->and(array_column($response->json('data.trend'), 'date')[6])->toBe('2026-10-06');
    });

    it('puts each trade and each event on its own UTC day', function () {
        $yesterday = [
            'symbol' => 'BTC_USDT', 'direction' => 'SHORT', 'pnl' => 5.0, 'leverage' => 50, 'opened_at' => null,
            'closed_at' => Carbon::parse('2026-10-05 23:30:00', 'UTC')->getTimestampMs(), 'hold_minutes' => null,
        ];

        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([$yesterday, closedAt('09:00:00', 3.0, 60.0)]));

        $trend = $this->actingAs(gradeUser())->getJson('/futures/daily-grade?date=2026-10-06')->json('data.trend');
        $byDate = array_column($trend, null, 'date');

        expect($byDate['2026-10-05']['trades'])->toBe(1)->and($byDate['2026-10-06']['trades'])->toBe(1);
    });

    it('still grades from the trades alone when the decision log cannot be read', function () {
        Schema::drop('trade_events');

        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([
            closedAt('09:00:00', 20.0, 120.0),
        ]));

        $response = $this->actingAs(gradeUser())->getJson('/futures/daily-grade')->assertOk();

        expect($response->json('data.grade.score'))->toBeInt()
            ->and($response->json('data.grade.partial'))->toBeTrue()
            ->and($response->json('data.grade.missing'))->toContain('process');
    });

    it('never grades a future date', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([]));

        $this->actingAs(gradeUser())
            ->getJson('/futures/daily-grade?date=2030-01-01')
            ->assertOk()
            ->assertJsonPath('data.grade.date', '2026-10-06');
    });

    it('reports an error rather than grading around missing MEXC history', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andThrow(new RuntimeException('API Key expired')));

        $this->actingAs(gradeUser())
            ->getJson('/futures/daily-grade')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'API Key expired');
    });

    it('rejects a malformed date', function () {
        $this->actingAs(gradeUser())
            ->getJson('/futures/daily-grade?date=yesterday')
            ->assertRedirect()
            ->assertSessionHasErrors('date');
    });
});

describe('the AI coach', function () {
    it('reviews a recomputed grade, and the prompt carries the numbers and the flags', function () {
        DayCoachAgent::fake([[
            'headline' => 'Quick closes cost you.', 'went_well' => 'You kept the loss small.',
            'cost_you' => 'One trade closed in 5 minutes.', 'focus_tomorrow' => 'Hold every trade 30 minutes.',
        ]]);

        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([
            closedAt('09:00:00', 20.0, 120.0), closedAt('10:00:00', -10.0, 5.0),
        ]));

        $this->actingAs(gradeUser())
            ->postJson('/futures/daily-grade/coach', [])
            ->assertOk()
            ->assertJsonPath('data.headline', 'Quick closes cost you.')
            ->assertJsonPath('data.focus_tomorrow', 'Hold every trade 30 minutes.');

        DayCoachAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'Grade:')
            && str_contains($p->prompt, 'PARTIAL, no data for: process')
            && str_contains($p->prompt, '1 of 2 closed trades were held under 15 minutes')
            && str_contains($p->prompt, 'TAO_USDT LONG: PnL -10, held 5 min (hasty)'));
    });

    it('has nothing to review on an empty day', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([]));

        $this->actingAs(gradeUser())
            ->postJson('/futures/daily-grade/coach', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nothing to review for this day yet.');
    });

    it('fails soft with the provider message', function () {
        DayCoachAgent::fake(fn () => throw new RuntimeException('Invalid API key'));

        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getClosedTrades')->andReturn([closedAt('09:00:00', 5.0, 60.0)]));

        $this->actingAs(gradeUser())
            ->postJson('/futures/daily-grade/coach', [])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Review failed: Invalid API key');
    });
});
