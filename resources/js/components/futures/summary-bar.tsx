import type { AccountAsset, Position } from '@/types/futures';

interface Props {
    account: AccountAsset[];
    positions: Position[];
}

export function SummaryBar({ account, positions }: Props) {
    const totalPnl   = positions.reduce((sum, p) => sum + (p.unrealizedPnl ?? 0), 0);
    // Longs add, shorts subtract
    const totalValue = positions.reduce((sum, p) => {
        const val = p.positionValue ?? 0;
        return sum + (p.positionType === 1 ? val : -val);
    }, 0);
    const equity     = account.find(a => a.currency === 'USDT')?.equity ?? 0;

    const fmt = (n: number) =>
        new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    return (
        <div className="grid grid-cols-3 gap-4">
            <StatCard
                label="Total Positions"
                value={`${totalValue >= 0 ? '+' : ''}${fmt(totalValue)}`}
                unit="USDT"
                positive={totalValue > 0}
                negative={totalValue < 0}
            />
            <StatCard
                label="Unrealized PNL"
                value={`${totalPnl >= 0 ? '+' : ''}${fmt(totalPnl)}`}
                unit="USDT"
                positive={totalPnl > 0}
                negative={totalPnl < 0}
            />
            <StatCard label="Total Equity" value={fmt(equity)} unit="USDT" />
        </div>
    );
}

function StatCard({
    label,
    value,
    unit,
    positive,
    negative,
}: {
    label: string;
    value: string;
    unit: string;
    positive?: boolean;
    negative?: boolean;
}) {
    const valueColor = positive
        ? 'text-emerald-500'
        : negative
        ? 'text-red-500'
        : 'text-foreground';

    return (
        <div className="rounded-xl border border-border bg-card px-5 py-4">
            <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">{label}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${valueColor}`}>
                {value}
                <span className="ml-1 text-sm font-normal text-muted-foreground">{unit}</span>
            </p>
        </div>
    );
}
