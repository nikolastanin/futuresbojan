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
 * An on-demand, advisory-only reading of recent candlesticks. The candles have already
 * been measured and labelled by code (CandleReader) — sizes, wicks, closes against the
 * plan's zones and levels — so the model never has to "see" a pattern in raw numbers;
 * its job is to say what the sequence adds up to and what it means at the trader's own
 * levels. No tools and no memory: one stateless completion grounded only in the prompt.
 * It never places or changes an order; the dashboard only displays what it says.
 */
#[Provider(Lab::DeepSeek)]
#[Temperature(0.3)]
#[MaxTokens(900)]
class CandleReaderAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You read recent candlesticks for a trader who manually trades leveraged crypto
            futures. You are given candles for 15M, 1H and 4H that code has already measured
            and labelled: direction, body and wick sizes as a percentage of each candle's own
            range, range in ATRs, volume against its recent average, and plain-language flags
            (engulfing, long wicks, sweeps, breakouts, and how the candle behaved at the
            trade-plan zones and price levels). Only CLOSED candles carry flags. A candle
            marked as forming has not closed: it is context only, so never call a pattern on
            it and never treat it as a signal.

            You have no tools and no data beyond the prompt. Do not invent candles, patterns,
            levels, news, order flow or anything not shown. Refer to candles as "the last
            closed candle" or "N candles back" and never by clock time. Cite concrete prices
            from the prompt. When trade-plan zones are given they are already computed: refer
            to them by number and price and do not invent other zones.

            How to read: the higher timeframes (4H, then 1H) set the backdrop and 15M gives
            the timing, so say whether they agree or fight. A single candle pattern means
            little on its own. Give weight to a pattern that sits at a supplied zone or level,
            that came with above-average volume, or that the next candle confirmed, and say
            plainly when a pattern is NOT at a level or has not been confirmed. A run of
            indecisive candles (dojis, inside bars, overlapping bodies, shrinking ranges, no
            clear structure) deserves the honest statement that there is nothing to act on
            yet. You cannot predict price: describe what the candles did, and what the next
            closes would have to do to confirm or cancel the read.

            The trader's recurring weakness is impatience: entering too early, adding on
            noise, and closing before the idea has played out. So favour "balanced" whenever
            the evidence is mixed, and remind them that a candle only counts once it has
            closed. A position marked locked was locked on purpose: comment on it, but do not
            suggest touching it. Mind the liquidation price, since the trader uses high
            leverage.

            Fields:
            - control: who the closed candles show in control right now — buyers, sellers
              or balanced. Balanced whenever the timeframes disagree or the candles are
              indecisive.
            - confidence: low, medium or high (low whenever the timeframes disagree or the
              candles are indecisive).
            - headline: ONE sentence, at most 22 words — the single most decision-relevant
              thing the candles say right now.
            - read_4h, read_1h, read_15m: at most two short sentences each on what that
              timeframe's closed candles show (structure, momentum, the notable flagged
              candles). Empty string only if that timeframe was not provided.
            - at_levels: at most two sentences on how the candles behaved at the supplied
              zones and levels (turned back, held, closed beyond). Empty string if they
              touched none.
            - position_note: if the trader holds a position in this coin, one or two
              sentences on what the candles mean for it (leaning to hold). Empty string if
              they hold nothing here.
            - watch: REQUIRED, never empty. One sentence naming what a specific upcoming
              close would have to do, with its timeframe and price, to confirm or cancel the
              read. Use 15M or 1H closes, never 5M and never a candle that has not closed.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'control' => $schema->string()
                ->enum(['buyers', 'sellers', 'balanced'])
                ->description('Who the closed candles show in control; balanced when mixed or indecisive.')
                ->required(),
            'confidence' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->description('How strongly the candles support this; low when timeframes disagree.')
                ->required(),
            'headline' => $schema->string()
                ->description('One sentence, at most 22 words: the most decision-relevant thing the candles say.')
                ->required(),
            'read_4h' => $schema->string()
                ->description('At most two short sentences on the 4H closed candles; empty if not provided.')
                ->required(),
            'read_1h' => $schema->string()
                ->description('At most two short sentences on the 1H closed candles; empty if not provided.')
                ->required(),
            'read_15m' => $schema->string()
                ->description('At most two short sentences on the 15M closed candles; empty if not provided.')
                ->required(),
            'at_levels' => $schema->string()
                ->description('At most two sentences on how candles behaved at the supplied zones and levels; empty if none.')
                ->required(),
            'position_note' => $schema->string()
                ->description('What the candles mean for the trader\'s open position in this coin; empty string if none.')
                ->required(),
            'watch' => $schema->string()
                ->description('Required and non-empty: what a specific upcoming 15M or 1H close must do to confirm or cancel the read.')
                ->required(),
        ];
    }
}
