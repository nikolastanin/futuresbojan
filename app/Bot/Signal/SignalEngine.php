<?php

namespace App\Bot\Signal;

use App\Bot\Config\BotConfig;
use App\Bot\Indicators\IndicatorService;
use App\Bot\Logging\BotLogger;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Sizing\PositionSizingService;
use App\Models\BotSignal;

/**
 * Combines multi-timeframe indicators (EMA, RSI, ATR, volume, trend, momentum,
 * price action / support-resistance) plus a market-wide USDT dominance overlay
 * into a LONG/SHORT confidence score (1-10) with a fully explained, per-factor breakdown.
 *
 * This phase only scores and logs signals — it does not place orders. RiskManager
 * and OrderManager (later phases) decide whether a qualifying signal actually trades.
 */
class SignalEngine
{
    // Factor weights sum to 10, so |netScore| maps directly onto the 1-10 confidence scale.
    // (Dominance is an additional macro overlay on top of that base 10, clamped at analyze() time.)
    private const WEIGHT_TREND_1H     = 2.0;
    private const WEIGHT_TREND_15M    = 1.5;
    private const WEIGHT_EMA_5M       = 1.0;
    private const WEIGHT_RSI          = 1.5;
    private const WEIGHT_VOLUME       = 1.0;
    private const WEIGHT_MOMENTUM     = 1.5;
    private const WEIGHT_PRICE_ACTION = 1.5;
    private const WEIGHT_DOMINANCE    = 1.5;

    public function __construct(
        private MarketDataService $marketData,
        private IndicatorService $indicators,
        private PositionSizingService $sizing,
    ) {}

    /**
     * Analyzes one symbol and persists the result to bot_signals.
     * Returns the persisted BotSignal so callers (TradeManager) can update
     * opened/skip_reason once RiskManager has made the actual trade decision.
     */
    public function analyze(string $symbol, ?float $takerFeeRate = null, ?array $dominanceTrend = null): \App\Models\BotSignal
    {
        $candles = $this->marketData->getCandlesForAllTimeframes($symbol);

        $tf1h  = $this->indicators->analyze($candles['1H']);
        $tf15m = $this->indicators->analyze($candles['15M']);
        $tf5m  = $this->indicators->analyze($candles['5M']);

        $ticker      = $this->marketData->getTicker($symbol);
        $currentPrice = (float) ($ticker['fairPrice'] ?? $tf5m['last_close']);

        $scored     = $this->score($tf1h, $tf15m, $tf5m, $candles['5M'], $currentPrice, $dominanceTrend);
        $direction  = $scored['direction'];
        $confidence = $scored['confidence'];
        $reasons    = $scored['reasons'];

        $threshold = BotConfig::get('minimum_confidence_to_trade');
        $wouldOpen = $direction !== null && $confidence >= $threshold;

        $result = [
            'symbol'           => $symbol,
            'direction'        => $direction,
            'confidence_score' => $confidence,
            'reasons'          => $reasons,
            'entry_price'      => null,
            'take_profit'      => null,
            'stop_loss'        => null,
            'estimated_fee_usdt' => null,
            'expected_net_profit_usdt' => null,
            'opened'           => false,
            'skip_reason'      => $direction === null
                ? 'no_clear_directional_edge'
                : ($wouldOpen ? 'pending_risk_evaluation' : "confidence {$confidence} below threshold {$threshold}"),
            'analyzed_at'      => now(),
        ];

        if ($wouldOpen) {
            $plan = $this->sizing->plan($direction, $confidence, $currentPrice, $tf15m['atr'], $takerFeeRate);
            $result['entry_price']              = $plan['entry_price'];
            $result['take_profit']              = $plan['take_profit'];
            $result['stop_loss']                = $plan['stop_loss'];
            $result['estimated_fee_usdt']        = $plan['estimated_fee_usdt'];
            $result['expected_net_profit_usdt']  = $plan['expected_net_profit_usdt'];
        }

        $signal = BotSignal::create($result);

        BotLogger::info('signal', "{$symbol}: " . ($direction ?? 'NO SIGNAL') . " confidence={$confidence}" . ($wouldOpen ? ' (qualifies to trade)' : ''), [
            'confidence' => $confidence,
            'direction'  => $direction,
            'reasons'    => $reasons,
            'would_open' => $wouldOpen,
        ], $symbol);

        return $signal;
    }

    /**
     * Pure scoring function: combines every factor into a direction + confidence + reasons
     * triple. No I/O, no persistence — reused as-is by both analyze() (live, via
     * MarketDataService) and BacktestEngine (historical, via precomputed candle slices),
     * so live and backtested behavior can never drift apart.
     *
     * @param ?array $dominanceTrend From DominanceService::getTrend() — shared across every
     *               pair in a cycle since it's a market-wide macro reading, not per-symbol.
     * @return array{direction: ?string, confidence: int, reasons: array<int, string>}
     */
    public function score(array $tf1h, array $tf15m, array $tf5m, array $candles5m, float $currentPrice, ?array $dominanceTrend = null): array
    {
        [$longPoints, $shortPoints, $reasons] = $this->scoreFactors($tf1h, $tf15m, $tf5m, $candles5m, $currentPrice, $dominanceTrend);

        $netScore = $longPoints - $shortPoints;

        return [
            'direction'  => $netScore > 0 ? 'LONG' : ($netScore < 0 ? 'SHORT' : null),
            'confidence' => (int) max(0, min(10, round(abs($netScore)))),
            'reasons'    => $reasons,
        ];
    }

    /**
     * Scores every factor toward LONG/SHORT and returns [longPoints, shortPoints, reasons[]].
     */
    private function scoreFactors(array $tf1h, array $tf15m, array $tf5m, array $candles5m, float $currentPrice, ?array $dominanceTrend = null): array
    {
        $long = 0.0;
        $short = 0.0;
        $reasons = [];

        // 1. Trend (1H) — primary bias, heaviest weight.
        if ($tf1h['trend'] === 'up') {
            $long += self::WEIGHT_TREND_1H;
            $reasons[] = "1H trend UP (EMA50 {$this->fmt($tf1h['ema50'])} > EMA200 {$this->fmt($tf1h['ema200'])}, price above EMA50) [+".self::WEIGHT_TREND_1H." LONG]";
        } elseif ($tf1h['trend'] === 'down') {
            $short += self::WEIGHT_TREND_1H;
            $reasons[] = "1H trend DOWN (EMA50 {$this->fmt($tf1h['ema50'])} < EMA200 {$this->fmt($tf1h['ema200'])}, price below EMA50) [+".self::WEIGHT_TREND_1H." SHORT]";
        } else {
            $reasons[] = "1H trend is {$tf1h['trend']} — no directional weight from primary trend";
        }

        // 2. Trend (15M) — confirms or contradicts the 1H bias.
        if ($tf15m['trend'] === 'up') {
            $long += self::WEIGHT_TREND_15M;
            $reasons[] = "15M trend UP, confirms bullish structure [+".self::WEIGHT_TREND_15M." LONG]";
        } elseif ($tf15m['trend'] === 'down') {
            $short += self::WEIGHT_TREND_15M;
            $reasons[] = "15M trend DOWN, confirms bearish structure [+".self::WEIGHT_TREND_15M." SHORT]";
        } else {
            $reasons[] = "15M trend is {$tf15m['trend']} — no confirmation either way";
        }

        // 3. EMA (5M) — short-term price position vs EMA50.
        if ($tf5m['ema50'] !== null) {
            if ($tf5m['last_close'] > $tf5m['ema50']) {
                $long += self::WEIGHT_EMA_5M;
                $reasons[] = "5M price {$this->fmt($tf5m['last_close'])} above 5M EMA50 {$this->fmt($tf5m['ema50'])} [+".self::WEIGHT_EMA_5M." LONG]";
            } else {
                $short += self::WEIGHT_EMA_5M;
                $reasons[] = "5M price {$this->fmt($tf5m['last_close'])} below 5M EMA50 {$this->fmt($tf5m['ema50'])} [+".self::WEIGHT_EMA_5M." SHORT]";
            }
        }

        // 4. RSI (1H) — oversold/overbought reversal bias, else trending-momentum zone.
        $rsi = $tf1h['rsi'];
        if ($rsi !== null) {
            if ($rsi < 30) {
                $long += self::WEIGHT_RSI;
                $reasons[] = "1H RSI {$rsi} is oversold — bullish reversal bias [+".self::WEIGHT_RSI." LONG]";
            } elseif ($rsi > 70) {
                $short += self::WEIGHT_RSI;
                $reasons[] = "1H RSI {$rsi} is overbought — bearish reversal bias [+".self::WEIGHT_RSI." SHORT]";
            } elseif ($rsi >= 50) {
                $partial = self::WEIGHT_RSI / 2;
                $long += $partial;
                $reasons[] = "1H RSI {$rsi} in bullish momentum zone (50-70) [+{$partial} LONG]";
            } elseif ($rsi < 50) {
                $partial = self::WEIGHT_RSI / 2;
                $short += $partial;
                $reasons[] = "1H RSI {$rsi} in bearish momentum zone (30-50) [+{$partial} SHORT]";
            }
        }

        // 5. Volume — rising short-term volume confirms the direction already implied by momentum.
        [$recentVol5, $priorVol15] = $this->recentVsPriorVolume($candles5m, 5, 15);
        $volumeRising = $recentVol5 !== null && $priorVol15 !== null && $recentVol5 > $priorVol15;
        $momentumDir  = $tf5m['momentum']['streak_direction'] ?? null;

        if ($volumeRising && $momentumDir === 'up') {
            $long += self::WEIGHT_VOLUME;
            $reasons[] = "5M volume rising alongside upward momentum [+".self::WEIGHT_VOLUME." LONG]";
        } elseif ($volumeRising && $momentumDir === 'down') {
            $short += self::WEIGHT_VOLUME;
            $reasons[] = "5M volume rising alongside downward momentum [+".self::WEIGHT_VOLUME." SHORT]";
        } else {
            $reasons[] = 'Volume does not clearly confirm either direction';
        }

        // 6. Momentum (5M) — consecutive same-direction candle streak.
        $streak = $tf5m['momentum']['streak'] ?? 0;
        if ($momentumDir === 'up' && $streak >= 2) {
            $weight = min(self::WEIGHT_MOMENTUM, self::WEIGHT_MOMENTUM * $streak / 4);
            $long += $weight;
            $reasons[] = "5M momentum: {$streak} consecutive up candles [+" . round($weight, 2) . " LONG]";
        } elseif ($momentumDir === 'down' && $streak >= 2) {
            $weight = min(self::WEIGHT_MOMENTUM, self::WEIGHT_MOMENTUM * $streak / 4);
            $short += $weight;
            $reasons[] = "5M momentum: {$streak} consecutive down candles [+" . round($weight, 2) . " SHORT]";
        } else {
            $reasons[] = 'No significant momentum streak on 5M';
        }

        // 7. Price action / support-resistance (15M) — proximity to a recent swing level.
        $sr = $tf15m['support_resistance'];
        if ($sr['support'] !== null && $currentPrice > 0) {
            $distPct = ($currentPrice - $sr['support']) / $currentPrice * 100;
            if ($distPct <= 0.5) {
                $long += self::WEIGHT_PRICE_ACTION;
                $reasons[] = "Price {$this->fmt($currentPrice)} is within 0.5% of 15M support {$this->fmt($sr['support'])} — bounce potential [+".self::WEIGHT_PRICE_ACTION." LONG]";
            }
        }
        if ($sr['resistance'] !== null && $currentPrice > 0) {
            $distPct = ($sr['resistance'] - $currentPrice) / $currentPrice * 100;
            if ($distPct <= 0.5) {
                $short += self::WEIGHT_PRICE_ACTION;
                $reasons[] = "Price {$this->fmt($currentPrice)} is within 0.5% of 15M resistance {$this->fmt($sr['resistance'])} — rejection potential [+".self::WEIGHT_PRICE_ACTION." SHORT]";
            }
        }

        // 8. USDT dominance (macro risk-on/risk-off overlay) — falling dominance means capital
        // is rotating out of stablecoins into crypto (bullish); rising means the opposite.
        if ($dominanceTrend !== null) {
            $changePct = $dominanceTrend['change_pct'];
            $threshold = BotConfig::get('dominance_change_threshold_pct');
            $lookback  = $dominanceTrend['lookback_minutes'];

            if ($changePct <= -$threshold) {
                $long += self::WEIGHT_DOMINANCE;
                $reasons[] = "USDT dominance fell {$changePct}pp over {$lookback}m (risk-on, capital rotating into crypto) [+".self::WEIGHT_DOMINANCE." LONG]";
            } elseif ($changePct >= $threshold) {
                $short += self::WEIGHT_DOMINANCE;
                $reasons[] = "USDT dominance rose {$changePct}pp over {$lookback}m (risk-off, capital rotating to stablecoin safety) [+".self::WEIGHT_DOMINANCE." SHORT]";
            } else {
                $reasons[] = "USDT dominance roughly flat ({$changePct}pp over {$lookback}m) — no macro bias";
            }
        }

        // Volatility gate — too flat or too wild to trust the entry, cap confidence via a proportional haircut.
        $atrPct = ($tf1h['atr'] !== null && $tf1h['last_close'] > 0) ? $tf1h['atr'] / $tf1h['last_close'] * 100 : null;
        if ($atrPct !== null && ($atrPct < 0.1 || $atrPct > 6.0)) {
            $long  *= 0.5;
            $short *= 0.5;
            $reasons[] = "1H volatility (ATR {$this->fmt($atrPct)}% of price) is outside the reliable range — confidence halved";
        }

        return [$long, $short, $reasons];
    }

    /**
     * Average volume of the most recent $recentN candles vs the $priorN candles before that.
     *
     * @return array{0: ?float, 1: ?float} [recentAverage, priorAverage]
     */
    private function recentVsPriorVolume(array $candles, int $recentN, int $priorN): array
    {
        $volumes = array_column($candles, 'volume');
        $total   = count($volumes);

        if ($total < $recentN + $priorN) {
            return [null, null];
        }

        $recent = array_slice($volumes, -$recentN);
        $prior  = array_slice($volumes, -($recentN + $priorN), $priorN);

        return [
            array_sum($recent) / count($recent),
            array_sum($prior) / count($prior),
        ];
    }

    private function fmt(?float $value): string
    {
        return $value === null ? 'n/a' : (string) round($value, 4);
    }
}
