<?php

namespace App\Manual;

/**
 * Builds the coach's prompt from a grade DailyGrader has already produced and returns
 * its structured review. The grade is recomputed server-side before this is called, so
 * nothing the browser sends can change what the coach is told.
 */
class DayCoachService
{
    private const TIMEOUT_SECONDS = 30;

    // DeepSeek Flash list prices at the peak-hours rate (2x off-peak), so the cost shown
    // to the user is a ceiling rather than an underestimate. Same figures as
    // HedgeAdvisorService.
    private const INPUT_COST_PER_MILLION  = 0.30;
    private const OUTPUT_COST_PER_MILLION = 1.20;

    /**
     * @param  array<string, mixed>  $grade  A DailyGrader grade with a non-null score.
     * @return array{headline: string, went_well: string, cost_you: string, focus_tomorrow: string, estimated_cost_usd: float}
     */
    public function review(array $grade): array
    {
        $response = (new DayCoachAgent)->prompt($this->buildPrompt($grade), timeout: self::TIMEOUT_SECONDS);

        $text = fn (string $key) => is_string($response[$key] ?? null) ? $response[$key] : '';

        $in  = (int) ($response->usage->promptTokens ?? 0);
        $out = (int) ($response->usage->completionTokens ?? 0);

        return [
            'headline'           => $text('headline'),
            'went_well'          => $text('went_well'),
            'cost_you'           => $text('cost_you'),
            'focus_tomorrow'     => $text('focus_tomorrow'),
            'estimated_cost_usd' => round(
                ($in / 1_000_000) * self::INPUT_COST_PER_MILLION + ($out / 1_000_000) * self::OUTPUT_COST_PER_MILLION,
                6,
            ),
        ];
    }

    private function buildPrompt(array $g): string
    {
        $s = $g['stats'];

        $lines = [
            "Date (UTC): {$g['date']}",
            "Grade: {$g['letter']} ({$g['score']}/100)".($g['partial'] ? ' — PARTIAL, no data for: '.implode(', ', $g['missing']) : ''),
            '',
            'COMPONENTS (score out of 100, weight):',
        ];

        foreach ($g['components'] as $c) {
            $detail = $c['detail'] === [] ? '' : ' — '.json_encode($c['detail']);
            $lines[] = '- '.ucfirst($c['name']).' (weight '.$c['weight'].'): '.($c['score'] ?? 'no data').$detail;
        }

        $lines[] = '';
        $lines[] = 'WHAT THE SYSTEM FLAGGED:';

        foreach ($g['flags'] as $flag) {
            $lines[] = '- ['.$flag['tone'].'] '.$flag['text'];
        }

        if ($g['flags'] === []) {
            $lines[] = '- Nothing flagged.';
        }

        $lines[] = '';
        $lines[] = 'NUMBERS:';
        $lines[] = "- Closed trades: {$s['trades']} ({$s['wins']} wins, {$s['losses']} losses); net realized PnL {$s['net_pnl']}; win rate ".($s['win_rate'] ?? 'n/a').'%';
        $lines[] = '- Average win '.($s['avg_win'] ?? 'n/a').', average loss '.($s['avg_loss'] ?? 'n/a').', worst trade '.($s['worst'] ?? 'n/a').', best trade '.($s['best'] ?? 'n/a');
        $lines[] = '- Average hold '.($s['avg_hold_minutes'] ?? 'n/a').' minutes';
        $lines[] = "- Logged decisions: {$s['entries']} entries, {$s['reduces']} reduces/closes, {$s['locks']} locks, {$s['early_unlocks']} early unlocks, {$s['blocked_attempts']} attempts to touch a locked position";

        if ($g['trades'] !== []) {
            $lines[] = '';
            $lines[] = 'CLOSED TRADES (oldest first):';

            foreach (array_slice($g['trades'], 0, 25) as $t) {
                $lines[] = "- {$t['symbol']} {$t['direction']}: PnL {$t['pnl']}, held ".($t['hold_minutes'] ?? 'n/a').' min'.($t['hasty'] ? ' (hasty)' : '');
            }
        }

        return implode("\n", $lines);
    }
}
