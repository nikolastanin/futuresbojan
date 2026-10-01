<?php

namespace App\Manual;

use App\Models\EquitySnapshot;

/**
 * Tracks account-wide Total Equity against price, per symbol, so the app can
 * answer "last time price was here, was I actually better off?" — independent
 * of price just moving around. Surfaced today only on the Hedge Balance Gauge,
 * but keyed generically by symbol so it works for whatever pair is being
 * actively traded, not just one coin.
 */
class EquityMemoryService
{
    // Don't record more than one snapshot per symbol this often — keeps the
    // table from growing unbounded while still giving good revisit resolution.
    private const SNAPSHOT_MIN_INTERVAL_MINUTES = 2;

    // Only compare against snapshots at least this old, so "last time" means
    // something rather than "a moment ago, nothing's changed yet".
    private const MIN_REVISIT_AGE_MINUTES = 60;

    // How close price has to be to a past reading to count as "the same level".
    private const PRICE_TOLERANCE_PCT = 0.3;

    /**
     * Records a new snapshot if one is due, then looks for the most recent prior
     * visit to roughly this price level and returns the comparison.
     *
     * @return array{matched: bool, reference_price: ?float, reference_equity: ?float,
     *     reference_recorded_at: ?string, equity_delta: ?float, price_diff_pct: ?float}
     */
    public function recordAndCompare(string $symbol, float $price, float $totalEquity): array
    {
        $this->recordIfDue($symbol, $price, $totalEquity);

        $tolerance = $price * (self::PRICE_TOLERANCE_PCT / 100);
        $cutoff = now()->subMinutes(self::MIN_REVISIT_AGE_MINUTES);

        $reference = EquitySnapshot::where('symbol', $symbol)
            ->where('recorded_at', '<=', $cutoff)
            ->whereBetween('price', [$price - $tolerance, $price + $tolerance])
            ->orderByDesc('recorded_at')
            ->first();

        if (! $reference) {
            return [
                'matched'               => false,
                'reference_price'       => null,
                'reference_equity'      => null,
                'reference_recorded_at' => null,
                'equity_delta'          => null,
                'price_diff_pct'        => null,
            ];
        }

        $referencePrice = (float) $reference->price;

        return [
            'matched'               => true,
            'reference_price'       => $referencePrice,
            'reference_equity'      => (float) $reference->total_equity,
            'reference_recorded_at' => $reference->recorded_at->toIso8601String(),
            'equity_delta'          => round($totalEquity - (float) $reference->total_equity, 2),
            'price_diff_pct'        => $referencePrice > 0
                ? round(($price - $referencePrice) / $referencePrice * 100, 3)
                : null,
        ];
    }

    private function recordIfDue(string $symbol, float $price, float $totalEquity): void
    {
        $latest = EquitySnapshot::where('symbol', $symbol)
            ->orderByDesc('recorded_at')
            ->first();

        if ($latest && $latest->recorded_at->diffInMinutes(now()) < self::SNAPSHOT_MIN_INTERVAL_MINUTES) {
            return;
        }

        EquitySnapshot::create([
            'symbol'       => $symbol,
            'price'        => $price,
            'total_equity' => $totalEquity,
            'recorded_at'  => now(),
        ]);
    }
}
