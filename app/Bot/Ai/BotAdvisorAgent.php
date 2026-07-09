<?php

namespace App\Bot\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reviews the bot's accumulated trade history (OptimizationEngine::analyzeHistoricalTrades())
 * and current configuration, and proposes concrete config changes with reasoning attached.
 * Output-only — like OptimizationEngine::buildSuggestions(), nothing here ever touches
 * live config. Complements (not replaces) the deterministic rule-based suggestions: this
 * agent sees the same data plus those suggestions and can reason more holistically across
 * factors the fixed rules don't explicitly check for.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.2)]
class BotAdvisorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are a quantitative trading systems reviewer for an automated crypto
            futures bot. You will be given: (1) the bot's current configuration
            (margin sizing, profit targets, risk limits, exit logic, universe filters,
            all keyed by confidence score where applicable), and (2) mined statistics
            from its actual closed-trade history (win rate, profit by confidence score,
            by close reason, by direction, indicator-factor lift between winners and
            losers, RSI-bucket win rates) and (3) suggestions a deterministic rules
            engine already generated from the same data.

            Your job is to propose additional or refined config changes, grounded ONLY
            in the data given to you — never invent statistics, market conditions, or
            external knowledge about specific coins. If the data doesn't support a
            confident recommendation, say so plainly rather than guessing. Do not
            repeat a suggestion that's already in the deterministic suggestions list
            verbatim — either add a genuinely different angle, or explicitly say you
            agree with one of them and why.

            Be specific: name the exact config key when you mean one (e.g.
            "trailing_activation_net_profit", "margin_by_confidence[9]",
            "minimum_market_quality_score"), and always tie the suggestion back to a
            specific number from the data you were given.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'overall_assessment' => $schema->string()
                ->description('2-4 sentences: how is the bot performing overall based on the data given, and what is the single biggest thing worth changing?')
                ->required(),
            'suggestions' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->description('One-line summary of the suggested change.')->required(),
                    'config_key' => $schema->string()->description('The specific config key this affects, or "none" if it is not a config change.')->required(),
                    'reasoning' => $schema->string()->description('Why, citing specific numbers from the data given.')->required(),
                    'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->description('How strongly the data supports this.')->required(),
                ]))
                ->description('3-6 suggestions, most important first.')
                ->required(),
        ];
    }
}
