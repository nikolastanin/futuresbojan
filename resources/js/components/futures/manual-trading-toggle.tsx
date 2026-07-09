import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import manual from '@/routes/manual';

const CONFIRM_PHRASE = 'ENABLE REAL TRADING';

interface Props {
    enabled: boolean;
    onChanged: (enabled: boolean) => void;
}

export function ManualTradingToggle({ enabled, onChanged }: Props) {
    const [checked, setChecked] = useState(enabled);
    const [confirmText, setConfirmText] = useState('');
    const [saving, setSaving] = useState(false);

    const wantsToEnable = checked && !enabled;
    const dirty = checked !== enabled;

    const save = async () => {
        if (wantsToEnable && confirmText !== CONFIRM_PHRASE) {
            toast.error(
                `Type "${CONFIRM_PHRASE}" exactly to enable real-money manual trading.`,
            );

            return;
        }

        setSaving(true);

        try {
            const res = await fetch(manual.settings.update.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    real_trading_enabled: checked,
                    confirm: confirmText,
                }),
            });
            const json = await res.json();

            if (json.success) {
                toast.success(
                    checked
                        ? 'Real manual trading enabled.'
                        : 'Manual trading set to paper mode.',
                );
                setConfirmText('');
                onChanged(checked);
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
        <div
            className={`flex flex-col gap-3 rounded-xl border bg-card p-4 ${checked ? 'border-red-500' : 'border-border'}`}
        >
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                        Manual order mode
                    </p>
                    <p className="mt-1 max-w-md text-xs text-muted-foreground">
                        When off (default), orders placed from this dashboard
                        are simulated only — nothing is sent to MEXC. When on,
                        orders placed here use real money. Separate from the
                        bot's own real-trading setting.
                    </p>
                </div>
                <label className="flex shrink-0 items-center gap-2 text-sm font-medium text-foreground">
                    <input
                        type="checkbox"
                        checked={checked}
                        onChange={(e) => setChecked(e.target.checked)}
                        className="size-4"
                    />
                    Real trading
                </label>
            </div>

            {wantsToEnable && (
                <div className="flex flex-col gap-2 rounded-lg border border-red-500/50 bg-red-500/10 p-3">
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-red-500">
                        <AlertTriangle className="size-3.5" />
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
                <Button
                    size="sm"
                    className="w-fit"
                    onClick={save}
                    disabled={saving}
                >
                    {saving ? 'Saving…' : 'Save'}
                </Button>
            )}
        </div>
    );
}
