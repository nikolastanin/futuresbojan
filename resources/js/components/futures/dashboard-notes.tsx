import { useCallback, useEffect, useRef, useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/dashboard/notes';

interface Props {
    notes: string;
}

const SAVE_DEBOUNCE_MS = 800;

type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

export function DashboardNotes({ notes: initialNotes }: Props) {
    const [content, setContent] = useState(initialNotes);
    const [status, setStatus] = useState<SaveStatus>('idle');
    const saveTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const save = useCallback(async (value: string) => {
        setStatus('saving');

        try {
            const action = update();
            const res = await fetch(action.url, {
                method: action.method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
                body: JSON.stringify({ content: value }),
            });

            setStatus(res.ok ? 'saved' : 'error');
        } catch {
            setStatus('error');
        }
    }, []);

    const handleChange = (value: string) => {
        setContent(value);

        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
        }

        saveTimeoutRef.current = setTimeout(() => save(value), SAVE_DEBOUNCE_MS);
    };

    useEffect(() => {
        return () => {
            if (saveTimeoutRef.current) {
                clearTimeout(saveTimeoutRef.current);
            }
        };
    }, []);

    const statusLabel =
        status === 'saving'
            ? 'Saving…'
            : status === 'saved'
              ? 'Saved'
              : status === 'error'
                ? 'Failed to save'
                : '';

    return (
        <div className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4">
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                    Notes
                </p>
                <span
                    className={`text-[11px] ${status === 'error' ? 'text-red-500' : 'text-muted-foreground'}`}
                >
                    {statusLabel}
                </span>
            </div>

            <Textarea
                value={content}
                onChange={(e) => handleChange(e.target.value)}
                placeholder="Jot down anything while trading…"
                className="min-h-40 resize-y text-sm"
            />
        </div>
    );
}
