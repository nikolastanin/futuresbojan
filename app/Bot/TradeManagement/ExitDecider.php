<?php

namespace App\Bot\TradeManagement;

use App\Bot\Config\BotConfig;

/**
 * Pure exit-priority decision: stop-loss (unconditional safety) -> trailing TP ->
 * static take-profit -> smart exit -> time exit. No I/O, no persistence — shared by
 * TradeManager (live/paper trades) and BacktestEngine (simulated trades) so the two
 * can never silently diverge on when a position should close.
 */
class ExitDecider
{
    /**
     * @param \Closure $momentumWeakening () => bool. Called lazily, only when momentum
     *        actually needs checking (smart exit is in range) — it's an API-backed check,
     *        so we don't want to pay for it on every trade every cycle unconditionally.
     * @param float $targetNetProfit The trade's own static take-profit target — caller-
     *        supplied (PositionSizingService::targetNetProfitForConfidence() against the
     *        trade's stored confidence) rather than read from config here, since different
     *        trades can have different targets depending on the confidence they opened at.
     * @return array{action: ?string, trailing_active: bool, peak_net_profit_usdt: ?float}
     * action is null (stay open) or one of: stop_loss, trailing_tp, take_profit, smart_exit, time_exit.
     */
    public function decide(
        bool $stopLossHit,
        float $netPnl,
        bool $trailingActive,
        ?float $peakNetProfitUsdt,
        \Closure $momentumWeakening,
        int $minutesOpen,
        float $targetNetProfit,
    ): array {
        // 1. Stop loss — unconditional safety check, always evaluated first.
        if ($stopLossHit) {
            return $this->result('stop_loss', $trailingActive, $peakNetProfitUsdt);
        }

        // 2. Trailing TP — once activated, it owns the exit decision: track the highest
        // net profit seen, close on a pullback from that peak rather than a static TP.
        if (BotConfig::get('trailing_tp_enabled')) {
            $activation = BotConfig::get('trailing_activation_net_profit');

            if ($trailingActive || $netPnl >= $activation) {
                $peak = max($netPnl, $peakNetProfitUsdt ?? $netPnl);
                $callback = BotConfig::get('trailing_callback_net_profit');
                $shouldClose = ($peak - $netPnl) >= $callback;

                return $this->result($shouldClose ? 'trailing_tp' : null, true, $peak);
            }
        }

        // 3. Static take profit (only reached if trailing is disabled or not yet activated).
        if ($netPnl >= $targetNetProfit) {
            return $this->result('take_profit', $trailingActive, $peakNetProfitUsdt);
        }

        // 4. Smart exit — close early if near target but momentum is fading, rather than
        // risking a round-trip back toward break-even/loss waiting for full TP.
        if (BotConfig::get('smart_exit_enabled')) {
            $minProfit = BotConfig::get('smart_exit_min_net_profit');
            if ($netPnl >= $minProfit && $momentumWeakening()) {
                return $this->result('smart_exit', $trailingActive, $peakNetProfitUsdt);
            }
        }

        // 5. Time exit.
        $maxDurationMinutes = BotConfig::get('max_position_duration_minutes');
        if ($minutesOpen >= $maxDurationMinutes && $netPnl <= 0) {
            return $this->result('time_exit', $trailingActive, $peakNetProfitUsdt);
        }

        return $this->result(null, $trailingActive, $peakNetProfitUsdt);
    }

    private function result(?string $action, bool $trailingActive, ?float $peak): array
    {
        return ['action' => $action, 'trailing_active' => $trailingActive, 'peak_net_profit_usdt' => $peak];
    }
}
