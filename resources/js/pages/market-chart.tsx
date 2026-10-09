import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { MarketChart } from '@/components/futures/market-chart';
import { chartLines } from '@/components/futures/position-chart';
import { SearchableSelect } from '@/components/futures/searchable-select';
import { useActiveSymbols } from '@/hooks/use-active-symbols';
import { useLivePositions } from '@/hooks/use-live-positions';
import { CHART_TIMEFRAMES } from '@/lib/market-chart';
import type { ChartTimeframe } from '@/lib/market-chart';
import { marketChart } from '@/routes';

interface Choice {
    symbol: string;
    tf: ChartTimeframe;
    levels: boolean;
    supertrend12: boolean;
    supertrend10: boolean;
    wave: boolean;
    positions: boolean;
}

const CHOICE_KEY = 'market-chart-choice';

const DEFAULT_CHOICE: Choice = {
    symbol: 'BTC_USDT',
    tf: '4H',
    levels: true,
    supertrend12: true,
    supertrend10: false,
    wave: false,
    positions: true,
};

// Browser storage can be missing or throw (private windows, blocked site data), so remembering
// the last coin and switches is a convenience that must never break the page.
function readChoice(): Choice {
    try {
        const stored = JSON.parse(localStorage.getItem(CHOICE_KEY) ?? '{}');
        const flag = (key: keyof Choice) =>
            typeof stored?.[key] === 'boolean'
                ? (stored[key] as boolean)
                : (DEFAULT_CHOICE[key] as boolean);

        return {
            symbol:
                typeof stored?.symbol === 'string' &&
                /^[A-Za-z0-9]+_USDT$/.test(stored.symbol)
                    ? stored.symbol
                    : DEFAULT_CHOICE.symbol,
            tf: CHART_TIMEFRAMES.includes(stored?.tf)
                ? stored.tf
                : DEFAULT_CHOICE.tf,
            levels: flag('levels'),
            supertrend12: flag('supertrend12'),
            supertrend10: flag('supertrend10'),
            wave: flag('wave'),
            positions: flag('positions'),
        };
    } catch {
        return DEFAULT_CHOICE;
    }
}

function writeChoice(choice: Choice): void {
    try {
        localStorage.setItem(CHOICE_KEY, JSON.stringify(choice));
    } catch {
        // ignore — the choice still holds for this session
    }
}

/**
 * A full-size chart of one coin: candles, the previous day/week/month/year levels from your
 * TradingView, SuperTrend, a WaveTrend pane if you want it, and your own open positions drawn
 * on the same chart. Not a replacement for TradingView (no drawing tools); it is the chart
 * that shows your levels and your trades next to the numbers the rest of the dashboard uses.
 */
export default function MarketChartPage() {
    const symbols = useActiveSymbols();
    const [choice, setChoice] = useState<Choice>(readChoice);
    const livePositions = useLivePositions(choice.positions);

    // Built on the latest choice, so two changes in one go (two switches pressed together) both hold.
    const update = (patch: Partial<Choice>) =>
        setChoice((previous) => ({ ...previous, ...patch }));

    useEffect(() => {
        writeChoice(choice);
    }, [choice]);

    const supertrend = useMemo(
        () => ({
            '12_2.5': choice.supertrend12,
            '10_3': choice.supertrend10,
        }),
        [choice.supertrend12, choice.supertrend10],
    );

    // The open legs in this coin, as lines (entries, break-even, liquidation, SL/TP).
    const positionLines = useMemo(() => {
        const legs = livePositions.filter((p) => p.symbol === choice.symbol);
        const price = legs.find((l) => l.fairPrice > 0)?.fairPrice ?? null;

        return chartLines(legs, price);
    }, [livePositions, choice.symbol]);

    return (
        <>
            <Head title="Market chart" />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <SearchableSelect
                        value={choice.symbol}
                        options={symbols}
                        onChange={(symbol) => update({ symbol })}
                        className="w-44 shrink-0"
                    />

                    <div
                        className="flex gap-1"
                        role="group"
                        aria-label="Timeframe"
                    >
                        {CHART_TIMEFRAMES.map((tf) => (
                            <button
                                key={tf}
                                type="button"
                                onClick={() => update({ tf })}
                                aria-pressed={choice.tf === tf}
                                className={`rounded border px-2 py-1 text-[11px] font-medium transition-colors ${
                                    choice.tf === tf
                                        ? 'border-violet-400 bg-violet-400/10 text-violet-400'
                                        : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                                }`}
                            >
                                {tf}
                            </button>
                        ))}
                    </div>

                    <div
                        className="flex flex-wrap gap-1"
                        role="group"
                        aria-label="Show on the chart"
                    >
                        <Switch
                            on={choice.levels}
                            onChange={(levels) => update({ levels })}
                            title="Previous day, week, month and year highs and lows, and the midpoints of the day, week and month"
                        >
                            Levels
                        </Switch>
                        <Switch
                            on={choice.supertrend12}
                            onChange={(supertrend12) =>
                                update({ supertrend12 })
                            }
                            title="SuperTrend: ATR period 12, multiplier 2.5"
                        >
                            SuperTrend 12 2.5
                        </Switch>
                        <Switch
                            on={choice.supertrend10}
                            onChange={(supertrend10) =>
                                update({ supertrend10 })
                            }
                            title="SuperTrend: ATR period 10, multiplier 3"
                        >
                            SuperTrend 10 3
                        </Switch>
                        <Switch
                            on={choice.wave}
                            onChange={(wave) => update({ wave })}
                            title="WaveTrend (10, 21) in a pane under the candles, with a dot at each cross"
                        >
                            WaveTrend
                        </Switch>
                        <Switch
                            on={choice.positions}
                            onChange={(positions) => update({ positions })}
                            title="Your open positions in this coin: entries, break-even, liquidation, stop-loss and take-profit"
                        >
                            My positions
                        </Switch>
                    </div>
                </div>

                <div className="relative min-h-[480px] flex-1 overflow-hidden rounded-xl border border-border bg-card">
                    <div className="absolute inset-0">
                        <MarketChart
                            symbol={choice.symbol}
                            tf={choice.tf}
                            showLevels={choice.levels}
                            supertrend={supertrend}
                            showWaveTrend={choice.wave}
                            positionLines={positionLines}
                        />
                    </div>
                </div>

                <p className="text-[10px] leading-snug text-muted-foreground">
                    PDH/PDL, PWH/PWL, PMH/PML and PYH/PYL are the previous
                    day&apos;s, week&apos;s, month&apos;s and year&apos;s high
                    and low. DP, WP and MP are the middle of the previous
                    day&apos;s, week&apos;s and month&apos;s range — the same as
                    on your TradingView, not the (H+L+C)/3 pivots of the
                    Analysis ladder. Days, weeks (from Monday), months and years
                    are UTC, and the prices are MEXC&apos;s own, so a level can
                    sit a little off TradingView&apos;s feed. Charting by{' '}
                    <a
                        href="https://www.tradingview.com/"
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-2 hover:text-foreground"
                    >
                        TradingView Lightweight Charts™
                    </a>
                    .
                </p>
            </div>
        </>
    );
}

function Switch({
    on,
    onChange,
    title,
    children,
}: {
    on: boolean;
    onChange: (on: boolean) => void;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={() => onChange(!on)}
            aria-pressed={on}
            title={title}
            className={`rounded border px-2 py-1 text-[11px] font-medium transition-colors ${
                on
                    ? 'border-violet-400 bg-violet-400/10 text-violet-400'
                    : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground'
            }`}
        >
            {children}
        </button>
    );
}

MarketChartPage.layout = {
    breadcrumbs: [{ title: 'Market chart', href: marketChart.url() }],
};
