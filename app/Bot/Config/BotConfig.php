<?php

namespace App\Bot\Config;

use App\Models\BotSetting;

/**
 * Reads bot configuration from config/bot.php, with per-key runtime overrides
 * stored in the bot_settings table (e.g. flipped from a future settings UI).
 *
 * DB overrides are stored as strings; get() casts them to match the type of
 * the config/bot.php default so callers always get bool/int/float/array as expected.
 */
class BotConfig
{
    private static ?array $overridesCache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $configDefault = config("bot.{$key}", $default);
        $override      = self::overrides()[$key] ?? null;

        if ($override === null) {
            return $configDefault;
        }

        return self::cast($override, $configDefault);
    }

    public static function set(string $key, mixed $value): void
    {
        BotSetting::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value],
        );

        self::$overridesCache = null;
    }

    public static function forget(string $key): void
    {
        BotSetting::where('key', $key)->delete();
        self::$overridesCache = null;
    }

    /** @return array<string, string> */
    private static function overrides(): array
    {
        if (self::$overridesCache === null) {
            self::$overridesCache = BotSetting::pluck('value', 'key')->all();
        }

        return self::$overridesCache;
    }

    private static function cast(string $raw, mixed $referenceDefault): mixed
    {
        return match (true) {
            is_bool($referenceDefault)  => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            is_int($referenceDefault)   => (int) $raw,
            is_float($referenceDefault) => (float) $raw,
            is_array($referenceDefault) => json_decode($raw, true) ?? [],
            default                     => $raw,
        };
    }
}
