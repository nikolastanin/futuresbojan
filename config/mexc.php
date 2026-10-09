<?php

return [
    'api_key'    => env('MEXC_API_KEY', ''),
    'secret_key' => env('MEXC_SECRET_KEY', ''),
    'base_url'   => env('MEXC_BASE_URL', 'https://contract.mexc.com'),

    // A scan fetches candles for dozens of coins in parallel batches; each batch takes at least
    // this long, so the request rate stays under the exchange's limit (about 20 per 2 seconds)
    // however fast the network is.
    'kline_batch_pause_ms' => (int) env('MEXC_KLINE_BATCH_PAUSE_MS', 1000),
];
