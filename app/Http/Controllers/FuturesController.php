<?php

namespace App\Http\Controllers;

use App\Services\MexcFuturesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FuturesController extends Controller
{
    public function __construct(private MexcFuturesService $mexc) {}

    public function index(): Response
    {
        try {
            $account   = $this->mexc->getAccountAssets();
            $positions = $this->mexc->getEnrichedPositions();
        } catch (\Throwable $e) {
            $account   = ['data' => []];
            $positions = [];
        }

        return Inertia::render('dashboard', [
            'account'   => $account['data'] ?? [],
            'positions' => $positions,
        ]);
    }

    public function account(): JsonResponse
    {
        try {
            $data = $this->mexc->getAccountAssets();
            return response()->json(['success' => true, 'data' => $data['data'] ?? []]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function tickers(): JsonResponse
    {
        try {
            $data = $this->mexc->getAllTickers();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function positions(): JsonResponse
    {
        try {
            $positions = $this->mexc->getEnrichedPositions();
            return response()->json(['success' => true, 'data' => $positions]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function placeOrders(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'orders'              => ['required', 'array', 'min:1'],
            'orders.*.symbol'     => ['required', 'string'],
            'orders.*.price'      => ['required', 'numeric', 'min:0'],
            'orders.*.marginUsdt' => ['required', 'numeric', 'min:0.01'],
            'orders.*.leverage'   => ['required', 'integer', 'min:1', 'max:200'],
            'orders.*.side'       => ['required', 'integer', 'in:1,2,3,4'],
            'orders.*.type'       => ['required', 'integer', 'in:1,2,3,4,5,6'],
            'orders.*.openType'   => ['required', 'integer', 'in:1,2'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        try {
            // Fetch tickers and contract sizes once for all unique symbols
            $symbols  = array_unique(array_column($validated['orders'], 'symbol'));
            $tickers  = $this->mexc->getTickerMap($symbols);
            $details  = $this->mexc->getContractSizeMap($symbols);

            $orders = [];
            foreach ($validated['orders'] as $row) {
                $sym          = $row['symbol'];
                $fairPrice    = $tickers[$sym]  ?? null;
                $contractSize = $details[$sym]  ?? null;

                if (! $fairPrice || ! $contractSize) {
                    throw new \RuntimeException("Could not fetch price/contract size for {$sym}");
                }

                // marginUsdt × leverage = notional USDT; convert to contracts
                $notional = $row['marginUsdt'] * $row['leverage'];
                $vol      = (int) floor($notional / ($fairPrice * $contractSize));

                if ($vol < 1) {
                    throw new \RuntimeException("Calculated volume for {$sym} is less than 1 contract. Increase USDT amount.");
                }

                $orders[] = [
                    'symbol'   => $sym,
                    'price'    => $row['price'],
                    'vol'      => $vol,
                    'leverage' => $row['leverage'],
                    'side'     => $row['side'],
                    'type'     => $row['type'],
                    'openType' => $row['openType'],
                ];
            }

            $result = count($orders) === 1
                ? $this->mexc->placeOrder($orders[0])
                : $this->mexc->placeBatchOrders($orders);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function closePosition(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol' => ['required', 'string'],
            'side'   => ['required', 'integer', 'in:2,4'],
            'vol'    => ['required', 'numeric', 'min:0.0001'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $validated = $validator->validated();

        try {
            $result = $this->mexc->closePosition(
                $validated['symbol'],
                (int) $validated['side'],
                (float) $validated['vol'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function flashClose(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol'       => ['required', 'string'],
            'holdVol'      => ['required', 'numeric', 'min:0.0001'],
            'positionType' => ['required', 'integer', 'in:1,2'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $validated = $validator->validated();

        // positionType 1=long → close side 4; positionType 2=short → close side 2
        $closeSide = $validated['positionType'] === 1 ? 4 : 2;

        try {
            $result = $this->mexc->closePosition(
                $validated['symbol'],
                $closeSide,
                (float) $validated['holdVol'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function debugHistory(): JsonResponse
    {
        try {
            $raw = $this->mexc->getRawHistory();
            return response()->json(['success' => true, 'data' => $raw]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function todayPnl(): JsonResponse
    {
        try {
            $data = $this->mexc->getTodayPnl();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function pnlCalendar(Request $request): Response
    {
        $year  = (int) ($request->query('year',  date('Y')));
        $month = (int) ($request->query('month', date('n')));

        try {
            $positions = $this->mexc->getMonthHistory($year, $month);
        } catch (\Throwable $e) {
            $positions = [];
        }

        // Aggregate realised PNL by calendar day (UTC date from updateTime ms)
        $dailyPnl = [];
        foreach ($positions as $pos) {
            $ts  = isset($pos['updateTime']) ? (int) $pos['updateTime'] : 0;
            $day = $ts > 0
                ? date('Y-m-d', (int) ($ts / 1000))
                : null;
            if (! $day) continue;
            $dailyPnl[$day] = round(($dailyPnl[$day] ?? 0) + (float) ($pos['realised'] ?? 0), 4);
        }

        $monthlyTotal = round(array_sum($dailyPnl), 4);
        $winDays      = count(array_filter($dailyPnl, fn($v) => $v > 0));
        $lossDays     = count(array_filter($dailyPnl, fn($v) => $v < 0));
        $bestDay      = ! empty($dailyPnl) ? max($dailyPnl) : 0;
        $worstDay     = ! empty($dailyPnl) ? min($dailyPnl) : 0;

        // Per-coin performance
        $coinMap = [];
        foreach ($positions as $pos) {
            $sym = $pos['symbol'] ?? 'UNKNOWN';
            $pnl = (float) ($pos['realised'] ?? 0);
            if (! isset($coinMap[$sym])) {
                $coinMap[$sym] = ['symbol' => $sym, 'pnl' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'best' => null, 'worst' => null];
            }
            $coinMap[$sym]['pnl']    = round($coinMap[$sym]['pnl'] + $pnl, 4);
            $coinMap[$sym]['trades']++;
            if ($pnl > 0) $coinMap[$sym]['wins']++;
            if ($pnl < 0) $coinMap[$sym]['losses']++;
            $coinMap[$sym]['best']  = $coinMap[$sym]['best'] === null  ? $pnl : max($coinMap[$sym]['best'],  $pnl);
            $coinMap[$sym]['worst'] = $coinMap[$sym]['worst'] === null ? $pnl : min($coinMap[$sym]['worst'], $pnl);
        }

        // Sort by absolute PNL descending
        usort($coinMap, fn($a, $b) => abs($b['pnl']) <=> abs($a['pnl']));

        return Inertia::render('pnl', [
            'year'         => $year,
            'month'        => $month,
            'dailyPnl'     => $dailyPnl,
            'monthlyTotal' => $monthlyTotal,
            'winDays'      => $winDays,
            'lossDays'     => $lossDays,
            'bestDay'      => $bestDay,
            'worstDay'     => $worstDay,
            'coinStats'    => array_values($coinMap),
        ]);
    }

    public function stopBreakEven(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol'       => ['required', 'string'],
            'positionType' => ['required', 'integer', 'in:1,2'],
            'vol'          => ['required', 'numeric', 'min:0.0001'],
            'triggerPrice' => ['required', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $v = $validator->validated();

        try {
            $result = $this->mexc->setStopAtBreakEven(
                $v['symbol'],
                (int)   $v['positionType'],
                (float) $v['vol'],
                (float) $v['triggerPrice'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function closeAll(): JsonResponse
    {
        try {
            $results = $this->mexc->closeAll();
            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
