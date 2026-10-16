/**
 * The numbers at the top of the dashboard, worked out in one place so the three cards and the slim
 * bar that floats over the page once the cards have scrolled away always agree. Pure functions with
 * no imports, so they are unit-tested straight from Node (`npm run test:js`).
 */

/** Equity targets, shown as a progress bar under the equity. */
export const MILESTONES = [250, 500, 1000, 1500];

const FINAL_MILESTONE = MILESTONES[MILESTONES.length - 1];

interface AssetLike {
    currency: string;
    equity: number;
}

interface LegLike {
    /** 1 for a long, 2 for a short. */
    positionType: number;
    positionValue?: number | null;
    unrealizedPnl?: number | null;
}

export interface Totals {
    /** Total equity in USDT; 0 when the account has no USDT row. */
    equity: number;
    /** Long value minus short value, which is what "Total Positions" shows. */
    netValue: number;
    longValue: number;
    shortValue: number;
    longCount: number;
    shortCount: number;
    /** Unrealized profit and loss of every open leg. */
    unrealized: number;
    /** The first milestone above the equity (the last one once they are all reached). */
    nextMilestone: number;
    allMilestonesReached: boolean;
    /** Equity as a share of the last milestone, 0 to 100. */
    milestoneProgress: number;
}

export function totalsOf(account: AssetLike[], positions: LegLike[]): Totals {
    const longs = positions.filter((p) => p.positionType === 1);
    const shorts = positions.filter((p) => p.positionType === 2);
    const value = (legs: LegLike[]) =>
        legs.reduce((sum, p) => sum + (p.positionValue ?? 0), 0);
    const equity = account.find((a) => a.currency === 'USDT')?.equity ?? 0;

    return {
        equity,
        netValue: value(longs) - value(shorts),
        longValue: value(longs),
        shortValue: value(shorts),
        longCount: longs.length,
        shortCount: shorts.length,
        unrealized: positions.reduce(
            (sum, p) => sum + (p.unrealizedPnl ?? 0),
            0,
        ),
        nextMilestone: MILESTONES.find((m) => equity < m) ?? FINAL_MILESTONE,
        allMilestonesReached: equity >= FINAL_MILESTONE,
        milestoneProgress: Math.min((equity / FINAL_MILESTONE) * 100, 100),
    };
}

/** "1,234.50": two decimals and thousands separators. */
export const fmtUsdt = (n: number): string =>
    n.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

/** "+88.99" or "-88.99": the sign always shown, for a figure that is a gain or a loss. */
export const fmtSigned = (n: number): string =>
    `${n > 0 ? '+' : ''}${fmtUsdt(n)}`;
