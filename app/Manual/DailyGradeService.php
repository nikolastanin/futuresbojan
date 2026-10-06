<?php

namespace App\Manual;

use App\Models\TradeEvent;
use App\Services\MexcFuturesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Assembles a run of daily grades: one fetch of the closed trades from MEXC and one
 * query of the logged decisions for the whole range, bucketed by UTC day (the same
 * convention as the PnL calendar), then graded day by day by DailyGrader. Failing to
 * reach MEXC throws rather than grading on a partial picture — a grade built without
 * the day's trades would be actively misleading.
 */
class DailyGradeService
{
    public function __construct(
        private MexcFuturesService $mexc,
        private DailyGrader $grader,
    ) {}

    /**
     * Grades for the $days days ending on $endDate (inclusive), oldest first.
     *
     * @return array<string, array<string, mixed>> Keyed by Y-m-d.
     */
    public function range(string $endDate, int $days = 7): array
    {
        // Short cache: the page, the dashboard pill and the coach all ask for the same
        // range, and MEXC's history endpoint is the slow part. Actions taking effect
        // within a couple of minutes is fine for a daily grade.
        return Cache::remember("daily-grades:{$endDate}:{$days}", now()->addSeconds(90), fn () => $this->compute($endDate, $days));
    }

    /** @return array<string, array<string, mixed>> */
    private function compute(string $endDate, int $days): array
    {
        $end   = Carbon::parse($endDate, 'UTC')->endOfDay();
        $start = $end->copy()->subDays($days - 1)->startOfDay();

        $tradesByDay = [];

        foreach ($this->mexc->getClosedTrades($start, $end) as $trade) {
            $day = Carbon::createFromTimestampMsUTC($trade['closed_at'])->toDateString();
            $tradesByDay[$day][] = $trade;
        }

        $eventsByDay = [];

        // The decision log is optional input: if it cannot be read (for instance the
        // table has not been migrated yet), grade from the trades alone and let the
        // grade report itself as partial, rather than failing the whole page.
        try {
            foreach (TradeEvent::whereBetween('occurred_at', [$start, $end])->orderBy('occurred_at')->get() as $event) {
                $day = $event->occurred_at->copy()->utc()->toDateString();

                $eventsByDay[$day][] = [
                    'type'      => $event->type,
                    'symbol'    => $event->symbol,
                    'direction' => $event->direction,
                    'details'   => $event->details,
                    'context'   => $event->context,
                    'at'        => $event->occurred_at->getTimestamp(),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Could not read trade events for grading: {$e->getMessage()}");
        }

        $grades = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();

            $grades[$key] = $this->grader->grade($key, $tradesByDay[$key] ?? [], $eventsByDay[$key] ?? []);
        }

        return $grades;
    }

    /**
     * The compact per-day view used for the trend strip.
     *
     * @param  array<string, array<string, mixed>>  $grades
     * @return array<int, array{date: string, score: ?int, letter: ?string, partial: bool, trades: int, net_pnl: float}>
     */
    public function summarize(array $grades): array
    {
        return array_values(array_map(fn ($g) => [
            'date'    => $g['date'],
            'score'   => $g['score'],
            'letter'  => $g['letter'],
            'partial' => $g['partial'],
            'trades'  => $g['stats']['trades'],
            'net_pnl' => $g['stats']['net_pnl'],
        ], $grades));
    }
}
