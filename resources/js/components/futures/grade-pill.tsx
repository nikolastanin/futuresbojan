import { Link } from '@inertiajs/react';
import { LETTER_STYLE } from '@/components/futures/daily-grade-card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { todayUtc, useDailyGrade } from '@/hooks/use-daily-grade';
import { tradingHistory } from '@/routes';

const REFRESH_MS = 5 * 60_000;

/**
 * Today's grade at a glance in the dashboard header, linking to the full breakdown on
 * the Trading History page. Quiet when there is nothing to grade yet or the grade can't
 * be loaded — it is a convenience, never something that should get in the way.
 */
export function GradePill() {
    const { grade } = useDailyGrade(todayUtc(), REFRESH_MS);

    if (!grade || grade.score === null || grade.letter === null) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href={tradingHistory()}
                    className={`flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-semibold transition-opacity hover:opacity-80 ${LETTER_STYLE[grade.letter]}`}
                >
                    <span className="text-[10px] font-normal text-muted-foreground">
                        Today
                    </span>
                    {grade.letter}
                    <span className="font-normal tabular-nums">
                        {grade.score}
                    </span>
                </Link>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-[11px]">
                Today’s trader grade
                {grade.partial ? ' (partial — some parts have no data yet)' : ''}
                . Click for the breakdown.
            </TooltipContent>
        </Tooltip>
    );
}
