import { Form, Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
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
    target_net_profit_per_trade: number;
    max_open_positions: number;
    max_total_margin_usdt: number;
    max_daily_loss_usdt: number;
    cooldown_minutes_per_pair: number;
}

interface Stats {
    open_positions: number;
    total_margin_committed: number;
    realized_pnl_today: number;
    total_trades: number;
}

interface Props {
    settings: Settings;
    stats: Stats;
}

export default function BotSettings({ settings, stats }: Props) {
    const [botEnabled, setBotEnabled] = useState(settings.bot_enabled);
    const [realTradingEnabled, setRealTradingEnabled] = useState(
        settings.real_trading_enabled,
    );
    const [confirmText, setConfirmText] = useState('');

    const wantsToEnableReal =
        realTradingEnabled && !settings.real_trading_enabled;
    const confirmSatisfied =
        !wantsToEnableReal || confirmText === CONFIRM_PHRASE;

    return (
        <>
            <Head title="Bot Settings" />

            <div className="flex h-full flex-1 flex-col gap-6 p-3 sm:p-4">
                <Heading
                    title="Trading Bot"
                    description="Automated market scanning, signal scoring, and paper/real trading controls"
                />

                <div className="grid gap-4 sm:grid-cols-4">
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
                </div>

                <Form
                    {...BotSettingsController.update.form()}
                    className="space-y-6"
                >
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
                                        Turns the continuous scan → score →
                                        risk-check → trade loop on or off. Off
                                        by default; a single debug cycle can
                                        still be run with{' '}
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
                                        <Label htmlFor="bot_enabled">
                                            Bot enabled
                                        </Label>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card
                                className={
                                    realTradingEnabled
                                        ? 'border-red-500'
                                        : undefined
                                }
                            >
                                <CardHeader>
                                    <CardTitle>Real trading</CardTitle>
                                    <CardDescription>
                                        When off (default), every qualifying
                                        signal is simulated only — nothing is
                                        ever sent to MEXC. When on, the bot
                                        places real market orders with real
                                        money on your connected MEXC account.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="real_trading_enabled"
                                            checked={realTradingEnabled}
                                            onCheckedChange={(v) => {
                                                setRealTradingEnabled(
                                                    v === true,
                                                );
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
                                                This will place real orders with
                                                real money
                                            </AlertTitle>
                                            <AlertDescription className="space-y-3">
                                                <p>
                                                    Every qualifying signal will
                                                    open an actual position on
                                                    your MEXC account at{' '}
                                                    {settings.leverage}x
                                                    leverage. Type{' '}
                                                    <strong>
                                                        {CONFIRM_PHRASE}
                                                    </strong>{' '}
                                                    below to confirm.
                                                </p>
                                                <Input
                                                    value={confirmText}
                                                    onChange={(e) =>
                                                        setConfirmText(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder={CONFIRM_PHRASE}
                                                    autoComplete="off"
                                                />
                                                <InputError
                                                    message={errors.confirm}
                                                />
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Current risk configuration
                                    </CardTitle>
                                    <CardDescription>
                                        Set in config/bot.php — edit and
                                        redeploy to change these.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Min confidence to trade
                                            </dt>
                                            <dd className="font-medium">
                                                {
                                                    settings.minimum_confidence_to_trade
                                                }
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Leverage
                                            </dt>
                                            <dd className="font-medium">
                                                {settings.leverage}x
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Target net profit / trade
                                            </dt>
                                            <dd className="font-medium">
                                                $
                                                {settings.target_net_profit_per_trade.toFixed(
                                                    2,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Max open positions
                                            </dt>
                                            <dd className="font-medium">
                                                {settings.max_open_positions}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Max total margin
                                            </dt>
                                            <dd className="font-medium">
                                                $
                                                {settings.max_total_margin_usdt}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Max daily loss
                                            </dt>
                                            <dd className="font-medium">
                                                -${settings.max_daily_loss_usdt}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Cooldown per pair
                                            </dt>
                                            <dd className="font-medium">
                                                {
                                                    settings.cooldown_minutes_per_pair
                                                }
                                                m
                                            </dd>
                                        </div>
                                    </dl>
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
            </div>
        </>
    );
}
