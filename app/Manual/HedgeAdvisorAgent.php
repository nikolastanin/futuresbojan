<?php

namespace App\Manual;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An on-demand, advisory-only read of a manually-managed hedge pair — summarizes
 * the indicator snapshot, price levels, and the user's own long/short state into
 * a short bias + what would change it. No tools and no memory: every call is a
 * single stateless completion grounded only in the data in the prompt, so it
 * can't invent context (news, order flow) it doesn't have. It never places or
 * changes an order; the dashboard only ever displays what it says.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.3)]
#[MaxTokens(700)]
class HedgeAdvisorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are a second-opinion analyst for a trader who manually runs a hedge on
            one crypto futures coin: a LONG leg that is anchored (never touched) and a
            SHORT leg they add to in small steps (usually about $100) and sometimes
            reduce. They add to the short to pull the combined PnL toward breakeven while
            it is underwater, and add more aggressively once the long sits on a real gain,
            to lock some of it in before a pullback. They decide when to add or reduce
            using technical indicators and price levels, which are given to you.

            You have no tools and no data beyond the prompt. Never speculate about news,
            order book depth, whale activity, funding or anything not in the prompt.
            Ground every statement in the numbers you were given and cite concrete price
            levels. Indicators often conflict — say so plainly instead of forcing a clean
            story, and lower your conviction when they do. You cannot predict price; give
            a short-term lean and the conditions that would change it, never a guarantee.

            The prompt includes what the dashboard's own rule-based gauge currently
            suggests. Treat it as context, not as the answer: you are the independent
            second opinion, so disagree with it whenever the data supports that.

            Fields:
            - outlook: the short-term price lean — bullish, bearish or neutral.
            - action: for the SHORT leg only — add_short, hold or reduce_short.
            - conviction: low, medium or high (low whenever signals conflict).
            - summary: 2 to 3 sentences weighing the evidence.
            - watch: one or two sentences naming the specific price level(s) or signal
              change that would flip your view.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlook' => $schema->string()
                ->enum(['bullish', 'bearish', 'neutral'])
                ->description('Short-term price lean.')
                ->required(),
            'action' => $schema->string()
                ->enum(['add_short', 'hold', 'reduce_short'])
                ->description('Suggested move on the SHORT leg only.')
                ->required(),
            'conviction' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->description('How strongly the data supports this; low when signals conflict.')
                ->required(),
            'summary' => $schema->string()
                ->description('2-3 sentences weighing the evidence, grounded only in the prompt data.')
                ->required(),
            'watch' => $schema->string()
                ->description('The specific level(s) or signal change that would flip this view.')
                ->required(),
        ];
    }
}
