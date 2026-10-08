/**
 * When the assistant's comment beside a position has gone out of date.
 *
 * A comment is written for one moment: this price, these positions. Left on screen it would
 * quietly start describing a market that is gone, and a stale "let's wait for 305" is worse
 * than none. So each comment is dimmed and labelled when the picture has changed — the
 * positions were added to or closed, price has moved about an hourly range, or it is simply
 * old. Pure functions with no imports, unit-tested from Node (`npm run test:js`).
 */

export interface BriefLeg {
    /** 1 = long, 2 = short. */
    positionType: 1 | 2;
    notional: number;
}

/** A comment older than this is stale however little has moved. */
export const MAX_AGE_MS = 20 * 60_000;

/** Price moving this many hourly ranges since the comment was written makes it stale. */
export const MOVE_RANGES = 1;

/** A leg's notional changing by more than this fraction (an add or a reduce) makes it stale. */
export const NOTIONAL_CHANGE = 0.1;

export type StaleReason = 'positions' | 'price' | 'age';

/** Short words for the label beside a stale comment. */
export const STALE_TEXT: Record<StaleReason, string> = {
    positions: 'positions changed',
    price: 'price has moved',
    age: 'a while ago',
};

/**
 * Whether the set of legs is different from when the comment was written: one opened or
 * closed, or one grew or shrank by more than the tolerance. Price drift alone moves a leg's
 * notional by a percent or two, which is not a change.
 */
export function legsChanged(
    then: BriefLeg[],
    now: BriefLeg[],
    tolerance = NOTIONAL_CHANGE,
): boolean {
    if (then.length !== now.length) {
        return true;
    }

    return now.some((leg) => {
        const before = then.find((l) => l.positionType === leg.positionType);

        if (!before) {
            return true;
        }

        return before.notional > 0
            ? Math.abs(leg.notional - before.notional) / before.notional >
                  tolerance
            : leg.notional > 0;
    });
}

/**
 * Why a comment is stale, or null while it still holds. A change in the positions comes
 * first (it makes the comment about something else), then price, then plain age.
 * `atrPct` is the coin's 1H range as a percent of price; without it a 1% move counts.
 */
export function staleReason(input: {
    ageMs: number;
    priceThen: number;
    priceNow: number;
    atrPct: number | null;
    legsThen: BriefLeg[];
    legsNow: BriefLeg[];
}): StaleReason | null {
    if (legsChanged(input.legsThen, input.legsNow)) {
        return 'positions';
    }

    const range = (input.atrPct && input.atrPct > 0 ? input.atrPct : 1) / 100;

    if (
        input.priceThen > 0 &&
        Math.abs(input.priceNow - input.priceThen) / input.priceThen >=
            MOVE_RANGES * range
    ) {
        return 'price';
    }

    return input.ageMs >= MAX_AGE_MS ? 'age' : null;
}

/** "just now", "5 min ago", "2 h ago". */
export function ageLabel(ms: number): string {
    const minutes = Math.floor(Math.max(ms, 0) / 60_000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    return `${Math.floor(minutes / 60)} h ago`;
}
