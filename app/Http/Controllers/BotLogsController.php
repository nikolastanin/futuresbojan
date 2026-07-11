<?php

namespace App\Http\Controllers;

use App\Bot\Logging\BotHeartbeat;
use App\Models\BotLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only JSON feed of bot_logs for the "server log" viewer on the Bot settings
 * page — every category BotLogger writes (signal, order, trade_manager, risk,
 * ai_validation, system, ...), most recent first.
 */
class BotLogsController extends Controller
{
    /** Polled by the heartbeat badge on the Bot Settings/Stats pages. */
    public function heartbeat(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => BotHeartbeat::status()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = BotLog::query()->latest();

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $logs = $query->limit(150)->get(['id', 'level', 'category', 'symbol', 'message', 'context', 'created_at'])
            ->map(fn (BotLog $l) => [
                'id'         => $l->id,
                'level'      => $l->level,
                'category'   => $l->category,
                'symbol'     => $l->symbol,
                'message'    => $l->message,
                'context'    => $l->context,
                'created_at' => $l->created_at->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
