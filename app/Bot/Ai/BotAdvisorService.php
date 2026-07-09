<?php

namespace App\Bot\Ai;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Bot\Optimization\OptimizationEngine;

/**
 * Runs BotAdvisorAgent (DeepSeek) against the bot's real trade-history findings and
 * current config, on demand — not part of the live trading cycle. Purely advisory:
 * returns suggestions for a human to read and apply manually, exactly like
 * OptimizationEngine::buildSuggestions(), just with an LLM reasoning over the same
 * data instead of fixed threshold rules.
 */
class BotAdvisorService
{
    public function __construct(private OptimizationEngine $optimization) {}

    /**
     * @return array{success: bool, error: ?string, overall_assessment: ?string,
     *               suggestions: array, findings: array, deterministic_suggestions: array,
     *               input_tokens: int, output_tokens: int, estimated_cost_usd: float}
     */
    public function advise(?int $sinceDays = null): array
    {
        $findings = $this->optimization->analyzeHistoricalTrades($sinceDays);

        if (($findings['trade_count'] ?? 0) === 0) {
            return $this->result(false, 'No closed trades yet — nothing to analyze.', $findings, []);
        }

        $deterministicSuggestions = $this->optimization->buildSuggestions($findings);

        try {
            $response = (new BotAdvisorAgent)->prompt(
                $this->buildPrompt($findings, $deterministicSuggestions),
                timeout: 60,
            );
        } catch (\Throwable $e) {
            $message = substr($e->getMessage(), 0, 500);
            BotLogger::error('ai_advisor', "Bot advisor request failed: {$message}", []);

            return $this->result(false, $message, $findings, $deterministicSuggestions);
        }

        $cost = $this->estimateCost($response->usage->promptTokens, $response->usage->completionTokens);

        BotLogger::info('ai_advisor', 'Bot advisor report generated', [
            'trade_count' => $findings['trade_count'],
            'suggestion_count' => count($response['suggestions'] ?? []),
            'estimated_cost_usd' => $cost,
        ]);

        return [
            'success' => true,
            'error' => null,
            'overall_assessment' => $response['overall_assessment'] ?? null,
            'suggestions' => $response['suggestions'] ?? [],
            'findings' => $findings,
            'deterministic_suggestions' => $deterministicSuggestions,
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'estimated_cost_usd' => $cost,
        ];
    }

    private function buildPrompt(array $findings, array $deterministicSuggestions): string
    {
        $config = [
            'margin_by_confidence' => BotConfig::get('margin_by_confidence'),
            'target_net_profit_by_confidence' => BotConfig::get('target_net_profit_by_confidence'),
            'minimum_confidence_to_trade' => BotConfig::get('minimum_confidence_to_trade'),
            'leverage' => BotConfig::get('leverage'),
            'max_open_positions' => BotConfig::get('max_open_positions'),
            'max_total_margin_usdt' => BotConfig::get('max_total_margin_usdt'),
            'max_daily_loss_usdt' => BotConfig::get('max_daily_loss_usdt'),
            'cooldown_minutes_per_pair' => BotConfig::get('cooldown_minutes_per_pair'),
            'breakeven_enabled' => BotConfig::get('breakeven_enabled'),
            'breakeven_trigger_net_profit' => BotConfig::get('breakeven_trigger_net_profit'),
            'breakeven_sustain_minutes' => BotConfig::get('breakeven_sustain_minutes'),
            'trailing_tp_enabled' => BotConfig::get('trailing_tp_enabled'),
            'trailing_activation_net_profit' => BotConfig::get('trailing_activation_net_profit'),
            'trailing_callback_net_profit' => BotConfig::get('trailing_callback_net_profit'),
            'smart_exit_enabled' => BotConfig::get('smart_exit_enabled'),
            'smart_exit_min_net_profit' => BotConfig::get('smart_exit_min_net_profit'),
            'max_position_duration_minutes' => BotConfig::get('max_position_duration_minutes'),
            'hedge_enabled' => BotConfig::get('hedge_enabled'),
            'minimum_24h_volume_usdt' => BotConfig::get('minimum_24h_volume_usdt'),
            'minimum_market_quality_score' => BotConfig::get('minimum_market_quality_score'),
            'max_pairs_to_analyze' => BotConfig::get('max_pairs_to_analyze'),
            'ai_validation_enabled' => BotConfig::get('ai_validation_enabled'),
        ];

        $configJson = json_encode($config, JSON_PRETTY_PRINT);
        $findingsJson = json_encode($findings, JSON_PRETTY_PRINT);
        $deterministicJson = json_encode($deterministicSuggestions, JSON_PRETTY_PRINT);

        return <<<TEXT
            Current bot configuration:
            {$configJson}

            Mined statistics from closed trade history:
            {$findingsJson}

            Suggestions a deterministic rules engine already generated from this same data:
            {$deterministicJson}
            TEXT;
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        $inputRate = (float) BotConfig::get('ai_validation_input_cost_per_million');
        $outputRate = (float) BotConfig::get('ai_validation_output_cost_per_million');

        return round(($inputTokens / 1_000_000) * $inputRate + ($outputTokens / 1_000_000) * $outputRate, 6);
    }

    private function result(bool $success, ?string $error, array $findings, array $deterministicSuggestions): array
    {
        return [
            'success' => $success,
            'error' => $error,
            'overall_assessment' => null,
            'suggestions' => [],
            'findings' => $findings,
            'deterministic_suggestions' => $deterministicSuggestions,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0.0,
        ];
    }
}
