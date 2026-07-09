import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { signals } from '@/routes/bot';

interface Signal {
    id: number;
    symbol: string;
    direction: string | null;
    confidence_score: number;
    reasons: string[];
    entry_price: number | null;
    take_profit: number | null;
    stop_loss: number | null;
    estimated_fee_usdt: number | null;
    expected_net_profit_usdt: number | null;
    opened: boolean;
    skip_reason: string | null;
    analyzed_at: string;
}

interface Props {
    signals: Signal[];
    symbol: string | null;
}

export default function BotSignals({ signals: initialSignals, symbol: initialSymbol }: Props) {
    const [symbolInput, setSymbolInput] = useState(initialSymbol ?? '');
    const [expandedId, setExpandedId] = useState<number | null>(null);

    function search() {
        router.get(
            signals.url(),
            symbolInput ? { symbol: symbolInput } : {},
            { preserveState: true },
        );
    }

    return (
        <>
            <Head title="Bot Signals" />

            <div className="flex h-full flex-1 flex-col gap-6 p-3 sm:p-4">
                <Heading
                    title="Bot Signals"
                    description="Every pair the bot has analyzed — its own confidence score and reasoning, straight from SignalEngine. Not a third-party indicator."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Search</CardTitle>
                        <CardDescription>
                            Filter by symbol (e.g. KAS_USDT or just KAS) to see
                            what the bot last thought about that pair. Leave
                            blank for the most recent 50 across everything
                            analyzed.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex max-w-sm gap-2">
                            <Input
                                value={symbolInput}
                                onChange={(e) =>
                                    setSymbolInput(e.target.value)
                                }
                                onKeyDown={(e) =>
                                    e.key === 'Enter' && search()
                                }
                                placeholder="KAS_USDT"
                            />
                            <Button type="button" onClick={search}>
                                Search
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {initialSymbol
                                ? `Signals matching "${initialSymbol}"`
                                : 'Recent signals'}
                        </CardTitle>
                        <CardDescription>
                            Last {initialSignals.length} analyzed. Confidence
                            is the bot's own 1-10 score from combining 1H/15M
                            trend, RSI, 5M EMA/volume/momentum, price action,
                            and USDT dominance.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {initialSignals.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No signals found.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="py-2 pr-4 font-medium">
                                                Symbol
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Direction
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Confidence
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Entry / TP / SL
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Expected net
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Opened?
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                When
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                <span className="sr-only">
                                                    Reasoning
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {initialSignals.map((s) => (
                                            <Fragment key={s.id}>
                                                <tr className="border-b last:border-0">
                                                    <td className="py-2 pr-4 font-medium">
                                                        {s.symbol}
                                                    </td>
                                                    <td
                                                        className={`py-2 pr-4 ${
                                                            s.direction ===
                                                            'LONG'
                                                                ? 'text-green-600'
                                                                : s.direction ===
                                                                    'SHORT'
                                                                  ? 'text-red-500'
                                                                  : 'text-muted-foreground'
                                                        }`}
                                                    >
                                                        {s.direction ?? '—'}
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        {s.confidence_score}
                                                    </td>
                                                    <td className="py-2 pr-4 text-muted-foreground">
                                                        {s.entry_price ??
                                                            '—'}{' '}
                                                        /{' '}
                                                        {s.take_profit ??
                                                            '—'}{' '}
                                                        / {s.stop_loss ?? '—'}
                                                    </td>
                                                    <td className="py-2 pr-4 text-muted-foreground">
                                                        {s.expected_net_profit_usdt !==
                                                        null
                                                            ? `$${s.expected_net_profit_usdt.toFixed(2)}`
                                                            : '—'}
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        {s.opened ? (
                                                            <span className="text-green-600">
                                                                Yes
                                                            </span>
                                                        ) : (
                                                            <span
                                                                className="text-muted-foreground"
                                                                title={
                                                                    s.skip_reason ??
                                                                    undefined
                                                                }
                                                            >
                                                                No
                                                                {s.skip_reason &&
                                                                    ` — ${s.skip_reason}`}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-4 text-muted-foreground">
                                                        {new Date(
                                                            s.analyzed_at,
                                                        ).toLocaleString()}
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setExpandedId(
                                                                    expandedId ===
                                                                        s.id
                                                                        ? null
                                                                        : s.id,
                                                                )
                                                            }
                                                        >
                                                            {expandedId ===
                                                            s.id
                                                                ? 'Hide'
                                                                : 'Why?'}
                                                        </Button>
                                                    </td>
                                                </tr>
                                                {expandedId === s.id && (
                                                    <tr className="border-b last:border-0 bg-muted/30">
                                                        <td
                                                            colSpan={8}
                                                            className="px-4 py-3"
                                                        >
                                                            <ul className="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                                                {s.reasons.map(
                                                                    (
                                                                        reason,
                                                                        i,
                                                                    ) => (
                                                                        <li
                                                                            key={
                                                                                i
                                                                            }
                                                                        >
                                                                            {
                                                                                reason
                                                                            }
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

BotSignals.layout = {
    breadcrumbs: [{ title: 'Bot Signals', href: signals.url() }],
};
