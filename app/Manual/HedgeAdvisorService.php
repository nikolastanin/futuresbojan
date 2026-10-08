<?php

namespace App\Manual;

/**
 * Builds the prompt from the dashboard's own live state and returns a structured
 * AI read — HedgeAdvisorAgent for a long+short pair, CoinAdvisorAgent for any other
 * coin. Purely informational — nothing here touches an order, a lock, or any
 * stored state.
 */
class HedgeAdvisorService
{
    private const TIMEOUT_SECONDS = 30;

    // DeepSeek Flash list prices at the peak-hours rate (2x off-peak), so the cost
    // shown to the user is a ceiling rather than an underestimate.
    private const INPUT_COST_PER_MILLION  = 0.30;
    private const OUTPUT_COST_PER_MILLION = 1.20;

    /**
     * The read for a hedge pair (long anchored, short being sized).
     *
     * @param  array<string, mixed>  $context  Validated dashboard snapshot (see buildHedgePrompt()).
     * @return array{kind: string, outlook: string, action: string, conviction: string, summary: string, watch: string, estimated_cost_usd: float}
     */
    public function read(array $context): array
    {
        $response = (new HedgeAdvisorAgent)->prompt(
            $this->buildHedgePrompt($context),
            timeout: self::TIMEOUT_SECONDS,
        );

        return [
            'kind'               => 'hedge',
            'outlook'            => $this->pick($response, 'outlook', ['bullish', 'bearish', 'neutral'], 'neutral'),
            'action'             => $this->pick($response, 'action', ['add_short', 'hold', 'reduce_short'], 'hold'),
            'conviction'         => $this->pick($response, 'conviction', ['low', 'medium', 'high'], 'low'),
            'summary'            => $this->text($response, 'summary'),
            'watch'              => $this->text($response, 'watch'),
            'estimated_cost_usd' => $this->cost($response),
        ];
    }

    /**
     * The read for any single coin, with the trader's position in it if they hold one.
     *
     * @param  array<string, mixed>  $context  Validated dashboard snapshot (see buildCoinPrompt()).
     * @return array{kind: string, outlook: string, stance: string, conviction: string, summary: string, position_note: string, watch: string, estimated_cost_usd: float}
     */
    public function readCoin(array $context): array
    {
        $response = (new CoinAdvisorAgent)->prompt(
            $this->buildCoinPrompt($context),
            timeout: self::TIMEOUT_SECONDS,
        );

        return [
            'kind'               => 'coin',
            'outlook'            => $this->pick($response, 'outlook', ['bullish', 'bearish', 'neutral'], 'neutral'),
            'stance'             => $this->pick($response, 'stance', ['long', 'short', 'wait'], 'wait'),
            'conviction'         => $this->pick($response, 'conviction', ['low', 'medium', 'high'], 'low'),
            'summary'            => $this->text($response, 'summary'),
            'position_note'      => $this->text($response, 'position_note'),
            'watch'              => $this->text($response, 'watch'),
            'estimated_cost_usd' => $this->cost($response),
        ];
    }

    /**
     * The coin-level half of an AI prompt: the indicator snapshot, price levels, timeframes,
     * trade-plan zones and the digest of the latest candles. Shared with the position brief,
     * so every AI read describes the market in exactly the same words.
     *
     * @param  array<string, mixed>  $context  Same shape as the read() context.
     * @return array<int, string>
     */
    public function technicalContext(array $context): array
    {
        return $this->technicalLines($context);
    }

    private function pick(mixed $response, string $key, array $allowed, string $fallback): string
    {
        return in_array($response[$key] ?? null, $allowed, true) ? $response[$key] : $fallback;
    }

    private function text(mixed $response, string $key): string
    {
        return is_string($response[$key] ?? null) ? $response[$key] : '';
    }

    private function cost(mixed $response): float
    {
        $inputTokens  = (int) ($response->usage->promptTokens ?? 0);
        $outputTokens = (int) ($response->usage->completionTokens ?? 0);

        return round(
            ($inputTokens / 1_000_000) * self::INPUT_COST_PER_MILLION
            + ($outputTokens / 1_000_000) * self::OUTPUT_COST_PER_MILLION,
            6,
        );
    }

    /** The coin-level half of every prompt: indicators, levels, timeframes, strength vs BTC. */
    private function technicalLines(array $ctx): array
    {
        $s = $ctx['signal'] ?? [];

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

        if (is_array($ctx['extras'] ?? null)) {
            $x = $ctx['extras'];

            if (is_array($x['levels'] ?? null)) {
                $l = $x['levels'];
                $lines[] = "- Higher-timeframe levels: weekly pivot {$this->v($l['weekly_pivot'] ?? null)}, prior-week high {$this->v($l['prior_week_high'] ?? null)} / low {$this->v($l['prior_week_low'] ?? null)}; monthly pivot {$this->v($l['monthly_pivot'] ?? null)}, prior-month high {$this->v($l['prior_month_high'] ?? null)} / low {$this->v($l['prior_month_low'] ?? null)}";
                $lines[] = "- Volume profile (last 7 days of 1H): point of control {$this->v($l['poc'] ?? null)}, value area {$this->v($l['val'] ?? null)} - {$this->v($l['vah'] ?? null)}";
            }

            if (is_array($x['mtf'] ?? null)) {
                $lines[] = '';
                $lines[] = 'MULTI-TIMEFRAME (trend from EMA50/200, RSI, MACD vs signal, combined lean):';

                foreach ($x['mtf'] as $row) {
                    $lines[] = "- {$this->v($row['tf'] ?? null)}: trend {$this->v($row['trend'] ?? null)}, RSI {$this->v($row['rsi'] ?? null)}, MACD {$this->v($row['macd'] ?? null)}, lean {$this->v($row['lean'] ?? null)}";
                }
            }

            if (is_array($x['vs_btc'] ?? null)) {
                $parts = [];

                foreach ($x['vs_btc'] as $window => $w) {
                    $parts[] = "{$window}: coin {$this->v($w['coin'] ?? null)}% vs BTC {$this->v($w['btc'] ?? null)}% (diff {$this->v($w['diff'] ?? null)})";
                }

                $lines[] = '- Strength vs BTC: '.implode('; ', $parts);
            }

            if (is_array($x['plan']['zones'] ?? null) && $x['plan']['zones'] !== []) {
                $plan = $x['plan'];

                $lines[] = '';
                $lines[] = 'TRADE PLAN ZONES (computed from the levels above — refer to these zones by their prices and do not invent others):';
                $lines[] = "- 15M SuperTrend: {$this->v($plan['supertrend_15m'] ?? null)}; 1H ATR: {$this->v($plan['atr_1h'] ?? null)} ({$this->v($plan['atr_pct'] ?? null)}% of price)";

                foreach ($plan['zones'] as $z) {
                    $factors = implode('+', array_map(fn ($src) => $this->v($src['label'] ?? null), $z['sources'] ?? []));
                    $checks  = implode(', ', array_map(
                        fn ($c) => "{$this->v($c['name'] ?? null)} {$this->v($c['state'] ?? null)}",
                        $z['confirmations'] ?? [],
                    ));
                    $targets = implode(', ', array_map(fn ($t) => $this->v($t['price'] ?? null), $z['targets'] ?? [])) ?: 'none';
                    $beyond  = ($z['side'] ?? '') === 'long' ? 'below' : 'above';

                    $lines[] = '- '.strtoupper($this->v($z['side'] ?? null))." zone {$this->v($z['number'] ?? null)} ({$this->v($z['strength'] ?? null)}): {$this->v($z['low'] ?? null)} - {$this->v($z['high'] ?? null)} [{$factors}], {$this->v($z['distance_pct'] ?? null)}% away ({$this->v($z['status'] ?? null)}); confirmations {$this->v($z['confirmed'] ?? null)}/3 ({$checks}); invalidated by a 1H close {$beyond} {$this->v($z['invalidation'] ?? null)}; illustrative stop {$this->v($z['stop'] ?? null)} ({$this->v($z['stop_distance_atr'] ?? null)}x ATR); targets {$targets}; R:R {$this->v($z['rr'] ?? null)}";
                }
            }

            // A digest of what the latest candles did, measured by CandleReader. The candles
            // themselves are not sent, only their flags, so the model cannot misread a row.
            if (is_array($x['candles'] ?? null)) {
                $digests = [];

                foreach (['4H', '1H', '15M'] as $tf) {
                    if (is_array($x['candles'][$tf] ?? null)) {
                        array_push($digests, ...CandleReader::briefLines($x['candles'][$tf]));
                    }
                }

                if ($digests !== []) {
                    $lines[] = '';
                    $lines[] = 'RECENT CANDLES (closed candles only, measured and labelled by code; a candle still forming is not a signal — cite these flags where they matter and do not invent patterns):';
                    array_push($lines, ...$digests);
                }
            }
        }

        return $lines;
    }

    private function buildCoinPrompt(array $ctx): string
    {
        $lines = $this->technicalLines($ctx);
        $p     = is_array($ctx['position'] ?? null) ? $ctx['position'] : null;

        $lines[] = '';
        $lines[] = 'TRADER\'S POSITION IN THIS COIN:';

        if ($p === null) {
            $lines[] = '- None — no open position in this coin.';
        } else {
            $liquidation = LegLiquidation::text($p, is_numeric($ctx['price'] ?? null) ? (float) $ctx['price'] : null);

            $lines[] = "- Open {$this->v($p['direction'] ?? null)}: notional \${$this->v($p['notional'] ?? null)}, entry {$this->v($p['entry'] ?? null)}, unrealized PnL \${$this->v($p['pnl'] ?? null)}, leverage {$this->v($p['leverage'] ?? null)}x, liquidation price {$liquidation}";
            $lines[] = "- Armed stop-loss: {$this->v($p['stop_loss'] ?? null, 'none')}; take-profit: {$this->v($p['take_profit'] ?? null, 'none')}";
            $lines[] = ! empty($p['locked'])
                ? '- This position is LOCKED on purpose'.(! empty($p['locked_until']) ? " until {$this->v($p['locked_until'])}" : ' (indefinitely)').' — comment only, do not suggest touching it.'
                : '- Not locked.';
        }

        return implode("\n", $lines);
    }

    private function buildHedgePrompt(array $ctx): string
    {
        $h     = $ctx['hedge'] ?? [];
        $lines = $this->technicalLines($ctx);

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
