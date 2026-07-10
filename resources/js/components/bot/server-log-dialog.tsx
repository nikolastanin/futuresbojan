import { ScrollText } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { logs as logsRoute } from '@/routes/bot';

interface LogEntry {
    id: number;
    level: 'debug' | 'info' | 'warning' | 'error';
    category: string;
    symbol: string | null;
    message: string;
    context: Record<string, unknown> | null;
    created_at: string;
}

const POLL_INTERVAL = 3_000;

const LEVEL_COLOR: Record<LogEntry['level'], string> = {
    debug: 'text-muted-foreground',
    info: 'text-foreground',
    warning: 'text-amber-500',
    error: 'text-red-500',
};

export function ServerLogDialog() {
    const [open, setOpen] = useState(false);
    const [logs, setLogs] = useState<LogEntry[]>([]);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const fetchLogs = () => {
            fetch(logsRoute.url(), { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((json) => {
                    if (json.success) {
                        setLogs(json.data);
                    }
                })
                .catch(() => {});
        };

        fetchLogs();

        const id = setInterval(fetchLogs, POLL_INTERVAL);

        return () => clearInterval(id);
    }, [open]);

    const formatTime = (iso: string) =>
        new Date(iso).toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="gap-1.5">
                    <ScrollText className="size-4" />
                    Server log
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[85vh] flex-col overflow-hidden sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Server log</DialogTitle>
                    <DialogDescription>
                        Every granular action the bot has taken — signal scans, risk
                        checks, order placement, position management, AI validation —
                        most recent first. Refreshes every 3s while open.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex-1 overflow-y-auto rounded-md border border-border">
                    {logs.length === 0 ? (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            No log entries yet.
                        </p>
                    ) : (
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-card">
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="px-3 py-2 font-medium">Time</th>
                                    <th className="px-3 py-2 font-medium">Level</th>
                                    <th className="px-3 py-2 font-medium">Category</th>
                                    <th className="px-3 py-2 font-medium">Symbol</th>
                                    <th className="px-3 py-2 font-medium">Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.map((log) => (
                                    <Fragment key={log.id}>
                                        <tr
                                            className="cursor-pointer border-b last:border-0 hover:bg-muted/30"
                                            onClick={() =>
                                                setExpandedId((prev) =>
                                                    prev === log.id ? null : log.id,
                                                )
                                            }
                                        >
                                            <td className="whitespace-nowrap px-3 py-1.5 text-muted-foreground">
                                                {formatTime(log.created_at)}
                                            </td>
                                            <td
                                                className={`px-3 py-1.5 font-semibold uppercase ${LEVEL_COLOR[log.level]}`}
                                            >
                                                {log.level}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-1.5 text-muted-foreground">
                                                {log.category}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-1.5 text-foreground">
                                                {log.symbol ?? '—'}
                                            </td>
                                            <td className="px-3 py-1.5 text-foreground">
                                                {log.message}
                                            </td>
                                        </tr>
                                        {expandedId === log.id && log.context && (
                                            <tr className="border-b bg-muted/20 last:border-0">
                                                <td colSpan={5} className="px-3 py-2">
                                                    <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-[11px] text-muted-foreground">
                                                        {JSON.stringify(log.context, null, 2)}
                                                    </pre>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
