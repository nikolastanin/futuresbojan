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

// Top ~100 coins by market cap with USDT futures pairs
export const SYMBOLS = [
    'BTC_USDT', 'ETH_USDT', 'BNB_USDT', 'SOL_USDT', 'XRP_USDT',
    'DOGE_USDT', 'ADA_USDT', 'AVAX_USDT', 'TRX_USDT', 'LINK_USDT',
    'TON_USDT', 'SHIB_USDT', 'DOT_USDT', 'BCH_USDT', 'NEAR_USDT',
    'LTC_USDT', 'UNI_USDT', 'MATIC_USDT', 'ICP_USDT', 'PEPE_USDT',
    'APT_USDT', 'XMR_USDT', 'ETC_USDT', 'FIL_USDT', 'STX_USDT',
    'OP_USDT', 'ARB_USDT', 'ATOM_USDT', 'IMX_USDT', 'HBAR_USDT',
    'VET_USDT', 'MKR_USDT', 'RNDR_USDT', 'INJ_USDT', 'GRT_USDT',
    'AAVE_USDT', 'ALGO_USDT', 'THETA_USDT', 'EGLD_USDT', 'SAND_USDT',
    'MANA_USDT', 'XTZ_USDT', 'FLOW_USDT', 'AXS_USDT', 'FTM_USDT',
    'GALA_USDT', 'EOS_USDT', 'CHZ_USDT', 'CRV_USDT', 'SNX_USDT',
    'LDO_USDT', 'SUI_USDT', 'TIA_USDT', 'WIF_USDT', 'JUP_USDT',
    'BONK_USDT', 'SEI_USDT', 'STRK_USDT', 'PYTH_USDT', 'BLUR_USDT',
    'DYDX_USDT', 'ORDI_USDT', 'SATS_USDT', 'MEME_USDT', 'TRB_USDT',
    'RUNE_USDT', 'PENDLE_USDT', 'GMX_USDT', 'CAKE_USDT', 'ZEC_USDT',
    'COMP_USDT', 'BAL_USDT', 'YFI_USDT', '1INCH_USDT', 'SUSHI_USDT',
    'ENS_USDT', 'RPL_USDT', 'CVX_USDT', 'SSV_USDT', 'OCEAN_USDT',
    'FET_USDT', 'AGIX_USDT', 'RNDR_USDT', 'WLD_USDT', 'CFX_USDT',
    'MAGIC_USDT', 'LQTY_USDT', 'GMX_USDT', 'GNS_USDT', 'PERP_USDT',
    'HOOK_USDT', 'HIGH_USDT', 'LEVER_USDT', 'ACH_USDT', 'MASK_USDT',
    'ANT_USDT', 'BAND_USDT', 'KNC_USDT', 'ZRX_USDT', 'STORJ_USDT',
] as const;
export type Symbol = (typeof SYMBOLS)[number];

export function symbolLabel(sym: string): string {
    return sym.replace('_', '/');
}

export function coinLabel(sym: string): string {
    return sym.split('_')[0];
}
