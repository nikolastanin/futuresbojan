import { useCallback, useState } from 'react';
import type { SignalPreview } from '@/hooks/use-signal-previews';
import { ageLabel, staleReason, STALE_TEXT } from '@/lib/brief';
import type { BriefLeg, StaleReason } from '@/lib/brief';
import {
    breakEvenPrice,
    equityZeroPrice,
    exposureBySymbol,
    legsFromPositions,
    liquidationInfo,
} from '@/lib/risk-math';
import type { Radar } from '@/lib/risk-math';
import { aiBrief as aiBriefRoute } from '@/routes/futures';
import type { Position } from '@/types/futures';

// The assistant's comment beside each open position. On click only: nothing is sent until
// the button is pressed. A comment is kept (per coin and language, and across a reload)
// so the space isn't empty after the first one, and is dimmed and labelled once the picture
// it described has changed (see lib/brief.ts).

export type BriefLanguage = 'en' | 'sr';

/** What POST /futures/ai-brief returns. */
export interface BriefRead {
    language: BriefLanguage;
    long_note: string;
    short_note: string;
    /** A price from the server's list that the comments hinge on; null when there isn't one. */
    watch_price: number | null;
    watch_label: string | null;
    watch_when: string;
    estimated_cost_usd: number;
}

/** A comment and the moment it was written for. */
export interface BriefEntry {
    read: BriefRead;
    at: number;
    price: number;
    legs: BriefLeg[];
}

type Pending = { status: 'loading' } | { status: 'error'; message: string };

/** What the slot beside one position shows. */
export type BriefView =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | {
          status: 'done';
          note: string;
          ageText: string;
          stale: StaleReason | null;
          staleText: string | null;
      };

const ENTRIES_KEY = 'position-briefs';
const LANGUAGE_KEY = 'assistant-language';
const MAX_ENTRIES = 24;

// Browser storage can be missing or throw (private windows, blocked site data), so
// remembering comments and the language is a convenience that must never break the list.
function readEntries(): Record<string, BriefEntry> {
    try {
        const parsed = JSON.parse(localStorage.getItem(ENTRIES_KEY) ?? '{}');

        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

function writeEntries(entries: Record<string, BriefEntry>): void {
    try {
        const newest = Object.entries(entries)
            .sort(([, a], [, b]) => b.at - a.at)
            .slice(0, MAX_ENTRIES);

        localStorage.setItem(
            ENTRIES_KEY,
            JSON.stringify(Object.fromEntries(newest)),
        );
    } catch {
        // ignore — the comment is still on screen for this session
    }
}

function readLanguage(): BriefLanguage {
    try {
        return localStorage.getItem(LANGUAGE_KEY) === 'sr' ? 'sr' : 'en';
    } catch {
        return 'en';
    }
}

const round2 = (n: number) => Math.round(n * 100) / 100;

const keyFor = (symbol: string, language: BriefLanguage) =>
    `${symbol}:${language}`;

const legsOf = (positions: Position[]): BriefLeg[] =>
    positions.map((p) => ({
        positionType: p.positionType,
        notional: p.positionValue,
    }));

const nowMs = () => Date.now();

/**
 * Everything the server needs from the page to write a comment for one coin: the open legs,
 * the indicator snapshot already on screen, and the live risk figures worked out here from
 * the positions (the server measures the market itself).
 */
function buildRequest(
    symbol: string,
    legs: Position[],
    signal: SignalPreview | 'loading' | 'error' | undefined,
    radar: Radar,
    language: BriefLanguage,
) {
    const exposure = exposureBySymbol(legsFromPositions(legs))[0];
    const atrPct = radar.coins.find((c) => c.symbol === symbol)?.atrPct ?? null;
    const nearest = exposure
        ? exposure.legs
              .map((leg) => liquidationInfo(leg, atrPct))
              .filter((l) => l !== null)
              .sort((a, b) => a.distancePct - b.distancePct)[0]
        : undefined;

    return {
        symbol,
        language,
        positions: legs.map((p) => ({
            direction: p.positionType === 1 ? 'LONG' : 'SHORT',
            notional: round2(p.positionValue),
            entry: p.openAvgPrice,
            pnl: round2(p.unrealizedPnl),
            leverage: p.leverage,
            liquidation_price: p.liquidatePrice,
            stop_loss: p.active_sl_tp?.stop_loss ?? null,
            take_profit: p.active_sl_tp?.take_profit ?? null,
            locked: p.locked,
            locked_until: p.lockedUntil,
        })),
        signal:
            signal && signal !== 'loading' && signal !== 'error'
                ? signal
                : null,
        risk: {
            radar_status: radar.status,
            equity: round2(radar.equity),
            typical_hour_pct:
                radar.hourlyRiskPct === null
                    ? null
                    : Math.round(radar.hourlyRiskPct),
            coin: exposure
                ? {
                      net_notional: round2(exposure.netNotional),
                      hedge_ratio: round2(exposure.hedgeRatio),
                      combined_pnl: round2(exposure.combinedPnl),
                      break_even:
                          exposure.longQty > 0 && exposure.shortQty > 0
                              ? breakEvenPrice(exposure)
                              : null,
                      equity_zero: equityZeroPrice(exposure, radar.equity),
                      liq: nearest
                          ? {
                                side: nearest.side,
                                price: nearest.price,
                                distance_pct: round2(nearest.distancePct),
                                distance_atr:
                                    nearest.distanceAtr === null
                                        ? null
                                        : round2(nearest.distanceAtr),
                            }
                          : null,
                  }
                : null,
        },
    };
}

export function usePositionBriefs() {
    const [language, setLanguage] = useState<BriefLanguage>(readLanguage);
    const [entries, setEntries] =
        useState<Record<string, BriefEntry>>(readEntries);
    const [pending, setPending] = useState<Record<string, Pending>>({});

    const changeLanguage = (next: BriefLanguage) => {
        setLanguage(next);

        try {
            localStorage.setItem(LANGUAGE_KEY, next);
        } catch {
            // ignore — the choice still holds for this session
        }
    };

    /** Ask the assistant about one coin, in the language chosen now. */
    const ask = useCallback(
        async (
            symbol: string,
            legs: Position[],
            signal: SignalPreview | 'loading' | 'error' | undefined,
            radar: Radar,
        ) => {
            const key = keyFor(symbol, language);

            setPending((prev) => ({ ...prev, [key]: { status: 'loading' } }));

            const settle = (next: Pending | null) =>
                setPending((prev) => {
                    const rest = { ...prev };

                    delete rest[key];

                    return next ? { ...rest, [key]: next } : rest;
                });

            const csrfToken =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';

            try {
                const res = await fetch(aiBriefRoute.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(
                        buildRequest(symbol, legs, signal, radar, language),
                    ),
                });
                const json = await res.json().catch(() => null);

                if (res.ok && json?.success) {
                    const entry: BriefEntry = {
                        read: json.data,
                        at: nowMs(),
                        price:
                            legs.find((l) => l.fairPrice > 0)?.fairPrice ?? 0,
                        legs: legsOf(legs),
                    };

                    setEntries((prev) => {
                        const next = { ...prev, [key]: entry };

                        writeEntries(next);

                        return next;
                    });
                    settle(null);
                } else {
                    settle({
                        status: 'error',
                        message:
                            json?.message ??
                            (res.status === 429
                                ? 'Too many questions — wait a minute.'
                                : 'The assistant is unavailable.'),
                    });
                }
            } catch {
                settle({ status: 'error', message: 'Network error.' });
            }
        },
        [language],
    );

    /** The entry for a coin in the language chosen now, if one has been written. */
    const entryFor = (symbol: string): BriefEntry | undefined =>
        entries[keyFor(symbol, language)];

    /** What to show beside one leg of a coin. */
    const viewFor = (
        symbol: string,
        positionType: 1 | 2,
        legs: Position[],
        atrPct: number | null,
    ): BriefView => {
        const key = keyFor(symbol, language);
        const wait = pending[key];

        if (wait) {
            return wait;
        }

        const entry = entries[key];

        if (!entry) {
            return { status: 'idle' };
        }

        const ageMs = nowMs() - entry.at;
        const stale = staleReason({
            ageMs,
            priceThen: entry.price,
            priceNow: legs.find((l) => l.fairPrice > 0)?.fairPrice ?? 0,
            atrPct,
            legsThen: entry.legs,
            legsNow: legsOf(legs),
        });

        return {
            status: 'done',
            note:
                positionType === 1
                    ? entry.read.long_note
                    : entry.read.short_note,
            ageText: ageLabel(ageMs),
            stale,
            staleText: stale ? STALE_TEXT[stale] : null,
        };
    };

    return { language, changeLanguage, ask, entryFor, viewFor };
}
