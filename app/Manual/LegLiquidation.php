<?php

namespace App\Manual;

/**
 * Which liquidation price belongs to a leg.
 *
 * The exchange reports 0 when a leg has none, and on a hedge it can report one price for both
 * legs (the account's, for whichever side is net). For the other leg that figure is on the
 * wrong side of the market — a short cannot be liquidated below the price — so it is not the
 * leg's own, and no prompt or list of citable prices should present it as such.
 */
class LegLiquidation
{
    /**
     * The leg's liquidation price: below the price for a long, above it for a short. Null when
     * there is none or it is on the wrong side. With no price to judge by, or a direction that
     * is neither, any positive figure is taken as it comes.
     *
     * @param  array<string, mixed>  $position  A leg as the dashboard sends it (direction, liquidation_price).
     */
    public static function of(array $position, ?float $price): ?float
    {
        $liquidation = $position['liquidation_price'] ?? null;

        if (! is_numeric($liquidation) || (float) $liquidation <= 0) {
            return null;
        }

        $liquidation = (float) $liquidation;
        $direction   = strtoupper((string) ($position['direction'] ?? ''));

        if ($price === null || $price <= 0 || ! in_array($direction, ['LONG', 'SHORT'], true)) {
            return $liquidation;
        }

        return ($direction === 'LONG' ? $liquidation < $price : $liquidation > $price) ? $liquidation : null;
    }

    /**
     * The figure as a prompt reads it, or the plain fact that this leg has none.
     *
     * @param  array<string, mixed>  $position
     */
    public static function text(array $position, ?float $price): string
    {
        $liquidation = self::of($position, $price);

        return $liquidation === null ? 'none reported for this leg' : (string) $liquidation;
    }
}
