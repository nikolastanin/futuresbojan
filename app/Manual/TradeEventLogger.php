<?php

namespace App\Manual;

use App\Models\TradeEvent;
use Illuminate\Support\Facades\Log;

/**
 * Records what the trader does on real positions so the daily grade can judge decisions,
 * not just outcomes. Two rules keep it safe to call from the order endpoints:
 *
 * - It never throws. A logging failure must not turn a successful order into an
 *   error, so every failure is swallowed (and written to the log).
 * - It never slows an order down. The event row is written immediately, but the
 *   analysis snapshot attached to an entry (which needs several kline lookups) is
 *   gathered after the response has been sent.
 */
class TradeEventLogger
{
    public function __construct(private AnalysisExtrasService $extras) {}

    /**
     * @param  array<string, mixed>  $details  What was done (margin, leverage, hours, prices...).
     * @param  bool  $snapshot  Attach what the analysis said at this moment (entries only).
     */
    public function record(string $type, string $symbol, ?string $direction, array $details = [], bool $snapshot = false): ?TradeEvent
    {
        try {
            $event = TradeEvent::create([
                'type'        => $type,
                'symbol'      => $symbol,
                'direction'   => $direction,
                'details'     => $details === [] ? null : $details,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Could not log trade event {$type} for {$symbol}: {$e->getMessage()}");

            return null;
        }

        if ($snapshot && $direction !== null) {
            app()->terminating(fn () => $this->attachSnapshot($event->id, $symbol, $direction));
        }

        return $event;
    }

    private function attachSnapshot(int $eventId, string $symbol, string $direction): void
    {
        try {
            $event = TradeEvent::find($eventId);

            if (! $event) {
                return;
            }

            $event->context = self::entryContext($direction, $this->extras->forSymbol($symbol));
            $event->save();
        } catch (\Throwable $e) {
            Log::warning("Could not attach analysis snapshot to trade event {$eventId} ({$symbol}): {$e->getMessage()}");
        }
    }

    /**
     * Boils the analysis down to what grading a new entry needs: was it at a zone the
     * plan listed on that side (and was that zone confirmed), and did it go with or
     * against the 4H backdrop. Pure — takes the AnalysisExtrasService output as given.
     *
     * @param  'LONG'|'SHORT'  $direction  The side of the position being entered.
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    public static function entryContext(string $direction, array $extras): array
    {
        $isLong   = $direction === 'LONG';
        $wantSide = $isLong ? 'long' : 'short';
        $plan     = $extras['plan'] ?? [];

        $candidates = array_values(array_filter(
            $plan['zones'] ?? [],
            fn ($z) => ($z['side'] ?? null) === $wantSide && in_array($z['status'] ?? null, ['in_zone', 'near'], true),
        ));

        usort($candidates, fn ($a, $b) => $a['distance_pct'] <=> $b['distance_pct']);
        $zone = $candidates[0] ?? null;

        $lean4h = null;

        foreach ($extras['mtf'] ?? [] as $row) {
            if (($row['tf'] ?? null) === '4H') {
                $lean4h = $row['lean'] ?? null;
            }
        }

        return [
            'zone_quality'   => $zone === null
                ? 'no_zone'
                : (($zone['confirmed'] ?? 0) === count($zone['confirmations'] ?? []) ? 'confirmed_zone' : 'unconfirmed_zone'),
            'with_higher_tf' => ($lean4h === null || $lean4h === 'mixed') ? null : ($lean4h === ($isLong ? 'up' : 'down')),
            'lean_4h'        => $lean4h,
            'supertrend_15m' => $plan['supertrend_15m'] ?? null,
            'price'          => $plan['price'] ?? null,
            'zone'           => $zone === null ? null : [
                'side'         => $zone['side'],
                'number'       => $zone['number'],
                'strength'     => $zone['strength'],
                'low'          => $zone['low'],
                'high'         => $zone['high'],
                'distance_pct' => $zone['distance_pct'],
                'status'       => $zone['status'],
                'confirmed'    => $zone['confirmed'],
                'total'        => count($zone['confirmations'] ?? []),
            ],
            'plan_summary'   => $plan['summary'] ?? null,
        ];
    }
}
