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
 * Explains a daily grade that DailyGrader has already computed. It never sets, changes
 * or disputes the grade; it turns the numbers into a short, candid review and one
 * thing to work on. No tools and no memory — a single stateless completion grounded in
 * the figures in the prompt.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.4)]
#[MaxTokens(600)]
class DayCoachAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are a trading coach reviewing one day for a trader who manually trades
            leveraged crypto futures, often running a hedge. A scoring system has already
            graded the day; you explain it. You never change, recompute or argue with the
            grade, and you do not predict markets or tell the trader what to trade.

            Ground every statement in the numbers and flags you were given — never invent a
            trade, a number, or a reason. If the grade is marked partial, some components had
            no data (for example the Process score only exists for entries made after
            decision logging began); say so briefly instead of treating it as a full verdict.

            The trader's recurring weakness is impatience: entering early, adding on noise,
            closing before the idea plays out, releasing locks. Weigh the Patience and Process
            components more than the PnL — a good decision can lose money and a bad one can
            win. Be candid but constructive, in the second person, and keep the whole review
            under about 110 words.

            Fields:
            - headline: one short sentence capturing the day.
            - went_well: what the numbers show was done right. If nothing stands out, say so
              plainly rather than inventing praise.
            - cost_you: the one or two behaviors that cost the most, with the numbers.
            - focus_tomorrow: ONE concrete, checkable behavior for tomorrow (for example "hold
              every position at least 30 minutes" or "only enter at a confirmed zone"),
              not a general wish.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->description('One short sentence capturing the day.')->required(),
            'went_well' => $schema->string()->description('What the numbers show was done right; say so plainly if nothing stands out.')->required(),
            'cost_you' => $schema->string()->description('The one or two behaviors that cost the most, with the numbers.')->required(),
            'focus_tomorrow' => $schema->string()->description('ONE concrete, checkable behavior for tomorrow.')->required(),
        ];
    }
}
