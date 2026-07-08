<?php

namespace App\Console\Commands;

use App\Bot\Config\BotConfig;
use App\Bot\Optimization\OptimizationEngine;
use App\Models\BotOptimizationReport;
use App\Models\BotTrade;
use Illuminate\Console\Command;

/**
 * Analyzes accumulated trade history and prints suggestions with reasoning. Never
 * changes real trading config — that's a manual step, by design (per the spec).
 */
class BotOptimizeCommand extends Command
{
    protected $signature = 'bot:optimize
        {--days= : Only consider trades closed in the last N days (default: all)}
        {--with-confidence-comparison : Also backtest-compare confidence thresholds 7/8/9 (slower)}
        {--with-hedge-comparison : Also backtest-compare hedge ratios 60/40, 70/30, 80/20 (slower)}
        {--symbols=* : Symbols to use for comparison backtests (defaults to config bot.default_pairs)}
        {--backtest-days=14 : History window for comparison backtests}';

    protected $description = 'Analyze trade history and suggest config changes (suggestions only — never auto-applied)';

    public function handle(OptimizationEngine $engine): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;
        $findings = $engine->analyzeHistoricalTrades($days);

        if (($findings['trade_count'] ?? 0) === 0) {
            $this->warn('No closed trades yet — nothing to analyze.');
            return self::SUCCESS;
        }

        $this->printFindings($findings);

        $symbols = $this->option('symbols') ?: BotConfig::get('default_pairs');
        $backtestDays = (int) $this->option('backtest-days');

        $confidenceComparison = null;
        if ($this->option('with-confidence-comparison')) {
            $this->info('Backtest-comparing confidence thresholds 7/8/9 — this runs multiple full backtests, please wait...');
            $confidenceComparison = $engine->compareConfidenceThresholds($symbols, $backtestDays);
            $this->printComparison('Confidence threshold comparison', $confidenceComparison, 'threshold');
        }

        $hedgeComparison = null;
        if ($this->option('with-hedge-comparison')) {
            $this->info('Backtest-comparing hedge ratios 60/40, 70/30, 80/20 — this runs multiple full backtests, please wait...');
            $hedgeComparison = $engine->compareHedgeRatios($symbols, $backtestDays);
            $this->printComparison('Hedge ratio comparison', $hedgeComparison, 'ratio');
        }

        $suggestions = $engine->buildSuggestions($findings, $confidenceComparison, $hedgeComparison);
        $this->printSuggestions($suggestions);

        $oldest = BotTrade::where('status', 'closed')->oldest('closed_at')->value('closed_at');
        $newest = BotTrade::where('status', 'closed')->latest('closed_at')->value('closed_at');

        BotOptimizationReport::create([
            'trade_count'  => $findings['trade_count'],
            'period_from'  => $oldest,
            'period_to'    => $newest,
            'findings'     => $findings,
            'suggestions'  => $suggestions,
            'generated_at' => now(),
        ]);

        $this->info('Report saved.');

        return self::SUCCESS;
    }

    private function printFindings(array $findings): void
    {
        $this->info("Analyzed {$findings['trade_count']} closed trades" . ($findings['sufficient_sample'] ? '' : ' (small sample — treat conclusions cautiously)'));
        $this->table(['Metric', 'Value'], [
            ['Win rate', "{$findings['win_rate_pct']}%"],
            ['Net profit', "\${$findings['net_profit_usdt']}"],
        ]);

        $this->line('By confidence score:');
        $rows = [];
        foreach ($findings['by_confidence'] as $score => $stats) {
            $rows[] = [$score, $stats['count'], "{$stats['win_rate_pct']}%", "\${$stats['avg_net_profit_usdt']}"];
        }
        $this->table(['Confidence', 'Trades', 'Win rate', 'Avg net profit'], $rows);

        $this->line('By close reason:');
        $rows = [];
        foreach ($findings['by_close_reason'] as $reason => $stats) {
            $rows[] = [$reason, $stats['count'], "{$stats['win_rate_pct']}%", "\${$stats['avg_net_profit_usdt']}"];
        }
        $this->table(['Close reason', 'Trades', 'Win rate', 'Avg net profit'], $rows);

        $this->line('Factor presence in winners vs losers (top 5 by lift):');
        $rows = [];
        foreach (array_slice($findings['factor_analysis'], 0, 5, true) as $factor => $stats) {
            $rows[] = [$factor, "{$stats['present_in_winners_pct']}%", "{$stats['present_in_losers_pct']}%", "{$stats['lift_pct_points']}pp"];
        }
        $this->table(['Factor', 'In winners', 'In losers', 'Lift'], $rows);
    }

    private function printComparison(string $title, array $rows, string $keyField): void
    {
        $this->line($title . ':');
        $this->table(
            [ucfirst($keyField), 'Trades', 'Win rate', 'Net profit', 'Max drawdown'],
            array_map(fn ($r) => [$r[$keyField], $r['trade_count'], "{$r['win_rate_pct']}%", "\${$r['net_profit_usdt']}", "\${$r['max_drawdown_usdt']}"], $rows),
        );
    }

    private function printSuggestions(array $suggestions): void
    {
        $this->line('');
        $this->line('<comment>Suggestions (nothing has been changed — apply manually if you agree):</comment>');
        foreach ($suggestions as $s) {
            $this->line("  • {$s['suggestion']}");
            $this->line("    Reasoning: {$s['reasoning']}");
        }
    }
}
