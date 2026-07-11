<?php

namespace App\Bot\Logging;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A dead-simple liveness signal for the persistent bot:run process, touched on
 * every fast-tick iteration (~3s) regardless of whether the bot is enabled, idle,
 * or trading. Exists because bot_logs entries are event-driven (a signal scan every
 * ~60s, trade actions as they happen) and can go quiet for long stretches even while
 * the process is perfectly healthy — this is the one signal that's independent of
 * what the bot is actually doing, so a dead process (not just a quiet one) is
 * distinguishable at a glance instead of discovered days later in a bad stats read.
 */
class BotHeartbeat
{
    private const CACHE_KEY = 'bot:heartbeat_at';

    // ~10x the default 3s position-management tick — tolerant of one slow API call
    // blocking a single iteration without falsely flagging the process as dead.
    private const STALE_AFTER_SECONDS = 30;

    // Long enough to rule out a single slow signal scan (which can take a while
    // across ~100 pairs) before actually calling the process offline.
    private const OFFLINE_AFTER_SECONDS = 300;

    public static function touch(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }

    /** @return array{last_active_at: ?string, seconds_ago: ?int, status: 'live'|'slow'|'offline'} */
    public static function status(): array
    {
        $lastActiveAt = Cache::get(self::CACHE_KEY);

        if (! $lastActiveAt) {
            return ['last_active_at' => null, 'seconds_ago' => null, 'status' => 'offline'];
        }

        $secondsAgo = (int) round(abs(now()->diffInSeconds(Carbon::parse($lastActiveAt))));

        $status = match (true) {
            $secondsAgo <= self::STALE_AFTER_SECONDS => 'live',
            $secondsAgo <= self::OFFLINE_AFTER_SECONDS => 'slow',
            default => 'offline',
        };

        return ['last_active_at' => $lastActiveAt, 'seconds_ago' => $secondsAgo, 'status' => $status];
    }
}
