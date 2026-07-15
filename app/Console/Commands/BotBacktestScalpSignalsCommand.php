<?php

namespace App\Console\Commands;

use App\Bot\Backtest\ScalpSignalBacktestEngine;
use App\Bot\Config\BotConfig;
use Illuminate\Console\Command;

/**
 * Backtests the Scalp Scanner's three newest signals — Market Structure, Candle
 * Reading, and FVG — in isolation from RSI/MACD/WaveTrend, so their standalone
 * quality can be judged before trusting them. Never sends orders, never touches
 * bot_trades.
 */
class BotBacktestScalpSignalsCommand extends Command
{
    protected $signature = 'bot:backtest-scalp-signals
        {symbols?* : Symbols to test, e.g. BTC_USDT ETH_USDT (defaults to config bot.default_pairs)}
        {--from= : Start date (Y-m-d or Y-m-d H:i), UTC}
        {--to= : End date (Y-m-d or Y-m-d H:i), UTC. Defaults to now}
        {--days=7 : Days of history to test, used when --from is omitted}
        {--tp-percent=1.0 : Take-profit distance as a % move from entry}
        {--sl-atr=1.5 : Stop-loss distance as a multiple of 15M ATR}';

    protected $description = 'Backtest the Scalp Scanner\'s Market Structure / Candle Reading / FVG signals in isolation';

    public function handle(ScalpSignalBacktestEngine $engine): int
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

        $tpPercent = (float) $this->option('tp-percent');
        $slAtr     = (float) $this->option('sl-atr');

        $this->info('Backtesting Market Structure + Candle Reading + FVG (' . $tpPercent . '% TP, ' . $slAtr . 'x ATR SL) on ' . implode(', ', $symbols) . ' from ' . date('Y-m-d H:i', $fromTs) . ' to ' . date('Y-m-d H:i', $toTs) . ' UTC');

        try {
            $result = $engine->run($symbols, $fromTs, $toTs, $tpPercent, $slAtr);
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
            ['Total trades', $summary['total_trades']],
            ['Win rate', "{$summary['win_rate_pct']}%"],
            ['Average profit', "\${$summary['average_profit_usdt']}"],
            ['Average loss', "\${$summary['average_loss_usdt']}"],
            ['Net profit after fees', "\${$summary['net_profit_after_fees_usdt']}"],
            ['Max drawdown', "\${$summary['max_drawdown_usdt']}"],
            ['Take-profit hits', $summary['take_profit_hits']],
            ['Stop-loss hits', $summary['stop_loss_hits']],
            ['Still open at test end', $summary['still_open_at_test_end']],
        ]);

        $this->info('By direction:');
        $rows = [];
        foreach ($summary['by_direction'] as $dir => $d) {
            $rows[] = [$dir, $d['count'], round($d['net'], 2)];
        }
        $this->table(['Direction', 'Trades', 'Net'], $rows);

        $this->info('By matched signal:');
        $rows = [];
        foreach ($summary['by_matched_signal'] as $match => $d) {
            $winRate = round(($d['wins'] ?? 0) / max($d['count'], 1) * 100, 1);
            $rows[] = [$match, $d['count'], "{$winRate}%", round($d['net'], 2)];
        }
        $this->table(['Matched on', 'Trades', 'Win rate', 'Net'], $rows);
    }
}
