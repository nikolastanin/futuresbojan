<?php

namespace App\Console\Commands;

use App\Bot\Backtest\BacktestEngine;
use App\Bot\Config\BotConfig;
use App\Models\BotBacktest;
use Illuminate\Console\Command;

/**
 * Runs the strategy against historical MEXC candles — never sends orders, never
 * touches bot_trades. Separate from live trading per the spec's requirement that
 * backtesting run in isolation.
 */
class BotBacktestCommand extends Command
{
    protected $signature = 'bot:backtest
        {symbols?* : Symbols to test, e.g. BTC_USDT ETH_USDT (defaults to config bot.default_pairs)}
        {--from= : Start date (Y-m-d or Y-m-d H:i), UTC}
        {--to= : End date (Y-m-d or Y-m-d H:i), UTC. Defaults to now}
        {--days=7 : Days of history to test, used when --from is omitted}';

    protected $description = 'Backtest the current strategy config against historical MEXC data';

    public function handle(BacktestEngine $engine): int
    {
        $symbols = $this->argument('symbols') ?: BotConfig::get('default_pairs');

        $toTs = $this->option('to') ? strtotime($this->option('to') . ' UTC') : time();
        $fromTs = $this->option('from')
            ? strtotime($this->option('from') . ' UTC')
            : $toTs - ((int) $this->option('days') * 86400);

        if (! $fromTs || ! $toTs || $fromTs >= $toTs) {
            $this->error('Invalid date range.');
            return self::FAILURE;
        }

        $this->info('Backtesting ' . implode(', ', $symbols) . ' from ' . date('Y-m-d H:i', $fromTs) . ' to ' . date('Y-m-d H:i', $toTs) . ' UTC');
        $this->warn('Reads live config (config/bot.php + bot_settings) — avoid running alongside a live bot:run process if you plan to temporarily override config values for this test.');

        $backtest = BotBacktest::create([
            'symbols'         => $symbols,
            'range_from'      => date('Y-m-d H:i:s', $fromTs),
            'range_to'        => date('Y-m-d H:i:s', $toTs),
            'config_snapshot' => config('bot'),
            'status'          => 'running',
            'started_at'      => now(),
        ]);

        try {
            $result = $engine->run($symbols, $fromTs, $toTs);
        } catch (\Throwable $e) {
            $backtest->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
            $this->error("Backtest failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $backtest->update([
            'status'       => 'completed',
            'summary'      => $result['summary'],
            'trades'       => $result['trades'],
            'completed_at' => now(),
        ]);

        $this->printSummary($result['summary']);
        $this->info("Saved as backtest #{$backtest->id}.");

        return self::SUCCESS;
    }

    private function printSummary(array $summary): void
    {
        if (($summary['total_trades'] ?? 0) === 0) {
            $this->warn('No trades were simulated in this range.');
            return;
        }

        $this->table(['Metric', 'Value'], [
            ['Total trades', $summary['total_trades']],
            ['Win rate', "{$summary['win_rate_pct']}%"],
            ['Average profit', "\${$summary['average_profit_usdt']}"],
            ['Average loss', "\${$summary['average_loss_usdt']}"],
            ['Net profit after fees', "\${$summary['net_profit_after_fees_usdt']}"],
            ['Max drawdown', "\${$summary['max_drawdown_usdt']}"],
            ['Largest daily loss', "\${$summary['largest_daily_loss_usdt']}"],
            ['Avg confidence (winners)', $summary['avg_confidence_winning_trades'] ?? 'n/a'],
            ['Avg confidence (losers)', $summary['avg_confidence_losing_trades'] ?? 'n/a'],
            ['Smart exit activations', $summary['smart_exit_activations']],
            ['Trailing TP activations', $summary['trailing_tp_activations']],
            ['Stop loss activations', $summary['stop_loss_activations']],
            ['Best pairs', implode(', ', $summary['best_pairs'])],
            ['Worst pairs', implode(', ', $summary['worst_pairs'])],
        ]);
    }
}
