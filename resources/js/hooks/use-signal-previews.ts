import { useEffect, useState } from 'react';
import { signalPreview as signalPreviewRoute } from '@/routes/futures';

// Reuses the bot's own SignalEngine::score() so a manual trade can be sanity-checked
// against the exact same confidence/reasoning the automated bot uses. This calls a
// live kline-fetch + indicator-calc endpoint (not a cheap ticker lookup), so it's
// fetched far less often than price.

export interface PriceLevels {
    pivot: number;
    r1: number;
    r2: number;
    s1: number;
    s2: number;
    prior_day_high: number;
    prior_day_low: number;
    week_high: number;
    week_low: number;
    ema10: number | null;
    ema20: number | null;
}

export interface DominanceReading {
    direction: 'risk_on' | 'risk_off' | 'neutral';
    change_pct: number;
    lookback_minutes: number;
}

export interface SignalPreview {
    direction: 'LONG' | 'SHORT' | null;
    confidence: number;
    reasons: string[];
    current_price: number;
    /** 1H EMA50/200 read: 'up' | 'down' | 'sideways' | 'unknown'. */
    trend: string;
    /** 5M candle streak (2+ same-direction closes): 'up' | 'down' | 'neutral'. */
    momentum: string;
    /** 15M swing structure: 'bullish' | 'bearish' | null. */
    structure: 'bullish' | 'bearish' | null;
    /** 1H ATR as a % of price. */
    volatility_pct: number | null;
    change_24h_pct: number | null;
    high_24h: number | null;
    low_24h: number | null;
    /** Pivots/EMA10/EMA20/week range from daily candles, null if too little history. */
    levels: PriceLevels | null;
    /** 1H Wilder's RSI (14), null if not enough candle history. */
    rsi: number | null;
    /** 15M MACD line vs signal line. */
    macd: 'bullish' | 'bearish' | 'neutral' | null;
    /** Reversal candle (engulfing/hammer/shooting star) on the latest confirmed 15M candle. */
    candle_pattern: 'bullish' | 'bearish' | null;
    /** Price currently sitting inside an untested 15M fair value gap. */
    fair_value_gap: 'bullish' | 'bearish' | null;
    /** WaveTrend (Cipher B) price/momentum divergence on 15M. */
    wavetrend_divergence: 'bullish' | 'bearish' | null;
    /** 5M volume vs its prior window. */
    volume_trend: 'rising' | 'falling' | 'flat' | null;
    /** Market-wide USDT dominance risk-on/risk-off overlay. */
    dominance: DominanceReading | null;
}

export interface SignalBadge {
    label: string;
    color: string;
    description: string;
}

export type SignalPreviewMap = Record<
    string,
    SignalPreview | 'loading' | 'error' | undefined
>;

/** Friendly label + color for SignalPreview.trend (1H EMA50/200 read). */
export function trendLabel(trend: string): { label: string; color: string } {
    switch (trend) {
        case 'up':
            return { label: 'Up', color: 'text-emerald-500' };
        case 'down':
            return { label: 'Down', color: 'text-red-500' };
        default:
            return { label: 'Flat', color: 'text-muted-foreground' };
    }
}

/** Friendly label + color for SignalPreview.momentum (5M candle streak). */
export function momentumLabel(momentum: string): {
    label: string;
    color: string;
} {
    switch (momentum) {
        case 'up':
            return { label: 'Bullish', color: 'text-emerald-500' };
        case 'down':
            return { label: 'Bearish', color: 'text-red-500' };
        default:
            return { label: 'Neutral', color: 'text-muted-foreground' };
    }
}

/** Friendly label + color for SignalPreview.structure (15M swing structure), or null if unclear. */
export function structureLabel(
    structure: 'bullish' | 'bearish' | null,
): { label: string; color: string } | null {
    if (structure === 'bullish') {
        return { label: 'Higher highs/lows', color: 'text-emerald-500' };
    }

    if (structure === 'bearish') {
        return { label: 'Lower highs/lows', color: 'text-red-500' };
    }

    return null;
}

/** Badge for SignalPreview.rsi (1H Wilder's RSI), or null if not enough candle history. */
export function rsiBadge(rsi: number | null): SignalBadge | null {
    if (rsi === null) {
        return null;
    }

    const color =
        rsi >= 70
            ? 'text-red-500'
            : rsi <= 30
              ? 'text-emerald-500'
              : 'text-muted-foreground';

    return {
        label: `RSI ${rsi}`,
        color,
        description:
            "1H Wilder's RSI (14). Above 70 = overbought (bearish reversal bias), below 30 = oversold (bullish reversal bias) — the same reading Bot says scores.",
    };
}

/** Badge for SignalPreview.macd (15M MACD line vs signal line), or null if unavailable. */
export function macdBadge(macd: SignalPreview['macd']): SignalBadge | null {
    if (macd === null) {
        return null;
    }

    if (macd === 'bullish') {
        return {
            label: 'MACD bullish',
            color: 'text-emerald-500',
            description:
                '15M MACD line is above its signal line — bullish momentum crossover.',
        };
    }

    if (macd === 'bearish') {
        return {
            label: 'MACD bearish',
            color: 'text-red-500',
            description:
                '15M MACD line is below its signal line — bearish momentum crossover.',
        };
    }

    return {
        label: 'MACD flat',
        color: 'text-muted-foreground',
        description:
            '15M MACD line is sitting on its signal line — no crossover either way.',
    };
}

/** Badge for SignalPreview.candle_pattern (latest confirmed 15M candle), or null if no pattern formed. */
export function candlePatternBadge(
    pattern: 'bullish' | 'bearish' | null,
): SignalBadge | null {
    if (pattern === null) {
        return null;
    }

    return pattern === 'bullish'
        ? {
              label: 'Bullish candle',
              color: 'text-emerald-500',
              description:
                  'A bullish reversal candle just printed on 15M — engulfing or hammer.',
          }
        : {
              label: 'Bearish candle',
              color: 'text-red-500',
              description:
                  'A bearish reversal candle just printed on 15M — engulfing or shooting star.',
          };
}

/** Badge for SignalPreview.fair_value_gap, or null if price isn't sitting inside one. */
export function fairValueGapBadge(
    fvg: 'bullish' | 'bearish' | null,
): SignalBadge | null {
    if (fvg === null) {
        return null;
    }

    return fvg === 'bullish'
        ? {
              label: 'In bullish FVG',
              color: 'text-emerald-500',
              description:
                  'Price is sitting inside an untested 15M fair value gap below it — often acts like fresh support.',
          }
        : {
              label: 'In bearish FVG',
              color: 'text-red-500',
              description:
                  'Price is sitting inside an untested 15M fair value gap above it — often acts like fresh resistance.',
          };
}

/** Badge for SignalPreview.wavetrend_divergence (Cipher B), or null if none is active. */
export function waveTrendDivergenceBadge(
    divergence: 'bullish' | 'bearish' | null,
): SignalBadge | null {
    if (divergence === null) {
        return null;
    }

    return divergence === 'bullish'
        ? {
              label: 'Bullish divergence',
              color: 'text-emerald-500',
              description:
                  'WaveTrend (Cipher B) bullish divergence on 15M: price made a lower low while momentum made a higher low — a classic early reversal tell.',
          }
        : {
              label: 'Bearish divergence',
              color: 'text-red-500',
              description:
                  'WaveTrend (Cipher B) bearish divergence on 15M: price made a higher high while momentum made a lower high — a classic early reversal tell.',
          };
}

/** Badge for SignalPreview.volume_trend (5M volume vs its prior window), or null if too little history. */
export function volumeTrendBadge(
    trend: 'rising' | 'falling' | 'flat' | null,
): SignalBadge | null {
    if (trend === null) {
        return null;
    }

    if (trend === 'rising') {
        return {
            label: 'Volume rising',
            color: 'text-sky-500',
            description:
                '5M volume is running above its prior window — confirms a genuine move rather than chop when it lines up with momentum.',
        };
    }

    if (trend === 'falling') {
        return {
            label: 'Volume falling',
            color: 'text-muted-foreground',
            description:
                '5M volume is running below its prior window — a move on fading volume is easier to fade.',
        };
    }

    return {
        label: 'Volume flat',
        color: 'text-muted-foreground',
        description: '5M volume is roughly unchanged from its prior window.',
    };
}

/** Badge for SignalPreview.dominance (market-wide USDT dominance overlay), or null if unavailable. */
export function dominanceBadge(
    dominance: DominanceReading | null,
): SignalBadge | null {
    if (dominance === null) {
        return null;
    }

    const sign = dominance.change_pct >= 0 ? '+' : '';
    const changeStr = `${sign}${dominance.change_pct}pp over ${dominance.lookback_minutes}m`;

    if (dominance.direction === 'risk_on') {
        return {
            label: 'Risk-on',
            color: 'text-emerald-500',
            description: `USDT dominance fell ${changeStr} — capital rotating out of stablecoins into crypto.`,
        };
    }

    if (dominance.direction === 'risk_off') {
        return {
            label: 'Risk-off',
            color: 'text-red-500',
            description: `USDT dominance rose ${changeStr} — capital rotating into stablecoin safety.`,
        };
    }

    return {
        label: 'Dominance flat',
        color: 'text-muted-foreground',
        description: `USDT dominance roughly flat (${changeStr}) — no macro bias.`,
    };
}

const SIGNAL_POLL_INTERVAL = 60_000;

export function useSignalPreviews(symbols: string[]): SignalPreviewMap {
    const [previews, setPreviews] = useState<SignalPreviewMap>({});
    const symbolsKey = symbols.slice().sort().join(',');

    useEffect(() => {
        if (!symbols.length) {
            return;
        }

        const fetchAll = () => {
            for (const symbol of symbols) {
                setPreviews((prev) => ({
                    ...prev,
                    [symbol]: prev[symbol] ?? 'loading',
                }));

                fetch(
                    `${signalPreviewRoute.url()}?symbol=${encodeURIComponent(symbol)}`,
                    {
                        headers: { Accept: 'application/json' },
                    },
                )
                    .then((r) => r.json())
                    .then((json) => {
                        if (json.success) {
                            setPreviews((prev) => ({
                                ...prev,
                                [symbol]: json.data,
                            }));
                        } else {
                            setPreviews((prev) => ({
                                ...prev,
                                [symbol]: 'error',
                            }));
                        }
                    })
                    .catch(() =>
                        setPreviews((prev) => ({ ...prev, [symbol]: 'error' })),
                    );
            }
        };

        fetchAll();
        const id = setInterval(fetchAll, SIGNAL_POLL_INTERVAL);

        return () => clearInterval(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [symbolsKey]);

    return previews;
}
