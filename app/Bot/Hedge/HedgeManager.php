<?php

namespace App\Bot\Hedge;

use App\Bot\Config\BotConfig;

/**
 * Decides whether a qualifying signal should be split into a main + hedge position,
 * and how. A hedge is not the bot randomly opening both sides — it's a smaller,
 * opposite-direction position sized to cushion a medium-confidence signal being wrong,
 * per the spec's default rule: confidence 7-8 hedges by default, 9-10 doesn't need to.
 */
class HedgeManager
{
    public function shouldHedge(int $confidence): bool
    {
        if (! BotConfig::get('hedge_enabled')) {
            return false;
        }

        if ($confidence >= 9) {
            return (bool) BotConfig::get('hedge_for_confidence_9_10');
        }

        if ($confidence >= 7) {
            return (bool) BotConfig::get('hedge_for_confidence_7_8');
        }

        return false;
    }

    /** @return array{main: float, hedge: float} Margin split of the total, per main/hedge_position_ratio. */
    public function splitMargin(float $totalMarginUsd): array
    {
        $mainRatio   = BotConfig::get('main_position_ratio');
        $hedgeRatio  = BotConfig::get('hedge_position_ratio');

        return [
            'main'  => round($totalMarginUsd * $mainRatio, 2),
            'hedge' => round($totalMarginUsd * $hedgeRatio, 2),
        ];
    }

    /**
     * Net directional exposure of a hedge set, for logging/inspection.
     *
     * @return array{long_usdt: float, short_usdt: float, net_direction: string, net_usdt: float}
     */
    public function netExposure(string $mainDirection, float $mainNominal, float $hedgeNominal): array
    {
        $longNominal  = $mainDirection === 'LONG' ? $mainNominal : $hedgeNominal;
        $shortNominal = $mainDirection === 'SHORT' ? $mainNominal : $hedgeNominal;
        $net          = $longNominal - $shortNominal;

        return [
            'long_usdt'     => $longNominal,
            'short_usdt'    => $shortNominal,
            'net_direction' => $net >= 0 ? 'LONG' : 'SHORT',
            'net_usdt'      => round(abs($net), 2),
        ];
    }
}
