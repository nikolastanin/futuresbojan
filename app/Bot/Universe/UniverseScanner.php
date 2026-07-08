<?php

namespace App\Bot\Universe;

use App\Bot\Config\BotConfig;
use App\Bot\Indicators\IndicatorService;
use App\Bot\Logging\BotLogger;
use App\Bot\MarketData\MarketDataService;
use App\Models\BotUniverseScan;
use Illuminate\Support\Str;

/**
 * Shortlists quality USDT perpetual futures pairs for SignalEngine to analyze.
 * Runs: active+volume filter (cheap, ticker-based) -> deep candle/indicator scan
 * on the top volume candidates -> market_quality_score -> rank -> take the best N.
 */
class UniverseScanner
{
    public function __construct(
        private MarketDataService $marketData,
        private IndicatorService $indicators,
    ) {}

    /**
     * Runs a full scan and returns the ordered list of symbols to hand to SignalEngine.
     *
     * @return array<int, string>
     */
    public function scan(): array
    {
        $scanId    = (string) Str::uuid();
        $scannedAt = now()->toDateTimeString();

        $minVolume        = BotConfig::get('minimum_24h_volume_usdt');
        $minCandles        = BotConfig::get('minimum_historical_candles');
        $minQualityScore   = BotConfig::get('minimum_market_quality_score');
        $maxPairsToAnalyze = BotConfig::get('max_pairs_to_analyze');
        $maxDeepScan       = BotConfig::get('max_deep_scan_candidates');

        $contracts = $this->marketData->getActiveUsdtContracts();
        $tickers   = $this->marketData->getAllTickers();

        $rows = [];
        $rejectedCounts = [];

        // Step 1: cheap volume filter using ticker data only.
        $volumeSurvivors = [];
        foreach ($contracts as $contract) {
            $symbol = $contract['symbol'];
            $ticker = $tickers->get($symbol);

            if (! $ticker) {
                $rows[] = $this->rejectedRow($scanId, $symbol, $scannedAt, 'no_ticker_data');
                $rejectedCounts['no_ticker_data'] = ($rejectedCounts['no_ticker_data'] ?? 0) + 1;
                continue;
            }

            $volume24h = (float) ($ticker['amount24'] ?? 0);

            if ($volume24h < $minVolume) {
                $rows[] = $this->rejectedRow($scanId, $symbol, $scannedAt, 'low_volume', $volume24h);
                $rejectedCounts['low_volume'] = ($rejectedCounts['low_volume'] ?? 0) + 1;
                continue;
            }

            $volumeSurvivors[] = ['symbol' => $symbol, 'volume24h' => $volume24h];
        }

        // Step 2: only deep-scan the top N by volume to bound kline requests per cycle.
        usort($volumeSurvivors, fn ($a, $b) => $b['volume24h'] <=> $a['volume24h']);
        $deepScanCandidates = array_slice($volumeSurvivors, 0, $maxDeepScan);
        $beyondCap          = array_slice($volumeSurvivors, $maxDeepScan);

        foreach ($beyondCap as $candidate) {
            $rows[] = $this->rejectedRow($scanId, $candidate['symbol'], $scannedAt, 'beyond_deep_scan_cap', $candidate['volume24h']);
            $rejectedCounts['beyond_deep_scan_cap'] = ($rejectedCounts['beyond_deep_scan_cap'] ?? 0) + 1;
        }

        // Step 3: candle history + market_quality_score for deep-scan candidates.
        $scored = [];
        foreach ($deepScanCandidates as $candidate) {
            $symbol = $candidate['symbol'];

            try {
                $candles = $this->marketData->getCandles($symbol, '1H', $minCandles);
            } catch (\Throwable $e) {
                $rows[] = $this->rejectedRow($scanId, $symbol, $scannedAt, 'kline_fetch_failed', $candidate['volume24h']);
                $rejectedCounts['kline_fetch_failed'] = ($rejectedCounts['kline_fetch_failed'] ?? 0) + 1;
                continue;
            }

            if (count($candles) < $minCandles) {
                $rows[] = $this->rejectedRow($scanId, $symbol, $scannedAt, 'insufficient_history', $candidate['volume24h']);
                $rejectedCounts['insufficient_history'] = ($rejectedCounts['insufficient_history'] ?? 0) + 1;
                continue;
            }

            $quality = $this->qualityScore($candles, $candidate['volume24h'], $minVolume);

            if ($quality['score'] < $minQualityScore) {
                $rows[] = $this->rejectedRow($scanId, $symbol, $scannedAt, 'low_quality_score', $candidate['volume24h'], $quality['score'], $quality['atr']);
                $rejectedCounts['low_quality_score'] = ($rejectedCounts['low_quality_score'] ?? 0) + 1;
                continue;
            }

            $scored[] = [
                'symbol'     => $symbol,
                'volume24h'  => $candidate['volume24h'],
                'score'      => $quality['score'],
                'atr'        => $quality['atr'],
                'atr_pct'    => $quality['atr_pct'],
                'trend_clarity' => $quality['sub_scores']['trend_clarity'],
            ];
        }

        // Step 4: rank by score, then volume, then volatility, then trend clarity (spec priority order).
        usort($scored, fn ($a, $b) => [$b['score'], $b['volume24h'], $b['atr_pct'], $b['trend_clarity']]
            <=> [$a['score'], $a['volume24h'], $a['atr_pct'], $a['trend_clarity']]);

        $final     = array_slice($scored, 0, $maxPairsToAnalyze);
        $trimmed   = array_slice($scored, $maxPairsToAnalyze);

        foreach ($trimmed as $candidate) {
            $rows[] = $this->rejectedRow($scanId, $candidate['symbol'], $scannedAt, 'beyond_max_pairs_limit', $candidate['volume24h'], $candidate['score'], $candidate['atr']);
            $rejectedCounts['beyond_max_pairs_limit'] = ($rejectedCounts['beyond_max_pairs_limit'] ?? 0) + 1;
        }

        foreach ($final as $candidate) {
            $rows[] = [
                'scan_id'               => $scanId,
                'symbol'                => $candidate['symbol'],
                'included'              => true,
                'market_quality_score'  => $candidate['score'],
                'volume_24h_usdt'       => $candidate['volume24h'],
                'atr'                   => $candidate['atr'],
                'exclusion_reason'      => null,
                'scanned_at'            => $scannedAt,
            ];
        }

        BotUniverseScan::insert(array_map(fn ($r) => $r + ['created_at' => $scannedAt, 'updated_at' => $scannedAt], $rows));

        BotLogger::info('universe_scan', 'Universe scan complete', [
            'scan_id'          => $scanId,
            'total_evaluated'  => count($rows),
            'rejected_by_reason' => $rejectedCounts,
            'final_pair_count' => count($final),
            'final_pairs'      => array_column($final, 'symbol'),
        ]);

        return array_column($final, 'symbol');
    }

    /**
     * market_quality_score (1-10) from volume, volatility, trend clarity, and candle structure.
     * Weighted: volume 35%, volatility 25%, trend clarity 25%, structure 15%.
     */
    private function qualityScore(array $candles, float $volume24h, float $minVolume): array
    {
        $indicators = $this->indicators->analyze($candles);
        $lastClose  = $indicators['last_close'];
        $atr        = $indicators['atr'] ?? 0;
        $atrPct     = $lastClose > 0 ? ($atr / $lastClose) * 100 : 0;

        // Volume: log-scaled between the minimum threshold (~4) and a $500M cap (~10).
        $volumeScore = $minVolume > 0
            ? 4 + 6 * min(1, log(max($volume24h, $minVolume) / $minVolume) / log(500_000_000 / $minVolume))
            : 5;

        // Volatility: healthy range is roughly 0.3%-3% ATR/price; too flat or too wild scores lower.
        $volatilityScore = match (true) {
            $atrPct < 0.1  => 1,
            $atrPct < 0.3  => 4 + ($atrPct - 0.1) / 0.2 * 3,       // 4 -> 7
            $atrPct <= 3.0 => 7 + min(3, (3.0 - abs($atrPct - 1.5)) / 1.5),
            $atrPct <= 6.0 => 10 - ($atrPct - 3.0) / 3.0 * 6,       // 10 -> 4
            default        => 2,
        };

        // Trend clarity: EMA50/EMA200 separation (as % of price) + momentum streak length.
        $emaSeparationPct = ($indicators['ema50'] && $indicators['ema200'] && $lastClose > 0)
            ? abs($indicators['ema50'] - $indicators['ema200']) / $lastClose * 100
            : 0;
        $streak = $indicators['momentum']['streak'] ?? 0;
        $trendClarityScore = min(10, ($emaSeparationPct * 4) + min(4, $streak));

        // Structure: candles with small wicks relative to range are "cleaner" (fewer false breakouts).
        $recent = array_slice($candles, -50);
        $wickRatios = array_map(function ($c) {
            $range = $c['high'] - $c['low'];
            return $range > 0 ? 1 - (abs($c['close'] - $c['open']) / $range) : 0.5;
        }, $recent);
        $avgWickRatio  = array_sum($wickRatios) / max(count($wickRatios), 1);
        $structureScore = max(0, min(10, 10 * (1 - $avgWickRatio)));

        $composite = round(
            $volumeScore * 0.35 + $volatilityScore * 0.25 + $trendClarityScore * 0.25 + $structureScore * 0.15
        );

        return [
            'score'   => (int) max(1, min(10, $composite)),
            'atr'     => $atr,
            'atr_pct' => round($atrPct, 4),
            'sub_scores' => [
                'volume'        => round($volumeScore, 2),
                'volatility'    => round($volatilityScore, 2),
                'trend_clarity' => round($trendClarityScore, 2),
                'structure'     => round($structureScore, 2),
            ],
        ];
    }

    private function rejectedRow(string $scanId, string $symbol, $scannedAt, string $reason, ?float $volume24h = null, ?int $score = null, ?float $atr = null): array
    {
        return [
            'scan_id'              => $scanId,
            'symbol'               => $symbol,
            'included'             => false,
            'market_quality_score' => $score,
            'volume_24h_usdt'      => $volume24h,
            'atr'                  => $atr,
            'exclusion_reason'     => $reason,
            'scanned_at'           => $scannedAt,
        ];
    }
}
