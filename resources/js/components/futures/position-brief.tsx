import { RotateCw, Sparkles } from 'lucide-react';
import type { BriefView } from '@/hooks/use-position-briefs';

interface Props {
    view: BriefView;
    onAsk: () => void;
    /** Where it sits in the row; the slot grows into whatever room the row has. */
    className?: string;
}

/**
 * The assistant's short comment beside one open position — in the free space of its row.
 * Nothing is sent until the button is pressed. After that the comment stays (so the space
 * isn't empty again), and once the picture it described has changed — price has moved
 * about an hourly range, the positions were added to, or it is simply old — it is dimmed
 * and labelled, with the button to ask again.
 */
export function BriefNote({ view, onAsk, className = '' }: Props) {
    if (view.status === 'idle') {
        return (
            <div className={`flex items-center ${className}`}>
                <button
                    type="button"
                    onClick={onAsk}
                    title="A friendly read of everything on the Analysis & risk tab, for this coin. On click only, about 0.2 cent."
                    className="flex items-center gap-1.5 rounded-md border border-violet-400/40 bg-violet-400/5 px-2.5 py-1 text-[11px] font-medium text-violet-400 transition-colors hover:border-violet-400 hover:bg-violet-400/10"
                >
                    <Sparkles className="size-3" />
                    Ask the assistant
                </button>
            </div>
        );
    }

    if (view.status === 'loading') {
        return (
            <div
                className={`flex items-center gap-1.5 text-[11px] text-muted-foreground ${className}`}
            >
                <Sparkles className="size-3.5 animate-pulse text-violet-400" />
                Looking at everything on the Analysis tab…
            </div>
        );
    }

    if (view.status === 'error') {
        return (
            <div className={`flex flex-wrap items-center gap-2 ${className}`}>
                <span className="text-[11px] text-red-500">{view.message}</span>
                <button
                    type="button"
                    onClick={onAsk}
                    className="text-[11px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    Try again
                </button>
            </div>
        );
    }

    const stale = view.stale !== null;

    return (
        <div
            className={`flex items-start gap-2 rounded-md border px-2.5 py-1.5 ${
                stale
                    ? 'border-border bg-muted/20'
                    : 'border-violet-400/30 bg-violet-400/5'
            } ${className}`}
        >
            <Sparkles
                className={`mt-0.5 size-3.5 shrink-0 ${stale ? 'text-muted-foreground' : 'text-violet-400'}`}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p
                    className={`text-[12px] leading-snug ${stale ? 'text-muted-foreground' : 'text-foreground'}`}
                >
                    {view.note.trim() !== ''
                        ? view.note
                        : 'Nothing to add on this one right now.'}
                </p>
                <p className="text-[9px] text-muted-foreground">
                    {view.ageText}
                    {view.staleText && (
                        <span className="text-amber-500">
                            {' '}
                            · {view.staleText} — ask again
                        </span>
                    )}
                </p>
            </div>
            <button
                type="button"
                onClick={onAsk}
                title="Ask again"
                className={`shrink-0 rounded p-1 transition-colors hover:bg-muted ${stale ? 'text-amber-500' : 'text-muted-foreground hover:text-foreground'}`}
            >
                <RotateCw className="size-3" />
            </button>
        </div>
    );
}
