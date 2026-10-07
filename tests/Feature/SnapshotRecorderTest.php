<?php

use App\Manual\SnapshotRecorder;
use App\Models\AccountSnapshot;
use App\Models\CoinSnapshot;
use App\Models\User;
use App\Services\MexcFuturesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function snapshotUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/**
 * Mocks the exchange: a hedged TAO pair, a single ETH long, and tickers for a few coins
 * (BTC is listed but not held).
 *
 * @param  array<int, array<string, mixed>>|null  $tickers
 */
function snapshotMexc(?array $tickers = null): void
{
    test()->mock(MexcFuturesService::class, function ($mock) use ($tickers) {
        $mock->shouldReceive('getAccountAssets')->andReturn(['data' => [
            ['currency' => 'USDT', 'equity' => 82.5, 'availableBalance' => 40.0, 'positionMargin' => 20.0, 'unrealized' => -19.5],
        ]]);

        $mock->shouldReceive('getEnrichedPositions')->andReturn([
            ['symbol' => 'TAO_USDT', 'positionType' => 1, 'positionValue' => 1500.0, 'unrealizedPnl' => -25.0, 'fairPrice' => 300.0, 'liquidatePrice' => 250.0],
            ['symbol' => 'TAO_USDT', 'positionType' => 2, 'positionValue' => 600.0, 'unrealizedPnl' => 4.0, 'fairPrice' => 300.0, 'liquidatePrice' => 330.0],
            ['symbol' => 'ETH_USDT', 'positionType' => 1, 'positionValue' => 200.0, 'unrealizedPnl' => 1.5, 'fairPrice' => 4000.0, 'liquidatePrice' => 0.0],
        ]);

        $mock->shouldReceive('getAllTickers')->andReturn($tickers ?? [
            ['symbol' => 'TAO_USDT', 'fairPrice' => 301.0, 'lastPrice' => 301.1, 'fundingRate' => 0.00005, 'holdVol' => 123456],
            ['symbol' => 'ETH_USDT', 'fairPrice' => 4000.0, 'lastPrice' => 4001.0, 'fundingRate' => -0.0001, 'holdVol' => 999],
            ['symbol' => 'BTC_USDT', 'fairPrice' => 60000.0, 'lastPrice' => 60010.0, 'fundingRate' => 0.0001, 'holdVol' => 5555],
        ]);
    });
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-07 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

describe('recording', function () {
    it('writes one account reading and one reading per held coin', function () {
        snapshotMexc();

        $this->actingAs(snapshotUser())->postJson('/futures/snapshot')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['recorded' => true, 'reason' => null]]);

        expect(AccountSnapshot::count())->toBe(1);

        $account = AccountSnapshot::first();

        expect($account->equity)->toBe(82.5)
            ->and($account->available)->toBe(40.0)
            ->and($account->position_margin)->toBe(20.0)
            ->and($account->unrealized)->toBe(-19.5)
            ->and($account->long_notional)->toBe(1700.0)
            ->and($account->short_notional)->toBe(600.0)
            ->and($account->position_count)->toBe(3)
            ->and($account->recorded_at->toDateTimeString())->toBe('2026-10-07 12:00:00');

        expect(CoinSnapshot::count())->toBe(2);

        $tao = CoinSnapshot::where('symbol', 'TAO_USDT')->first();

        expect($tao->price)->toBe(301.0)
            ->and($tao->funding_rate)->toBe(0.00005)
            ->and($tao->open_interest)->toBe(123456.0)
            ->and($tao->long_notional)->toBe(1500.0)
            ->and($tao->short_notional)->toBe(600.0)
            ->and($tao->combined_pnl)->toBe(-21.0)
            // The short's liquidation (330) is the closer one: 30 / 300 = 10%.
            ->and($tao->nearest_liq_pct)->toBe(10.0);

        $eth = CoinSnapshot::where('symbol', 'ETH_USDT')->first();

        expect($eth->funding_rate)->toBe(-0.0001)
            ->and($eth->nearest_liq_pct)->toBeNull();
    });

    it('also keeps the market data of coins that are only being looked at', function () {
        snapshotMexc();

        $this->actingAs(snapshotUser())
            ->postJson('/futures/snapshot', ['symbols' => ['btc_usdt', 'NOPE_USDT']])
            ->assertOk();

        $btc = CoinSnapshot::where('symbol', 'BTC_USDT')->first();

        expect($btc)->not->toBeNull()
            ->and($btc->price)->toBe(60000.0)
            ->and($btc->funding_rate)->toBe(0.0001)
            ->and($btc->long_notional)->toBe(0.0)
            ->and($btc->short_notional)->toBe(0.0)
            ->and($btc->combined_pnl)->toBeNull()
            // A name the exchange does not list has nothing to keep.
            ->and(CoinSnapshot::where('symbol', 'NOPE_USDT')->exists())->toBeFalse();
    });

    it('keeps at most five extra coins', function () {
        $names   = ['AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF'];
        $tickers = array_map(fn ($n) => ['symbol' => "{$n}_USDT", 'fairPrice' => 1.0, 'fundingRate' => 0.0, 'holdVol' => 1], $names);

        snapshotMexc($tickers);

        app(SnapshotRecorder::class)->recordIfDue(array_map(fn ($n) => "{$n}_USDT", $names));

        // Two held coins plus the first five extras.
        expect(CoinSnapshot::count())->toBe(2 + 5)
            ->and(CoinSnapshot::where('symbol', 'FFF_USDT')->exists())->toBeFalse();
    });

    it('does not record again until the interval has passed', function () {
        snapshotMexc();
        $user = snapshotUser();

        $this->actingAs($user)->postJson('/futures/snapshot')->assertJson(['data' => ['recorded' => true]]);

        // A second open tab asking a minute later.
        Carbon::setTestNow('2026-10-07 12:01:00');
        $this->actingAs($user)->postJson('/futures/snapshot')
            ->assertOk()
            ->assertJson(['data' => ['recorded' => false, 'reason' => 'throttled']]);

        expect(AccountSnapshot::count())->toBe(1);

        Carbon::setTestNow('2026-10-07 12:05:00');
        $this->actingAs($user)->postJson('/futures/snapshot')->assertJson(['data' => ['recorded' => true]]);

        expect(AccountSnapshot::count())->toBe(2);
    });

    it('swallows an exchange failure instead of breaking the page', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getAccountAssets')->andThrow(new RuntimeException('API Key expired')));

        $this->actingAs(snapshotUser())->postJson('/futures/snapshot')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['recorded' => false, 'reason' => 'error']]);

        expect(AccountSnapshot::count())->toBe(0);
    });

    it('records nothing when the account has no USDT row', function () {
        $this->mock(MexcFuturesService::class, fn ($mock) => $mock->shouldReceive('getAccountAssets')->andReturn(['data' => []]));

        $this->actingAs(snapshotUser())->postJson('/futures/snapshot')
            ->assertJson(['data' => ['recorded' => false, 'reason' => 'no_account']]);

        expect(AccountSnapshot::count())->toBe(0);
    });

    it('keeps going quietly when the tables have not been migrated', function () {
        snapshotMexc();
        Schema::drop('account_snapshots');
        Schema::drop('coin_snapshots');

        $user = snapshotUser();

        $this->actingAs($user)->postJson('/futures/snapshot')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['recorded' => false, 'reason' => 'error']]);

        $this->actingAs($user)->getJson('/futures/equity-today')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    });

    it('rejects a malformed coin name and needs a signed-in user', function () {
        snapshotMexc();

        $this->actingAs(snapshotUser())
            ->post('/futures/snapshot', ['symbols' => ['not a symbol']])
            ->assertSessionHasErrors('symbols.0');

        auth()->logout();

        // Signed-out requests are sent to the login page like the rest of the app.
        $this->postJson('/futures/snapshot')->assertRedirect();
        $this->getJson('/futures/equity-today')->assertRedirect();
    });
});

describe('equity today', function () {
    function snapshotAt(string $time, float $equity): void
    {
        AccountSnapshot::create(['equity' => $equity, 'recorded_at' => Carbon::parse($time, 'UTC')]);
    }

    it('summarises the UTC day so far', function () {
        snapshotAt('2026-10-06 23:00:00', 200.0); // yesterday: not part of today
        snapshotAt('2026-10-07 00:10:00', 100.0);
        snapshotAt('2026-10-07 06:00:00', 120.0);
        snapshotAt('2026-10-07 09:00:00', 90.0);

        $this->actingAs(snapshotUser())->getJson('/futures/equity-today')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'open'     => 100.0,
                'high'     => 120.0,
                'low'      => 90.0,
                'last'     => 90.0,
                'count'    => 3,
                'first_at' => '2026-10-07T00:10:00+00:00',
                'last_at'  => '2026-10-07T09:00:00+00:00',
            ]]);
    });

    it('is null until the first reading of the day', function () {
        snapshotAt('2026-10-06 23:00:00', 200.0);

        $this->actingAs(snapshotUser())->getJson('/futures/equity-today')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    });
});

describe('nearest liquidation distance', function () {
    it('takes the closest leg and ignores unknown or wrong-sided prices', function () {
        $legs = new Collection([
            ['positionType' => 1, 'fairPrice' => 100.0, 'liquidatePrice' => 80.0],  // long: 20%
            ['positionType' => 2, 'fairPrice' => 100.0, 'liquidatePrice' => 105.0], // short: 5%
            ['positionType' => 1, 'fairPrice' => 100.0, 'liquidatePrice' => 0.0],   // unknown
            ['positionType' => 1, 'fairPrice' => 100.0, 'liquidatePrice' => 120.0], // wrong side of the mark
        ]);

        expect(SnapshotRecorder::nearestLiquidationPct($legs))->toBe(5.0)
            ->and(SnapshotRecorder::nearestLiquidationPct(new Collection))->toBeNull();
    });
});
