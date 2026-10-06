import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import { aiRead as aiReadRoute } from '@/routes/futures';

interface AiRead {
    outlook: 'bullish' | 'bearish' | 'neutral';
    action: 'add_short' | 'hold' | 'reduce_short';
    conviction: 'low' | 'medium' | 'high';
    summary: string;
    watch: string;
    estimated_cost_usd: number;
}

type State =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'done'; read: AiRead; at: Date };

const OUTLOOK_STYLE: Record<AiRead['outlook'], string> = {
    bullish: 'text-emerald-500',
    bearish: 'text-red-500',
    neutral: 'text-muted-foreground',
};

const ACTION_META: Record<AiRead['action'], { label: string; color: string }> =
    {
        add_short: { label: 'Add to short', color: 'text-emerald-500' },
        hold: { label: 'Hold', color: 'text-amber-500' },
        reduce_short: { label: 'Reduce short', color: 'text-red-500' },
    };

interface Props {
    /** Live dashboard snapshot to send; null while the signal preview hasn't loaded. */
    payload: Record<string, unknown> | null;
}

/**
 * On-click only — nothing is sent until the button is pressed, so it costs
 * a fraction of a cent per press and nothing otherwise. Purely informational:
 * the result is displayed, never acted on.
 */
export function HedgeAiRead({ payload }: Props) {
    const [state, setState] = useState<State>({ status: 'idle' });

    const run = async () => {
        if (!payload) {
            return;
        }

        setState({ status: 'loading' });

        const csrfToken =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '';

        try {
            const res = await fetch(aiReadRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const json = await res.json().catch(() => null);

            if (res.ok && json?.success) {
                setState({ status: 'done', read: json.data, at: new Date() });
            } else {
                setState({
                    status: 'error',
                    message:
                        json?.message ??
                        (res.status === 429
                            ? 'Too many AI reads — wait a minute.'
                            : 'AI read failed.'),
                });
            }
        } catch {
            setState({ status: 'error', message: 'Network error.' });
        }
    };

    const loading = state.status === 'loading';

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={run}
                    disabled={!payload || loading}
                    className="flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground transition-colors hover:border-violet-400/60 hover:text-violet-400 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Sparkles
                        className={`size-3 text-violet-400 ${loading ? 'animate-pulse' : ''}`}
                    />
                    {loading
                        ? 'Reading…'
                        : state.status === 'done'
                          ? 'Re-run AI read'
                          : 'AI read'}
                </button>
                {state.status === 'idle' && (
                    <span className="text-[10px] text-muted-foreground">
                        On-click second opinion on this hedge — not a forecast.
                    </span>
                )}
                {state.status === 'error' && (
                    <span className="text-[11px] text-red-500">
                        {state.message}
                    </span>
                )}
            </div>

            {state.status === 'done' && (
                <div className="flex flex-col gap-1.5 rounded-md border border-violet-400/30 bg-violet-400/5 px-2.5 py-2 text-[11px]">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="text-muted-foreground">
                            Outlook{' '}
                            <span
                                className={`font-semibold capitalize ${OUTLOOK_STYLE[state.read.outlook]}`}
                            >
                                {state.read.outlook}
                            </span>
                        </span>
                        <span className="text-muted-foreground">
                            Short leg{' '}
                            <span
                                className={`font-semibold ${ACTION_META[state.read.action].color}`}
                            >
                                {ACTION_META[state.read.action].label}
                            </span>
                        </span>
                        <span className="text-muted-foreground">
                            Conviction{' '}
                            <span className="font-semibold text-foreground capitalize">
                                {state.read.conviction}
                            </span>
                        </span>
                    </div>
                    <p className="text-foreground">{state.read.summary}</p>
                    <p className="text-muted-foreground">
                        <span className="font-semibold text-foreground">
                            Watch:
                        </span>{' '}
                        {state.read.watch}
                    </p>
                    <p className="text-[9px] text-muted-foreground">
                        AI second opinion (DeepSeek) — informational, not a
                        forecast · ~$
                        {state.read.estimated_cost_usd.toFixed(4)} ·{' '}
                        {state.at.toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </p>
                </div>
            )}
        </div>
    );
}
