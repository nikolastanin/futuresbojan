<?php

namespace App\Bot\TradeManagement;

use App\Bot\Config\BotConfig;
use App\Bot\Hedge\HedgeManager;
use App\Bot\Indicators\IndicatorService;
use App\Bot\Logging\BotLogger;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Orders\OrderManager;
use App\Bot\Risk\RiskManager;
use App\Bot\Sizing\PositionSizingService;
use App\Models\BotSignal;
use App\Models\BotTrade;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of a trade after SignalEngine produces a qualifying signal:
 * risk-checks it, sizes it (splitting into a main+hedge leg pair when HedgeManager
 * calls for it), sends it through OrderManager, and — every cycle — checks every
 * open position (or hedge set) for stop-loss / trailing-TP / static-TP / smart-exit /
 * time-exit / daily-loss-breach and closes it through OrderManager when triggered.
 */
class TradeManager
{
    public function __construct(
        private RiskManager $risk,
        private OrderManager $orders,
        private PositionSizingService $sizing,
        private IndicatorService $indicators,
        private HedgeManager $hedge,
        private ExitDecider $exitDecider,
    ) {}

    /**
     * Attempts to open a trade for a qualifying signal. Always updates the signal's
     * opened/skip_reason with the real outcome, whether or not the trade opened.
     */
    public function openTrade(BotSignal $signal, array $contractDetail): void
    {
        $marginUsd = $this->sizing->marginForConfidence($signal->confidence_score);
        $leverage  = BotConfig::get('leverage');
        $feeRate   = isset($contractDetail['takerFeeRate']) ? (float) $contractDetail['takerFeeRate'] : null;

        $riskResult = $this->risk->evaluate(
            $signal->symbol,
            $signal->direction,
            $marginUsd,
            (float) $signal->entry_price,
            (float) $signal->stop_loss,
            $contractDetail,
        );

        if (! $riskResult['allowed']) {
            $signal->update(['opened' => false, 'skip_reason' => $riskResult['reason']]);
            return;
        }

        $tradeSetId = (string) Str::uuid();

        $mainTrade = $this->hedge->shouldHedge($signal->confidence_score)
            ? $this->openHedgeSet($signal, $contractDetail, $tradeSetId, $marginUsd, $leverage, $feeRate)
            : $this->openLeg(
                $signal->symbol, $signal->direction, $marginUsd, $leverage,
                (float) $signal->entry_price, (float) $signal->take_profit, (float) $signal->stop_loss,
                $contractDetail, $tradeSetId, 'main', $signal->id, $signal->confidence_score, $signal->reasons,
                (float) $signal->estimated_fee_usdt,
            );

        if (! $mainTrade) {
            $signal->update(['opened' => false, 'skip_reason' => 'order_failed: main leg']);
            return;
        }

        $signal->update(['opened' => true, 'skip_reason' => null]);
    }

    /**
     * Opens both legs of a hedge set: a larger main position in the signal's direction
     * plus a smaller opposite-direction hedge, sized per main/hedge_position_ratio.
     * Stop-loss distance is technical (ATR-derived) and margin-independent, so both legs
     * reuse the same $ distance from entry that the signal already computed for the main
     * direction. Take-profit is margin-dependent, so each leg gets its own proportional
     * target — informational only; manageOpenPositions() actually closes hedge sets on
     * *combined* net PnL across both legs, not either leg's individual TP.
     *
     * Returns the main leg (or null if even the main leg failed to open) — the caller only
     * needs to know whether *a* position now exists to track; the hedge leg is best-effort.
     */
    private function openHedgeSet(BotSignal $signal, array $contractDetail, string $tradeSetId, float $totalMarginUsd, int $leverage, ?float $feeRate): ?BotTrade
    {
        $split          = $this->hedge->splitMargin($totalMarginUsd);
        $entryPrice     = (float) $signal->entry_price;
        $hedgeDirection = $signal->direction === 'LONG' ? 'SHORT' : 'LONG';
        $slDistance     = abs($entryPrice - (float) $signal->stop_loss);
        $targetTotal    = BotConfig::get('target_net_profit_per_trade');
        $mainRatio      = BotConfig::get('main_position_ratio');
        $hedgeRatioCfg  = BotConfig::get('hedge_position_ratio');

        $mainStopLoss  = $signal->direction === 'LONG' ? $entryPrice - $slDistance : $entryPrice + $slDistance;
        $hedgeStopLoss = $hedgeDirection === 'LONG' ? $entryPrice - $slDistance : $entryPrice + $slDistance;

        $mainTakeProfit  = $this->sizing->takeProfitForMargin($signal->direction, $split['main'], $entryPrice, $feeRate, $targetTotal * $mainRatio);
        $hedgeTakeProfit = $this->sizing->takeProfitForMargin($hedgeDirection, $split['hedge'], $entryPrice, $feeRate, $targetTotal * $hedgeRatioCfg);

        $mainTrade = $this->openLeg(
            $signal->symbol, $signal->direction, $split['main'], $leverage,
            $entryPrice, $mainTakeProfit, $mainStopLoss, $contractDetail, $tradeSetId, 'main',
            $signal->id, $signal->confidence_score, $signal->reasons,
            $this->sizing->feesForMargin($split['main'], $feeRate),
        );

        if (! $mainTrade) {
            return null;
        }

        $hedgeTrade = $this->openLeg(
            $signal->symbol, $hedgeDirection, $split['hedge'], $leverage,
            $entryPrice, $hedgeTakeProfit, $hedgeStopLoss, $contractDetail, $tradeSetId, 'hedge',
            $signal->id, $signal->confidence_score, $signal->reasons,
            $this->sizing->feesForMargin($split['hedge'], $feeRate),
        );

        $exposure = $this->hedge->netExposure($signal->direction, $split['main'] * $leverage, $split['hedge'] * $leverage);

        BotLogger::info('trade_manager', "Hedge set opened for {$signal->symbol}: main {$signal->direction} \${$split['main']} / hedge {$hedgeDirection} \${$split['hedge']}"
            . ($hedgeTrade ? '' : ' — hedge leg FAILED, running main-only'), [
            'trade_set_id'     => $tradeSetId,
            'main_margin_usd'  => $split['main'],
            'hedge_margin_usd' => $split['hedge'],
            'net_exposure'     => $exposure,
            'hedge_leg_opened' => $hedgeTrade !== null,
        ], $signal->symbol);

        return $mainTrade;
    }

    /** Opens one leg (main or hedge) via OrderManager and persists it. Returns null on order failure. */
    private function openLeg(
        string $symbol, string $direction, float $marginUsd, int $leverage,
        float $entryPrice, float $takeProfit, float $stopLoss, array $contractDetail,
        string $tradeSetId, string $leg, ?int $signalId, int $confidence, ?array $reasonForEntry, ?float $feeUsdt,
    ): ?BotTrade {
        $orderResult = $this->orders->open($symbol, $direction, $marginUsd, $leverage, $entryPrice, $stopLoss, $contractDetail);

        if (! $orderResult['success']) {
            BotLogger::error('trade_manager', "Failed to open {$leg} leg for {$symbol}: {$orderResult['error']}", [], $symbol);
            return null;
        }

        $trade = BotTrade::create([
            'trade_set_id'     => $tradeSetId,
            'leg'              => $leg,
            'bot_signal_id'    => $signalId,
            'symbol'           => $symbol,
            'direction'        => $direction,
            'margin_usd'       => $marginUsd,
            'leverage'         => $leverage,
            'contract_vol'     => $orderResult['contract_vol'],
            'order_id'         => $orderResult['order_id'],
            'entry_price'      => $orderResult['fill_price'],
            'take_profit'      => $takeProfit,
            'stop_loss'        => $stopLoss,
            'fee_usdt'         => $feeUsdt,
            'confidence_score' => $confidence,
            'reason_for_entry' => $reasonForEntry,
            'mode'             => $orderResult['mode'],
            'status'           => 'open',
            'opened_at'        => now(),
        ]);

        BotLogger::info('trade_manager', "Opened {$leg} leg: {$direction} {$symbol} margin \${$marginUsd} ({$orderResult['mode']})", [
            'trade_set_id' => $tradeSetId, 'confidence' => $confidence,
        ], $symbol);

        return $trade;
    }

    /**
     * Runs every cycle: force-closes everything if the daily loss limit is breached,
     * otherwise groups open trades by trade_set_id and manages each position (single-leg
     * or hedge set) for stop-loss / trailing-TP / static-TP / smart-exit / time-exit.
     */
    public function manageOpenPositions(MarketDataService $marketData): void
    {
        $openTrades = BotTrade::where('status', 'open')->get();

        if ($openTrades->isEmpty()) {
            return;
        }

        if ($this->dailyLossLimitBreached()) {
            BotLogger::warning('trade_manager', 'Daily loss limit breached — closing all open positions');
            $this->closeAll($marketData, 'daily_loss_limit_breached');
            return;
        }

        $tickers = $marketData->getAllTickers();

        $openTrades = $this->reconcileRealTrades($openTrades, $tickers);
        if ($openTrades->isEmpty()) {
            return;
        }

        foreach ($openTrades->groupBy('trade_set_id') as $legs) {
            if ($legs->count() >= 2) {
                $this->manageHedgeSet($legs, $tickers, $marketData);
            } else {
                $this->manageSingleLeg($legs->first(), $tickers, $marketData);
            }
        }
    }

    /**
     * A real trade can be closed outside the bot entirely — the manual dashboard,
     * MEXC's own app, or forced liquidation — none of which ever touch bot_trades.
     * Without this check, that row stays stuck 'open' forever: an inflated phantom
     * position counted against max_open_positions/max_total_margin, and a close
     * attempt that would keep failing every cycle since there's nothing left on the
     * exchange to close. Cross-checks every open real-mode trade's symbol+direction
     * against MEXC's actual live positions and reconciles any that are gone, using
     * current market price as a best-effort exit (the real closing fill is unknown
     * since the bot wasn't the one who closed it). Paper trades never touch the
     * exchange, so they're always left as-is and this never fires an API call at
     * all when there are no real trades open.
     *
     * @return Collection<int, BotTrade> the trades that are still genuinely open
     */
    private function reconcileRealTrades(Collection $openTrades, Collection $tickers): Collection
    {
        $realTrades = $openTrades->where('mode', 'real');
        if ($realTrades->isEmpty()) {
            return $openTrades;
        }

        try {
            $liveKeys = collect($this->orders->getLivePositions())
                ->map(fn (array $p) => $p['symbol'] . '|' . $p['positionType'])
                ->flip();
        } catch (\Throwable $e) {
            BotLogger::error('trade_manager', "Failed to fetch live MEXC positions for reconciliation: {$e->getMessage()} — skipping this cycle's check", []);
            return $openTrades;
        }

        return $openTrades->filter(function (BotTrade $trade) use ($liveKeys, $tickers) {
            if ($trade->mode !== 'real') {
                return true;
            }

            $positionType = $trade->direction === 'LONG' ? 1 : 2;
            if ($liveKeys->has($trade->symbol . '|' . $positionType)) {
                return true;
            }

            $exitPrice = $this->currentPrice($trade->symbol, $tickers) ?? (float) $trade->entry_price;
            $netPnl = $this->currentNetPnl($trade, $exitPrice);

            $trade->update([
                'exit_price'      => $exitPrice,
                'net_profit_usdt' => round($netPnl, 4),
                'status'          => 'closed',
                'close_reason'    => 'closed_externally',
                'closed_at'       => now(),
            ]);

            BotLogger::warning('trade_manager', "{$trade->direction} {$trade->symbol} was closed outside the bot (no longer an open MEXC position) — reconciled at current price \${$exitPrice}, net PnL \${$trade->net_profit_usdt} (approximate, not the real closing fill)", [
                'entry_price' => $trade->entry_price,
            ], $trade->symbol);

            return false;
        })->values();
    }

    private function manageSingleLeg(BotTrade $trade, Collection $tickers, MarketDataService $marketData): void
    {
        $currentPrice = $this->currentPrice($trade->symbol, $tickers);
        if ($currentPrice === null) {
            return;
        }

        $netPnl = $this->currentNetPnl($trade, $currentPrice);

        $decision = $this->exitDecider->decide(
            $this->stopLossHit($trade, $currentPrice),
            $netPnl,
            $trade->trailing_active,
            $trade->peak_net_profit_usdt !== null ? (float) $trade->peak_net_profit_usdt : null,
            fn () => $this->momentumWeakening($trade, $marketData),
            $trade->opened_at->diffInMinutes(now()),
        );

        if ($decision['trailing_active'] !== $trade->trailing_active || $decision['peak_net_profit_usdt'] !== $trade->peak_net_profit_usdt) {
            $trade->update(['trailing_active' => $decision['trailing_active'], 'peak_net_profit_usdt' => $decision['peak_net_profit_usdt']]);
        }

        if ($decision['action'] !== null) {
            $this->closeTrade($trade, $currentPrice, $decision['action']);
            return;
        }

        $this->checkBreakeven($trade, $netPnl);
    }

    /**
     * Ratchets a trade's stop-loss to the fee-adjusted break-even price once net
     * profit has stayed at or above the configured trigger for the configured
     * sustain window — one-way (never re-checked once applied), and the sustain
     * timer resets if profit dips back below the trigger before the window
     * elapses. Checked once per cycle, so precision is bounded by
     * signal_scan_interval_seconds. Hedge sets are intentionally out of scope
     * (combined break-even across two legs on the same symbol is a materially
     * different calculation) and skip this — hedging is off by default anyway.
     */
    private function checkBreakeven(BotTrade $trade, float $netPnl): void
    {
        if ($trade->breakeven_applied || ! BotConfig::get('breakeven_enabled')) {
            return;
        }

        $triggerProfit = (float) BotConfig::get('breakeven_trigger_net_profit');

        if ($netPnl < $triggerProfit) {
            if ($trade->breakeven_profit_since !== null) {
                $trade->update(['breakeven_profit_since' => null]);
            }
            return;
        }

        if ($trade->breakeven_profit_since === null) {
            $trade->update(['breakeven_profit_since' => now()]);
            return;
        }

        $sustainMinutes = (int) BotConfig::get('breakeven_sustain_minutes');
        if ($trade->breakeven_profit_since->diffInMinutes(now()) < $sustainMinutes) {
            return;
        }

        $this->applyBreakeven($trade);
    }

    private function applyBreakeven(BotTrade $trade): void
    {
        $breakevenPrice = $this->sizing->breakevenPrice(
            $trade->direction,
            (float) $trade->entry_price,
            (float) $trade->margin_usd,
            $trade->leverage,
            (float) ($trade->fee_usdt ?? 0),
        );

        $this->orders->moveStopLoss($trade->symbol, $trade->direction, $trade->mode, $trade->contract_vol, $breakevenPrice);

        $trade->update(['stop_loss' => $breakevenPrice, 'breakeven_applied' => true]);

        BotLogger::info('trade_manager', "Break-even stop-loss applied for {$trade->direction} {$trade->symbol}: SL moved to {$breakevenPrice}", [
            'entry_price' => $trade->entry_price,
        ], $trade->symbol);
    }

    /**
     * Manages a hedge set (main + hedge leg sharing a trade_set_id) as one unit: the
     * closing decision is driven by *combined* net PnL across both legs, not either
     * leg's individual TP — per the spec, the target is ~$2 net for the whole set.
     * Trailing state is tracked on the main leg's row (authoritative for the set).
     */
    private function manageHedgeSet(Collection $legs, Collection $tickers, MarketDataService $marketData): void
    {
        $mainLeg  = $legs->firstWhere('leg', 'main');
        $hedgeLeg = $legs->firstWhere('leg', 'hedge');

        if (! $mainLeg || ! $hedgeLeg) {
            // Malformed set (shouldn't happen) — fall back to managing each leg independently.
            foreach ($legs as $leg) {
                $this->manageSingleLeg($leg, $tickers, $marketData);
            }
            return;
        }

        $mainPrice  = $this->currentPrice($mainLeg->symbol, $tickers);
        $hedgePrice = $this->currentPrice($hedgeLeg->symbol, $tickers);
        if ($mainPrice === null || $hedgePrice === null) {
            return;
        }

        $combinedNetPnl = $this->currentNetPnl($mainLeg, $mainPrice) + $this->currentNetPnl($hedgeLeg, $hedgePrice);

        // Main-leg stop loss breach closes the whole set (captures the hedge's offsetting
        // gain) — trailing state for the set is tracked on the main leg's row.
        $decision = $this->exitDecider->decide(
            $this->stopLossHit($mainLeg, $mainPrice),
            $combinedNetPnl,
            $mainLeg->trailing_active,
            $mainLeg->peak_net_profit_usdt !== null ? (float) $mainLeg->peak_net_profit_usdt : null,
            fn () => $this->momentumWeakening($mainLeg, $marketData),
            $mainLeg->opened_at->diffInMinutes(now()),
        );

        if ($decision['trailing_active'] !== $mainLeg->trailing_active || $decision['peak_net_profit_usdt'] !== $mainLeg->peak_net_profit_usdt) {
            $mainLeg->update(['trailing_active' => $decision['trailing_active'], 'peak_net_profit_usdt' => $decision['peak_net_profit_usdt']]);
        }

        if ($decision['action'] !== null) {
            $this->closeHedgeSet($mainLeg, $hedgeLeg, $mainPrice, $hedgePrice, $decision['action']);
        }
    }

    private function closeHedgeSet(BotTrade $mainLeg, BotTrade $hedgeLeg, float $mainPrice, float $hedgePrice, string $reason): void
    {
        $this->closeTrade($mainLeg, $mainPrice, $reason);
        $this->closeTrade($hedgeLeg, $hedgePrice, $reason);

        BotLogger::info('trade_manager', "Closed hedge set for {$mainLeg->symbol} ({$reason})", [
            'trade_set_id'             => $mainLeg->trade_set_id,
            'main_net_profit_usdt'     => $mainLeg->net_profit_usdt,
            'hedge_net_profit_usdt'    => $hedgeLeg->net_profit_usdt,
            'combined_net_profit_usdt' => round((float) $mainLeg->net_profit_usdt + (float) $hedgeLeg->net_profit_usdt, 4),
        ], $mainLeg->symbol);
    }

    private function currentPrice(string $symbol, Collection $tickers): ?float
    {
        $ticker = $tickers->get($symbol);
        if (! $ticker) {
            BotLogger::warning('trade_manager', "No ticker data for {$symbol}, skipping this cycle", [], $symbol);
            return null;
        }

        $price = (float) ($ticker['fairPrice'] ?? $ticker['lastPrice'] ?? 0);

        return $price > 0 ? $price : null;
    }

    /**
     * Exhaustion check for Smart Exit: RSI in overbought/oversold territory (against
     * the trade's direction) combined with a stalled or reversed momentum streak.
     */
    private function momentumWeakening(BotTrade $trade, MarketDataService $marketData): bool
    {
        try {
            $candles = $marketData->getCandles($trade->symbol, '5M', 30);
        } catch (\Throwable $e) {
            return false; // missing data shouldn't force an exit
        }

        if (count($candles) < 15) {
            return false;
        }

        $rsi = $this->indicators->rsi(array_column($candles, 'close'), 14);
        if ($rsi === null) {
            return false;
        }

        $momentum = $this->indicators->momentum($candles, 5);

        return $trade->direction === 'LONG'
            ? $rsi > 70 && $momentum['streak_direction'] !== 'up'
            : $rsi < 30 && $momentum['streak_direction'] !== 'down';
    }

    public function closeAll(MarketDataService $marketData, string $reason): void
    {
        $tickers = $marketData->getAllTickers();

        foreach (BotTrade::where('status', 'open')->get() as $trade) {
            $ticker = $tickers->get($trade->symbol);
            $currentPrice = (float) ($ticker['fairPrice'] ?? $ticker['lastPrice'] ?? $trade->entry_price);
            $this->closeTrade($trade, $currentPrice, $reason);
        }
    }

    /**
     * Closes a single open leg on demand (the Bot settings page's manual "Close" button).
     * Closes only the one leg clicked — for a hedge set that leaves the other leg open
     * and still bot-managed, which is intentional: it lets you e.g. drop the hedge and
     * keep the main position, or vice versa, rather than forcing an all-or-nothing exit.
     *
     * @return array{success: bool, error: ?string}
     */
    public function closeManually(BotTrade $trade, MarketDataService $marketData): array
    {
        if ($trade->status !== 'open') {
            return ['success' => false, 'error' => 'Position is not open.'];
        }

        $ticker = $marketData->getTicker($trade->symbol);
        $currentPrice = $ticker ? (float) ($ticker['fairPrice'] ?? $ticker['lastPrice'] ?? 0) : 0;

        if ($currentPrice <= 0) {
            return ['success' => false, 'error' => 'Could not fetch current price for ' . $trade->symbol . '.'];
        }

        if (! $this->closeTrade($trade, $currentPrice, 'manual_close')) {
            return ['success' => false, 'error' => 'Exchange close failed for ' . $trade->symbol . ' — check bot logs.'];
        }

        return ['success' => true, 'error' => null];
    }

    private function stopLossHit(BotTrade $trade, float $currentPrice): bool
    {
        return $trade->direction === 'LONG'
            ? $currentPrice <= (float) $trade->stop_loss
            : $currentPrice >= (float) $trade->stop_loss;
    }

    private function currentNetPnl(BotTrade $trade, float $currentPrice): float
    {
        $nominal = $trade->margin_usd * $trade->leverage;
        $priceChangePct = ((float) $currentPrice - (float) $trade->entry_price) / (float) $trade->entry_price
            * ($trade->direction === 'LONG' ? 1 : -1);

        return $nominal * $priceChangePct - (float) ($trade->fee_usdt ?? 0);
    }

    private function closeTrade(BotTrade $trade, float $exitPrice, string $reason): bool
    {
        $orderResult = $this->orders->close($trade->symbol, $trade->direction, $trade->mode, $trade->contract_vol, $exitPrice);

        if (! $orderResult['success']) {
            BotLogger::error('trade_manager', "Failed to close {$trade->symbol}: {$orderResult['error']} — will retry next cycle", [], $trade->symbol);
            return false;
        }

        $netPnl = $this->currentNetPnl($trade, $orderResult['fill_price']);

        $trade->update([
            'exit_price'      => $orderResult['fill_price'],
            'net_profit_usdt' => round($netPnl, 4),
            'status'          => 'closed',
            'close_reason'    => $reason,
            'closed_at'       => now(),
        ]);

        BotLogger::info('trade_manager', "Closed {$trade->direction} {$trade->symbol} ({$reason}), net PnL \${$trade->net_profit_usdt}", [
            'entry_price' => $trade->entry_price, 'exit_price' => $orderResult['fill_price'], 'net_profit_usdt' => $trade->net_profit_usdt,
        ], $trade->symbol);

        return true;
    }

    private function dailyLossLimitBreached(): bool
    {
        $max = BotConfig::get('max_daily_loss_usdt');
        $todayStart = now()->setTimezone('UTC')->startOfDay();

        $realizedToday = (float) BotTrade::where('status', 'closed')
            ->where('closed_at', '>=', $todayStart)
            ->sum('net_profit_usdt');

        return $realizedToday <= -$max;
    }
}
