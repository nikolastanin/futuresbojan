<?php

namespace App\Bot\Sizing;

use App\Bot\Config\BotConfig;

/**
 * Turns a confidence score into a margin/nominal size and computes the TP/SL/fee
 * numbers for that size. Pure calculation — used both by SignalEngine (to preview
 * what a qualifying signal *would* trade) and by TradeManager (to actually open it),
 * so the two never disagree.
 */
class PositionSizingService
{
    /** Margin ($1-4) for a given confidence score, per the spec's confidence->margin table. */
    public function marginForConfidence(int $confidence): float
    {
        $marginSteps   = BotConfig::get('margin_steps'); // [1,2,3,4] for confidence 7,8,9,10
        $minConfidence = BotConfig::get('minimum_confidence_to_trade');
        $marginIndex   = max(0, min(count($marginSteps) - 1, $confidence - $minConfidence));

        return $marginSteps[$marginIndex];
    }

    /**
     * @return array{margin_usd: float, leverage: int, nominal_usdt: float, entry_price: float,
     *               take_profit: float, stop_loss: float, estimated_fee_usdt: float, expected_net_profit_usdt: float}
     */
    public function plan(string $direction, int $confidence, float $entryPrice, ?float $atr, ?float $takerFeeRate): array
    {
        $marginUsd = $this->marginForConfidence($confidence);
        $leverage  = BotConfig::get('leverage');
        $nominal   = $marginUsd * $leverage;
        $feeRate   = $takerFeeRate ?? BotConfig::get('taker_fee_rate');
        $targetNetProfit = BotConfig::get('target_net_profit_per_trade');

        $takeProfit = $this->takeProfitForMargin($direction, $marginUsd, $entryPrice, $feeRate, $targetNetProfit);

        // Stop loss: 1.5x the 15M ATR away from entry.
        $atr        = $atr ?? ($entryPrice * 0.01);
        $slDistance = 1.5 * $atr;
        $stopLoss   = $direction === 'LONG' ? $entryPrice - $slDistance : $entryPrice + $slDistance;

        return [
            'margin_usd'                => $marginUsd,
            'leverage'                  => $leverage,
            'nominal_usdt'              => $nominal,
            'entry_price'               => round($entryPrice, 8),
            'take_profit'               => round($takeProfit, 8),
            'stop_loss'                 => round($stopLoss, 8),
            'estimated_fee_usdt'        => round($this->feesForMargin($marginUsd, $feeRate), 4),
            'expected_net_profit_usdt'  => round($targetNetProfit, 4),
        ];
    }

    /**
     * TP price for an arbitrary margin size targeting a given net profit — used for hedge
     * legs, whose size is a ratio-split of the signal's full margin rather than a size
     * looked up directly from the confidence table. Unlike stop-loss distance (which is
     * purely technical/ATR-based and margin-independent), the TP price does depend on
     * position size: a smaller leg needs a bigger % price move to reach the same $ target.
     */
    public function takeProfitForMargin(string $direction, float $marginUsd, float $entryPrice, ?float $takerFeeRate, float $targetNetProfit): float
    {
        $leverage  = BotConfig::get('leverage');
        $nominal   = $marginUsd * $leverage;
        $feeRate   = $takerFeeRate ?? BotConfig::get('taker_fee_rate');
        $totalFees = $this->feesForMargin($marginUsd, $feeRate);

        $priceMovePct = $nominal > 0 ? ($targetNetProfit + $totalFees) / $nominal : 0;

        return round($direction === 'LONG' ? $entryPrice * (1 + $priceMovePct) : $entryPrice * (1 - $priceMovePct), 8);
    }

    /** Round-trip (open + close) taker fee estimate for a given margin size. */
    public function feesForMargin(float $marginUsd, ?float $takerFeeRate): float
    {
        $leverage = BotConfig::get('leverage');
        $nominal  = $marginUsd * $leverage;
        $feeRate  = $takerFeeRate ?? BotConfig::get('taker_fee_rate');

        return 2 * $nominal * $feeRate;
    }
}
