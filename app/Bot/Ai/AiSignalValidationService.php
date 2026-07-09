<?php

namespace App\Bot\Ai;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Bot\Sizing\PositionSizingService;
use App\Models\BotAiValidation;
use App\Models\BotSignal;

/**
 * Runs SignalValidatorAgent (DeepSeek) against a signal that already qualifies
 * on the deterministic indicator score, and applies its verdict within a hard
 * cap: it can veto the trade or shave exactly 1 point off confidence, never
 * raise it. Only take-profit is recomputed on a reduce (margin-dependent);
 * stop-loss is purely technical/ATR-based and is never touched here.
 *
 * Fails open (indicator-only score, zero trade impact) on any error, timeout,
 * missing API key, disabled config, or exhausted daily budget — AI validation
 * is a strictly optional extra check, never a blocker for the bot loop.
 */
class AiSignalValidationService
{
    public function __construct(private PositionSizingService $sizing) {}

    public function apply(BotSignal $signal, ?float $takerFeeRate = null): BotSignal
    {
        if (! BotConfig::get('ai_validation_enabled')) {
            return $signal;
        }

        if ($signal->skip_reason !== 'pending_risk_evaluation') {
            return $signal; // only validate signals that already qualify
        }

        $budget = (float) BotConfig::get('ai_validation_daily_budget_usd');
        $spentToday = self::spentToday();

        if ($spentToday >= $budget) {
            BotLogger::warning('ai_validation', "Skipped AI validation for {$signal->symbol}: daily budget \${$budget} exhausted (spent \${$spentToday})", [], $signal->symbol);
            return $signal;
        }

        try {
            $response = (new SignalValidatorAgent)->prompt(
                $this->buildPrompt($signal),
                timeout: (int) BotConfig::get('ai_validation_timeout_seconds'),
            );
        } catch (\Throwable $e) {
            $message = substr($e->getMessage(), 0, 500);
            BotLogger::warning('ai_validation', "AI validation failed for {$signal->symbol}, falling back to indicator-only score: {$message}", [], $signal->symbol);
            $this->recordValidation($signal, 'error', $message, 0, 0, 0.0);

            return $signal;
        }

        $verdict = in_array($response['verdict'] ?? null, ['confirm', 'reduce', 'veto'], true) ? $response['verdict'] : 'confirm';
        $reasoning = is_string($response['reasoning'] ?? null) ? $response['reasoning'] : '';
        $cost = $this->estimateCost($response->usage->promptTokens, $response->usage->completionTokens);

        $originalConfidence = $signal->confidence_score;
        $finalConfidence = $verdict === 'reduce' ? max(1, $originalConfidence - 1) : $originalConfidence;

        $threshold = (int) BotConfig::get('minimum_confidence_to_trade');
        $stillQualifies = $verdict !== 'veto' && $finalConfidence >= $threshold;

        $this->recordValidation($signal, $verdict, $reasoning, $response->usage->promptTokens, $response->usage->completionTokens, $cost);

        $reasons = $signal->reasons ?? [];
        $reasons[] = "AI validation ({$verdict}): {$reasoning}";

        if (! $stillQualifies) {
            $signal->update([
                'reasons'           => $reasons,
                'confidence_score'  => $finalConfidence,
                'skip_reason'       => $verdict === 'veto'
                    ? "ai_validation_veto: {$reasoning}"
                    : "confidence {$finalConfidence} below threshold {$threshold} after AI adjustment",
            ]);

            BotLogger::info('ai_validation', "{$signal->symbol}: AI {$verdict} — trade will not open ({$reasoning})", [
                'original_confidence' => $originalConfidence, 'final_confidence' => $finalConfidence,
            ], $signal->symbol);

            return $signal->refresh();
        }

        if ($finalConfidence !== $originalConfidence) {
            $marginUsd = $this->sizing->marginForConfidence($finalConfidence);
            $targetNetProfit = (float) BotConfig::get('target_net_profit_per_trade');

            $signal->update([
                'reasons'                  => $reasons,
                'confidence_score'         => $finalConfidence,
                'take_profit'              => $this->sizing->takeProfitForMargin($signal->direction, $marginUsd, (float) $signal->entry_price, $takerFeeRate, $targetNetProfit),
                'estimated_fee_usdt'       => round($this->sizing->feesForMargin($marginUsd, $takerFeeRate), 4),
                'expected_net_profit_usdt' => round($targetNetProfit, 4),
            ]);
        } else {
            $signal->update(['reasons' => $reasons]);
        }

        BotLogger::info('ai_validation', "{$signal->symbol}: AI {$verdict} ({$reasoning})", [
            'original_confidence' => $originalConfidence, 'final_confidence' => $finalConfidence,
        ], $signal->symbol);

        return $signal->refresh();
    }

    private function buildPrompt(BotSignal $signal): string
    {
        $reasons = implode('; ', $signal->reasons ?? []);

        return <<<TEXT
            Symbol: {$signal->symbol}
            Direction: {$signal->direction}
            Indicator confidence score: {$signal->confidence_score}/10
            Entry price: {$signal->entry_price}
            Take profit: {$signal->take_profit}
            Stop loss: {$signal->stop_loss}
            Per-factor breakdown from the indicator model: {$reasons}
            TEXT;
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        $inputRate  = (float) BotConfig::get('ai_validation_input_cost_per_million');
        $outputRate = (float) BotConfig::get('ai_validation_output_cost_per_million');

        return round(($inputTokens / 1_000_000) * $inputRate + ($outputTokens / 1_000_000) * $outputRate, 6);
    }

    private function recordValidation(BotSignal $signal, string $verdict, string $reasoning, int $inputTokens, int $outputTokens, float $cost): void
    {
        BotAiValidation::create([
            'bot_signal_id'             => $signal->id,
            'symbol'                    => $signal->symbol,
            'original_confidence_score' => $signal->confidence_score,
            'final_confidence_score'    => $verdict === 'reduce' ? max(1, $signal->confidence_score - 1) : $signal->confidence_score,
            'verdict'                   => $verdict,
            'reasoning'                 => $reasoning,
            'input_tokens'              => $inputTokens,
            'output_tokens'             => $outputTokens,
            'estimated_cost_usd'        => $cost,
        ]);
    }

    public static function spentToday(): float
    {
        $todayStart = now()->setTimezone('UTC')->startOfDay();

        return (float) BotAiValidation::where('created_at', '>=', $todayStart)->sum('estimated_cost_usd');
    }
}
