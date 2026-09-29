<?php

namespace App\Bot\Config;

/**
 * Reads config from config/bot.php — the shared indicator/market-data settings
 * (timeframes, dominance) still used by SignalEngine/MarketDataService/DominanceService.
 * Used to also support per-key runtime overrides stored in a bot_settings table for
 * the automated bot's settings page; that page and table are gone, so this is now a
 * plain config-file reader.
 */
class BotConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return config("bot.{$key}", $default);
    }
}
