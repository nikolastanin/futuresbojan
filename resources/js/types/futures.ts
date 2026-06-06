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

// Top 200 coins by market cap with USDT futures pairs
export const SYMBOLS: string[] = [
    // Top 10
    'BTC_USDT', 'ETH_USDT', 'BNB_USDT', 'SOL_USDT', 'XRP_USDT',
    'DOGE_USDT', 'ADA_USDT', 'AVAX_USDT', 'TRX_USDT', 'TON_USDT',
    // 11-30
    'SHIB_USDT', 'LINK_USDT', 'DOT_USDT', 'BCH_USDT', 'NEAR_USDT',
    'LTC_USDT', 'UNI_USDT', 'ICP_USDT', 'PEPE_USDT', 'APT_USDT',
    'SUI_USDT', 'STX_USDT', 'HBAR_USDT', 'MATIC_USDT', 'OP_USDT',
    'ARB_USDT', 'ATOM_USDT', 'IMX_USDT', 'INJ_USDT', 'RUNE_USDT',
    // 31-60
    'AAVE_USDT', 'FIL_USDT', 'MKR_USDT', 'VET_USDT', 'RNDR_USDT',
    'GRT_USDT', 'ALGO_USDT', 'THETA_USDT', 'EGLD_USDT', 'SAND_USDT',
    'MANA_USDT', 'XTZ_USDT', 'FLOW_USDT', 'AXS_USDT', 'FTM_USDT',
    'GALA_USDT', 'EOS_USDT', 'CHZ_USDT', 'CRV_USDT', 'SNX_USDT',
    'LDO_USDT', 'TIA_USDT', 'WIF_USDT', 'JUP_USDT', 'BONK_USDT',
    'SEI_USDT', 'STRK_USDT', 'PYTH_USDT', 'BLUR_USDT', 'DYDX_USDT',
    // 61-90
    'ORDI_USDT', 'MEME_USDT', 'TRB_USDT', 'PENDLE_USDT', 'GMX_USDT',
    'CAKE_USDT', 'ZEC_USDT', 'COMP_USDT', 'BAL_USDT', 'YFI_USDT',
    '1INCH_USDT', 'SUSHI_USDT', 'ENS_USDT', 'RPL_USDT', 'WLD_USDT',
    'CFX_USDT', 'FET_USDT', 'AGIX_USDT', 'XMR_USDT', 'ETC_USDT',
    'MAGIC_USDT', 'GNS_USDT', 'MASK_USDT', 'KNC_USDT', 'ZRX_USDT',
    'STORJ_USDT', 'BAND_USDT', 'ANT_USDT', 'OCEAN_USDT', 'CVX_USDT',
    // 91-120
    'LQTY_USDT', 'SSV_USDT', 'HOOK_USDT', 'HIGH_USDT', 'ACH_USDT',
    'LEVER_USDT', 'PERP_USDT', 'ROSE_USDT', 'KAVA_USDT', 'ONE_USDT',
    'ZIL_USDT', 'CELO_USDT', 'ICX_USDT', 'ONT_USDT', 'SKL_USDT',
    'CELR_USDT', 'DUSK_USDT', 'POLS_USDT', 'NKN_USDT', 'ALPHA_USDT',
    'RAY_USDT', 'SRM_USDT', 'ORCA_USDT', 'MNGO_USDT', 'STEP_USDT',
    'PORT_USDT', 'COPE_USDT', 'SAMO_USDT', 'SLIM_USDT', 'FIDA_USDT',
    // 121-150
    'AUDIO_USDT', 'GODS_USDT', 'ILV_USDT', 'GHST_USDT', 'QUICK_USDT',
    'IDEX_USDT', 'SUPER_USDT', 'ALICE_USDT', 'TLM_USDT', 'DEGO_USDT',
    'FORTH_USDT', 'RARE_USDT', 'CLV_USDT', 'FARM_USDT', 'ERN_USDT',
    'MIR_USDT', 'BICO_USDT', 'JASMY_USDT', 'GAL_USDT', 'APE_USDT',
    'GMT_USDT', 'GST_USDT', 'LOOKS_USDT', 'HERO_USDT', 'MC_USDT',
    'DAR_USDT', 'PEOPLE_USDT', 'LOKA_USDT', 'CITY_USDT', 'BETA_USDT',
    // 151-180
    'VOXEL_USDT', 'ARPA_USDT', 'POND_USDT', 'XVXVS_USDT', 'XVS_USDT',
    'ALPACA_USDT', 'FOR_USDT', 'WING_USDT', 'DODO_USDT', 'BEL_USDT',
    'FIO_USDT', 'COCOS_USDT', 'UNFI_USDT', 'LINA_USDT', 'GLMR_USDT',
    'MOVR_USDT', 'ACA_USDT', 'KSM_USDT', 'HNT_USDT', 'IOTX_USDT',
    'SC_USDT', 'DCR_USDT', 'DGB_USDT', 'ZEN_USDT', 'NANO_USDT',
    'WAVES_USDT', 'XEM_USDT', 'LSK_USDT', 'RVN_USDT', 'CKB_USDT',
    // 181-200
    'IOTA_USDT', 'QTUM_USDT', 'BAT_USDT', 'ANKR_USDT', 'HOT_USDT',
    'WAN_USDT', 'FUN_USDT', 'MTL_USDT', 'DENT_USDT', 'KEY_USDT',
    'SXP_USDT', 'OGN_USDT', 'LPT_USDT', 'NMR_USDT', 'TWT_USDT',
    'PUNDIX_USDT', 'BTTC_USDT', 'WIN_USDT', 'JST_USDT', 'NFT_USDT',
];

export function symbolLabel(sym: string): string {
    return sym.replace('_', '/');
}

export function coinLabel(sym: string): string {
    return sym.split('_')[0];
}
