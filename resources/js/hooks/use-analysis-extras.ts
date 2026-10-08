import { useEffect, useState } from 'react';
import { analysisExtras as analysisExtrasRoute } from '@/routes/futures';

// The Analysis panel's deeper read for one coin: a multi-timeframe grid,
// higher-timeframe and volume-profile levels, and strength vs BTC. Fetched only
// for the coin on screen (not per position row), at the same 60s cadence as the
// signal preview.

export interface MtfRow {
    tf: string;
    trend: string;
    rsi: number | null;
    macd: 'bullish' | 'bearish' | 'neutral' | null;
    lean: 'up' | 'down' | 'mixed';
}

export interface ExtraLevels {
    weekly_pivot: number | null;
    prior_week_high: number | null;
    prior_week_low: number | null;
    monthly_pivot: number | null;
    prior_month_high: number | null;
    prior_month_low: number | null;
    poc: number | null;
    vah: number | null;
    val: number | null;
}

export interface StrengthWindow {
    coin: number | null;
    btc: number | null;
    diff: number | null;
}

export interface PlanConfirmation {
    name: string;
    state: 'confirmed' | 'waiting' | 'unknown';
    detail: string;
}

export interface PlanZone {
    id: string;
    side: 'long' | 'short';
    number: number;
    strength: 'strong' | 'solid' | 'weak';
    low: number;
    high: number;
    sources: { label: string; price: number }[];
    distance_pct: number;
    status: 'in_zone' | 'near' | 'far';
    confirmations: PlanConfirmation[];
    confirmed: number;
    invalidation: number;
    stop: number;
    stop_distance_atr: number | null;
    stop_distance_pct: number | null;
    targets: { price: number; label: string }[];
    rr: number | null;
    warnings: string[];
}

/** Zones to watch on each side of price, computed server-side from the levels. */
export interface TradePlan {
    price: number;
    atr_1h: number | null;
    atr_pct: number | null;
    supertrend_15m: 'bullish' | 'bearish' | null;
    /** One or two plain sentences on where the plan stands, built from the zones themselves. */
    summary: string;
    zones: PlanZone[];
}

export interface CandleFlag {
    key: string;
    bias: 'bullish' | 'bearish' | 'neutral';
    /** A statement about one closed candle, in plain words (built and tested on the server). */
    label: string;
}

export interface CandleRow {
    /** Open time, unix seconds. */
    time: number;
    closed: boolean;
    /** 0 = the last closed candle, 1 = the one before it…; null for the candle still forming. */
    ago: number | null;
    open: number;
    high: number;
    low: number;
    close: number;
    direction: 'up' | 'down' | 'flat';
    /** The candle's range as a multiple of the ATR before it. */
    range_atr: number;
    /** Shares of the candle's own range, in percent. */
    body_pct: number;
    upper_wick_pct: number;
    lower_wick_pct: number;
    /** Volume against its recent average; null while forming. */
    volume_ratio: number | null;
    /** Only closed candles are ever flagged. */
    flags: CandleFlag[];
}

export interface CandleSequence {
    closed: number;
    up: number;
    down: number;
    net_change_pct: number;
    structure: 'higher_highs_higher_lows' | 'lower_highs_lower_lows' | 'mixed';
    range_trend: 'compressing' | 'expanding' | 'steady';
    volume_trend: 'rising' | 'falling' | 'steady';
    close_in_range_pct: number;
    /** One plain-English sentence on the whole run of candles. */
    summary: string;
}

/** The latest candles of one timeframe, measured and labelled on the server. */
export interface CandleTape {
    tf: string;
    atr: number;
    atr_pct: number | null;
    /** The candle that has not closed yet — context only. */
    forming: CandleRow | null;
    /** Closed candles, newest first. */
    candles: CandleRow[];
    sequence: CandleSequence;
}

export type WaveTrendZone =
    | 'deep_overbought'
    | 'overbought'
    | 'neutral'
    | 'oversold'
    | 'deep_oversold';

/** A candle on which WT1 ended up on the other side of WT2 (the dots on the TradingView pane). */
export interface WaveTrendCross {
    direction: 'up' | 'down';
    /** Open time of that candle, unix seconds. */
    time: number;
    /** 0 = the last closed candle, 1 = the one before it… */
    ago: number;
    /** WT2 on that candle, where the dot sits. */
    level: number;
    zone: WaveTrendZone;
}

/** Where the WaveTrend oscillator stands on one timeframe, measured on the server. */
export interface WaveTrendRead {
    tf: string;
    wt1: number;
    wt2: number;
    /** WT1 minus WT2 right now. */
    gap: number;
    zone: WaveTrendZone;
    /** How far a neutral reading still is from the first line on its side; null once past it. */
    to_line: {
        line: 'oversold' | 'overbought';
        level: number;
        distance: number;
    } | null;
    /** True while the newest candle is still open — the numbers above include it. */
    forming: boolean;
    /** Unix seconds the open candle closes; null when none is open. */
    closes_at: number | null;
    /** Which line was on top at the last CLOSED candle. */
    side: 'above' | 'below';
    /** A cross on the open candle: not confirmed until it closes, and it can flip back. */
    forming_cross: 'up' | 'down' | null;
    /** Crosses on closed candles, newest first. */
    crosses: WaveTrendCross[];
}

export interface AnalysisExtras {
    symbol: string;
    mtf: MtfRow[];
    levels: ExtraLevels;
    /** Null for BTC itself. */
    vs_btc: Record<string, StrengthWindow> | null;
    plan: TradePlan;
    /** By timeframe (15M, 1H, 4H); null for one with too few closed candles. */
    candles: Record<string, CandleTape | null>;
    /** By timeframe (15M, 1H, 4H); null for one with too few candles for settled values. */
    wavetrend: Record<string, WaveTrendRead | null>;
}

const POLL_INTERVAL = 60_000;

export function useAnalysisExtras(
    symbol: string,
    enabled = true,
): AnalysisExtras | 'loading' | 'error' {
    const [bySymbol, setBySymbol] = useState<
        Record<string, AnalysisExtras | 'error'>
    >({});

    useEffect(() => {
        // Skipped while the panel is minimised — no point polling what isn't shown.
        if (!enabled) {
            return;
        }

        const load = () => {
            fetch(
                `${analysisExtrasRoute.url()}?symbol=${encodeURIComponent(symbol)}`,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => r.json())
                .then((json) =>
                    setBySymbol((prev) => ({
                        ...prev,
                        [symbol]: json.success ? json.data : 'error',
                    })),
                )
                .catch(() =>
                    setBySymbol((prev) => ({ ...prev, [symbol]: 'error' })),
                );
        };

        load();
        const id = setInterval(load, POLL_INTERVAL);

        return () => clearInterval(id);
    }, [symbol, enabled]);

    // Keyed by symbol so switching coins shows "loading" instead of the previous
    // coin's grid for a moment.
    return bySymbol[symbol] ?? 'loading';
}
