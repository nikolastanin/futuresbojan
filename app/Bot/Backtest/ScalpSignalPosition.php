<?php

namespace App\Bot\Backtest;

/**
 * Plain (non-Eloquent) simulated leg for ScalpSignalBacktestEngine — a TP/SL
 * position opened purely off Market Structure / Candle Reading / FVG, so its
 * results measure those three signals in isolation from RSI/MACD/WaveTrend.
 */
class ScalpSignalPosition
{
    public string $status = 'open';
    public ?float $exitPrice = null;
    public ?int $exitTime = null;
    public ?string $closeReason = null;
    public ?float $netProfitUsdt = null;

    public function __construct(
        public string $symbol,
        public string $direction, // LONG | SHORT
        public string $matchedOn, // e.g. "MarketStructure+FVG"
        public float $nominalUsdt,
        public float $entryPrice,
        public int $entryTime,
        public float $takeProfit,
        public float $stopLoss,
        public float $feeUsdt,
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
            'symbol'           => $this->symbol,
            'direction'        => $this->direction,
            'matched_on'       => $this->matchedOn,
            'nominal_usdt'     => $this->nominalUsdt,
            'entry_price'      => $this->entryPrice,
            'entry_time'       => date('Y-m-d H:i:s', $this->entryTime),
            'take_profit'      => $this->takeProfit,
            'stop_loss'        => $this->stopLoss,
            'fee_usdt'         => $this->feeUsdt,
            'status'           => $this->status,
            'exit_price'       => $this->exitPrice,
            'exit_time'        => $this->exitTime ? date('Y-m-d H:i:s', $this->exitTime) : null,
            'close_reason'     => $this->closeReason,
            'net_profit_usdt'  => $this->netProfitUsdt,
        ];
    }
}
