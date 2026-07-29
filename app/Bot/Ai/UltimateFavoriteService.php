<?php

namespace App\Bot\Ai;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Bot\Scalp\ScalpScanner;
use App\Models\BotFavoritePick;
use App\Models\BotSignal;
use App\Models\BotTrade;

/**
 * Periodically (never on a Dashboard request) builds the "Ultimate Favorite"
 * shortlist: the live bot's most-confident recent signals, cross-checked against
 * Scalp Scanner's independent technical grade for the same symbols, filtered to
 * confidence tiers that haven't recently been a net loser, then narrowed by an
 * optional DeepSeek review. Persists the whole batch to bot_favorite_picks so the
 * Dashboard only ever reads a cheap cached result — see UltimateFavoritePickerAgent
 * for the AI step's own scope and limits.
 */
class UltimateFavoriteService
{
    public function __construct(private ScalpScanner $scalpScanner) {}

    public function refresh(): void
    {
        if (! BotConfig::get('ultimate_favorite_enabled')) {
            return;
        }

        $candidates = $this->buildCandidates();
        $computedAt = now();

        if (! empty($candidates) && BotConfig::get('ultimate_favorite_ai_enabled')) {
            $candidates = $this->applyAiPicks($candidates);
        }

        foreach ($candidates as $candidate) {
            BotFavoritePick::create([...$candidate, 'computed_at' => $computedAt]);
        }

        // Keep the table from growing unbounded — only recent batches are ever read.
        BotFavoritePick::where('computed_at', '<', $computedAt->clone()->subDays(2))->delete();

        BotLogger::info('ultimate_favorite', 'Ultimate Favorite refreshed', [
            'candidate_count' => count($candidates),
            'ai_picks' => count(array_filter($candidates, fn ($c) => $c['is_ai_pick'] ?? false)),
        ]);
    }

    /** @return array<int, array> */
    private function buildCandidates(): array
    {
        $minConfidence = (int) BotConfig::get('ultimate_favorite_min_confidence');
        $pool = (int) BotConfig::get('ultimate_favorite_candidate_pool');
        $recentCutoff = now()->subMinutes(30);

        $latestIdsPerSymbol = BotSignal::query()
            ->where('analyzed_at', '>=', $recentCutoff)
            ->selectRaw('MAX(id) as id')
            ->groupBy('symbol')
            ->pluck('id');

        if ($latestIdsPerSymbol->isEmpty()) {
            return [];
        }

        $signals = BotSignal::whereIn('id', $latestIdsPerSymbol)
            ->whereNotNull('direction')
            ->where('confidence_score', '>=', $minConfidence)
            ->orderByDesc('confidence_score')
            ->limit($pool)
            ->get(['symbol', 'direction', 'confidence_score', 'entry_price']);

        if ($signals->isEmpty()) {
            return [];
        }

        $grades = [];
        try {
            foreach ($this->scalpScanner->scan($signals->pluck('symbol')->all()) as $result) {
                $grades[$result['symbol']] = $result;
            }
        } catch (\Throwable $e) {
            BotLogger::warning('ultimate_favorite', "Scalp scan failed during Ultimate Favorite refresh: {$e->getMessage()}", []);
        }

        $confidenceValues = $signals->pluck('confidence_score')->unique()->values()->all();
        $tierStats = $this->tierPerformance($confidenceValues);
        $minSampleSize = (int) BotConfig::get('ultimate_favorite_min_tier_sample_size');
        $minWinRate = (float) BotConfig::get('ultimate_favorite_min_tier_win_rate');

        $candidates = [];
        foreach ($signals as $signal) {
            $scalp = $grades[$signal->symbol] ?? null;

            // Scalp Scanner flagged this symbol but on the opposite side — a genuine
            // conflict between the two independent scores, not a clean pick.
            if ($scalp !== null && $scalp['direction'] !== $signal->direction) {
                continue;
            }

            $tier = $tierStats[$signal->confidence_score] ?? null;
            $sampleSize = $tier['total'] ?? 0;
            $winRate = $tier && $tier['total'] > 0 ? round($tier['won'] / $tier['total'] * 100, 1) : null;
            $netProfit = $tier['net_profit'] ?? null;

            // Only actively exclude a tier once there's enough recent history to trust
            // the read — with too little data, let the candidate through unfiltered.
            if ($sampleSize >= $minSampleSize && ($winRate < $minWinRate || $netProfit <= 0)) {
                continue;
            }

            $grade = $scalp['grade'] ?? null;
            $combinedScore = $grade !== null
                ? round($signal->confidence_score * 0.5 + $grade * 0.5, 1)
                : (float) $signal->confidence_score;

            $candidates[] = [
                'symbol' => $signal->symbol,
                'direction' => $signal->direction,
                'entry_price' => (float) $signal->entry_price,
                'confidence_score' => $signal->confidence_score,
                'scalp_grade' => $grade,
                'combined_score' => $combinedScore,
                'tier_win_rate' => $winRate,
                'tier_net_profit_usdt' => $netProfit !== null ? round($netProfit, 4) : null,
                'tier_sample_size' => $sampleSize,
                'is_ai_pick' => false,
                'ai_reasoning' => null,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        return $candidates;
    }

    /**
     * Aggregate win rate / net profit per confidence score, from real closed-trade
     * history over the configured lookback window — "how has this confidence tier
     * actually performed lately", not any individual coin's own history.
     *
     * @param array<int, int> $confidenceValues
     * @return array<int, array{total: int, won: int, net_profit: float}>
     */
    private function tierPerformance(array $confidenceValues): array
    {
        if (empty($confidenceValues)) {
            return [];
        }

        $lookbackDays = (int) BotConfig::get('ultimate_favorite_performance_lookback_days');

        return BotTrade::query()
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->subDays($lookbackDays))
            ->whereIn('confidence_score', $confidenceValues)
            ->selectRaw('confidence_score, COUNT(*) as total, SUM(CASE WHEN net_profit_usdt > 0 THEN 1 ELSE 0 END) as won, SUM(net_profit_usdt) as net_profit')
            ->groupBy('confidence_score')
            ->get()
            ->keyBy('confidence_score')
            ->map(fn ($row) => [
                'total' => (int) $row->total,
                'won' => (int) $row->won,
                'net_profit' => (float) $row->net_profit,
            ])
            ->all();
    }

    /**
     * @param array<int, array> $candidates
     * @return array<int, array>
     */
    private function applyAiPicks(array $candidates): array
    {
        $topN = (int) BotConfig::get('ultimate_favorite_ai_candidate_count');
        $shortlist = array_slice($candidates, 0, $topN);

        try {
            $response = (new UltimateFavoritePickerAgent)->prompt(
                $this->buildPrompt($shortlist),
                timeout: (int) BotConfig::get('ultimate_favorite_ai_timeout_seconds'),
            );
        } catch (\Throwable $e) {
            BotLogger::warning('ultimate_favorite', "AI pick failed, falling back to combined-score ranking: {$e->getMessage()}", []);

            return $candidates;
        }

        $maxPicks = (int) BotConfig::get('ultimate_favorite_ai_max_picks');
        $picks = array_slice(is_array($response['picks'] ?? null) ? $response['picks'] : [], 0, $maxPicks);
        $reasoningBySymbol = [];

        foreach ($picks as $pick) {
            if (is_string($pick['symbol'] ?? null) && is_string($pick['reasoning'] ?? null)) {
                $reasoningBySymbol[$pick['symbol']] = $pick['reasoning'];
            }
        }

        foreach ($candidates as &$candidate) {
            if (isset($reasoningBySymbol[$candidate['symbol']])) {
                $candidate['is_ai_pick'] = true;
                $candidate['ai_reasoning'] = $reasoningBySymbol[$candidate['symbol']];
            }
        }

        return $candidates;
    }

    /** @param array<int, array> $shortlist */
    private function buildPrompt(array $shortlist): string
    {
        $lines = array_map(fn ($c) => sprintf(
            '%s: %s, combined score %.1f/10 (live confidence %d/10, scalp grade %s), tier win rate %s, tier net profit %s (sample size %d)',
            $c['symbol'],
            $c['direction'],
            $c['combined_score'],
            $c['confidence_score'],
            $c['scalp_grade'] !== null ? "{$c['scalp_grade']}/10" : 'n/a',
            $c['tier_win_rate'] !== null ? "{$c['tier_win_rate']}%" : 'not enough data yet',
            $c['tier_net_profit_usdt'] !== null ? "\${$c['tier_net_profit_usdt']}" : 'not enough data yet',
            $c['tier_sample_size'],
        ), $shortlist);

        $list = implode("\n", $lines);

        return <<<TEXT
            Shortlist of candidates, already sorted by combined score (highest first):
            {$list}
            TEXT;
    }
}
