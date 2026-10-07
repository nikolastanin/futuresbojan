import { Activity, ArrowUpDown } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import type { RadarStatus } from '@/lib/risk-math';

export type DashboardTab = 'trade' | 'analysis';

interface Props {
    active: DashboardTab;
    onChange: (tab: DashboardTab) => void;
    /**
     * Where the risk radar stands. The radar lives on the Analysis tab, so when it is
     * amber or red that shows on the tab itself — a warning must not be hidden just
     * because the other tab is the one open.
     */
    radarStatus: RadarStatus;
}

const TABS: { id: DashboardTab; label: string; icon: typeof Activity }[] = [
    { id: 'trade', label: 'Trade', icon: ArrowUpDown },
    { id: 'analysis', label: 'Analysis & risk', icon: Activity },
];

const RADAR_PILL: Record<'watch' | 'danger', { label: string; cls: string }> = {
    watch: {
        label: 'WATCH',
        cls: 'border-amber-500/40 bg-amber-500/10 text-amber-500',
    },
    danger: {
        label: 'DANGER',
        cls: 'border-red-500/50 bg-red-500/10 text-red-500',
    },
};

/**
 * The dashboard's two halves: Trade (orders and open positions) and Analysis & risk (the
 * risk radar and the per-coin analysis). Each tab's content is rendered by the page; this
 * is only the strip, with the radar's state shown on the Analysis tab when it needs a look.
 */
export function DashboardTabs({ active, onChange, radarStatus }: Props) {
    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        event.preventDefault();

        const step = event.key === 'ArrowRight' ? 1 : -1;
        const next =
            TABS[
                (TABS.findIndex((t) => t.id === active) + step + TABS.length) %
                    TABS.length
            ];

        onChange(next.id);
        document.getElementById(`tab-${next.id}`)?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label="Dashboard sections"
            className="flex items-end gap-1 border-b border-border"
        >
            {TABS.map(({ id, label, icon: Icon }) => {
                const selected = id === active;
                const pill =
                    id === 'analysis' &&
                    (radarStatus === 'watch' || radarStatus === 'danger')
                        ? RADAR_PILL[radarStatus]
                        : null;

                return (
                    <button
                        key={id}
                        id={`tab-${id}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={`panel-${id}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(id)}
                        onKeyDown={onKeyDown}
                        className={`-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                            selected
                                ? 'border-blue-500 text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Icon
                            className={`size-4 ${selected ? 'text-blue-500' : ''}`}
                        />
                        {label}
                        {pill && (
                            <span
                                title={`Risk radar: ${pill.label.toLowerCase()} — open this tab to see why`}
                                className={`rounded border px-1 py-px text-[9px] leading-none font-bold tracking-wide ${pill.cls}`}
                            >
                                {pill.label}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
