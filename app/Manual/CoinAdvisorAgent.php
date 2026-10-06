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
 * An on-demand, advisory-only read of any single coin — for when there is no hedge
 * pair to size (HedgeAdvisorAgent covers that) but the trader still wants a second
 * opinion on what the indicators, timeframes and levels add up to, and what that
 * means for a position they may or may not hold. No tools and no memory: one
 * stateless completion grounded only in the prompt. It never places or changes an
 * order; the dashboard only displays what it says.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.3)]
#[MaxTokens(700)]
class CoinAdvisorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You are a second-opinion analyst for a trader who manually trades leveraged
            crypto futures. You are given a snapshot for one coin — indicators, a
            multi-timeframe table, price levels and strength versus BTC — and, if they
            hold one, their open position in it. They decide when to act using these
            same numbers.

            You have no tools and no data beyond the prompt. Never speculate about news,
            order book depth, whale activity, funding or anything not in the prompt.
            Ground every statement in the numbers you were given and cite concrete price
            levels. Indicators often conflict — say so plainly instead of forcing a clean
            story, and lower your conviction when they do. Use the multi-timeframe table:
            the higher timeframes (4H, 1D) set the backdrop and the lower ones the timing,
            so say whether they agree or fight. Levels from different sources within about
            0.3% of each other are a stronger zone; say so. When trade-plan zones are given
            they are already computed from the levels: refer to them by price and number and
            do not invent other zones or levels. You cannot predict price; give
            a short-term lean and the conditions that would change it, never a guarantee.

            The trader's recurring weakness is impatience: entering too early, adding on
            noise, and closing winners or losers before the idea has played out. So default
            to "wait" when there is no position, and to holding when there is one, unless
            the data clearly argues otherwise. A position marked locked was locked on
            purpose: comment on it, but do not suggest touching it. Pay attention to how
            close price is to the liquidation price, since the trader uses high leverage.

            Fields:
            - outlook: the short-term price lean — bullish, bearish or neutral.
            - stance: which side the data favors for a NEW entry right now — long, short
              or wait. Use wait whenever signals conflict or price is mid-range.
            - conviction: low, medium or high (low whenever signals conflict).
            - summary: at most 3 short sentences and about 50 words. Lead with the single
              most decision-relevant point and name the main conflict between signals if
              there is one. Do not recite every indicator — the trader can already see them.
            - position_note: if the trader holds a position, one or two sentences on what
              this read means for it (hold, add, reduce or close, with the reason, leaning
              to hold). Empty string if they hold nothing in this coin.
            - watch: REQUIRED, never empty. One sentence naming at least one specific price
              level or signal change that would flip your view. Any condition involving a
              close must name its timeframe, and use 15M or 1H closes, never 5M.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlook' => $schema->string()
                ->enum(['bullish', 'bearish', 'neutral'])
                ->description('Short-term price lean.')
                ->required(),
            'stance' => $schema->string()
                ->enum(['long', 'short', 'wait'])
                ->description('Which side the data favors for a new entry now; wait when signals conflict.')
                ->required(),
            'conviction' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->description('How strongly the data supports this; low when signals conflict.')
                ->required(),
            'summary' => $schema->string()
                ->description('At most 3 short sentences (~50 words), grounded only in the prompt data.')
                ->required(),
            'position_note' => $schema->string()
                ->description('What this means for the trader\'s open position in this coin; empty string if none.')
                ->required(),
            'watch' => $schema->string()
                ->description('Required and non-empty: the specific price level(s) or signal change that would flip this view.')
                ->required(),
        ];
    }
}
