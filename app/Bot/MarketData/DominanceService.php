<?php

namespace App\Bot\MarketData;

use App\Bot\Config\BotConfig;
use App\Bot\Logging\BotLogger;
use App\Models\BotDominanceSnapshot;
use Illuminate\Support\Facades\Http;

/**
 * USDT dominance (macro risk-on/risk-off overlay), sourced from CoinGecko's free
 * public /api/v3/global endpoint (no API key required). Falling USDT dominance
 * means capital is rotating out of stablecoins into crypto (risk-on, bullish);
 * rising means capital is fleeing to stablecoin safety (risk-off, bearish) —
 * inversely correlated with BTC/altcoin price action.
 */
class DominanceService
{
    private const ENDPOINT = 'https://api.coingecko.com/api/v3/global';

    /**
     * Fetches a fresh snapshot if the last one is stale, otherwise reuses it.
     * Falls back to the last known snapshot (even if stale) on fetch failure.
     *
     * @return array{usdt_dominance_pct: float, btc_dominance_pct: float}|null
     */
    public function refresh(): ?array
    {
        if (! BotConfig::get('dominance_enabled')) {
            return null;
        }

        $latest = BotDominanceSnapshot::orderByDesc('recorded_at')->first();
        $refreshIntervalMinutes = BotConfig::get('dominance_refresh_interval_minutes');

        if ($latest && $latest->recorded_at->diffInMinutes(now()) < $refreshIntervalMinutes) {
            return ['usdt_dominance_pct' => (float) $latest->usdt_dominance_pct, 'btc_dominance_pct' => (float) $latest->btc_dominance_pct];
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
                ->timeout(10)
                ->get(self::ENDPOINT);

            $percentages = $response->json('data.market_cap_percentage', []);

            if (! $response->successful() || ! isset($percentages['usdt'], $percentages['btc'])) {
                throw new \RuntimeException('CoinGecko global endpoint returned unexpected data: ' . $response->status());
            }

            $usdt = (float) $percentages['usdt'];
            $btc  = (float) $percentages['btc'];

            BotDominanceSnapshot::create([
                'usdt_dominance_pct' => $usdt,
                'btc_dominance_pct'  => $btc,
                'recorded_at'        => now(),
            ]);

            BotLogger::info('market_data', "USDT dominance snapshot: {$usdt}% (BTC {$btc}%)");

            return ['usdt_dominance_pct' => $usdt, 'btc_dominance_pct' => $btc];
        } catch (\Throwable $e) {
            BotLogger::warning('market_data', "Failed to refresh USDT dominance: {$e->getMessage()}");

            return $latest ? ['usdt_dominance_pct' => (float) $latest->usdt_dominance_pct, 'btc_dominance_pct' => (float) $latest->btc_dominance_pct] : null;
        }
    }

    /**
     * Change in USDT dominance (percentage points) over the configured lookback
     * window. Returns null if dominance tracking is disabled, unreachable with no
     * prior snapshot, or there isn't yet enough history to compare against.
     *
     * @return array{current_usdt_dominance_pct: float, reference_usdt_dominance_pct: float, change_pct: float, lookback_minutes: int}|null
     */
    public function getTrend(): ?array
    {
        $current = $this->refresh();
        if (! $current) {
            return null;
        }

        $lookbackMinutes = BotConfig::get('dominance_lookback_minutes');
        $cutoff = now()->subMinutes($lookbackMinutes);

        $reference = BotDominanceSnapshot::where('recorded_at', '<=', $cutoff)
            ->orderByDesc('recorded_at')
            ->first();

        if (! $reference) {
            return null; // not enough history yet — the snapshot table needs to age past one lookback window
        }

        return [
            'current_usdt_dominance_pct'   => $current['usdt_dominance_pct'],
            'reference_usdt_dominance_pct' => (float) $reference->usdt_dominance_pct,
            'change_pct'                   => round($current['usdt_dominance_pct'] - (float) $reference->usdt_dominance_pct, 4),
            'lookback_minutes'             => $lookbackMinutes,
        ];
    }
}
