<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function tradesUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/** A deal the way MEXC lists it, `$minutesAgo` minutes back. */
function tradesDeal(int $n, int $side = 1, int $minutesAgo = 30, array $over = []): array
{
    return $over + [
        'id'        => (string) (9_000_000_000 + $n),
        'symbol'    => 'TAO_USDT',
        'side'      => $side,
        'vol'       => 10,
        'price'     => 273.4,
        'fee'       => 0,
        'profit'    => 0,
        'orderId'   => (string) (739_000_000_000_000_000 + $n),
        'timestamp' => (time() - $minutesAgo * 60) * 1000,
        'taker'     => true,
    ];
}

/**
 * Fakes MEXC: `$pages` answers the fills endpoint page by page (the last one repeats), `$shape` picks
 * how the docs' two examples wrap them, and the public contract list gives TAO a contract size of 0.01.
 * Anything in `$override` (a response, or a failure) is used for the fills endpoint instead.
 */
function fakeMexcFills(array $pages, string $shape = 'list', $override = null): void
{
    Http::fake([
        '*private/order/list/order_deals/v3*' => $override ?? function (Request $request) use ($pages, $shape) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $rows = $pages[min((int) $query['page_num'], count($pages)) - 1];

            return Http::response([
                'success' => true,
                'code'    => 0,
                'data'    => $shape === 'list' ? $rows : ['pageSize' => 1000, 'totalCount' => count($rows), 'resultList' => $rows],
            ]);
        },
        '*contract/detail*' => Http::response(['success' => true, 'code' => 0, 'data' => [
            ['symbol' => 'TAO_USDT', 'contractSize' => 0.01],
            ['symbol' => 'BTC_USDT', 'contractSize' => 0.0001],
        ]]),
    ]);
}

/** The query of every request sent to the fills endpoint. */
function sentFillQueries(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0]->url())
        ->filter(fn (string $url) => str_contains($url, 'order_deals/v3'))
        ->map(function (string $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $query;
        })
        ->values()
        ->all();
}

beforeEach(function () {
    Cache::flush();
});

it('sends your fills in a coin, with their size in USDT', function () {
    fakeMexcFills([[tradesDeal(1, 1, 50), tradesDeal(2, 4, 20, ['vol' => 5, 'price' => 280, 'profit' => 1.5])]]);

    $data = $this->actingAs(tradesUser())
        ->getJson('/futures/market-chart-trades?symbol=tao_usdt')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.symbol', 'TAO_USDT')
        ->json('data');

    expect($data['fills'])->toHaveCount(2)
        // oldest first: 10 contracts of 0.01 at 273.4, then 5 at 280 that closed a long for +1.5
        ->and($data['fills'][0])->toMatchArray(['side' => 1, 'price' => 273.4, 'vol' => 10, 'notional' => 27.34, 'profit' => 0])
        ->and($data['fills'][1])->toMatchArray(['side' => 4, 'price' => 280, 'vol' => 5, 'notional' => 14.0, 'profit' => 1.5])
        ->and($data['fills'][1]['order_id'])->toBeString();
});

it('reads the fills whether MEXC wraps them in a resultList or sends them bare', function () {
    $user = $this->actingAs(tradesUser());

    foreach (['list', 'wrapped'] as $shape) {
        Cache::flush();
        fakeMexcFills([[tradesDeal(1), tradesDeal(2, 3)]], $shape);

        $user->getJson('/futures/market-chart-trades?symbol=TAO_USDT')
            ->assertOk()
            ->assertJsonCount(2, 'data.fills');
    }
});

it('asks for this coin only, from the first candle on and never further back than 90 days', function () {
    fakeMexcFills([[tradesDeal(1)]]);

    $user = $this->actingAs(tradesUser());

    $recent = time() - 3 * 86400;
    $user->getJson('/futures/market-chart-trades?symbol=TAO_USDT&since='.$recent)->assertOk()->assertJsonPath('data.since', $recent);
    $user->getJson('/futures/market-chart-trades?symbol=TAO_USDT&since=0')->assertOk();
    $user->getJson('/futures/market-chart-trades?symbol=TAO_USDT')->assertOk();

    $queries = sentFillQueries();

    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toMatchArray(['symbol' => 'TAO_USDT', 'start_time' => (string) ($recent * 1000), 'page_num' => '1', 'page_size' => '1000']);

    // With no `since`, or one that is older than the window, the window's own start is used.
    foreach ([$queries[1], $queries[2]] as $query) {
        expect(abs(((int) $query['start_time']) / 1000 - (time() - 90 * 86400)))->toBeLessThan(10)
            ->and(abs(((int) $query['end_time']) / 1000 - time()))->toBeLessThan(10);
    }
});

it('reads on to the next page while a page comes back full', function () {
    $full = array_map(fn (int $n) => tradesDeal($n, 1, 600 + $n), range(1, 1000));

    fakeMexcFills([$full, [tradesDeal(5000, 2, 5)]]);

    $data = $this->actingAs(tradesUser())->getJson('/futures/market-chart-trades?symbol=TAO_USDT')->assertOk()->json('data');

    expect($data['fills'])->toHaveCount(1001)
        ->and(array_column(sentFillQueries(), 'page_num'))->toBe(['1', '2'])
        // oldest first, so the newest fill is the last
        ->and($data['fills'][1000]['side'])->toBe(2);
});

it('sends no fills for a coin you have not traded', function () {
    fakeMexcFills([[]]);

    $this->actingAs(tradesUser())
        ->getJson('/futures/market-chart-trades?symbol=BTC_USDT')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.fills', []);
});

it('still sends the fills, without an amount in USDT, when the contract list cannot be had', function () {
    Http::fake([
        '*private/order/list/order_deals/v3*' => Http::response(['success' => true, 'code' => 0, 'data' => [tradesDeal(1)]]),
        '*contract/detail*'                   => Http::response('down', 503),
    ]);

    $fills = $this->actingAs(tradesUser())->getJson('/futures/market-chart-trades?symbol=TAO_USDT')->assertOk()->json('data.fills');

    expect($fills)->toHaveCount(1)
        ->and($fills[0]['notional'])->toBeNull();
});

it('says why when MEXC refuses', function () {
    fakeMexcFills([], override: Http::response(['success' => false, 'code' => 602, 'message' => 'Signature verification failed'], 200));

    $this->actingAs(tradesUser())
        ->getJson('/futures/market-chart-trades?symbol=TAO_USDT')
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Signature verification failed');
});

it('rejects a missing or malformed request and redirects guests to the login page', function () {
    $user = tradesUser();

    $this->actingAs($user)->getJson('/futures/market-chart-trades')->assertRedirect()->assertSessionHasErrors('symbol');
    $this->actingAs($user)->getJson('/futures/market-chart-trades?symbol=not+a+symbol')->assertRedirect()->assertSessionHasErrors('symbol');
    $this->actingAs($user)->getJson('/futures/market-chart-trades?symbol=BTC_USDT&since=soon')->assertRedirect()->assertSessionHasErrors('since');

    auth()->logout();

    $this->getJson('/futures/market-chart-trades?symbol=BTC_USDT')->assertRedirect();
});
