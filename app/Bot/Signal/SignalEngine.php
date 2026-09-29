<?php

namespace App\Bot\Signal;

use App\Bot\Config\BotConfig;
use App\Bot\Indicators\IndicatorService;

/**
 * Combines multi-timeframe indicators (EMA, RSI, MACD, ATR/volatility, volume, trend,
 * momentum, price action / support-resistance) plus a market-wide USDT dominance overlay
 * into a LONG/SHORT confidence score (1-10) with a fully explained, per-factor breakdown.
 *
 * Pure scoring only — used by the Dashboard's live "Bot says"/Trend/Momentum/Structure
 * read (FuturesController::signalPreview()) and by ScalpSignalBacktestEngine. No longer
 * wired into any live trading decision.
 */
class SignalEngine
{
    // Factor weights sum to 10, so |netScore| maps directly onto the 1-10 confidence scale.
    private const WEIGHT_TREND_1H     = 2.25;
    private const WEIGHT_TREND_15M    = 1.0;
    private const WEIGHT_MACD         = 0.5;
    private const WEIGHT_EMA_5M       = 0.75;
    private const WEIGHT_RSI          = 1.5;
    private const WEIGHT_VOLUME       = 0.75;
    private const WEIGHT_MOMENTUM     = 1.5;
    private const WEIGHT_PRICE_ACTION = 1.5;
    private const WEIGHT_VOLATILITY   = 0.25;
    private const WEIGHT_DOMINANCE    = 1.5;

    public function __construct(
        private IndicatorService $indicators,
    ) {}

    /**
     * Pure scoring function: combines every factor into a direction + confidence + reasons
     * triple. No I/O, no persistence.
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

        // 3. MACD (15M) — EMA12/26 crossover confirms trend/momentum direction independent
        // of the raw EMA50/200 trend check above.
        $macd = $tf15m['macd'];
        if ($macd['macd'] !== null && $macd['signal'] !== null) {
            if ($macd['macd'] > $macd['signal']) {
                $long += self::WEIGHT_MACD;
                $reasons[] = "15M MACD ({$this->fmt($macd['macd'])}) above signal ({$this->fmt($macd['signal'])}) — bullish momentum [+".self::WEIGHT_MACD." LONG]";
            } elseif ($macd['macd'] < $macd['signal']) {
                $short += self::WEIGHT_MACD;
                $reasons[] = "15M MACD ({$this->fmt($macd['macd'])}) below signal ({$this->fmt($macd['signal'])}) — bearish momentum [+".self::WEIGHT_MACD." SHORT]";
            } else {
                $reasons[] = '15M MACD sitting exactly on its signal line — no directional weight';
            }
        } else {
            $reasons[] = '15M MACD unavailable — not enough candle history';
        }

        // 4. EMA (5M) — short-term price position vs EMA50.
        if ($tf5m['ema50'] !== null) {
            if ($tf5m['last_close'] > $tf5m['ema50']) {
                $long += self::WEIGHT_EMA_5M;
                $reasons[] = "5M price {$this->fmt($tf5m['last_close'])} above 5M EMA50 {$this->fmt($tf5m['ema50'])} [+".self::WEIGHT_EMA_5M." LONG]";
            } else {
                $short += self::WEIGHT_EMA_5M;
                $reasons[] = "5M price {$this->fmt($tf5m['last_close'])} below 5M EMA50 {$this->fmt($tf5m['ema50'])} [+".self::WEIGHT_EMA_5M." SHORT]";
            }
        }

        // 5. RSI (1H) — oversold/overbought reversal bias, else trending-momentum zone.
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

        // 6. Volume — rising short-term volume confirms the direction already implied by momentum.
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

        // 7. Momentum (5M) — consecutive same-direction candle streak.
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

        // 8. Volatility (5M) — expanding true range alongside momentum confirms a genuine
        // breakout rather than chop; flat/contracting volatility adds no directional weight.
        [$recentTR, $priorTR] = $this->recentVsPriorTrueRange($candles5m, 5, 15);
        $volatilityExpanding = $recentTR !== null && $priorTR !== null && $recentTR > $priorTR * 1.1;

        if ($volatilityExpanding && $momentumDir === 'up') {
            $long += self::WEIGHT_VOLATILITY;
            $reasons[] = "5M volatility expanding ({$this->fmt($recentTR)} vs {$this->fmt($priorTR)} prior) alongside upward momentum — breakout confirmation [+".self::WEIGHT_VOLATILITY." LONG]";
        } elseif ($volatilityExpanding && $momentumDir === 'down') {
            $short += self::WEIGHT_VOLATILITY;
            $reasons[] = "5M volatility expanding ({$this->fmt($recentTR)} vs {$this->fmt($priorTR)} prior) alongside downward momentum — breakout confirmation [+".self::WEIGHT_VOLATILITY." SHORT]";
        } else {
            $reasons[] = 'Volatility not clearly expanding — no breakout confirmation either way';
        }

        // 9. Price action / support-resistance (15M) — proximity to a recent swing level.
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

        // 10. USDT dominance (macro risk-on/risk-off overlay) — falling dominance means capital
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

    /**
     * Average true range of the most recent $recentN candles vs the $priorN candles
     * before that — mirrors recentVsPriorVolume(), used to detect volatility expansion.
     *
     * @return array{0: ?float, 1: ?float} [recentAverage, priorAverage]
     */
    private function recentVsPriorTrueRange(array $candles, int $recentN, int $priorN): array
    {
        $ranges = $this->indicators->trueRanges($candles);
        $total  = count($ranges);

        if ($total < $recentN + $priorN) {
            return [null, null];
        }

        $recent = array_slice($ranges, -$recentN);
        $prior  = array_slice($ranges, -($recentN + $priorN), $priorN);

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
