import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { BookOpen, ChevronLeft, ChevronRight, RefreshCw, X } from 'lucide-react';
import { Toaster } from '@/components/ui/sonner';
import { Button } from '@/components/ui/button';
import { tradingJournal } from '@/routes';
import futures from '@/routes/futures';
import { toast } from 'sonner';

// ─── Rich text renderer ───────────────────────────────────────────────────────
// Highlights: $numbers → amber, LONG → green, SHORT → red, coin tickers → blue,
// negative PNL → red, positive PNL → green, bold key labels.

function RichParagraph({ text }: { text: string }) {
    // Tokenise with a regex that captures all interesting patterns
    const parts = text.split(
        /(\bLONG\b|\bSHORT\b|\bOpen Long\b|\bClose Long\b|\bOpen Short\b|\bClose Short\b|\$[\d,]+(?:\.\d+)?|[-+]?\$[\d,]+(?:\.\d+)?|\b[A-Z]{2,5}\b(?=\s|$|[|,])|\b\d{1,2}:\d{2}\s*UTC\b)/g
    );

    return (
        <span>
            {parts.map((part, i) => {
                if (/^(LONG|Open Long|Close Long)$/i.test(part))
                    return <span key={i} className="font-bold text-emerald-500">{part}</span>;
                if (/^(SHORT|Open Short|Close Short)$/i.test(part))
                    return <span key={i} className="font-bold text-red-500">{part}</span>;
                // Dollar amount with explicit + sign or just positive
                if (/^\+\$/.test(part))
                    return <span key={i} className="font-semibold text-emerald-500">{part}</span>;
                // Dollar amount with - sign
                if (/^-\$/.test(part))
                    return <span key={i} className="font-semibold text-red-500">{part}</span>;
                // Plain dollar amount
                if (/^\$[\d,]/.test(part))
                    return <span key={i} className="font-semibold text-amber-400">{part}</span>;
                // Time stamps
                if (/\d{1,2}:\d{2}\s*UTC/.test(part))
                    return <span key={i} className="font-mono text-xs text-muted-foreground">{part}</span>;
                // Coin tickers (all-caps 2-5 chars)
                if (/^[A-Z]{2,5}$/.test(part))
                    return <span key={i} className="font-semibold text-sky-400">{part}</span>;
                return <span key={i}>{part}</span>;
            })}
        </span>
    );
}

interface Props {
    year:    number;
    month:   number;
    entries: Record<string, string>; // { '2026-06-20': 'entry text' }
}

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

export default function TradingJournal({ year, month, entries: initialEntries }: Props) {
    const [entries,    setEntries]    = useState<Record<string, string>>(initialEntries);
    const [expanded,   setExpanded]   = useState<string | null>(null);
    const [generating, setGenerating] = useState(false);

    const today     = new Date();
    const todayKey  = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const isToday   = year === today.getFullYear() && month === today.getMonth() + 1;

    // Calendar grid
    const firstDay   = new Date(year, month - 1, 1).getDay();
    const daysInMonth = new Date(year, month, 0).getDate();

    const navigate = (dir: -1 | 1) => {
        let y = year, m = month + dir;
        if (m < 1)  { m = 12; y--; }
        if (m > 12) { m = 1;  y++; }
        router.get(tradingJournal.url(), { year: y, month: m }, { preserveState: false });
    };

    const dayKey = (d: number) =>
        `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

    const generateToday = async () => {
        setGenerating(true);
        try {
            const res = await fetch(futures.journal.regenerate.url(), {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    'Accept':       'application/json',
                },
                body: JSON.stringify({}),
            });
            const json = await res.json();
            if (json.success) {
                setEntries(prev => ({ ...prev, [json.date]: json.entry }));
                setExpanded(json.date);
                toast.success('Journal entry saved.');
            } else {
                toast.error(json.message ?? 'Failed to generate journal.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setGenerating(false);
        }
    };

    const expandedEntry = expanded ? entries[expanded] : null;
    const paragraphs    = expandedEntry?.split('\n').filter(l => l.trim()) ?? [];

    return (
        <>
            <Head title="Trading Journal" />
            <Toaster position="top-right" richColors />

            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <BookOpen className="size-4 text-muted-foreground" />
                        <h1 className="text-base font-semibold text-foreground sm:text-lg">Trading Journal</h1>
                    </div>
                    {isToday && (
                        <Button onClick={generateToday} disabled={generating} variant="outline" size="sm" className="gap-2">
                            <RefreshCw className={`size-3.5 ${generating ? 'animate-spin' : ''}`} />
                            {generating ? 'Generating…' : entries[todayKey] ? 'Regenerate Today' : 'Generate Today'}
                        </Button>
                    )}
                </div>

                {/* Month nav */}
                <div className="flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3">
                    <button onClick={() => navigate(-1)} className="rounded p-1 text-muted-foreground hover:text-foreground transition-colors">
                        <ChevronLeft className="size-5" />
                    </button>
                    <span className="text-sm font-semibold text-foreground">{MONTHS[month - 1]} {year}</span>
                    <button onClick={() => navigate(1)} className="rounded p-1 text-muted-foreground hover:text-foreground transition-colors">
                        <ChevronRight className="size-5" />
                    </button>
                </div>

                {/* Calendar */}
                <div className="rounded-xl border border-border bg-card overflow-hidden">
                    {/* Day headers */}
                    <div className="grid grid-cols-7 border-b border-border">
                        {DAYS.map(d => (
                            <div key={d} className="py-2 text-center text-[11px] font-medium uppercase tracking-widest text-muted-foreground">
                                {d}
                            </div>
                        ))}
                    </div>

                    {/* Day cells */}
                    <div className="grid grid-cols-7">
                        {/* Empty cells before first day */}
                        {Array.from({ length: firstDay }).map((_, i) => (
                            <div key={`e${i}`} className="border-b border-r border-border min-h-[64px]" />
                        ))}

                        {Array.from({ length: daysInMonth }).map((_, i) => {
                            const day    = i + 1;
                            const key    = dayKey(day);
                            const hasEntry = Boolean(entries[key]);
                            const isExp  = expanded === key;
                            const isTodayCell = key === todayKey;
                            const col    = (firstDay + i) % 7;
                            const isLastCol = col === 6;

                            return (
                                <div
                                    key={key}
                                    onClick={() => hasEntry && setExpanded(isExp ? null : key)}
                                    className={`relative min-h-[64px] border-b border-border p-2 transition-colors
                                        ${!isLastCol ? 'border-r' : ''}
                                        ${hasEntry ? 'cursor-pointer hover:bg-muted/40' : ''}
                                        ${isExp ? 'bg-muted/30' : ''}
                                    `}
                                >
                                    {/* Day number */}
                                    <span className={`text-xs font-semibold ${isTodayCell ? 'flex size-5 items-center justify-center rounded-full bg-emerald-500 text-white' : 'text-muted-foreground'}`}>
                                        {day}
                                    </span>

                                    {/* Entry indicator */}
                                    {hasEntry && (
                                        <div className="mt-1.5 flex items-center gap-1">
                                            <div className="size-1.5 rounded-full bg-emerald-500" />
                                            <span className="text-[10px] text-muted-foreground">Journal</span>
                                        </div>
                                    )}

                                    {/* Generating indicator on today */}
                                    {isTodayCell && generating && (
                                        <div className="mt-1.5 flex items-center gap-1">
                                            <RefreshCw className="size-3 animate-spin text-muted-foreground" />
                                            <span className="text-[10px] text-muted-foreground">Generating…</span>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Modal */}
                {expanded && expandedEntry && (() => {
                    const date = new Date(expanded + 'T12:00:00');
                    const weekday = date.toLocaleDateString('en-US', { weekday: 'long' });
                    const dateStr = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                    const isExpandedToday = expanded === todayKey;

                    return (
                        <div className="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" onClick={() => setExpanded(null)}>
                            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
                            <div
                                className="relative flex w-full max-w-xl flex-col rounded-2xl border border-border bg-card shadow-2xl overflow-hidden max-h-[85vh]"
                                onClick={e => e.stopPropagation()}
                            >
                                {/* Modal header */}
                                <div className="flex items-start justify-between gap-3 border-b border-border bg-muted/30 px-5 py-4">
                                    <div>
                                        <p className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{weekday}</p>
                                        <p className="mt-0.5 text-base font-semibold text-foreground">{dateStr}</p>
                                        {isExpandedToday && (
                                            <span className="mt-1 inline-block rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-500">Today</span>
                                        )}
                                    </div>
                                    <button onClick={() => setExpanded(null)} className="mt-0.5 shrink-0 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                        <X className="size-4" />
                                    </button>
                                </div>

                                {/* Legend */}
                                <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-2.5 text-[10px]">
                                    <span className="font-semibold text-emerald-500">■ LONG / positive</span>
                                    <span className="font-semibold text-red-500">■ SHORT / negative</span>
                                    <span className="font-semibold text-amber-400">■ price / value</span>
                                    <span className="font-semibold text-sky-400">■ coin</span>
                                </div>

                                {/* Body */}
                                <div className="overflow-y-auto px-5 py-5">
                                    <div className="flex flex-col gap-4 text-sm leading-7 text-foreground">
                                        {paragraphs.map((para, i) => (
                                            <p key={i} className={para.match(/^\d+\./) ? 'border-l-2 border-border pl-3' : ''}>
                                                <RichParagraph text={para} />
                                            </p>
                                        ))}
                                    </div>
                                </div>

                                {/* Footer */}
                                <div className="border-t border-border bg-muted/20 px-5 py-3">
                                    <p className="text-[11px] text-muted-foreground">AI-generated summary based on account data. Not financial advice.</p>
                                </div>
                            </div>
                        </div>
                    );
                })()}
            </div>
        </>
    );
}

TradingJournal.layout = {
    breadcrumbs: [{ title: 'Trading Journal', href: tradingJournal.url() }],
};
