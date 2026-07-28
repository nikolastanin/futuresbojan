export interface AccountAsset {
    currency: string;
    positionMargin: number;
    frozenBalance: number;
    availableBalance: number;
    cashBalance: number;
    equity: number;
    unrealized: number;
    bonus: number;
}

// ATR-based SL/TP suggestion for a manually-opened position — purely informational,
// never applied to any order automatically. See FuturesController::predictSlTp().
export interface SlTpPrediction {
    stop_loss: number;
    take_profit: number;
    stop_loss_pct: number;
    take_profit_pct: number;
}

// Currently-armed trigger orders on MEXC for a position, read back from
// planorder/list/orders — distinct from SlTpPrediction, which is only a suggestion.
export interface ActiveSlTp {
    stop_loss: number | null;
    take_profit: number | null;
}

export interface Position {
    positionId: number;
    symbol: string;             // e.g. "BTC_USDT"
    positionType: 1 | 2;        // 1=long, 2=short
    openType: 1 | 2;            // 1=isolated, 2=cross
    state: number;
    frozenVol: number;
    closeVol: number;
    holdAvgPrice: number;
    openAvgPrice: number;
    closeAvgPrice: number;
    liquidatePrice: number;
    oim: number;
    im: number;
    holdFee: number;
    realised: number;
    leverage: number;
    createTime: number;
    updateTime: number;
    autoAddIm: boolean;
    holdVol: number;            // position size in contracts
    unrealizedPnl: number;
    positionValue: number;      // USDT notional value
    version: number;
    profitRatio: number;
    newOpenAvgPrice: number;
    newHoldAvgPrice: number;
    adlLevel: number | null;
    adlSortValue: number | null;
    fairPrice: number;
    sl_tp_prediction: SlTpPrediction | null;
    active_sl_tp: ActiveSlTp | null;
}

// A simulated manual order — never touches MEXC, separate from bot paper trades.
export interface PaperPosition {
    id: number;
    symbol: string;
    direction: 'LONG' | 'SHORT';
    margin_usdt: number;
    leverage: number;
    entry_price: number;
    current_price: number | null;
    unrealized_pnl: number | null;
    stop_loss: number | null;
    take_profit: number | null;
    sl_tp_prediction: SlTpPrediction | null;
    opened_at: string;
}

// A one-off request to prefill the order form (e.g. from the Liquidity Hunt panel's
// "Long"/"Short" button) — nonce ensures the effect fires even if the same button is
// clicked twice in a row with identical resulting values.
export interface OrderPrefillRequest {
    nonce: number;
    symbol: string;
    side: 1 | 3;
    price: number;
}

// Order form row
export interface OrderRow {
    id: string;
    symbol: string;
    price: string;
    vol: string;
    leverage: number;
    side: 1 | 3;    // 1=open long, 3=open short
    type: 1 | 5;    // 1=limit, 5=market
    openType: 1 | 2;
}

export function symbolLabel(sym: string): string {
    return sym.replace('_', '/');
}

export function coinLabel(sym: string): string {
    return sym.split('_')[0];
}
