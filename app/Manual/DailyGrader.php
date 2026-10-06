<?php

namespace App\Manual;

/**
 * Turns one day of closed trades and logged decisions into a grade. Pure and
 * deterministic: the same inputs always give the same grade, every number is shown in
 * the breakdown, and the AI coach only explains it — it never sets it.
 *
 * Four components, weighted by how much they say about the *process* rather than the
 * outcome (a good decision can lose money, and a bad one can win):
 *
 *   Result   (20)  profit factor of the day's closed trades
 *   Risk     (25)  average win vs average loss, and whether any one loss was outsized
 *   Patience (30)  trades closed in minutes, over-trading, locks released early, and
 *                  attempts to touch a locked position
 *   Process  (25)  whether each logged entry was at a confirmed plan zone and with the
 *                  4H backdrop (only exists for entries made after logging began)
 *
 * A component with nothing to measure is left out and the others are re-weighted, and
 * the grade is then marked partial so it is never mistaken for a full one.
 */
class DailyGrader
{
    public const WEIGHTS = ['result' => 20, 'risk' => 25, 'patience' => 30, 'process' => 25];

    /** A position closed within this many minutes of being opened counts as hasty. */
    public const HASTY_MINUTES = 15;

    /** Trades closed in a day before over-trading starts costing points. */
    public const COMFORT_TRADES = 8;

    /** A stop/TP set within this many minutes after an entry counts as protecting it. */
    private const PROTECT_WITHIN_MINUTES = 30;

    private const ENTRY_QUALITY = ['confirmed_zone' => 100, 'unconfirmed_zone' => 55, 'no_zone' => 25];

    /**
     * @param  array<int, array{symbol: string, direction: ?string, pnl: float, leverage: ?int, opened_at: ?int, closed_at: int, hold_minutes: ?float}>  $trades  Closed that day.
     * @param  array<int, array{type: string, symbol: string, direction: ?string, details: ?array, context: ?array, at: int}>  $events  Logged that day (unix seconds).
     * @return array<string, mixed>
     */
    public function grade(string $date, array $trades, array $events): array
    {
        $stats = $this->stats($trades, $events);

        $components = [
            'result'   => $this->result($stats),
            'risk'     => $this->risk($stats),
            'patience' => $this->patience($trades, $events, $stats),
            'process'  => $this->process($events),
        ];

        $weighted = 0.0;
        $weights  = 0;
        $missing  = [];

        foreach ($components as $name => $component) {
            if ($component['score'] === null) {
                $missing[] = $name;

                continue;
            }

            $weighted += $component['score'] * self::WEIGHTS[$name];
            $weights  += self::WEIGHTS[$name];
        }

        $score = $weights > 0 ? (int) round($weighted / $weights) : null;

        $flags = [];

        foreach ($components as $component) {
            array_push($flags, ...$component['flags']);
        }

        usort($flags, fn ($a, $b) => ['warn' => 0, 'info' => 1, 'good' => 2][$a['tone']] <=> ['warn' => 0, 'info' => 1, 'good' => 2][$b['tone']]);

        return [
            'date'       => $date,
            'score'      => $score,
            'letter'     => $score === null ? null : $this->letter($score),
            'partial'    => $score !== null && $missing !== [],
            'missing'    => $missing,
            'components' => array_map(
                fn ($name, $c) => ['name' => $name, 'weight' => self::WEIGHTS[$name], 'score' => $c['score'], 'detail' => $c['detail']],
                array_keys($components),
                array_values($components),
            ),
            'flags'      => $flags,
            'stats'      => $stats,
            'trades'     => array_map(
                fn ($t) => $t + ['hasty' => $t['hold_minutes'] !== null && $t['hold_minutes'] < self::HASTY_MINUTES],
                $trades,
            ),
        ];
    }

    public function letter(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default      => 'F',
        };
    }

    private function stats(array $trades, array $events): array
    {
        $pnls   = array_column($trades, 'pnl');
        $wins   = array_values(array_filter($pnls, fn ($p) => $p > 0));
        $losses = array_values(array_filter($pnls, fn ($p) => $p < 0));
        $holds  = array_values(array_filter(array_column($trades, 'hold_minutes'), fn ($h) => $h !== null));
        $count  = fn (string $type) => count(array_filter($events, fn ($e) => $e['type'] === $type));

        return [
            'trades'            => count($trades),
            'wins'              => count($wins),
            'losses'            => count($losses),
            'net_pnl'           => round(array_sum($pnls), 4),
            'gross_win'         => round(array_sum($wins), 4),
            'gross_loss'        => round(abs(array_sum($losses)), 4),
            'win_rate'          => $trades === [] ? null : round(count($wins) / count($trades) * 100, 1),
            'avg_win'           => $wins === [] ? null : round(array_sum($wins) / count($wins), 4),
            'avg_loss'          => $losses === [] ? null : round(abs(array_sum($losses)) / count($losses), 4),
            'max_loss'          => $losses === [] ? null : round(abs(min($losses)), 4),
            // From the raw numbers, not the rounded ones above, so an exact ratio can't
            // tip over a threshold or a rounding boundary because of 4-decimal rounding.
            'max_loss_vs_avg'   => $losses === [] ? null : abs(min($losses)) / (abs(array_sum($losses)) / count($losses)),
            'best'              => $pnls === [] ? null : round(max($pnls), 4),
            'worst'             => $pnls === [] ? null : round(min($pnls), 4),
            'avg_hold_minutes'  => $holds === [] ? null : round(array_sum($holds) / count($holds), 1),
            'entries'           => $count('entry'),
            'reduces'           => $count('reduce') + $count('close') + $count('close_all'),
            'locks'             => $count('lock'),
            'early_unlocks'     => $count('unlock_early'),
            'blocked_attempts'  => $count('blocked_attempt'),
        ];
    }

    private function result(array $stats): array
    {
        if ($stats['trades'] === 0) {
            return $this->component(null);
        }

        $win  = $stats['gross_win'];
        $loss = $stats['gross_loss'];

        $score = match (true) {
            $loss == 0 && $win > 0 => 100,
            $loss == 0             => 50,
            $win == 0              => 0,
            default                => (int) max(0, min(100, round(50 * $win / $loss))),
        };

        return $this->component($score, [
            'net_pnl'       => $stats['net_pnl'],
            'profit_factor' => $loss > 0 ? round($win / $loss, 2) : null,
            'win_rate'      => $stats['win_rate'],
        ], [
            $stats['net_pnl'] < 0
                ? $this->flag('info', 'Net realized PnL was −$'.number_format(abs($stats['net_pnl']), 2)." across {$stats['trades']} closed trade".($stats['trades'] === 1 ? '' : 's').'.')
                : $this->flag('info', 'Net realized PnL was +$'.number_format($stats['net_pnl'], 2)." across {$stats['trades']} closed trade".($stats['trades'] === 1 ? '' : 's').'.'),
        ]);
    }

    private function risk(array $stats): array
    {
        if ($stats['trades'] === 0) {
            return $this->component(null);
        }

        $avgWin  = $stats['avg_win'];
        $avgLoss = $stats['avg_loss'];

        $payoff = match (true) {
            $avgLoss === null => 100,
            $avgWin === null  => 0,
            default           => (int) max(0, min(100, round(50 * $avgWin / $avgLoss))),
        };

        $ratio    = $stats['max_loss_vs_avg'] ?? 1.0;
        $outsized = $avgLoss === null ? 100 : (int) max(0, min(100, round($ratio <= 2 ? 100 : 100 - 25 * ($ratio - 2))));

        $flags = [];

        if ($avgWin !== null && $avgLoss !== null && $avgLoss > $avgWin) {
            $flags[] = $this->flag('warn', 'Your average loss ($'.number_format($avgLoss, 2).') is bigger than your average win ($'.number_format($avgWin, 2).').');
        }

        if ($stats['losses'] >= 2 && $ratio > 2) {
            $flags[] = $this->flag('warn', 'Your biggest loss ($'.number_format($stats['max_loss'], 2).') was '.round($ratio, 1).'× your average loss.');
        }

        return $this->component((int) round(0.6 * $payoff + 0.4 * $outsized), [
            'avg_win'          => $avgWin,
            'avg_loss'         => $avgLoss,
            'payoff_score'     => $payoff,
            'max_loss_vs_avg'  => $avgLoss === null ? null : round($ratio, 2),
        ], $flags);
    }

    private function patience(array $trades, array $events, array $stats): array
    {
        $parts = [];
        $flags = [];

        $known = array_values(array_filter($trades, fn ($t) => $t['hold_minutes'] !== null));

        if ($known !== []) {
            $hasty = count(array_filter($known, fn ($t) => $t['hold_minutes'] < self::HASTY_MINUTES));

            $parts['quick_closes'] = (int) round(100 * (1 - $hasty / count($known)));

            if ($hasty > 0) {
                $flags[] = $this->flag('warn', "{$hasty} of ".count($known).' closed trade'.(count($known) === 1 ? ' was' : 's were').' held under '.self::HASTY_MINUTES.' minutes.');
            } elseif (count($known) >= 2) {
                $flags[] = $this->flag('good', 'No trade was closed in under '.self::HASTY_MINUTES.' minutes.');
            }
        }

        if ($stats['trades'] > 0) {
            $parts['trade_count'] = $stats['trades'] <= self::COMFORT_TRADES
                ? 100
                : max(0, 100 - 8 * ($stats['trades'] - self::COMFORT_TRADES));

            if ($stats['trades'] > self::COMFORT_TRADES) {
                $flags[] = $this->flag('warn', "{$stats['trades']} trades closed today — over the ".self::COMFORT_TRADES.'-trade comfort line.');
            }
        }

        $early   = $stats['early_unlocks'];
        $blocked = $stats['blocked_attempts'];

        if ($early + $blocked + $stats['locks'] > 0) {
            $parts['lock_discipline'] = max(0, 100 - 25 * $early - 10 * $blocked);

            if ($early > 0) {
                $flags[] = $this->flag('warn', "{$early} lock".($early === 1 ? ' was' : 's were').' released before '.($early === 1 ? 'it' : 'they').' expired.');
            }

            if ($blocked > 0) {
                $flags[] = $this->flag('warn', "{$blocked} attempt".($blocked === 1 ? '' : 's').' to touch a position you had locked.');
            }

            if ($early === 0 && $blocked === 0 && $stats['locks'] > 0) {
                $flags[] = $this->flag('good', 'Every lock you set was left alone.');
            }
        }

        // Half the weakest part, half the average: patience is the trader's stated weak spot,
        // so a hasty day must not be diluted by an easy part (trade count is nearly always
        // fine) — but one bad part should not erase the others either.
        $score = $parts === [] ? null : (int) round(0.5 * min($parts) + 0.5 * array_sum($parts) / count($parts));

        return $this->component($score, $parts, $flags);
    }

    private function process(array $events): array
    {
        $entries = array_values(array_filter($events, fn ($e) => $e['type'] === 'entry'));
        $scored  = array_values(array_filter($entries, fn ($e) => isset(self::ENTRY_QUALITY[$e['context']['zone_quality'] ?? ''])));

        $flags = [];

        if ($entries !== [] && count($scored) < count($entries)) {
            $flags[] = $this->flag('info', (count($entries) - count($scored)).' entr'.(count($entries) - count($scored) === 1 ? 'y' : 'ies').' could not be scored (no analysis snapshot was captured).');
        }

        if ($scored === []) {
            return $this->component(null, [], $flags);
        }

        $points      = [];
        $confirmed   = 0;
        $noZone      = 0;
        $againstHtf  = 0;

        foreach ($scored as $entry) {
            $quality = $entry['context']['zone_quality'];
            $base    = self::ENTRY_QUALITY[$quality];

            $confirmed += $quality === 'confirmed_zone' ? 1 : 0;
            $noZone    += $quality === 'no_zone' ? 1 : 0;

            if (($entry['context']['with_higher_tf'] ?? null) === false) {
                $againstHtf++;
                $base = max(0, $base - 20);
            }

            $points[] = $base;
        }

        $total = count($scored);

        $flags[] = $confirmed === $total
            ? $this->flag('good', $total === 1 ? 'Your entry was at a fully confirmed zone.' : "All {$total} entries were at a fully confirmed zone.")
            : $this->flag($confirmed > 0 ? 'info' : 'warn', "{$confirmed} of {$total} entr".($total === 1 ? 'y was' : 'ies were').' at a fully confirmed zone.');

        if ($noZone > 0) {
            $flags[] = $this->flag('warn', "{$noZone} entr".($noZone === 1 ? 'y' : 'ies').' had no plan zone near price.');
        }

        if ($againstHtf > 0) {
            $flags[] = $this->flag('warn', "{$againstHtf} entr".($againstHtf === 1 ? 'y' : 'ies').' went against the 4H backdrop.');
        }

        $protected = 0;

        foreach ($scored as $entry) {
            foreach ($events as $other) {
                if ($other['type'] === 'sl_tp'
                    && $other['symbol'] === $entry['symbol']
                    && $other['at'] >= $entry['at']
                    && $other['at'] - $entry['at'] <= self::PROTECT_WITHIN_MINUTES * 60) {
                    $protected++;

                    break;
                }
            }
        }

        $flags[] = $this->flag('info', "{$protected} of {$total} entr".($total === 1 ? 'y' : 'ies').' had a stop or take-profit set within '.self::PROTECT_WITHIN_MINUTES.' minutes.');

        return $this->component((int) round(array_sum($points) / $total), [
            'entries_scored'     => $total,
            'at_confirmed_zone'  => $confirmed,
            'no_zone'            => $noZone,
            'against_4h'         => $againstHtf,
            'protected'          => $protected,
        ], $flags);
    }

    /** @return array{score: ?int, detail: array, flags: array} */
    private function component(?int $score, array $detail = [], array $flags = []): array
    {
        return ['score' => $score, 'detail' => $detail, 'flags' => $flags];
    }

    /** @return array{tone: string, text: string} */
    private function flag(string $tone, string $text): array
    {
        return ['tone' => $tone, 'text' => $text];
    }
}
