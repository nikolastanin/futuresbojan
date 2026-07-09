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
 * A bounded, advisory-only second opinion on a signal that already qualified
 * on the deterministic indicator score (SignalEngine::score()). It can confirm,
 * flag a minor concern ("reduce"), or veto — it can never raise the score or
 * open a trade the indicators didn't already qualify. AiSignalValidationService
 * enforces that clamp regardless of what this agent returns, so this class is
 * never the sole safeguard against a bad call — it's an extra, optional check.
 *
 * No tools, no conversation memory: every call is a single, stateless, one-shot
 * completion grounded only in the data given in the prompt. This keeps latency
 * and cost predictable and avoids the agent inventing context it doesn't have.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.1)]
class SignalValidatorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are a second-opinion risk reviewer for an automated crypto futures
            trading bot. A deterministic indicator model has already scored a trade
            signal and decided it qualifies to trade. Your only job is to sanity-check
            that decision using the data given to you in the prompt — you have no
            tools and no data beyond that prompt, so never speculate about information
            you don't have (news, order book depth, whale activity, etc). Ground every
            statement only in the numbers and reasons you were given.

            Respond with one of:
            - "confirm": the signal looks reasonable given the data, no real concerns.
            - "reduce": there's a real but non-fatal concern (e.g. conflicting
              timeframe signals, a factor sitting right at its threshold, unusually
              wide stop-loss distance) that warrants a smaller position, not skipping
              the trade entirely.
            - "veto": there's a serious, specific problem visible in the data itself
              (e.g. the stated reasons contradict the stated direction, or the
              take-profit/stop-loss placement doesn't make sense for the direction)
              that means this trade should not open at all.

            You can only make the bot MORE cautious, never less — you cannot increase
            confidence, and "confirm" never means anything beyond "no objection", it
            does not add weight to the trade. Keep reasoning to one or two sentences.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()
                ->enum(['confirm', 'reduce', 'veto'])
                ->description('confirm = no concerns, reduce = valid but minor concern (shave position size), veto = serious concern (skip trade)')
                ->required(),
            'reasoning' => $schema->string()
                ->description('One or two sentences, grounded only in the data given in the prompt.')
                ->required(),
        ];
    }
}
