import { Crown, Sparkles } from 'lucide-react';
import { coinLabel } from '@/types/futures';

export interface UltimateFavoritePick {
    symbol: string;
    direction: 'LONG' | 'SHORT';
    price: number;
    confidenceScore: number;
    scalpGrade: number | null;
    combinedScore: number;
    tierWinRate: number | null;
    tierNetProfitUsdt: number | null;
    tierSampleSize: number;
    isAiPick: boolean;
    aiReasoning: string | null;
}

interface Props {
    picks: UltimateFavoritePick[];
    onOpenOrder?: (pick: UltimateFavoritePick) => void;
}

const fmtPrice = (n: number) =>
    n >= 1
        ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
        : n.toLocaleString('en-US', { maximumFractionDigits: 6 });

/**
 * The single best-looking setup right now, blending the bot's own live confidence
 * score with the Scalp Scanner's independent technical grade and validating against
 * how well that confidence tier has actually performed recently. Refreshed
 * periodically by the bot loop (see UltimateFavoriteService), never computed live
 * from this component — an empty state here just means nothing stood out this cycle.
 */
export function UltimateFavorite({ picks, onOpenOrder }: Props) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border border-t-2 border-t-rose-500 bg-card p-4">
            <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                <Crown className="size-3.5 text-rose-500" />
                Ultimate Favorite
            </p>
            <p className="text-[11px] text-muted-foreground">
                Live confidence + Scalp Scanner grade, validated against that tier's
                recent real performance. Refreshed periodically, not on every visit.
            </p>

            {picks.length === 0 ? (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    Nothing stands out this cycle — check back after the next refresh.
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    {picks.map((p) => {
                        const isLong = p.direction === 'LONG';

                        return (
                            <li
                                key={p.symbol}
                                className="flex flex-col gap-1.5 rounded-md border border-rose-500/30 bg-rose-500/5 px-2.5 py-2"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="min-w-0 flex-1 text-sm font-semibold text-foreground">
                                        {coinLabel(p.symbol)}
                                    </span>
                                    <span
                                        className={`shrink-0 text-xs font-bold ${isLong ? 'text-emerald-500' : 'text-red-500'}`}
                                    >
                                        {p.direction}
                                    </span>
                                    <span
                                        className="shrink-0 rounded-full border border-rose-500/60 bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-bold text-rose-500"
                                        title="Combined score: live confidence blended with Scalp Scanner grade"
                                    >
                                        {p.combinedScore}/10
                                    </span>
                                    {p.isAiPick && (
                                        <span
                                            className="flex shrink-0 items-center gap-0.5 rounded-full bg-background px-1.5 py-0.5 text-[10px] font-bold text-foreground"
                                            title="Reviewed and selected by DeepSeek"
                                        >
                                            <Sparkles className="size-3" />
                                            AI
                                        </span>
                                    )}
                                </div>

                                <div className="text-[11px] text-muted-foreground">
                                    Confidence {p.confidenceScore}/10
                                    {p.scalpGrade !== null && <> · Scalp grade {p.scalpGrade}/10</>}
                                    {p.tierWinRate !== null ? (
                                        <>
                                            {' · Tier '}
                                            {p.tierWinRate}% win rate ({p.tierSampleSize} trades)
                                        </>
                                    ) : (
                                        ' · Not enough tier history yet'
                                    )}
                                </div>

                                {p.aiReasoning && (
                                    <p className="text-[11px] text-foreground/80 italic">
                                        "{p.aiReasoning}"
                                    </p>
                                )}

                                {onOpenOrder && (
                                    <button
                                        type="button"
                                        className={`h-7 w-fit rounded-md px-2.5 text-xs font-medium text-white ${
                                            isLong ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-red-600 hover:bg-red-500'
                                        }`}
                                        onClick={() => onOpenOrder(p)}
                                    >
                                        {isLong ? 'Long' : 'Short'} @ ${fmtPrice(p.price)}
                                    </button>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
