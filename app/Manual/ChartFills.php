<?php

namespace App\Manual;

use App\Services\MexcFuturesService;
use Illuminate\Support\Facades\Cache;

/**
 * Your completed orders in one coin, for the arrows on the Market chart: every fill MEXC has
 * recorded in the window, with when it happened, which side it was (1 open long, 2 close short,
 * 3 open short, 4 close long), the price, the size in USDT and, on a closing fill, MEXC's own
 * profit figure. Which candle each fill belongs to, and how fills in one candle are joined into one
 * arrow, is the page's business (lib/market-chart), because only the page knows the candles.
 * Read-only: nothing here sends or changes an order.
 */
class ChartFills
{
    /** How far back to ask. MEXC only answers for recent history, so older fills are not shown. */
    public const WINDOW_DAYS = 90;

    public function __construct(private MexcFuturesService $mexc) {}

    /**
     * @param  int|null  $since  Unix seconds: the first candle on the chart; never further back than the window.
     * @param  int|null  $now  Unix seconds; the clock, unless a test sets it.
     * @return array{symbol: string, since: int, fills: array<int, array<string, mixed>>}
     */
    public function forSymbol(string $symbol, ?int $since = null, ?int $now = null): array
    {
        $now   ??= time();
        $start   = max($since ?? 0, $now - self::WINDOW_DAYS * 86400);

        $deals = $this->mexc->getOrderDeals($symbol, $start * 1000, $now * 1000);

        return [
            'symbol' => $symbol,
            'since'  => $start,
            'fills'  => self::fromDeals($deals, $this->contractSize($symbol)),
        ];
    }

    /**
     * MEXC's deal rows as the chart takes them, oldest first. Rows that cannot be a fill (no price,
     * size, time or a known side) are left out, and a deal listed twice (a new fill can shift the
     * pages while they are being read) counts once. Without the coin's contract size the amount in
     * USDT is unknown, so it is null rather than a guess.
     *
     * @param  array<int, mixed>  $deals
     * @return array<int, array{time: int, side: int, price: float, vol: float, notional: ?float, profit: float, fee: float, order_id: string}>
     */
    public static function fromDeals(array $deals, ?float $contractSize): array
    {
        $fills = [];
        $seen  = [];

        foreach ($deals as $deal) {
            if (! is_array($deal)) {
                continue;
            }

            $side  = (int) ($deal['side'] ?? 0);
            $price = (float) ($deal['price'] ?? 0);
            $vol   = (float) ($deal['vol'] ?? 0);
            $ms    = (int) ($deal['timestamp'] ?? 0);
            $id    = (string) ($deal['id'] ?? '');

            if (! in_array($side, [1, 2, 3, 4], true) || $price <= 0 || $vol <= 0 || $ms <= 0) {
                continue;
            }

            if ($id !== '') {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
            }

            $fills[] = [
                'time'     => intdiv($ms, 1000),
                'side'     => $side,
                'price'    => $price,
                'vol'      => $vol,
                'notional' => ($contractSize ?? 0) > 0 ? round($vol * $contractSize * $price, 2) : null,
                'profit'   => round((float) ($deal['profit'] ?? 0), 4),
                'fee'      => round((float) ($deal['fee'] ?? 0), 6),
                'order_id' => (string) ($deal['orderId'] ?? ''),
            ];
        }

        usort($fills, fn (array $a, array $b) => [$a['time'], $a['order_id']] <=> [$b['time'], $b['order_id']]);

        return $fills;
    }

    /** The coin's contract size, which only changes if MEXC relists it, so an hour in the cache is plenty. Null if it cannot be had. */
    private function contractSize(string $symbol): ?float
    {
        try {
            return Cache::remember("chart-fills:contract-size:{$symbol}", 3600, function () use ($symbol) {
                $size = $this->mexc->getContractSizeMap([$symbol])[$symbol] ?? null;

                return $size > 0 ? $size : null;
            });
        } catch (\Throwable) {
            return null;
        }
    }
}
