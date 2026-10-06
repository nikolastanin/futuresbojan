<?php

namespace App\Manual;

use App\Bot\Indicators\IndicatorService;
use App\Bot\MarketData\MarketDataService;

/**
 * The heavier, deeper half of the Analysis panel for one coin: a multi-timeframe
 * agreement grid, higher-timeframe and volume-profile price levels, and strength
 * relative to BTC. Kept off signalPreview() on purpose — that endpoint is polled by
 * the order form, every position row and the panel, while this is only needed for
 * the one coin on screen, and it reaches for 4H/1D candles and BTC's candles too.
 * Read-only market data; nothing here touches an account or an order.
 */
class AnalysisExtrasService
{
    private const BTC_SYMBOL = 'BTC_USDT';

    /** label => [MEXC interval, cache seconds] — slower timeframes cache longer. */
    private const TIMEFRAMES = [
        '5M'  => ['Min5', 45],
        '15M' => ['Min15', 60],
        '1H'  => ['Min60', 60],
        '4H'  => ['Hour4', 300],
        '1D'  => ['Day1', 900],
    ];

    /** Rolling windows (in 1H candles) for relative strength vs BTC. */
    private const STRENGTH_WINDOWS = ['1H' => 1, '4H' => 4, '24H' => 24];

    /** Level key => the short label shown in the ladder and used in the trade plan. */
    private const LEVEL_LABELS = [
        'r2' => 'R2', 'r1' => 'R1', 'week_high' => 'WH', 'prior_day_high' => 'PDH',
        'pivot' => 'DP', 'prior_day_low' => 'PDL', 'week_low' => 'WL', 's1' => 'S1', 's2' => 'S2',
        'ema10' => 'EMA10', 'ema20' => 'EMA20',
        'weekly_pivot' => 'WP', 'prior_week_high' => 'PWH', 'prior_week_low' => 'PWL',
        'monthly_pivot' => 'MP', 'prior_month_high' => 'PMH', 'prior_month_low' => 'PML',
        'poc' => 'POC', 'vah' => 'VAH', 'val' => 'VAL',
    ];

    public function __construct(
        private MarketDataService $marketData,
        private IndicatorService $indicators,
        private TradePlanBuilder $planBuilder,
    ) {}

    /**
     * @return array{
     *     symbol: string,
     *     mtf: array<int, array{tf: string, trend: string, rsi: ?float, macd: ?string, lean: string}>,
     *     levels: array<string, ?float>,
     *     vs_btc: ?array<string, array{coin: ?float, btc: ?float, diff: ?float}>
     * }
     */
    public function forSymbol(string $symbol): array
    {
        $candlesByTf = [];

        foreach (self::TIMEFRAMES as $label => [$interval, $ttl]) {
            $candlesByTf[$label] = $this->marketData->getCandlesCached($symbol, $interval, 200, $ttl);
        }

        // ~75 days so the prior calendar month is fully covered even at month-end.
        $daily = $this->marketData->getCandlesCached($symbol, 'Day1', 75, 900);

        $volumeProfile = $this->indicators->volumeProfile(array_slice($candlesByTf['1H'], -168));

        $mtf    = $this->multiTimeframe($candlesByTf);
        $levels = array_merge(
            $this->indicators->htfLevels($daily),
            [
                'poc' => $volumeProfile['poc'] ?? null,
                'vah' => $volumeProfile['vah'] ?? null,
                'val' => $volumeProfile['val'] ?? null,
            ],
        );

        return [
            'symbol' => $symbol,
            'mtf'    => $mtf,
            'levels' => $levels,
            'vs_btc' => $symbol === self::BTC_SYMBOL ? null : $this->strengthVsBtc($candlesByTf['1H']),
            'plan'   => $this->tradePlan($symbol, $candlesByTf, $levels, $mtf),
        ];
    }

    /**
     * The zone map: every level the ladder shows (daily pivots and EMAs from the same
     * daily candles signalPreview() uses, so the numbers match, plus the weekly/monthly
     * and volume-profile levels above), stacked into zones with confirmations from the
     * 15M SuperTrend and the timeframe grid.
     *
     * @param array<string, array> $candlesByTf
     * @param array<string, ?float> $extraLevels
     * @param array<int, array> $mtf
     */
    private function tradePlan(string $symbol, array $candlesByTf, array $extraLevels, array $mtf): array
    {
        $allLevels = array_merge(
            $this->indicators->priceLevels($this->marketData->getDailyCandles($symbol)) ?? [],
            $extraLevels,
        );

        $labelled = [];

        foreach (self::LEVEL_LABELS as $key => $label) {
            if (isset($allLevels[$key]) && is_numeric($allLevels[$key])) {
                $labelled[$label] = (float) $allLevels[$key];
            }
        }

        $ticker = $this->marketData->getTicker($symbol);
        $last5m = end($candlesByTf['5M']);
        $price  = (float) ($ticker['fairPrice'] ?? ($last5m ? $last5m['close'] : 0));

        return $this->planBuilder->build(
            $price,
            $labelled,
            $this->indicators->atr($candlesByTf['1H']),
            $mtf,
            $this->indicators->superTrend($candlesByTf['15M'])['direction'] ?? null,
        );
    }

    /**
     * One row per timeframe: trend (EMA50/200 read), RSI, MACD direction, and a
     * combined lean so agreement across timeframes is visible at a glance.
     *
     * @param array<string, array> $candlesByTf
     */
    private function multiTimeframe(array $candlesByTf): array
    {
        $rows = [];

        foreach ($candlesByTf as $label => $candles) {
            $a    = $this->indicators->analyze($candles);
            $macd = $a['macd'];

            $macdDirection = ($macd['macd'] === null || $macd['signal'] === null)
                ? null
                : ($macd['macd'] > $macd['signal'] ? 'bullish' : ($macd['macd'] < $macd['signal'] ? 'bearish' : 'neutral'));

            $score = match ($a['trend']) {
                'up'    => 1,
                'down'  => -1,
                default => 0,
            } + match ($macdDirection) {
                'bullish' => 1,
                'bearish' => -1,
                default   => 0,
            };

            $rows[] = [
                'tf'    => $label,
                'trend' => $a['trend'],
                'rsi'   => $a['rsi'],
                'macd'  => $macdDirection,
                'lean'  => $score > 0 ? 'up' : ($score < 0 ? 'down' : 'mixed'),
            ];
        }

        return $rows;
    }

    /**
     * How much this coin has moved over each rolling window versus BTC — a positive
     * diff means it is leading BTC, negative that it is lagging. Windows are measured
     * in 1H candle closes (so "24H" is price now vs 24 hourly closes ago).
     *
     * @param array $coinHourly
     * @return array<string, array{coin: ?float, btc: ?float, diff: ?float}>
     */
    private function strengthVsBtc(array $coinHourly): array
    {
        $btcHourly = $this->marketData->getCandlesCached(self::BTC_SYMBOL, 'Min60', 200, 60);

        $result = [];

        foreach (self::STRENGTH_WINDOWS as $label => $candlesBack) {
            $coin = $this->percentChange($coinHourly, $candlesBack);
            $btc  = $this->percentChange($btcHourly, $candlesBack);

            $result[$label] = [
                'coin' => $coin,
                'btc'  => $btc,
                'diff' => ($coin !== null && $btc !== null) ? round($coin - $btc, 2) : null,
            ];
        }

        return $result;
    }

    private function percentChange(array $candles, int $candlesBack): ?float
    {
        $count = count($candles);

        if ($count <= $candlesBack) {
            return null;
        }

        $past = (float) $candles[$count - 1 - $candlesBack]['close'];

        return $past > 0
            ? round(((float) $candles[$count - 1]['close'] / $past - 1) * 100, 2)
            : null;
    }
}
