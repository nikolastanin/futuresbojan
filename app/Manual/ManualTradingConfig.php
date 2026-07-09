<?php

namespace App\Manual;

use App\Models\ManualSetting;

/**
 * Controls whether manual order placement from the Dashboard actually hits
 * MEXC or is simulated. Entirely separate from the bot's own
 * App\Bot\Config\BotConfig / real_trading_enabled — this only affects orders
 * placed by hand from the Dashboard's order form, never the automated bot.
 * Off (paper) by default, same safety-first default as the bot.
 */
class ManualTradingConfig
{
    private const KEY = 'real_trading_enabled';

    public static function isRealTradingEnabled(): bool
    {
        $value = ManualSetting::where('key', self::KEY)->value('value');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setRealTradingEnabled(bool $enabled): void
    {
        ManualSetting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => $enabled ? '1' : '0'],
        );
    }
}
