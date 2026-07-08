<?php

namespace App\Bot\Backtest;

/**
 * Plain (non-Eloquent) in-memory mirror of bot_trades, used by BacktestEngine to
 * simulate a position without touching the live trading ledger or MEXC.
 */
class BacktestPosition
{
    public string $tradeSetId;
    public string $leg; // main | hedge
    public string $symbol;
    public string $direction; // LONG | SHORT
    public float $marginUsd;
    public int $leverage;
    public float $entryPrice;
    public int $entryTime;
    public float $takeProfit;
    public float $stopLoss;
    public float $feeUsdt;
    public int $confidenceScore;

    public bool $trailingActive = false;
    public ?float $peakNetProfitUsdt = null;

    public string $status = 'open'; // open | closed
    public ?float $exitPrice = null;
    public ?int $exitTime = null;
    public ?string $closeReason = null;
    public ?float $netProfitUsdt = null;

    public function __construct(
        string $tradeSetId,
        string $leg,
        string $symbol,
        string $direction,
        float $marginUsd,
        int $leverage,
        float $entryPrice,
        int $entryTime,
        float $takeProfit,
        float $stopLoss,
        float $feeUsdt,
        int $confidenceScore,
    ) {
        $this->tradeSetId      = $tradeSetId;
        $this->leg             = $leg;
        $this->symbol          = $symbol;
        $this->direction       = $direction;
        $this->marginUsd       = $marginUsd;
        $this->leverage        = $leverage;
        $this->entryPrice      = $entryPrice;
        $this->entryTime       = $entryTime;
        $this->takeProfit      = $takeProfit;
        $this->stopLoss        = $stopLoss;
        $this->feeUsdt         = $feeUsdt;
        $this->confidenceScore = $confidenceScore;
    }

    public function netPnlAt(float $currentPrice): float
    {
        $nominal = $this->marginUsd * $this->leverage;
        $priceChangePct = ($currentPrice - $this->entryPrice) / $this->entryPrice
            * ($this->direction === 'LONG' ? 1 : -1);

        return $nominal * $priceChangePct - $this->feeUsdt;
    }

    public function stopLossHit(float $currentPrice): bool
    {
        return $this->direction === 'LONG'
            ? $currentPrice <= $this->stopLoss
            : $currentPrice >= $this->stopLoss;
    }

    public function close(float $exitPrice, int $exitTime, string $reason): void
    {
        $this->status        = 'closed';
        $this->exitPrice     = $exitPrice;
        $this->exitTime      = $exitTime;
        $this->closeReason   = $reason;
        $this->netProfitUsdt = round($this->netPnlAt($exitPrice), 4);
    }

    public function toArray(): array
    {
        return [
            'trade_set_id'      => $this->tradeSetId,
            'leg'               => $this->leg,
            'symbol'            => $this->symbol,
            'direction'         => $this->direction,
            'margin_usd'        => $this->marginUsd,
            'leverage'          => $this->leverage,
            'entry_price'       => $this->entryPrice,
            'entry_time'        => date('Y-m-d H:i:s', $this->entryTime),
            'take_profit'       => $this->takeProfit,
            'stop_loss'         => $this->stopLoss,
            'fee_usdt'          => $this->feeUsdt,
            'confidence_score'  => $this->confidenceScore,
            'status'            => $this->status,
            'exit_price'        => $this->exitPrice,
            'exit_time'         => $this->exitTime ? date('Y-m-d H:i:s', $this->exitTime) : null,
            'close_reason'      => $this->closeReason,
            'net_profit_usdt'   => $this->netProfitUsdt,
        ];
    }
}
