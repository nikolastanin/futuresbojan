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
 * Final review pass over a shortlist of "Ultimate Favorite" candidates that already
 * passed deterministic filtering (live confidence + Scalp Scanner grade blended into
 * a combined score, confidence tier not a recent loser). This agent can only narrow
 * the shortlist down further or reorder it by conviction — it never adds a symbol
 * that wasn't already in the candidate list, and it's fine to pick none at all if
 * nothing genuinely stands out. Advisory only: UltimateFavoriteService still ranks
 * candidates by combined_score regardless of what this agent returns, so a failed
 * or empty AI call never blanks out the Dashboard box.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.2)]
class UltimateFavoritePickerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are doing a final quality check on a shortlist of crypto futures trade
            candidates for an automated bot's dashboard "Ultimate Favorite" suggestion
            box — the one or two ideas a human trader would see highlighted above
            everything else. Each candidate in the shortlist already passed two
            deterministic checks before reaching you: a blended score combining the
            bot's own live indicator confidence with a separate technical scalp-setup
            grade, and a filter that already excluded any confidence tier whose recent
            real trade history has been a net loser. You have no tools and no data
            beyond what's given in the prompt — never speculate about news, order book
            depth, or anything not in the numbers you were given.

            From the shortlist, pick 0 to a few symbols worth featuring as the
            standout pick(s) right now. Prefer picking fewer, more convincing symbols
            over padding the list — it is completely fine, and often correct, to pick
            zero if nothing in the shortlist looks genuinely compelling relative to
            the others. Ground every reasoning line only in the specific numbers given
            for that candidate (its scores, its tier's win rate and net profit).
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'picks' => $schema->array()
                ->items($schema->object([
                    'symbol' => $schema->string()->description('Must be one of the symbols given in the shortlist.')->required(),
                    'reasoning' => $schema->string()->description('One or two sentences, grounded only in that candidate\'s given numbers.')->required(),
                ]))
                ->description('0 to a few standout picks from the shortlist, most convincing first. Empty array if none stand out.')
                ->required(),
        ];
    }
}
