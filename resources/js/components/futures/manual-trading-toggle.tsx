import { AlertTriangle, FlaskConical, Radio } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import manual from '@/routes/manual';

const CONFIRM_PHRASE = 'ENABLE REAL TRADING';

interface Props {
    enabled: boolean;
    onChanged: (enabled: boolean) => void;
}

/**
 * Compact corner widget — a small status pill (Paper/LIVE) that expands into the
 * full toggle + confirm flow on click, so this doesn't dominate the page the way a
 * full-width box did. Click-outside closes it, matching SearchableSelect's pattern.
 */
export function ManualTradingToggle({ enabled, onChanged }: Props) {
    const [open, setOpen] = useState(false);
    const [checked, setChecked] = useState(enabled);
    const [confirmText, setConfirmText] = useState('');
    const [saving, setSaving] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);

        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const wantsToEnable = checked && !enabled;
    const dirty = checked !== enabled;

    const save = async () => {
        if (wantsToEnable && confirmText !== CONFIRM_PHRASE) {
            toast.error(`Type "${CONFIRM_PHRASE}" exactly to enable real-money manual trading.`);

            return;
        }

        setSaving(true);

        try {
            const res = await fetch(manual.settings.update.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    real_trading_enabled: checked,
                    confirm: confirmText,
                }),
            });
            const json = await res.json();

            if (json.success) {
                toast.success(checked ? 'Real manual trading enabled.' : 'Manual trading set to paper mode.');
                setConfirmText('');
                onChanged(checked);
                setOpen(false);
            } else {
                toast.error(json.message ?? 'Failed to update.');
                setChecked(enabled);
            }
        } catch {
            toast.error('Network error.');
            setChecked(enabled);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${
                    enabled
                        ? 'border-red-500/60 bg-red-500/10 text-red-500 hover:bg-red-500/20'
                        : 'border-border bg-card text-muted-foreground hover:text-foreground'
                }`}
                title="Manual order mode — separate from the bot's own real-trading setting"
            >
                {enabled ? (
                    <Radio className="size-3 animate-pulse" />
                ) : (
                    <FlaskConical className="size-3" />
                )}
                {enabled ? 'LIVE' : 'Paper'}
            </button>

            {open && (
                <div className="absolute top-full right-0 z-50 mt-1.5 w-72 rounded-xl border border-border bg-popover p-3 shadow-lg">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                                Manual order mode
                            </p>
                            <p className="mt-1 text-[11px] text-muted-foreground">
                                Off = simulated only. On = orders use real money.
                            </p>
                        </div>
                        <label className="flex shrink-0 items-center gap-1.5 text-xs font-medium text-foreground">
                            <input
                                type="checkbox"
                                checked={checked}
                                onChange={(e) => setChecked(e.target.checked)}
                                className="size-3.5"
                            />
                            Real
                        </label>
                    </div>

                    {wantsToEnable && (
                        <div className="mt-2 flex flex-col gap-2 rounded-lg border border-red-500/50 bg-red-500/10 p-2.5">
                            <p className="flex items-center gap-1.5 text-[11px] font-semibold text-red-500">
                                <AlertTriangle className="size-3.5 shrink-0" />
                                This will place real orders with real money.
                            </p>
                            <Input
                                value={confirmText}
                                onChange={(e) => setConfirmText(e.target.value)}
                                placeholder={CONFIRM_PHRASE}
                                className="h-8 text-sm"
                                autoComplete="off"
                            />
                        </div>
                    )}

                    {dirty && (
                        <Button size="sm" className="mt-2 w-full" onClick={save} disabled={saving}>
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
