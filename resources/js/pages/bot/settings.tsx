import { Form, Head, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useState } from 'react';
import BotSettingsController from '@/actions/App/Http/Controllers/BotSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const CONFIRM_PHRASE = 'ENABLE REAL TRADING';

interface Settings {
    bot_enabled: boolean;
    real_trading_enabled: boolean;
    minimum_confidence_to_trade: number;
    leverage: number;
    max_open_positions: number;
    max_total_margin_usdt: number;
    max_daily_loss_usdt: number;
    cooldown_minutes_per_pair: number;
    ai_validation_enabled: boolean;
    ai_validation_daily_budget_usd: number;
    margin_by_confidence: Record<string, number>;
    target_net_profit_by_confidence: Record<string, number>;
}

interface Stats {
    open_positions: number;
    total_margin_committed: number;
    realized_pnl_today: number;
    total_trades: number;
    ai_spend_today: number;
}

interface OpenPosition {
    id: number;
    trade_set_id: string;
    leg: string;
    symbol: string;
    direction: string;
    margin_usd: number;
    leverage: number;
    entry_price: number;
    current_price: number | null;
    unrealized_pnl: number | null;
    take_profit: number;
    stop_loss: number;
    trailing_active: boolean;
    confidence_score: number;
    mode: string;
    opened_at: string;
}

interface Props {
    settings: Settings;
    stats: Stats;
    openPositions: OpenPosition[];
}

export default function BotSettings({ settings, stats, openPositions }: Props) {
    const [closingId, setClosingId] = useState<number | null>(null);

    // Poll stats + open positions every 5s so this page reflects what the bot is
    // actually doing live, instead of only what existed when the page first loaded.
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['stats', 'openPositions'] });
        }, 5000);

        return () => clearInterval(interval);
    }, []);

    function handleClose(pos: OpenPosition) {
        if (
            !confirm(
                `Close ${pos.direction} ${pos.symbol} (${pos.leg} leg, $${pos.margin_usd.toFixed(2)} margin, ${pos.mode} mode) now?`,
            )
        ) {
            return;
        }

        setClosingId(pos.id);
        router.post(
            BotSettingsController.closePosition.url({ trade: pos.id }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['stats', 'openPositions'],
                onFinish: () => setClosingId(null),
            },
        );
    }

    return (
        <>
            <Head title="Bot Settings" />

            <div className="flex h-full flex-1 flex-col gap-6 p-3 sm:p-4">
                <Heading
                    title="Trading Bot"
                    description="Automated market scanning, signal scoring, and paper/real trading controls"
                />

                <div className="grid gap-4 sm:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Open positions</CardDescription>
                            <CardTitle className="text-2xl">
                                {stats.open_positions}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Margin committed</CardDescription>
                            <CardTitle className="text-2xl">
                                ${stats.total_margin_committed.toFixed(2)}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                Realized PnL today
                            </CardDescription>
                            <CardTitle
                                className={`text-2xl ${stats.realized_pnl_today < 0 ? 'text-red-500' : 'text-green-600'}`}
                            >
                                ${stats.realized_pnl_today.toFixed(2)}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Total trades</CardDescription>
                            <CardTitle className="text-2xl">
                                {stats.total_trades}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                AI spend today ({settings.ai_validation_enabled ? 'on' : 'off'})
                            </CardDescription>
                            <CardTitle className="text-2xl">
                                ${stats.ai_spend_today.toFixed(4)}{' '}
                                <span className="text-sm font-normal text-muted-foreground">
                                    / $
                                    {settings.ai_validation_daily_budget_usd.toFixed(2)}
                                </span>
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Open positions</CardTitle>
                        <CardDescription>
                            What the bot currently has open — paper or real,
                            live unrealized PnL from current market price.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {openPositions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No open positions.
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
                                                Side
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Leg
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Margin
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Entry
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Current
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Unrealized PnL
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                TP / SL
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Conf.
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Mode
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                Opened
                                            </th>
                                            <th className="py-2 pr-4 font-medium">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {openPositions.map((pos) => (
                                            <tr
                                                key={pos.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 pr-4 font-medium">
                                                    {pos.symbol}
                                                </td>
                                                <td
                                                    className={`py-2 pr-4 ${pos.direction === 'LONG' ? 'text-green-600' : 'text-red-500'}`}
                                                >
                                                    {pos.direction}
                                                </td>
                                                <td className="py-2 pr-4 text-muted-foreground">
                                                    {pos.leg}
                                                    {pos.trailing_active &&
                                                        ' (trailing)'}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    ${pos.margin_usd.toFixed(2)}{' '}
                                                    @ {pos.leverage}x
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {pos.entry_price}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {pos.current_price ?? 'n/a'}
                                                </td>
                                                <td
                                                    className={`py-2 pr-4 font-medium ${
                                                        pos.unrealized_pnl ===
                                                        null
                                                            ? ''
                                                            : pos.unrealized_pnl >=
                                                                0
                                                              ? 'text-green-600'
                                                              : 'text-red-500'
                                                    }`}
                                                >
                                                    {pos.unrealized_pnl === null
                                                        ? 'n/a'
                                                        : `$${pos.unrealized_pnl.toFixed(2)}`}
                                                </td>
                                                <td className="py-2 pr-4 text-muted-foreground">
                                                    {pos.take_profit} /{' '}
                                                    {pos.stop_loss}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {pos.confidence_score}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    <span
                                                        className={
                                                            pos.mode === 'real'
                                                                ? 'text-red-500'
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {pos.mode}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-4 text-muted-foreground">
                                                    {new Date(
                                                        pos.opened_at,
                                                    ).toLocaleString()}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
                                                        disabled={
                                                            closingId ===
                                                            pos.id
                                                        }
                                                        onClick={() =>
                                                            handleClose(pos)
                                                        }
                                                    >
                                                        {closingId === pos.id
                                                            ? 'Closing…'
                                                            : 'Close'}
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Keyed on the settings payload so this fully remounts (fresh useState)
                    whenever the server's actual values change — including after a
                    rejected save (e.g. wrong confirm phrase), so the form can never
                    silently keep showing an unsaved toggle as if it were active. */}
                <SettingsForm
                    key={JSON.stringify(settings)}
                    settings={settings}
                />
            </div>
        </>
    );
}

function SettingsForm({ settings }: { settings: Settings }) {
    const [botEnabled, setBotEnabled] = useState(settings.bot_enabled);
    const [realTradingEnabled, setRealTradingEnabled] = useState(
        settings.real_trading_enabled,
    );
    const [confirmText, setConfirmText] = useState('');

    const [minConfidence, setMinConfidence] = useState(
        settings.minimum_confidence_to_trade,
    );
    const [leverage, setLeverage] = useState(settings.leverage);
    const [maxOpenPositions, setMaxOpenPositions] = useState(
        settings.max_open_positions,
    );
    const [maxTotalMargin, setMaxTotalMargin] = useState(
        settings.max_total_margin_usdt,
    );
    const [maxDailyLoss, setMaxDailyLoss] = useState(
        settings.max_daily_loss_usdt,
    );
    const [cooldownMinutes, setCooldownMinutes] = useState(
        settings.cooldown_minutes_per_pair,
    );
    const [aiValidationEnabled, setAiValidationEnabled] = useState(
        settings.ai_validation_enabled,
    );
    const [marginByConfidence, setMarginByConfidence] = useState(
        settings.margin_by_confidence,
    );
    const [targetNetProfitByConfidence, setTargetNetProfitByConfidence] =
        useState(settings.target_net_profit_by_confidence);

    const wantsToEnableReal =
        realTradingEnabled && !settings.real_trading_enabled;
    const confirmSatisfied =
        !wantsToEnableReal || confirmText === CONFIRM_PHRASE;

    return (
        <Form {...BotSettingsController.update.form()} className="space-y-6">
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="bot_enabled"
                        value={botEnabled ? '1' : '0'}
                    />
                    <input
                        type="hidden"
                        name="real_trading_enabled"
                        value={realTradingEnabled ? '1' : '0'}
                    />
                    <input
                        type="hidden"
                        name="ai_validation_enabled"
                        value={aiValidationEnabled ? '1' : '0'}
                    />
                    {wantsToEnableReal && (
                        <input
                            type="hidden"
                            name="confirm"
                            value={confirmText}
                        />
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Bot loop</CardTitle>
                            <CardDescription>
                                Turns the continuous scan → score → risk-check →
                                trade loop on or off. Off by default; a single
                                debug cycle can still be run with{' '}
                                <code className="rounded bg-muted px-1 py-0.5">
                                    php artisan bot:run --once
                                </code>
                                .
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="bot_enabled"
                                    checked={botEnabled}
                                    onCheckedChange={(v) =>
                                        setBotEnabled(v === true)
                                    }
                                />
                                <Label htmlFor="bot_enabled">Bot enabled</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <Card
                        className={
                            realTradingEnabled ? 'border-red-500' : undefined
                        }
                    >
                        <CardHeader>
                            <CardTitle>Real trading</CardTitle>
                            <CardDescription>
                                When off (default), every qualifying signal is
                                simulated only — nothing is ever sent to MEXC.
                                When on, the bot places real market orders with
                                real money on your connected MEXC account.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="real_trading_enabled"
                                    checked={realTradingEnabled}
                                    onCheckedChange={(v) => {
                                        setRealTradingEnabled(v === true);
                                        setConfirmText('');
                                    }}
                                />
                                <Label htmlFor="real_trading_enabled">
                                    Real trading enabled (LIVE MONEY)
                                </Label>
                            </div>

                            {wantsToEnableReal && (
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertTitle>
                                        This will place real orders with real
                                        money
                                    </AlertTitle>
                                    <AlertDescription className="space-y-3">
                                        <p>
                                            Every qualifying signal will open an
                                            actual position on your MEXC account
                                            at {settings.leverage}x leverage.
                                            Type{' '}
                                            <strong>{CONFIRM_PHRASE}</strong>{' '}
                                            below to confirm.
                                        </p>
                                        <Input
                                            value={confirmText}
                                            onChange={(e) =>
                                                setConfirmText(e.target.value)
                                            }
                                            placeholder={CONFIRM_PHRASE}
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.confirm} />
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Risk configuration</CardTitle>
                            <CardDescription>
                                Editable from here — takes effect on the bot's
                                next cycle, no restart needed.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="minimum_confidence_to_trade">
                                        Min confidence to trade (1-10)
                                    </Label>
                                    <Input
                                        id="minimum_confidence_to_trade"
                                        name="minimum_confidence_to_trade"
                                        type="number"
                                        min={1}
                                        max={10}
                                        value={minConfidence}
                                        onChange={(e) =>
                                            setMinConfidence(
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors.minimum_confidence_to_trade
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="leverage">Leverage</Label>
                                    <Input
                                        id="leverage"
                                        name="leverage"
                                        type="number"
                                        min={1}
                                        max={200}
                                        value={leverage}
                                        onChange={(e) =>
                                            setLeverage(Number(e.target.value))
                                        }
                                    />
                                    <InputError message={errors.leverage} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="max_open_positions">
                                        Max open positions
                                    </Label>
                                    <Input
                                        id="max_open_positions"
                                        name="max_open_positions"
                                        type="number"
                                        min={1}
                                        max={100}
                                        value={maxOpenPositions}
                                        onChange={(e) =>
                                            setMaxOpenPositions(
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.max_open_positions}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="max_total_margin_usdt">
                                        Max total margin ($)
                                    </Label>
                                    <Input
                                        id="max_total_margin_usdt"
                                        name="max_total_margin_usdt"
                                        type="number"
                                        step="0.01"
                                        min={1}
                                        value={maxTotalMargin}
                                        onChange={(e) =>
                                            setMaxTotalMargin(
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.max_total_margin_usdt}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="max_daily_loss_usdt">
                                        Max daily loss ($)
                                    </Label>
                                    <Input
                                        id="max_daily_loss_usdt"
                                        name="max_daily_loss_usdt"
                                        type="number"
                                        step="0.01"
                                        min={1}
                                        value={maxDailyLoss}
                                        onChange={(e) =>
                                            setMaxDailyLoss(
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.max_daily_loss_usdt}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cooldown_minutes_per_pair">
                                        Cooldown per pair (minutes)
                                    </Label>
                                    <Input
                                        id="cooldown_minutes_per_pair"
                                        name="cooldown_minutes_per_pair"
                                        type="number"
                                        min={0}
                                        value={cooldownMinutes}
                                        onChange={(e) =>
                                            setCooldownMinutes(
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors.cooldown_minutes_per_pair
                                        }
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Margin per confidence score</CardTitle>
                            <CardDescription>
                                USD margin used for a trade at each confidence
                                score, at {leverage}x leverage. Takes effect
                                on the bot's next cycle.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-3 gap-4 sm:grid-cols-6">
                                {['5', '6', '7', '8', '9', '10'].map(
                                    (confidence) => (
                                        <div
                                            key={confidence}
                                            className="grid gap-1.5"
                                        >
                                            <Label
                                                htmlFor={`margin_by_confidence_${confidence}`}
                                            >
                                                Conf. {confidence}
                                            </Label>
                                            <Input
                                                id={`margin_by_confidence_${confidence}`}
                                                name={`margin_by_confidence[${confidence}]`}
                                                type="number"
                                                step="0.01"
                                                min={0.1}
                                                value={
                                                    marginByConfidence[
                                                        confidence
                                                    ]
                                                }
                                                onChange={(e) =>
                                                    setMarginByConfidence(
                                                        (prev) => ({
                                                            ...prev,
                                                            [confidence]:
                                                                Number(
                                                                    e.target
                                                                        .value,
                                                                ),
                                                        }),
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `margin_by_confidence.${confidence}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ),
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Target net profit per confidence score
                            </CardTitle>
                            <CardDescription>
                                Net $ profit a trade at each confidence score
                                takes as its static take-profit target.
                                Trailing TP and smart exit can still let a
                                trade run past this. Takes effect on the
                                bot's next cycle.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-3 gap-4 sm:grid-cols-6">
                                {['5', '6', '7', '8', '9', '10'].map(
                                    (confidence) => (
                                        <div
                                            key={confidence}
                                            className="grid gap-1.5"
                                        >
                                            <Label
                                                htmlFor={`target_net_profit_by_confidence_${confidence}`}
                                            >
                                                Conf. {confidence}
                                            </Label>
                                            <Input
                                                id={`target_net_profit_by_confidence_${confidence}`}
                                                name={`target_net_profit_by_confidence[${confidence}]`}
                                                type="number"
                                                step="0.01"
                                                min={0.01}
                                                value={
                                                    targetNetProfitByConfidence[
                                                        confidence
                                                    ]
                                                }
                                                onChange={(e) =>
                                                    setTargetNetProfitByConfidence(
                                                        (prev) => ({
                                                            ...prev,
                                                            [confidence]:
                                                                Number(
                                                                    e.target
                                                                        .value,
                                                                ),
                                                        }),
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `target_net_profit_by_confidence.${confidence}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ),
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>AI signal validation</CardTitle>
                            <CardDescription>
                                When on, DeepSeek reviews every signal that
                                already qualifies on the indicator score
                                before it trades. It can only make the bot
                                more cautious — skip the trade entirely, or
                                shave 1 point off confidence (smaller
                                position) — never raise the score above what
                                the indicators produced. Falls back to
                                indicator-only silently if the daily budget (
                                ${settings.ai_validation_daily_budget_usd.toFixed(2)}
                                ) is exhausted or the API call fails.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="ai_validation_enabled"
                                    checked={aiValidationEnabled}
                                    onCheckedChange={(v) =>
                                        setAiValidationEnabled(v === true)
                                    }
                                />
                                <Label htmlFor="ai_validation_enabled">
                                    AI signal validation enabled
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <Button
                        type="submit"
                        disabled={processing || !confirmSatisfied}
                    >
                        Save settings
                    </Button>
                </>
            )}
        </Form>
    );
}
