<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser), so the factory's random
// users would be bounced to the login page.
const ALLOWED_EMAIL = 'bukvicbojan@gmail.com';

/** Fakes MEXC's public kline endpoint: a gentle uptrend, one candle per interval step. */
function fakeMexcKlines(): void
{
    $stepFor = fn (string $interval) => match ($interval) {
        'Min5' => 300, 'Min15' => 900, 'Min60' => 3600, 'Hour4' => 14400, 'Day1' => 86400,
        default => 3600,
    };

    Http::fake([
        '*contract/kline/*' => function (Request $request) use ($stepFor) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $step  = $stepFor($query['interval'] ?? 'Min60');
            $start = (int) $query['start'];
            $end   = (int) $query['end'];

            $data = ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []];

            for ($i = 0, $t = $start - ($start % $step); $t <= $end; $t += $step, $i++) {
                $price = 100 + $i * 0.05;

                $data['time'][]  = $t;
                $data['open'][]  = $price;
                $data['high'][]  = $price + 0.5;
                $data['low'][]   = $price - 0.5;
                $data['close'][] = $price + 0.1;
                $data['vol'][]   = 1000 + ($i % 7) * 100;
            }

            return Http::response(['success' => true, 'code' => 0, 'data' => $data]);
        },
    ]);
}

beforeEach(function () {
    Cache::flush();
    fakeMexcKlines();
});

it('redirects guests to the login page', function () {
    $this->get('/futures/analysis-extras?symbol=TAO_USDT')->assertRedirect('/login');
});

it('requires a symbol', function () {
    $user = User::factory()->create(['email' => ALLOWED_EMAIL]);

    // bootstrap/app.php only renders JSON errors for api/* paths, so (like every other
    // endpoint here) a validation failure redirects back with a session error.
    $this->actingAs($user)
        ->getJson('/futures/analysis-extras')
        ->assertRedirect()
        ->assertSessionHasErrors('symbol');
});

it('returns the multi-timeframe grid, extra levels and strength vs BTC', function () {
    $user = User::factory()->create(['email' => ALLOWED_EMAIL]);

    $response = $this->actingAs($user)
        ->getJson('/futures/analysis-extras?symbol=tao_usdt')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.symbol', 'TAO_USDT');

    expect(array_column($response->json('data.mtf'), 'tf'))->toBe(['5M', '15M', '1H', '4H', '1D']);

    foreach ($response->json('data.mtf') as $row) {
        expect($row['lean'])->toBeIn(['up', 'down', 'mixed']);
    }

    expect(array_keys($response->json('data.levels')))->toBe([
        'weekly_pivot', 'prior_week_high', 'prior_week_low',
        'monthly_pivot', 'prior_month_high', 'prior_month_low',
        'poc', 'vah', 'val',
    ]);

    expect(array_keys($response->json('data.vs_btc')))->toBe(['1H', '4H', '24H']);

    expect($response->json('data.plan'))->toHaveKeys(['price', 'atr_1h', 'supertrend_15m', 'summary', 'zones']);
    expect($response->json('data.plan.summary'))->toBeString()->not->toBeEmpty();

    foreach ($response->json('data.plan.zones') as $zone) {
        expect($zone['side'])->toBeIn(['long', 'short'])
            ->and($zone['strength'])->toBeIn(['strong', 'solid', 'weak'])
            ->and($zone['confirmations'])->toHaveCount(3);
    }
});

it('measures the latest candles on 15M, 1H and 4H', function () {
    $user = User::factory()->create(['email' => ALLOWED_EMAIL]);

    $tapes = $this->actingAs($user)
        ->getJson('/futures/analysis-extras?symbol=TAO_USDT')
        ->assertOk()
        ->json('data.candles');

    expect(array_keys($tapes))->toBe(['15M', '1H', '4H']);

    foreach ($tapes as $tf => $tape) {
        expect($tape['tf'])->toBe($tf)
            ->and($tape['atr'])->toBeGreaterThan(0)
            ->and($tape['candles'])->toHaveCount(12)
            ->and(array_column($tape['candles'], 'ago'))->toBe(range(0, 11))
            // The candle for the current period has not closed: shown, but never labelled.
            ->and($tape['forming']['closed'])->toBeFalse()
            ->and($tape['forming']['flags'])->toBe([])
            ->and($tape['sequence']['summary'])->toBeString()->not->toBeEmpty();

        foreach ($tape['candles'] as $row) {
            expect($row['closed'])->toBeTrue()
                ->and($row['direction'])->toBeIn(['up', 'down', 'flat']);
        }
    }
});

it('reads WaveTrend on 15M, 1H and 4H, with the open candle kept apart from the closed ones', function () {
    $user = User::factory()->create(['email' => ALLOWED_EMAIL]);

    $reads = $this->actingAs($user)
        ->getJson('/futures/analysis-extras?symbol=TAO_USDT')
        ->assertOk()
        ->json('data.wavetrend');

    expect(array_keys($reads))->toBe(['15M', '1H', '4H']);

    foreach ($reads as $tf => $read) {
        expect($read['tf'])->toBe($tf)
            ->and($read['wt1'])->toBeFloat()
            ->and($read['wt2'])->toBeFloat()
            ->and($read['zone'])->toBeIn(['deep_overbought', 'overbought', 'neutral', 'oversold', 'deep_oversold'])
            ->and($read['side'])->toBeIn(['above', 'below'])
            // The fake series ends with the candle of the current period, which has not closed.
            ->and($read['forming'])->toBeTrue()
            ->and($read['closes_at'])->toBeInt()
            ->and($read['crosses'])->toBeArray();
    }
});

it('omits strength vs BTC when the coin is BTC itself', function () {
    $user = User::factory()->create(['email' => ALLOWED_EMAIL]);

    $this->actingAs($user)
        ->getJson('/futures/analysis-extras?symbol=BTC_USDT')
        ->assertOk()
        ->assertJsonPath('data.vs_btc', null);
});
