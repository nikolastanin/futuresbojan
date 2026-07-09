<?php

return [

    // Master switches — also overridable at runtime via the bot_settings table.
    'bot_enabled'          => env('BOT_ENABLED', false),
    'real_trading_enabled' => env('BOT_REAL_TRADING_ENABLED', false),

    // Margin / leverage
    'leverage'    => env('BOT_LEVERAGE', 100),
    'margin_mode' => env('BOT_MARGIN_MODE', 'CROSS'),
    'margin_steps' => [1, 2, 3, 4], // USD margin per trade set; confidence 7/8/9/10 -> $1/$2/$3/$4

    // Profit target
    'target_net_profit_per_trade' => env('BOT_TARGET_NET_PROFIT', 2.00),

    // Signal thresholds
    'minimum_confidence_to_trade' => env('BOT_MIN_CONFIDENCE', 7),

    // Risk management
    'cooldown_minutes_per_pair' => env('BOT_COOLDOWN_MINUTES', 5),
    'max_open_positions'        => env('BOT_MAX_OPEN_POSITIONS', 15),
    'max_total_margin_usdt'     => env('BOT_MAX_TOTAL_MARGIN', 50),
    'max_daily_loss_usdt'       => env('BOT_MAX_DAILY_LOSS', 50),

    // Smart exit / trailing TP (Phase 5)
    'smart_exit_enabled'          => env('BOT_SMART_EXIT_ENABLED', true),
    'smart_exit_min_net_profit'   => env('BOT_SMART_EXIT_MIN_NET_PROFIT', 1.50),
    'trailing_tp_enabled'              => env('BOT_TRAILING_TP_ENABLED', true),
    'trailing_activation_net_profit'   => env('BOT_TRAILING_ACTIVATION_NET_PROFIT', 2.00),
    'trailing_callback_net_profit'     => env('BOT_TRAILING_CALLBACK_NET_PROFIT', 0.50),
    'max_position_duration_minutes'    => env('BOT_MAX_POSITION_DURATION_MINUTES', 180),

    // Hedge strategy (Phase 6)
    'hedge_enabled'             => env('BOT_HEDGE_ENABLED', false),
    'main_position_ratio'       => env('BOT_MAIN_POSITION_RATIO', 0.70),
    'hedge_position_ratio'      => env('BOT_HEDGE_POSITION_RATIO', 0.30),
    'hedge_for_confidence_7_8'  => env('BOT_HEDGE_FOR_CONFIDENCE_7_8', true),
    'hedge_for_confidence_9_10' => env('BOT_HEDGE_FOR_CONFIDENCE_9_10', false),
    'close_both_sides_on_target' => env('BOT_CLOSE_BOTH_SIDES_ON_TARGET', true),
    'allow_hedge_add'           => env('BOT_ALLOW_HEDGE_ADD', false),
    'allow_reduce_hedge'        => env('BOT_ALLOW_REDUCE_HEDGE', true),
    'hedge_reduce_percentage'   => env('BOT_HEDGE_REDUCE_PERCENTAGE', 25),

    // Position add (default disallowed)
    'allow_position_add' => env('BOT_ALLOW_POSITION_ADD', false),

    // Universe scanner
    'universe_scanner_enabled'    => env('BOT_UNIVERSE_SCANNER_ENABLED', true),
    'universe_scan_interval_minutes' => env('BOT_UNIVERSE_SCAN_INTERVAL_MINUTES', 5),
    'max_pairs_to_analyze'        => env('BOT_MAX_PAIRS_TO_ANALYZE', 50),
    'minimum_market_quality_score' => env('BOT_MIN_MARKET_QUALITY_SCORE', 6),
    'minimum_historical_candles'  => env('BOT_MIN_HISTORICAL_CANDLES', 200),
    'minimum_24h_volume_usdt'     => env('BOT_MIN_24H_VOLUME_USDT', 5_000_000),
    // Only the top N pairs by 24h volume get full candle/indicator analysis per scan,
    // to bound the number of kline requests per cycle. Should be >= max_pairs_to_analyze.
    'max_deep_scan_candidates'    => env('BOT_MAX_DEEP_SCAN_CANDIDATES', 80),

    // Market data / indicators
    'timeframes' => ['5M' => 'Min5', '15M' => 'Min15', '1H' => 'Min60'],
    'signal_scan_interval_seconds'       => env('BOT_SIGNAL_SCAN_INTERVAL_SECONDS', 60),
    'position_management_interval_seconds' => env('BOT_POSITION_MANAGEMENT_INTERVAL_SECONDS', 20),

    // Fee estimate (MEXC USDT futures taker fee, used for net-profit / TP calculations)
    'taker_fee_rate' => env('BOT_TAKER_FEE_RATE', 0.0006),
    'maker_fee_rate' => env('BOT_MAKER_FEE_RATE', 0.0002),

    // Fallback static pair universe, used only if the universe scanner is disabled.
    'default_pairs' => [
        'BTC_USDT', 'ETH_USDT', 'SOL_USDT', 'BNB_USDT', 'XRP_USDT',
    ],

    // USDT dominance (macro risk-on/risk-off overlay). Falling dominance = capital
    // rotating into crypto (risk-on, bullish bias); rising = capital fleeing to
    // stablecoin safety (risk-off, bearish bias). Sourced from CoinGecko's free
    // public /api/v3/global endpoint — no API key required.
    'dominance_enabled'                  => env('BOT_DOMINANCE_ENABLED', true),
    'dominance_refresh_interval_minutes' => env('BOT_DOMINANCE_REFRESH_INTERVAL_MINUTES', 5),
    'dominance_lookback_minutes'         => env('BOT_DOMINANCE_LOOKBACK_MINUTES', 60),
    'dominance_change_threshold_pct'     => env('BOT_DOMINANCE_CHANGE_THRESHOLD_PCT', 0.10),

    // AI signal validation (DeepSeek, via laravel/ai). Off by default. Only ever
    // runs on signals that already qualify on the deterministic indicator score;
    // it can veto or shave up to 1 point off that score, never raise it. Falls
    // back to the indicator-only score (no trade impact) on any error, timeout,
    // or missing API key — never blocks a cycle.
    'ai_validation_enabled'          => env('BOT_AI_VALIDATION_ENABLED', false),
    'ai_validation_daily_budget_usd' => env('BOT_AI_VALIDATION_DAILY_BUDGET_USD', 1.00),
    'ai_validation_timeout_seconds'  => env('BOT_AI_VALIDATION_TIMEOUT_SECONDS', 20),
    // Rough cost estimate ($/1M tokens) for budget tracking — not exact billing.
    'ai_validation_input_cost_per_million'  => env('BOT_AI_VALIDATION_INPUT_COST_PER_MILLION', 0.14),
    'ai_validation_output_cost_per_million' => env('BOT_AI_VALIDATION_OUTPUT_COST_PER_MILLION', 0.28),
];
