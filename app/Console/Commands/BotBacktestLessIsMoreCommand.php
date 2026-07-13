<?php

namespace App\Console\Commands;

use App\Bot\Backtest\LessIsMoreBacktestEngine;
use Illuminate\Console\Command;

/**
 * Backtests the Dashboard's "Less Is More" batch micro-position tool against
 * historical MEXC candles: keeps N TP-only LONG micro positions open at all times,
 * refilling "first in line" from the top-100 symbol list (config/top_symbols.php)
 * whenever a slot frees up — no signal/confidence check, purely list order,
 * matching the live tool. Never sends orders, never touches bot_trades/ManualPaperTrade.
 */
class BotBacktestLessIsMoreCommand extends Command
{
    protected $signature = 'bot:backtest-less-is-more
        {symbols?* : Candidate pool in priority order, e.g. BTC_USDT ETH_USDT (defaults to config/top_symbols.php)}
        {--from= : Start date (Y-m-d or Y-m-d H:i), UTC}
        {--to= : End date (Y-m-d or Y-m-d H:i), UTC. Defaults to now}
        {--days=5 : Days of history to test, used when --from is omitted}
        {--count=5 : Concurrent micro positions to keep open (1-20)}
        {--tp-percent=1.5 : Take-profit distance as a % move from entry}
        {--nominal-min=100 : Minimum nominal (leveraged) size per position, USDT}
        {--nominal-max=200 : Maximum nominal (leveraged) size per position, USDT}';

    protected $description = 'Backtest the "Less Is More" batch micro-position Dashboard tool against historical MEXC data';

    public function handle(LessIsMoreBacktestEngine $engine): int
    {
        $symbols = $this->argument('symbols') ?: config('top_symbols');

        $toTs = $this->option('to') ? strtotime($this->option('to') . ' UTC') : time();
        $fromTs = $this->option('from')
            ? strtotime($this->option('from') . ' UTC')
            : $toTs - ((int) $this->option('days') * 86400);

        if (! $fromTs || ! $toTs || $fromTs >= $toTs) {
            $this->error('Invalid date range.');
            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        if ($count < 1 || $count > 20) {
            $this->error('--count must be between 1 and 20.');
            return self::FAILURE;
        }

        $tpPercent = (float) $this->option('tp-percent');

        $this->info('Backtesting Less Is More (' . $count . ' concurrent, ' . $tpPercent . '% TP) on ' . count($symbols) . ' candidate coins from ' . date('Y-m-d H:i', $fromTs) . ' to ' . date('Y-m-d H:i', $toTs) . ' UTC');

        try {
            $result = $engine->run(
                $symbols,
                $fromTs,
                $toTs,
                $count,
                $tpPercent,
                (float) $this->option('nominal-min'),
                (float) $this->option('nominal-max'),
            );
        } catch (\Throwable $e) {
            $this->error("Backtest failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->printSummary($result['summary']);

        return self::SUCCESS;
    }

    private function printSummary(array $summary): void
    {
        if (($summary['total_trades'] ?? 0) === 0) {
            $this->warn('No trades were simulated in this range.');
            return;
        }

        $this->table(['Metric', 'Value'], [
            ['Concurrent positions', $summary['requested_concurrent_positions']],
            ['Total trades', $summary['total_trades']],
            ['Win rate', "{$summary['win_rate_pct']}%"],
            ['Average profit', "\${$summary['average_profit_usdt']}"],
            ['Average loss', "\${$summary['average_loss_usdt']}"],
            ['Net profit after fees', "\${$summary['net_profit_after_fees_usdt']}"],
            ['Max drawdown', "\${$summary['max_drawdown_usdt']}"],
            ['Largest daily loss', "\${$summary['largest_daily_loss_usdt']}"],
            ['Avg hold time', "{$summary['avg_hold_minutes']} min"],
            ['Take-profit hits', $summary['take_profit_hits']],
            ['Still open at test end', $summary['still_open_at_test_end']],
            ['Best pairs', implode(', ', $summary['best_pairs'])],
            ['Worst pairs', implode(', ', $summary['worst_pairs'])],
        ]);
    }
}
