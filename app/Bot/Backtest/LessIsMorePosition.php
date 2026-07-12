<?php

namespace App\Bot\Backtest;

/**
 * Plain (non-Eloquent) simulated leg for LessIsMoreBacktestEngine — a TP-only
 * micro position, no stop-loss, no smart exit/trailing.
 */
class LessIsMorePosition
{
    public string $status = 'open';
    public ?float $exitPrice = null;
    public ?int $exitTime = null;
    public ?string $closeReason = null;
    public ?float $netProfitUsdt = null;

    public function __construct(
        public string $symbol,
        public string $direction,
        public float $nominalUsdt,
        public float $entryPrice,
        public int $entryTime,
        public float $takeProfit,
        public float $feeUsdt,
        public int $confidenceScore,
    ) {}

    public function netPnlAt(float $currentPrice): float
    {
        $priceChangePct = ($currentPrice - $this->entryPrice) / $this->entryPrice
            * ($this->direction === 'LONG' ? 1 : -1);

        return $this->nominalUsdt * $priceChangePct - $this->feeUsdt;
    }

    public function takeProfitHit(float $currentPrice): bool
    {
        return $this->direction === 'LONG'
            ? $currentPrice >= $this->takeProfit
            : $currentPrice <= $this->takeProfit;
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
            'symbol'           => $this->symbol,
            'direction'        => $this->direction,
            'nominal_usdt'     => $this->nominalUsdt,
            'entry_price'      => $this->entryPrice,
            'entry_time'       => date('Y-m-d H:i:s', $this->entryTime),
            'take_profit'      => $this->takeProfit,
            'fee_usdt'         => $this->feeUsdt,
            'confidence_score' => $this->confidenceScore,
            'status'           => $this->status,
            'exit_price'       => $this->exitPrice,
            'exit_time'        => $this->exitTime ? date('Y-m-d H:i:s', $this->exitTime) : null,
            'close_reason'     => $this->closeReason,
            'net_profit_usdt'  => $this->netProfitUsdt,
        ];
    }
}
