<?php

namespace App\Bot\Risk;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Models\BotTrade;
use App\Services\MexcFuturesService;

/**
 * Mandatory pre-trade checks. Reads "what's currently open" from the bot's own
 * bot_trades ledger (true for both paper and real trades), and reads account
 * equity from the real MEXC account so even paper trading respects real balance
 * constraints. Every check is logged, not just the final verdict.
 */
class RiskManager
{
    public function __construct(private MexcFuturesService $mexc) {}

    /**
     * @param array $contractDetail Row from MexcFuturesService::getContractList() for this symbol (needs maxLeverage).
     * @return array{allowed: bool, reason: ?string, checks: array<string, array{passed: bool, detail: string}>}
     */
    public function evaluate(
        string $symbol,
        string $direction,
        float $marginUsd,
        float $entryPrice,
        float $stopLoss,
        array $contractDetail,
    ): array {
        $checks = [];

        $checks['cooldown'] = $this->checkCooldown($symbol);
        $checks['no_existing_position'] = $this->checkNoExistingPosition($symbol);
        $checks['max_open_positions'] = $this->checkMaxOpenPositions();
        $checks['max_total_margin'] = $this->checkMaxTotalMargin($marginUsd);
        $checks['daily_loss_limit'] = $this->checkDailyLossLimit();
        $checks['available_balance'] = $this->checkAvailableBalance($marginUsd);
        $checks['leverage_within_contract_max'] = $this->checkLeverage($contractDetail);
        $checks['stop_loss_valid'] = $this->checkStopLossValid($direction, $entryPrice, $stopLoss);

        $failedKey = null;
        foreach ($checks as $key => $check) {
            if (! $check['passed']) {
                $failedKey = $key;
                break;
            }
        }
        $allowed = $failedKey === null;
        $reason  = $failedKey !== null ? "{$failedKey}: {$checks[$failedKey]['detail']}" : null;

        BotLogger::info('risk', $allowed ? "Risk checks passed for {$symbol}" : "Risk checks REJECTED {$symbol}: {$reason}", [
            'direction' => $direction,
            'margin_usd' => $marginUsd,
            'checks' => $checks,
        ], $symbol);

        return ['allowed' => $allowed, 'reason' => $reason, 'checks' => $checks];
    }

    private function pass(string $detail): array
    {
        return ['passed' => true, 'detail' => $detail];
    }

    private function fail(string $detail): array
    {
        return ['passed' => false, 'detail' => $detail];
    }

    private function checkCooldown(string $symbol): array
    {
        $cooldownMinutes = BotConfig::get('cooldown_minutes_per_pair');

        $lastTrade = BotTrade::where('symbol', $symbol)
            ->orderByDesc('opened_at')
            ->first();

        if (! $lastTrade) {
            return $this->pass('no prior trade on this pair');
        }

        $referenceTime = $lastTrade->closed_at ?? $lastTrade->opened_at;
        $minutesSince  = $referenceTime->diffInMinutes(now());

        if ($minutesSince < $cooldownMinutes) {
            return $this->fail("last trade on {$symbol} was {$minutesSince}m ago, cooldown is {$cooldownMinutes}m");
        }

        return $this->pass("{$minutesSince}m since last trade, cooldown satisfied");
    }

    private function checkNoExistingPosition(string $symbol): array
    {
        $exists = BotTrade::where('symbol', $symbol)->where('status', 'open')->exists();

        return $exists ? $this->fail('a position on this pair is already open') : $this->pass('no open position on this pair');
    }

    private function checkMaxOpenPositions(): array
    {
        $max = BotConfig::get('max_open_positions');
        // A hedge set has 2 legs (main + hedge) sharing one trade_set_id but counts as a
        // single position, so count distinct sets rather than raw bot_trades rows.
        $count = BotTrade::where('status', 'open')->distinct('trade_set_id')->count('trade_set_id');

        return $count >= $max
            ? $this->fail("{$count} positions already open, max is {$max}")
            : $this->pass("{$count}/{$max} positions open");
    }

    private function checkMaxTotalMargin(float $marginUsd): array
    {
        $max = BotConfig::get('max_total_margin_usdt');
        $committed = (float) BotTrade::where('status', 'open')->sum('margin_usd');
        $projected = $committed + $marginUsd;

        return $projected > $max
            ? $this->fail("total margin would be \${$projected}, max is \${$max}")
            : $this->pass("total margin would be \${$projected}/\${$max}");
    }

    private function checkDailyLossLimit(): array
    {
        $max = BotConfig::get('max_daily_loss_usdt');
        $todayStart = now()->setTimezone('UTC')->startOfDay();

        $realizedToday = (float) BotTrade::where('status', 'closed')
            ->where('closed_at', '>=', $todayStart)
            ->sum('net_profit_usdt');

        return $realizedToday <= -$max
            ? $this->fail("today's realized PnL is \${$realizedToday}, daily loss limit is -\${$max}")
            : $this->pass("today's realized PnL is \${$realizedToday}, within -\${$max} limit");
    }

    private function checkAvailableBalance(float $marginUsd): array
    {
        try {
            $account = $this->mexc->getAccountAssets();
            $usdt    = collect($account['data'] ?? [])->firstWhere('currency', 'USDT');
            $equity  = (float) ($usdt['equity'] ?? 0);
        } catch (\Throwable $e) {
            return $this->fail("could not fetch account equity: {$e->getMessage()}");
        }

        $committed = (float) BotTrade::where('status', 'open')->sum('margin_usd');
        $available = $equity - $committed;

        return $available < $marginUsd
            ? $this->fail("available balance \${$available} (equity \${$equity} - committed \${$committed}) is less than required \${$marginUsd}")
            : $this->pass("available balance \${$available} covers required \${$marginUsd}");
    }

    private function checkLeverage(array $contractDetail): array
    {
        $configuredLeverage = BotConfig::get('leverage');
        $maxLeverage = (int) ($contractDetail['maxLeverage'] ?? $configuredLeverage);

        return $configuredLeverage > $maxLeverage
            ? $this->fail("configured leverage {$configuredLeverage}x exceeds this contract's max {$maxLeverage}x")
            : $this->pass("configured leverage {$configuredLeverage}x is within contract max {$maxLeverage}x");
    }

    /**
     * Sanity-checks the stop loss itself (liquidation distance is out of scope):
     * it must sit on the correct side of entry and be a nonzero distance away.
     */
    private function checkStopLossValid(string $direction, float $entryPrice, float $stopLoss): array
    {
        if ($stopLoss <= 0) {
            return $this->fail('stop loss is not set');
        }

        $correctSide = $direction === 'LONG' ? $stopLoss < $entryPrice : $stopLoss > $entryPrice;

        if (! $correctSide) {
            return $this->fail("stop loss {$stopLoss} is on the wrong side of entry {$entryPrice} for a {$direction}");
        }

        $distancePct = abs($entryPrice - $stopLoss) / $entryPrice * 100;

        return $this->pass("stop loss {$stopLoss} is " . round($distancePct, 3) . "% from entry {$entryPrice}");
    }
}
