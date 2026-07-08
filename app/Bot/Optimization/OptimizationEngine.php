<?php

namespace App\Bot\Optimization;

use App\Bot\Backtest\BacktestEngine;
use App\Bot\Config\BotConfig;
use App\Models\BotTrade;
use Illuminate\Support\Collection;

/**
 * Analyzes accumulated trade history to find which factors, confidence scores, and
 * config values correlate with winning vs losing trades, and proposes config changes
 * with the reasoning spelled out. Never applies anything itself — BotConfig is only
 * ever touched transiently (and always reverted) when running comparison backtests.
 *
 * The goal, per the spec, is not for the bot to "learn" the strategy — it's to
 * surface which of the fixed rules are actually earning their keep.
 */
class OptimizationEngine
{
    private const MIN_SAMPLE_SIZE = 20;

    private const FACTOR_PATTERNS = [
        'trend_1h'     => '/^1H trend/',
        'trend_15m'    => '/^15M trend/',
        'ema_5m'       => '/^5M price/',
        'rsi'          => '/^1H RSI/',
        'volume'       => '/^(5M volume|Volume does not)/',
        'momentum'     => '/^(5M momentum|No significant momentum)/',
        'price_action' => '/^Price .*(support|resistance)/',
        'dominance'    => '/^USDT dominance/',
    ];

    public function __construct(private BacktestEngine $backtestEngine) {}

    /** @return array Full findings, sample-size aware throughout. */
    public function analyzeHistoricalTrades(?int $sinceDays = null): array
    {
        $query = BotTrade::where('status', 'closed');
        if ($sinceDays) {
            $query->where('closed_at', '>=', now()->subDays($sinceDays));
        }
        $trades = $query->get();
        $total  = $trades->count();

        if ($total === 0) {
            return ['trade_count' => 0];
        }

        $winners = $trades->filter(fn ($t) => (float) $t->net_profit_usdt > 0);

        return [
            'trade_count'        => $total,
            'sufficient_sample'  => $total >= self::MIN_SAMPLE_SIZE,
            'win_rate_pct'       => round($winners->count() / $total * 100, 2),
            'net_profit_usdt'    => round((float) $trades->sum('net_profit_usdt'), 4),
            'by_confidence'      => $this->byConfidence($trades),
            'by_direction'       => $this->byDirection($trades),
            'by_close_reason'    => $this->byCloseReason($trades),
            'factor_analysis'    => $this->factorAnalysis($trades),
            'rsi_analysis'       => [
                'LONG'  => $this->rsiDirectionAnalysis($trades, 'LONG'),
                'SHORT' => $this->rsiDirectionAnalysis($trades, 'SHORT'),
            ],
        ];
    }

    /**
     * Empirically tests whether a different minimum_confidence_to_trade would have
     * performed better, by re-running the exact same historical window at each
     * threshold. Restores the original config value even if a run throws.
     *
     * @return array<int, array{threshold: int, net_profit_usdt: float, win_rate_pct: float, trade_count: int}>
     */
    public function compareConfidenceThresholds(array $symbols, int $days, array $thresholds = [7, 8, 9]): array
    {
        $original = BotConfig::get('minimum_confidence_to_trade');
        $to   = time();
        $from = $to - $days * 86400;
        $results = [];

        try {
            foreach ($thresholds as $threshold) {
                BotConfig::set('minimum_confidence_to_trade', $threshold);
                $result = $this->backtestEngine->run($symbols, $from, $to);
                $summary = $result['summary'];
                $results[] = [
                    'threshold'       => $threshold,
                    'trade_count'     => $summary['total_trades'] ?? 0,
                    'win_rate_pct'    => $summary['win_rate_pct'] ?? 0,
                    'net_profit_usdt' => $summary['net_profit_after_fees_usdt'] ?? 0,
                    'max_drawdown_usdt' => $summary['max_drawdown_usdt'] ?? 0,
                ];
            }
        } finally {
            BotConfig::set('minimum_confidence_to_trade', $original);
        }

        return $results;
    }

    /**
     * Empirically tests hedge ratios (e.g. 60/40 vs 70/30 vs 80/20) the same way.
     * Forces hedge_enabled on for the duration of the comparison so the ratio actually
     * gets exercised, then restores both settings regardless of outcome.
     *
     * @param array<int, array{0: float, 1: float}> $ratios Pairs of [main_ratio, hedge_ratio]
     * @return array<int, array{ratio: string, net_profit_usdt: float, win_rate_pct: float, max_drawdown_usdt: float}>
     */
    public function compareHedgeRatios(array $symbols, int $days, array $ratios = [[0.6, 0.4], [0.7, 0.3], [0.8, 0.2]]): array
    {
        $originalEnabled = BotConfig::get('hedge_enabled');
        $originalMain    = BotConfig::get('main_position_ratio');
        $originalHedge   = BotConfig::get('hedge_position_ratio');
        $originalConf78  = BotConfig::get('hedge_for_confidence_7_8');
        $to   = time();
        $from = $to - $days * 86400;
        $results = [];

        try {
            BotConfig::set('hedge_enabled', true);
            BotConfig::set('hedge_for_confidence_7_8', true);

            foreach ($ratios as [$main, $hedge]) {
                BotConfig::set('main_position_ratio', $main);
                BotConfig::set('hedge_position_ratio', $hedge);
                $result = $this->backtestEngine->run($symbols, $from, $to);
                $summary = $result['summary'];
                $results[] = [
                    'ratio'             => round($main * 100) . '/' . round($hedge * 100),
                    'trade_count'       => $summary['total_trades'] ?? 0,
                    'win_rate_pct'      => $summary['win_rate_pct'] ?? 0,
                    'net_profit_usdt'   => $summary['net_profit_after_fees_usdt'] ?? 0,
                    'max_drawdown_usdt' => $summary['max_drawdown_usdt'] ?? 0,
                ];
            }
        } finally {
            BotConfig::set('hedge_enabled', $originalEnabled);
            BotConfig::set('main_position_ratio', $originalMain);
            BotConfig::set('hedge_position_ratio', $originalHedge);
            BotConfig::set('hedge_for_confidence_7_8', $originalConf78);
        }

        return $results;
    }

    /**
     * Turns findings (+ optional comparison results) into concrete suggestions with
     * the reasoning spelled out. Output only — nothing here touches live config.
     */
    public function buildSuggestions(array $findings, ?array $confidenceComparison = null, ?array $hedgeComparison = null): array
    {
        $suggestions = [];

        if (($findings['trade_count'] ?? 0) < self::MIN_SAMPLE_SIZE) {
            $suggestions[] = [
                'parameter' => null,
                'suggestion' => 'Not enough closed trades yet for a reliable suggestion',
                'reasoning' => "Only {$findings['trade_count']} closed trades available; want at least " . self::MIN_SAMPLE_SIZE . ' before drawing conclusions from historical win rate.',
            ];
            return $suggestions;
        }

        // Confidence threshold, from historical mined data.
        $byConf = $findings['by_confidence'] ?? [];
        if (isset($byConf[7]) && $byConf[7]['count'] >= 5) {
            $conf7WinRate = $byConf[7]['win_rate_pct'];
            $higherConf = collect($byConf)->filter(fn ($v, $k) => $k >= 8)->values();
            if ($higherConf->isNotEmpty()) {
                $higherWinRate = round($higherConf->avg('win_rate_pct'), 1);
                if ($higherWinRate - $conf7WinRate >= 10) {
                    $current = BotConfig::get('minimum_confidence_to_trade');
                    $suggestions[] = [
                        'parameter'  => 'minimum_confidence_to_trade',
                        'current_value' => $current,
                        'suggested_value' => 8,
                        'suggestion' => "Raise minimum_confidence_to_trade from {$current} to 8",
                        'reasoning'  => "Confidence-7 trades won {$conf7WinRate}% of the time ({$byConf[7]['count']} trades) vs {$higherWinRate}% for confidence 8+ — a " . round($higherWinRate - $conf7WinRate, 1) . " point gap.",
                    ];
                }
            }
        }

        // Direction-specific RSI threshold, from historical mined data.
        foreach (['LONG', 'SHORT'] as $direction) {
            $buckets = $findings['rsi_analysis'][$direction] ?? [];
            $withData = collect($buckets)->filter(fn ($b) => $b['count'] >= 5);
            if ($withData->count() >= 2) {
                $best  = $withData->sortByDesc('win_rate_pct')->first();
                $worst = $withData->sortBy('win_rate_pct')->first();
                if ($best && $worst && ($best['win_rate_pct'] - $worst['win_rate_pct']) >= 20) {
                    $bestBucket = $withData->sortByDesc('win_rate_pct')->keys()->first();
                    $suggestions[] = [
                        'parameter'  => null,
                        'suggestion' => "Consider weighting RSI more heavily for {$direction} signals",
                        'reasoning'  => "{$direction} trades win {$best['win_rate_pct']}% of the time when entered in the '{$bestBucket}' RSI bucket, vs as low as {$worst['win_rate_pct']}% in other buckets.",
                    ];
                }
            }
        }

        // Factor lift, from historical mined data.
        foreach (($findings['factor_analysis'] ?? []) as $factor => $stats) {
            if ($stats['lift_pct_points'] >= 15) {
                $suggestions[] = [
                    'parameter'  => null,
                    'suggestion' => "The '{$factor}' factor looks meaningfully predictive — consider increasing its weight",
                    'reasoning'  => "Present in {$stats['present_in_winners_pct']}% of winning trades vs only {$stats['present_in_losers_pct']}% of losing trades.",
                ];
            } elseif ($stats['lift_pct_points'] <= -15) {
                $suggestions[] = [
                    'parameter'  => null,
                    'suggestion' => "The '{$factor}' factor looks unreliable — consider reducing its weight",
                    'reasoning'  => "Present in {$stats['present_in_losers_pct']}% of losing trades vs only {$stats['present_in_winners_pct']}% of winning trades.",
                ];
            }
        }

        // Confidence threshold, from backtest comparison (empirical, stronger evidence).
        if ($confidenceComparison) {
            $best = collect($confidenceComparison)->sortByDesc('net_profit_usdt')->first();
            $current = collect($confidenceComparison)->firstWhere('threshold', BotConfig::get('minimum_confidence_to_trade'));
            if ($best && $current && $best['threshold'] !== $current['threshold'] && $best['net_profit_usdt'] > $current['net_profit_usdt']) {
                $suggestions[] = [
                    'parameter'       => 'minimum_confidence_to_trade',
                    'current_value'   => $current['threshold'],
                    'suggested_value' => $best['threshold'],
                    'suggestion'      => "Backtest suggests raising minimum_confidence_to_trade from {$current['threshold']} to {$best['threshold']}",
                    'reasoning'       => "Re-running the same historical window at threshold {$best['threshold']} produced \${$best['net_profit_usdt']} net profit ({$best['win_rate_pct']}% win rate) vs \${$current['net_profit_usdt']} ({$current['win_rate_pct']}%) at the current threshold {$current['threshold']}.",
                ];
            }
        }

        // Hedge ratio, from backtest comparison (empirical).
        if ($hedgeComparison) {
            $best = collect($hedgeComparison)->sortByDesc('net_profit_usdt')->first();
            $currentRatio = round(BotConfig::get('main_position_ratio') * 100) . '/' . round(BotConfig::get('hedge_position_ratio') * 100);
            $current = collect($hedgeComparison)->firstWhere('ratio', $currentRatio);
            if ($best && $current && $best['ratio'] !== $current['ratio'] && $best['net_profit_usdt'] > $current['net_profit_usdt']) {
                $suggestions[] = [
                    'parameter'       => 'main_position_ratio / hedge_position_ratio',
                    'current_value'   => $current['ratio'],
                    'suggested_value' => $best['ratio'],
                    'suggestion'      => "Backtest suggests a {$best['ratio']} hedge ratio over the current {$current['ratio']}",
                    'reasoning'       => "Over the same historical window, {$best['ratio']} produced \${$best['net_profit_usdt']} net profit with \${$best['max_drawdown_usdt']} max drawdown, vs \${$current['net_profit_usdt']} / \${$current['max_drawdown_usdt']} at {$current['ratio']}.",
                ];
            } else {
                $suggestions[] = [
                    'parameter'  => null,
                    'suggestion' => "Current hedge ratio {$currentRatio} looks fine — no change suggested",
                    'reasoning'  => 'None of the tested alternatives produced meaningfully better net profit over the same historical window.',
                ];
            }
        }

        if (empty($suggestions)) {
            $suggestions[] = [
                'parameter'  => null,
                'suggestion' => 'No changes suggested',
                'reasoning'  => 'Nothing in the historical data clears the bar for a confident recommendation yet.',
            ];
        }

        return $suggestions;
    }

    private function byConfidence(Collection $trades): array
    {
        $out = [];
        foreach ($trades->groupBy('confidence_score') as $score => $group) {
            $winners = $group->filter(fn ($t) => (float) $t->net_profit_usdt > 0);
            $out[(int) $score] = [
                'count'               => $group->count(),
                'win_rate_pct'        => round($winners->count() / $group->count() * 100, 2),
                'avg_net_profit_usdt' => round((float) $group->avg('net_profit_usdt'), 4),
            ];
        }
        ksort($out);

        return $out;
    }

    private function byDirection(Collection $trades): array
    {
        $out = [];
        foreach (['LONG', 'SHORT'] as $direction) {
            $group = $trades->where('direction', $direction);
            if ($group->isEmpty()) {
                continue;
            }
            $winners = $group->filter(fn ($t) => (float) $t->net_profit_usdt > 0);
            $out[$direction] = [
                'count'               => $group->count(),
                'win_rate_pct'        => round($winners->count() / $group->count() * 100, 2),
                'avg_net_profit_usdt' => round((float) $group->avg('net_profit_usdt'), 4),
            ];
        }

        return $out;
    }

    private function byCloseReason(Collection $trades): array
    {
        $out = [];
        foreach ($trades->groupBy('close_reason') as $reason => $group) {
            $winners = $group->filter(fn ($t) => (float) $t->net_profit_usdt > 0);
            $out[$reason ?? 'unknown'] = [
                'count'               => $group->count(),
                'win_rate_pct'        => round($winners->count() / $group->count() * 100, 2),
                'avg_net_profit_usdt' => round((float) $group->avg('net_profit_usdt'), 4),
            ];
        }

        return $out;
    }

    private function factorAnalysis(Collection $trades): array
    {
        $winners = $trades->filter(fn ($t) => (float) $t->net_profit_usdt > 0);
        $losers  = $trades->filter(fn ($t) => (float) $t->net_profit_usdt <= 0);

        $winnerCounts = $this->countFactors($winners);
        $loserCounts  = $this->countFactors($losers);
        $winnerTotal  = max($winners->count(), 1);
        $loserTotal   = max($losers->count(), 1);

        $factors = array_unique([...array_keys($winnerCounts), ...array_keys($loserCounts)]);
        $out = [];
        foreach ($factors as $factor) {
            $winPct  = round(($winnerCounts[$factor] ?? 0) / $winnerTotal * 100, 1);
            $losePct = round(($loserCounts[$factor] ?? 0) / $loserTotal * 100, 1);
            $out[$factor] = [
                'present_in_winners_pct' => $winPct,
                'present_in_losers_pct'  => $losePct,
                'lift_pct_points'        => round($winPct - $losePct, 1),
            ];
        }
        uasort($out, fn ($a, $b) => $b['lift_pct_points'] <=> $a['lift_pct_points']);

        return $out;
    }

    /** @return array<string, int> factor => number of trades where it contributed */
    private function countFactors(Collection $trades): array
    {
        $counts = [];
        foreach ($trades as $trade) {
            $seen = [];
            foreach (($trade->reason_for_entry ?? []) as $reason) {
                if (! str_contains($reason, '[+')) {
                    continue; // only reasons that actually contributed to the score
                }
                foreach (self::FACTOR_PATTERNS as $factor => $pattern) {
                    if (! isset($seen[$factor]) && preg_match($pattern, $reason)) {
                        $counts[$factor] = ($counts[$factor] ?? 0) + 1;
                        $seen[$factor] = true;
                    }
                }
            }
        }

        return $counts;
    }

    /** @return array<string, array{count: int, wins: int, win_rate_pct: float}> */
    private function rsiDirectionAnalysis(Collection $trades, string $direction): array
    {
        $buckets = [];
        foreach ($trades->where('direction', $direction) as $trade) {
            foreach (($trade->reason_for_entry ?? []) as $reason) {
                if (preg_match('/1H RSI ([\d.]+)/', $reason, $m)) {
                    $rsi = (float) $m[1];
                    $bucket = match (true) {
                        $rsi < 40 => 'rsi_below_40',
                        $rsi < 50 => 'rsi_40_to_50',
                        $rsi < 60 => 'rsi_50_to_60',
                        default   => 'rsi_60_plus',
                    };
                    $buckets[$bucket]['count'] = ($buckets[$bucket]['count'] ?? 0) + 1;
                    if ((float) $trade->net_profit_usdt > 0) {
                        $buckets[$bucket]['wins'] = ($buckets[$bucket]['wins'] ?? 0) + 1;
                    }
                    break; // one RSI reading per trade
                }
            }
        }

        foreach ($buckets as &$data) {
            $data['wins'] ??= 0;
            $data['win_rate_pct'] = round($data['wins'] / $data['count'] * 100, 1);
        }

        return $buckets;
    }
}
