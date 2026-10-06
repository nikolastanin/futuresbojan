<?php

namespace App\Manual;

/**
 * Builds the prompt for HedgeAdvisorAgent from the dashboard's own live state and
 * returns its structured read. Purely informational — nothing here touches an
 * order, a lock, or any stored state.
 */
class HedgeAdvisorService
{
    private const TIMEOUT_SECONDS = 30;

    // DeepSeek Flash list prices at the peak-hours rate (2x off-peak), so the cost
    // shown to the user is a ceiling rather than an underestimate.
    private const INPUT_COST_PER_MILLION  = 0.30;
    private const OUTPUT_COST_PER_MILLION = 1.20;

    /**
     * @param  array<string, mixed>  $context  Validated dashboard snapshot (see buildPrompt()).
     * @return array{outlook: string, action: string, conviction: string, summary: string, watch: string, estimated_cost_usd: float}
     */
    public function read(array $context): array
    {
        $response = (new HedgeAdvisorAgent)->prompt(
            $this->buildPrompt($context),
            timeout: self::TIMEOUT_SECONDS,
        );

        $pick = fn (string $key, array $allowed, string $fallback) => in_array($response[$key] ?? null, $allowed, true)
            ? $response[$key]
            : $fallback;

        $inputTokens  = (int) ($response->usage->promptTokens ?? 0);
        $outputTokens = (int) ($response->usage->completionTokens ?? 0);

        return [
            'outlook'            => $pick('outlook', ['bullish', 'bearish', 'neutral'], 'neutral'),
            'action'             => $pick('action', ['add_short', 'hold', 'reduce_short'], 'hold'),
            'conviction'         => $pick('conviction', ['low', 'medium', 'high'], 'low'),
            'summary'            => is_string($response['summary'] ?? null) ? $response['summary'] : '',
            'watch'              => is_string($response['watch'] ?? null) ? $response['watch'] : '',
            'estimated_cost_usd' => round(
                ($inputTokens / 1_000_000) * self::INPUT_COST_PER_MILLION
                + ($outputTokens / 1_000_000) * self::OUTPUT_COST_PER_MILLION,
                6,
            ),
        ];
    }

    private function buildPrompt(array $ctx): string
    {
        $s = $ctx['signal'] ?? [];
        $h = $ctx['hedge'] ?? [];

        $lines = [
            "Symbol: {$this->v($ctx['symbol'] ?? null)}",
            "Current price: {$this->v($ctx['price'] ?? null)}",
            '',
            'TECHNICAL SNAPSHOT (same indicator engine as the dashboard):',
            "- Composite score: {$this->v($s['direction'] ?? null)} ({$this->v($s['confidence'] ?? null)}/10)",
            "- 1H trend: {$this->v($s['trend'] ?? null)}; 5M momentum: {$this->v($s['momentum'] ?? null)}",
            "- 15M structure shift (change of character): {$this->v($s['structure'] ?? null, 'none')}",
            "- 1H RSI: {$this->v($s['rsi'] ?? null)}; 15M MACD vs signal: {$this->v($s['macd'] ?? null)}",
            "- 5M volume vs prior window: {$this->v($s['volume_trend'] ?? null)}",
            "- 15M candle pattern: {$this->v($s['candle_pattern'] ?? null, 'none')}; fair value gap price is inside: {$this->v($s['fair_value_gap'] ?? null, 'none')}; WaveTrend divergence: {$this->v($s['wavetrend_divergence'] ?? null, 'none')}",
            "- 1H volatility (ATR % of price): {$this->v($s['volatility_pct'] ?? null)}%",
            "- 24h change: {$this->v($s['change_24h_pct'] ?? null)}%; 24h range: {$this->v($s['low_24h'] ?? null)} - {$this->v($s['high_24h'] ?? null)}",
        ];

        if (is_array($s['dominance'] ?? null)) {
            $d = $s['dominance'];
            $lines[] = "- USDT dominance: {$this->v($d['direction'] ?? null)} ({$this->v($d['change_pct'] ?? null)}pp over {$this->v($d['lookback_minutes'] ?? null)}m)";
        }

        if (! empty($s['reasons']) && is_array($s['reasons'])) {
            $lines[] = '- Score factor breakdown: '.implode('; ', array_map('strval', array_slice($s['reasons'], 0, 14)));
        }

        if (is_array($ctx['levels'] ?? null)) {
            $l = $ctx['levels'];
            $lines[] = '';
            $lines[] = 'PRICE LEVELS:';
            $lines[] = "- Resistance: R1 {$this->v($l['r1'] ?? null)}, R2 {$this->v($l['r2'] ?? null)}, prior-day high {$this->v($l['prior_day_high'] ?? null)}, week high {$this->v($l['week_high'] ?? null)}";
            $lines[] = "- Pivot: {$this->v($l['pivot'] ?? null)}; daily EMA10 {$this->v($l['ema10'] ?? null)}, EMA20 {$this->v($l['ema20'] ?? null)}";
            $lines[] = "- Support: S1 {$this->v($l['s1'] ?? null)}, S2 {$this->v($l['s2'] ?? null)}, prior-day low {$this->v($l['prior_day_low'] ?? null)}, week low {$this->v($l['week_low'] ?? null)}";
        }

        $lines[] = '';
        $lines[] = 'TRADER\'S HEDGE:';
        $lines[] = "- Long (anchored): notional \${$this->v($h['long_notional'] ?? null)}, entry {$this->v($h['long_entry'] ?? null)}, unrealized PnL \${$this->v($h['long_pnl'] ?? null)}";
        $lines[] = "- Short: notional \${$this->v($h['short_notional'] ?? null)}, entry {$this->v($h['short_entry'] ?? null)}, unrealized PnL \${$this->v($h['short_pnl'] ?? null)}";
        $lines[] = "- Combined PnL: \${$this->v($h['combined_pnl'] ?? null)}; short is {$this->v($h['short_vs_long_pct'] ?? null)}% of the long's size (\${$this->v($h['remaining_to_target'] ?? null)} left to match it)";
        $lines[] = "- Dashboard gauge currently says: {$this->v($h['gauge_suggestion'] ?? null)} (zone: {$this->v($h['zone'] ?? null)})";

        if (is_array($ctx['equity_memory'] ?? null)) {
            $m = $ctx['equity_memory'];
            $lines[] = "- Last time price was near {$this->v($m['reference_price'] ?? null)}, account equity was \${$this->v($m['reference_equity'] ?? null)}; now it is \${$this->v($m['current_equity'] ?? null)}";
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
