import { useEffect, useState } from 'react';
import { heartbeat as heartbeatRoute } from '@/routes/bot';

export interface HeartbeatStatus {
    last_active_at: string | null;
    seconds_ago: number | null;
    status: 'live' | 'slow' | 'offline';
}

const POLL_INTERVAL = 10_000;

const STATUS_CONFIG: Record<HeartbeatStatus['status'], { dot: string; label: string; text: string }> = {
    live: { dot: 'bg-emerald-500', label: 'Live', text: 'text-emerald-600 dark:text-emerald-400' },
    slow: { dot: 'bg-amber-500', label: 'Slow', text: 'text-amber-600 dark:text-amber-400' },
    offline: { dot: 'bg-red-500', label: 'Offline', text: 'text-red-600 dark:text-red-400' },
};

function relativeTime(secondsAgo: number | null): string {
    if (secondsAgo === null) {
        return 'never';
    }

    if (secondsAgo < 5) {
        return 'just now';
    }

    if (secondsAgo < 60) {
        return `${secondsAgo}s ago`;
    }

    if (secondsAgo < 3600) {
        return `${Math.floor(secondsAgo / 60)}m ago`;
    }

    return `${Math.floor(secondsAgo / 3600)}h ago`;
}

/**
 * Shows whether the bot:run process is actually alive right now — independent of
 * whether it's had anything to log recently. See App\Bot\Logging\BotHeartbeat for
 * why this needed its own signal rather than reading the last bot_logs timestamp.
 */
export function HeartbeatBadge({ initial }: { initial: HeartbeatStatus }) {
    const [status, setStatus] = useState<HeartbeatStatus>(initial);

    useEffect(() => {
        const poll = () => {
            fetch(heartbeatRoute.url(), { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((json) => {
                    if (json.success) {
                        setStatus(json.data);
                    }
                })
                .catch(() => {});
        };

        const id = setInterval(poll, POLL_INTERVAL);

        return () => clearInterval(id);
    }, []);

    const config = STATUS_CONFIG[status.status];

    return (
        <div
            className="flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs"
            title={status.last_active_at ? `Last active: ${status.last_active_at}` : 'The bot process has never reported in'}
        >
            <span className="relative flex size-2">
                {status.status === 'live' && (
                    <span className={`absolute inline-flex size-full animate-ping rounded-full opacity-75 ${config.dot}`} />
                )}
                <span className={`relative inline-flex size-2 rounded-full ${config.dot}`} />
            </span>
            <span className={`font-medium ${config.text}`}>{config.label}</span>
            <span className="text-muted-foreground">— last active {relativeTime(status.seconds_ago)}</span>
        </div>
    );
}
