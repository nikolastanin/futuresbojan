import { useEquityMemory } from '@/hooks/use-equity-memory';

/**
 * Renders nothing — it only keeps price-equity memory recording for a hedged coin.
 * The Analysis panel shows one coin at a time, so without this a hedged coin that
 * isn't currently selected would stop accumulating history and leave gaps for the
 * next time price revisits a level.
 */
export function EquityMemoryRecorder({
    symbol,
    price,
    totalEquity,
}: {
    symbol: string;
    price: number;
    totalEquity: number;
}) {
    useEquityMemory(symbol, price > 0 ? price : null, totalEquity);

    return null;
}
