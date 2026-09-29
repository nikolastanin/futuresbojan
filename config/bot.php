<?php

// Config for the shared indicator/market-data infrastructure the manual dashboard
// reuses (SignalEngine, IndicatorService, MarketDataService, DominanceService,
// ScalpScanner) — the automated trading bot itself (risk management, order
// placement, trade lifecycle, universe scanning) has been removed; this file only
// keeps the keys those surviving classes still read.
return [

    // Market data / indicators — timeframe labels SignalEngine/ScalpScanner analyze.
    'timeframes' => ['5M' => 'Min5', '15M' => 'Min15', '1H' => 'Min60'],

    // MEXC lists non-crypto instruments (stocks, indices, metals, oil) as USDT-quoted
    // "futures" alongside real cryptocurrencies. On by default so symbol lists only
    // ever surface actual crypto pairs.
    'crypto_only' => env('BOT_CRYPTO_ONLY', true),

    // USDT dominance (macro risk-on/risk-off overlay). Falling dominance = capital
    // rotating into crypto (risk-on, bullish bias); rising = capital fleeing to
    // stablecoin safety (risk-off, bearish bias). Sourced from CoinGecko's free
    // public /api/v3/global endpoint — no API key required.
    'dominance_enabled'                  => env('BOT_DOMINANCE_ENABLED', true),
    'dominance_refresh_interval_minutes' => env('BOT_DOMINANCE_REFRESH_INTERVAL_MINUTES', 5),
    'dominance_lookback_minutes'         => env('BOT_DOMINANCE_LOOKBACK_MINUTES', 60),
    'dominance_change_threshold_pct'     => env('BOT_DOMINANCE_CHANGE_THRESHOLD_PCT', 0.10),
];
