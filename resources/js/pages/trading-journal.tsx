import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { BookOpen, RefreshCw } from 'lucide-react';
import { Toaster } from '@/components/ui/sonner';
import { Button } from '@/components/ui/button';
import { tradingJournal } from '@/routes';
import futures from '@/routes/futures';
import { toast } from 'sonner';

export default function TradingJournal() {
    const [entry,       setEntry]       = useState<string | null>(null);
    const [generatedAt, setGeneratedAt] = useState<string | null>(null);
    const [loading,     setLoading]     = useState(false);

    const generate = async () => {
        setLoading(true);
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
                setEntry(json.entry);
                setGeneratedAt(json.generatedAt);
            } else {
                toast.error(json.message ?? 'Failed to generate journal.');
            }
        } catch {
            toast.error('Network error.');
        } finally {
            setLoading(false);
        }
    };

    // Auto-fetch once on mount
    useEffect(() => { generate(); }, []);

    const formatDate = (iso: string) => {
        const d = new Date(iso);
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
            + ' · ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    };

    const paragraphs = entry?.split('\n').filter(l => l.trim()) ?? [];

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
                    <Button
                        onClick={generate}
                        disabled={loading}
                        variant="outline"
                        size="sm"
                        className="gap-2"
                    >
                        <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
                        {loading ? 'Generating…' : 'Regenerate'}
                    </Button>
                </div>

                {/* Journal card */}
                <div className="rounded-xl border border-border bg-card p-5 sm:p-6">
                    {generatedAt && (
                        <p className="mb-4 text-[11px] uppercase tracking-widest text-muted-foreground">
                            {formatDate(generatedAt)}
                        </p>
                    )}

                    {loading ? (
                        <div className="flex flex-col gap-3 animate-pulse">
                            {[...Array(6)].map((_, i) => (
                                <div key={i} className={`h-4 rounded bg-muted ${i % 3 === 2 ? 'w-2/3' : 'w-full'}`} />
                            ))}
                        </div>
                    ) : entry ? (
                        <div className="flex flex-col gap-3 text-sm leading-relaxed text-foreground">
                            {paragraphs.map((para, i) => (
                                <p key={i}>{para}</p>
                            ))}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-3 py-8 text-center text-muted-foreground">
                            <BookOpen className="size-8 opacity-30" />
                            <p className="text-sm">No journal entry yet.</p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

TradingJournal.layout = {
    breadcrumbs: [{ title: 'Trading Journal', href: tradingJournal.url() }],
};
