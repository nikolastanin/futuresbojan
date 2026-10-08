<?php

namespace App\Manual;

/**
 * Builds the candle-reading prompt and returns the model's structured read.
 *
 * The candles are measured here on the server (AnalysisExtrasService → CandleReader),
 * never taken from the browser, so what the model is told cannot be altered by anything
 * the page sends; the browser only says which coin and which of the trader's positions
 * to mention. Advisory only — nothing here touches an order or a lock.
 */
class CandleReadService
{
    private const TIMEOUT_SECONDS = 30;

    // DeepSeek Flash list prices at the peak-hours rate (2x off-peak), so the cost shown
    // to the user is a ceiling rather than an underestimate. Same figures as
    // HedgeAdvisorService.
    private const INPUT_COST_PER_MILLION  = 0.30;
    private const OUTPUT_COST_PER_MILLION = 1.20;

    /** Higher timeframes first: they set the backdrop the 15M timing is read against. */
    private const TIMEFRAME_ORDER = ['4H', '1H', '15M'];

    public function __construct(private AnalysisExtrasService $extras) {}

    /**
     * @param  array<int, array<string, mixed>>  $positions  The trader's open legs in this coin (none, one, or both sides).
     * @return array{control: string, confidence: string, headline: string, read_4h: string, read_1h: string, read_15m: string, at_levels: string, position_note: string, watch: string, estimated_cost_usd: float}
     */
    public function read(string $symbol, array $positions = []): array
    {
        $extras = $this->extras->forSymbol($symbol);

        if (array_filter($extras['candles'] ?? []) === []) {
            throw new \RuntimeException("Not enough closed candles on {$symbol} to read yet.");
        }

        $response = (new CandleReaderAgent)->prompt(
            $this->buildPrompt($symbol, $extras, $positions),
            timeout: self::TIMEOUT_SECONDS,
        );

        $text = fn (string $key) => is_string($response[$key] ?? null) ? $response[$key] : '';
        $pick = fn (string $key, array $allowed, string $fallback) => in_array($response[$key] ?? null, $allowed, true) ? $response[$key] : $fallback;

        $in  = (int) ($response->usage->promptTokens ?? 0);
        $out = (int) ($response->usage->completionTokens ?? 0);

        return [
            'control'            => $pick('control', ['buyers', 'sellers', 'balanced'], 'balanced'),
            'confidence'         => $pick('confidence', ['low', 'medium', 'high'], 'low'),
            'headline'           => $text('headline'),
            'read_4h'            => $text('read_4h'),
            'read_1h'            => $text('read_1h'),
            'read_15m'           => $text('read_15m'),
            'at_levels'          => $text('at_levels'),
            'position_note'      => $text('position_note'),
            'watch'              => $text('watch'),
            'estimated_cost_usd' => round(
                ($in / 1_000_000) * self::INPUT_COST_PER_MILLION + ($out / 1_000_000) * self::OUTPUT_COST_PER_MILLION,
                6,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $extras  An AnalysisExtrasService result.
     * @param  array<int, array<string, mixed>>  $positions
     */
    private function buildPrompt(string $symbol, array $extras, array $positions): string
    {
        $plan  = $extras['plan'] ?? [];
        $lines = [
            "Symbol: {$symbol}",
            'Current price: '.$this->v($plan['price'] ?? null),
            '',
            'CANDLES — closed candles measured and labelled by code, newest first. Body and wicks are a % of each candle\'s own range; "1 ATR" is that timeframe\'s own average candle range; flags are statements about closed candles only.',
        ];

        foreach (self::TIMEFRAME_ORDER as $tf) {
            $tape = $extras['candles'][$tf] ?? null;

            if (is_array($tape)) {
                array_push($lines, ...CandleReader::fullLines($tape));
                $lines[] = '';
            }
        }

        $zones = $plan['zones'] ?? [];

        if ($zones !== []) {
            $lines[] = 'TRADE PLAN ZONES (already computed from the price levels — refer to them by number and price, do not invent others):';

            foreach ($zones as $z) {
                $sources = implode('+', array_map(fn ($s) => $this->v($s['label'] ?? null), $z['sources'] ?? []));
                $lines[] = '- '.strtoupper($this->v($z['side'] ?? null))." zone {$this->v($z['number'] ?? null)} ({$this->v($z['strength'] ?? null)}): "
                    .CandleReader::price((float) ($z['low'] ?? 0)).' - '.CandleReader::price((float) ($z['high'] ?? 0))
                    ." [{$sources}], {$this->v($z['distance_pct'] ?? null)}% away ({$this->v($z['status'] ?? null)})";
            }

            $lines[] = '';
        }

        $lines[] = 'TRADER\'S POSITION IN THIS COIN:';

        if ($positions === []) {
            $lines[] = '- None — no open position in this coin.';
        }

        $price = is_numeric($plan['price'] ?? null) ? (float) $plan['price'] : null;

        foreach ($positions as $p) {
            $liquidation = LegLiquidation::text($p, $price);

            $lines[] = "- Open {$this->v($p['direction'] ?? null)}: notional \${$this->v($p['notional'] ?? null)}, entry {$this->v($p['entry'] ?? null)}, unrealized PnL \${$this->v($p['pnl'] ?? null)}, leverage {$this->v($p['leverage'] ?? null)}x, liquidation price {$liquidation}";
            $lines[] = "  Armed stop-loss: {$this->v($p['stop_loss'] ?? null, 'none')}; take-profit: {$this->v($p['take_profit'] ?? null, 'none')}";
            $lines[] = ! empty($p['locked'])
                ? '  This position is LOCKED on purpose'.(! empty($p['locked_until']) ? " until {$this->v($p['locked_until'])}" : ' (indefinitely)').' — comment only, do not suggest touching it.'
                : '  Not locked.';
        }

        return implode("\n", $lines);
    }

    private function v(mixed $value, string $fallback = 'n/a'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return is_scalar($value) ? (string) $value : $fallback;
    }
}
