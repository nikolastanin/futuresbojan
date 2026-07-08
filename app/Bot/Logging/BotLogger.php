<?php

namespace App\Bot\Logging;

use App\Models\BotLog;
use Illuminate\Support\Facades\Log;

/**
 * Structured logging for the bot: every entry is persisted to bot_logs (queryable
 * for a future UI / the spec's "very detailed log" requirement) and mirrored to
 * the dedicated 'bot' file channel for tailing during development.
 */
class BotLogger
{
    public static function debug(string $category, string $message, array $context = [], ?string $symbol = null): void
    {
        self::write('debug', $category, $message, $context, $symbol);
    }

    public static function info(string $category, string $message, array $context = [], ?string $symbol = null): void
    {
        self::write('info', $category, $message, $context, $symbol);
    }

    public static function warning(string $category, string $message, array $context = [], ?string $symbol = null): void
    {
        self::write('warning', $category, $message, $context, $symbol);
    }

    public static function error(string $category, string $message, array $context = [], ?string $symbol = null): void
    {
        self::write('error', $category, $message, $context, $symbol);
    }

    private static function write(string $level, string $category, string $message, array $context, ?string $symbol): void
    {
        BotLog::create([
            'level'    => $level,
            'category' => $category,
            'symbol'   => $symbol,
            'message'  => $message,
            'context'  => $context,
        ]);

        Log::channel('bot')->log($level, "[{$category}]" . ($symbol ? " {$symbol}" : '') . " {$message}", $context);
    }
}
