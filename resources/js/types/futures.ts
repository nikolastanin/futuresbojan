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

// Top 500 coins by market cap with USDT futures pairs
export const SYMBOLS: string[] = [
    // Top 10
    'BTC_USDT', 'ETH_USDT', 'BNB_USDT', 'SOL_USDT', 'XRP_USDT',
    'DOGE_USDT', 'ADA_USDT', 'AVAX_USDT', 'TRX_USDT', 'TON_USDT', 'KAS_USDT',
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
    // 201-230
    'FLOKI_USDT', 'Brett_USDT', 'POPCAT_USDT', 'MEW_USDT', 'MOG_USDT',
    'TURBO_USDT', 'BABYDOGE_USDT', 'NEIRO_USDT', 'DOGS_USDT', 'HMSTR_USDT',
    'CATI_USDT', 'NOT_USDT', 'TAO_USDT', 'RENDER_USDT', 'IO_USDT',
    'ZK_USDT', 'ETHFI_USDT', 'ENA_USDT', 'REZ_USDT', 'OMNI_USDT',
    'W_USDT', 'PORTAL_USDT', 'MANTA_USDT', 'ALT_USDT', 'PIXEL_USDT',
    'DYM_USDT', 'MYRO_USDT', 'BOME_USDT', 'SLERF_USDT', 'WEN_USDT',
    // 231-260
    'ATH_USDT', 'ONDO_USDT', 'BEAM_USDT', 'MAVIA_USDT', 'RONIN_USDT',
    'SUPER_USDT', 'XAI_USDT', 'AGLD_USDT', 'RDNT_USDT', 'LOOM_USDT',
    'BIGTIME_USDT', 'VANRY_USDT', 'ID_USDT', 'EDU_USDT', 'METIS_USDT',
    'MINA_USDT', 'IOST_USDT', 'HIFI_USDT', 'LOOM_USDT', 'PROM_USDT',
    'CTXC_USDT', 'BLZ_USDT', 'VITE_USDT', 'REEF_USDT', 'NULS_USDT',
    'COS_USDT', 'PROS_USDT', 'IRIS_USDT', 'STMX_USDT', 'VIDT_USDT',
    // 261-290
    'TKO_USDT', 'HARD_USDT', 'BURGER_USDT', 'SBMP_USDT', 'CTC_USDT',
    'TRU_USDT', 'SPARTA_USDT', 'DF_USDT', 'XEND_USDT', 'AUCTION_USDT',
    'POSI_USDT', 'MBOX_USDT', 'CHESS_USDT', 'PEEL_USDT', 'FIRO_USDT',
    'TORN_USDT', 'TOMO_USDT', 'HXRO_USDT', 'NBS_USDT', 'DEGO_USDT',
    'CPOOL_USDT', 'ATA_USDT', 'OOKI_USDT', 'BOND_USDT', 'BAKE_USDT',
    'XEC_USDT', 'PAXG_USDT', 'ASTR_USDT', 'REI_USDT', 'OXT_USDT',
    // 291-320
    'POLS_USDT', 'FORTH_USDT', 'SWEAT_USDT', 'BSW_USDT', 'RAD_USDT',
    'CTK_USDT', 'AKRO_USDT', 'TRIBE_USDT', 'CVC_USDT', 'REQ_USDT',
    'BADGER_USDT', 'MDX_USDT', 'LAZIO_USDT', 'SANTOS_USDT', 'PORTO_USDT',
    'FRONT_USDT', 'UFT_USDT', 'KEEP_USDT', 'TVK_USDT', 'DUSK_USDT',
    'XVXVS_USDT', 'FIRO_USDT', 'ORN_USDT', 'GLMR_USDT', 'BIFI_USDT',
    'FORM_USDT', 'GYEN_USDT', 'POLYX_USDT', 'PHA_USDT', 'COMBO_USDT',
    // 321-350
    'DATA_USDT', 'GTC_USDT', 'IRIS_USDT', 'AUCTION_USDT', 'MITH_USDT',
    'PUNDIX_USDT', 'SXPOLD_USDT', 'DREP_USDT', 'STPT_USDT', 'TORN_USDT',
    'DEGO_USDT', 'QUICK_USDT', 'SUPER_USDT', 'XVS_USDT', 'BAKE_USDT',
    'BURGER_USDT', 'UFT_USDT', 'TKO_USDT', 'HARD_USDT', 'BSW_USDT',
    'CHESS_USDT', 'DAR_USDT', 'LAZIO_USDT', 'SANTOS_USDT', 'PORTO_USDT',
    'TWT_USDT', 'USTC_USDT', 'LUNC_USDT', 'BETH_USDT', 'BNX_USDT',
    // 351-380
    'HOOK_USDT', 'MAGIC_USDT', 'HFT_USDT', 'POLYX_USDT', 'GNS_USDT',
    'HIGH_USDT', 'PERP_USDT', 'RPL_USDT', 'CVX_USDT', 'LDO_USDT',
    'APE_USDT', 'GMT_USDT', 'LOOKS_USDT', 'AXL_USDT', 'KLAY_USDT',
    'BICO_USDT', 'GAL_USDT', 'REI_USDT', 'STARL_USDT', 'MC_USDT',
    'NEXO_USDT', 'MANA_USDT', 'SAND_USDT', 'AXS_USDT', 'GALA_USDT',
    'CHR_USDT', 'VELO_USDT', 'CTSI_USDT', 'POLS_USDT', 'AGLD_USDT',
    // 381-410
    'ARPA_USDT', 'BADGER_USDT', 'BAND_USDT', 'CELR_USDT', 'CKB_USDT',
    'CTXC_USDT', 'CVP_USDT', 'DOCK_USDT', 'DUSK_USDT', 'ELEC_USDT',
    'FOR_USDT', 'IOTX_USDT', 'KEY_USDT', 'LINA_USDT', 'LOOM_USDT',
    'MDT_USDT', 'NKN_USDT', 'OG_USDT', 'OM_USDT', 'ORN_USDT',
    'PAXG_USDT', 'PHA_USDT', 'PROS_USDT', 'QNT_USDT', 'RDNT_USDT',
    'REEF_USDT', 'REQ_USDT', 'RLC_USDT', 'ROSE_USDT', 'SFP_USDT',
    // 411-440
    'SKL_USDT', 'SPARTA_USDT', 'SPELL_USDT', 'STMX_USDT', 'STRAX_USDT',
    'SYS_USDT', 'TCT_USDT', 'TRU_USDT', 'TRVL_USDT', 'TVK_USDT',
    'UFT_USDT', 'UNFI_USDT', 'VIDT_USDT', 'VITE_USDT', 'VTHO_USDT',
    'WING_USDT', 'WOO_USDT', 'XEC_USDT', 'XNO_USDT', 'XPRT_USDT',
    'ZEN_USDT', 'ZIL_USDT', 'DODO_USDT', 'BEL_USDT', 'POSI_USDT',
    'SLP_USDT', 'POND_USDT', 'BLZ_USDT', 'CELO_USDT', 'COCOS_USDT',
    // 441-470
    'BOND_USDT', 'ALCX_USDT', 'FXS_USDT', 'OHM_USDT', 'TOKE_USDT',
    'SPA_USDT', 'TIME_USDT', 'BTRST_USDT', 'IDEX_USDT', 'OVR_USDT',
    'ATLAS_USDT', 'POLIS_USDT', 'GENE_USDT', 'CONV_USDT', 'RACA_USDT',
    'NAKA_USDT', 'DDX_USDT', 'BOBA_USDT', 'SAITAMA_USDT', 'ELON_USDT',
    'SHPING_USDT', 'MINU_USDT', 'TLOS_USDT', 'VOXEL_USDT', 'ORC_USDT',
    'WBTC_USDT', 'FRAX_USDT', 'LUSD_USDT', 'XAUT_USDT', 'ALUSD_USDT',
    // 471-500
    'ACM_USDT', 'BAR_USDT', 'INTER_USDT', 'JUV_USDT', 'OG_USDT',
    'PSG_USDT', 'ALPINE_USDT', 'AMB_USDT', 'BETA_USDT', 'BIFI_USDT',
    'BTT_USDT', 'CHESS_USDT', 'COMBO_USDT', 'DF_USDT', 'DODO_USDT',
    'EGLD_USDT', 'EPS_USDT', 'FIRO_USDT', 'FORM_USDT', 'FRONT_USDT',
    'GTC_USDT', 'HAPI_USDT', 'HXRO_USDT', 'JASMY_USDT', 'KDA_USDT',
    'KLAY_USDT', 'MDX_USDT', 'MOB_USDT', 'MOVR_USDT', 'MXC_USDT',
];

export function symbolLabel(sym: string): string {
    return sym.replace('_', '/');
}

export function coinLabel(sym: string): string {
    return sym.split('_')[0];
}
