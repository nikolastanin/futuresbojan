<?php

namespace App\Bot\Orders;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Services\MexcFuturesService;

/**
 * Executes trade decisions. In paper mode, simulates a fill and logs it — nothing
 * is sent to MEXC. In real mode (gated behind real_trading_enabled), places the
 * market entry plus a stop-loss trigger order. Same return shape either way so
 * TradeManager doesn't need to know which mode it's in.
 *
 * Take-profit is intentionally NOT placed as an exchange-side trigger order: smart
 * exit and trailing TP (TradeManager) make the exit decision dynamically each cycle,
 * which would race against a static exchange trigger firing early. Stop loss stays
 * exchange-side as a hard safety net that fires even if the bot process is down.
 */
class OrderManager
{
    public function __construct(private MexcFuturesService $mexc) {}

    /**
     * @return array{success: bool, mode: string, fill_price: float, order_id: ?string, contract_vol: ?float, error: ?string}
     */
    public function open(
        string $symbol,
        string $direction,
        float $marginUsd,
        int $leverage,
        float $entryPrice,
        float $stopLoss,
        array $contractDetail,
    ): array {
        $mode = BotConfig::get('real_trading_enabled') ? 'real' : 'paper';

        if ($mode === 'paper') {
            BotLogger::info('order', "PAPER OPEN {$direction} {$symbol} margin \${$marginUsd} @ {$entryPrice} (SL {$stopLoss})", [
                'direction' => $direction, 'margin_usd' => $marginUsd, 'entry_price' => $entryPrice,
                'stop_loss' => $stopLoss,
            ], $symbol);

            return ['success' => true, 'mode' => 'paper', 'fill_price' => $entryPrice, 'order_id' => null, 'contract_vol' => null, 'error' => null];
        }

        $contractSize = (float) ($contractDetail['contractSize'] ?? 0);
        $nominal      = $marginUsd * $leverage;
        $vol          = $contractSize > 0 ? floor($nominal / ($entryPrice * $contractSize)) : 0;

        if ($vol < 1) {
            $error = "computed contract volume < 1 (nominal \${$nominal}, contractSize {$contractSize})";
            BotLogger::error('order', "REAL OPEN FAILED {$symbol}: {$error}", [], $symbol);
            return ['success' => false, 'mode' => 'real', 'fill_price' => 0, 'order_id' => null, 'contract_vol' => null, 'error' => $error];
        }

        $side         = $direction === 'LONG' ? 1 : 3; // 1=open long, 3=open short
        $positionType = $direction === 'LONG' ? 1 : 2; // 1=long, 2=short (for trigger orders)

        try {
            $orderRes = $this->mexc->placeOrder([
                'symbol'   => $symbol,
                'price'    => 0,
                'vol'      => $vol,
                'leverage' => $leverage,
                'side'     => $side,
                'type'     => 5, // market
                'openType' => 2, // cross margin
            ]);
        } catch (\Throwable $e) {
            // Entry itself never filled — no position exists, safe to report failure outright.
            BotLogger::error('order', "REAL OPEN FAILED {$symbol}: {$e->getMessage()}", [], $symbol);
            return ['success' => false, 'mode' => 'real', 'fill_price' => 0, 'order_id' => null, 'contract_vol' => null, 'error' => $e->getMessage()];
        }

        $orderId = $orderRes['data']['orderId'] ?? $orderRes['data'] ?? null;

        // From here a real position exists on the exchange. A trigger-order failure must
        // NOT cause this to report failure — that would leave a live, untracked position
        // (no bot_trades row, so nothing would ever manage or close it). Report success
        // regardless; TradeManager's own price-polling in manageOpenPositions() enforces
        // SL every cycle as a backstop even if the exchange-side trigger never got placed
        // (take-profit is bot-side only — see class docblock).
        try {
            $this->mexc->placeTriggerOrder($symbol, $positionType, $vol, $stopLoss, 'stop_loss');
            BotLogger::info('order', "REAL OPEN {$direction} {$symbol} vol {$vol} @ market (SL {$stopLoss})", [
                'order_id' => $orderId, 'contract_vol' => $vol,
            ], $symbol);
        } catch (\Throwable $e) {
            BotLogger::error('order', "REAL OPEN {$symbol}: entry filled but stop-loss trigger failed — {$e->getMessage()} — bot-side polling will still enforce SL", [
                'order_id' => $orderId, 'contract_vol' => $vol,
            ], $symbol);
        }

        // fill_price is the planning-time price, not a queried actual execution price —
        // acceptable slippage approximation at this $1-4 micro-margin size.
        return ['success' => true, 'mode' => 'real', 'fill_price' => $entryPrice, 'order_id' => is_scalar($orderId) ? (string) $orderId : null, 'contract_vol' => $vol, 'error' => null];
    }

    /**
     * @return array{success: bool, fill_price: float, error: ?string}
     */
    public function close(string $symbol, string $direction, string $mode, ?float $contractVol, float $currentPrice): array
    {
        if ($mode === 'paper') {
            BotLogger::info('order', "PAPER CLOSE {$direction} {$symbol} @ {$currentPrice}", [], $symbol);
            return ['success' => true, 'fill_price' => $currentPrice, 'error' => null];
        }

        if (! $contractVol) {
            $error = 'missing contract_vol for real close';
            BotLogger::error('order', "REAL CLOSE FAILED {$symbol}: {$error}", [], $symbol);
            return ['success' => false, 'fill_price' => 0, 'error' => $error];
        }

        $closeSide = $direction === 'LONG' ? 4 : 3; // 4=close long, 3=close short

        try {
            $this->mexc->closePosition($symbol, $closeSide, $contractVol);
            BotLogger::info('order', "REAL CLOSE {$direction} {$symbol} vol {$contractVol} @ market", [], $symbol);
            return ['success' => true, 'fill_price' => $currentPrice, 'error' => null];
        } catch (\Throwable $e) {
            BotLogger::error('order', "REAL CLOSE FAILED {$symbol}: {$e->getMessage()}", [], $symbol);
            return ['success' => false, 'fill_price' => 0, 'error' => $e->getMessage()];
        }
    }
}
