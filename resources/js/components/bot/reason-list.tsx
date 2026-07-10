import { CheckCircle2, MinusCircle, XCircle } from 'lucide-react';

type ReasonSentiment = 'long' | 'short' | 'neutral';

function classifyReason(reason: string): ReasonSentiment {
    if (reason.includes('LONG]')) {
        return 'long';
    }

    if (reason.includes('SHORT]')) {
        return 'short';
    }

    return 'neutral';
}

interface Props {
    reasons: string[];
    className?: string;
}

/** Renders SignalEngine's per-factor reasoning with a checkmark/x/dash per line,
 * based on whether that factor weighed LONG, SHORT, or contributed no direction. */
export function ReasonList({ reasons, className = '' }: Props) {
    return (
        <ul className={`flex flex-col gap-1.5 ${className}`}>
            {reasons.map((reason, i) => {
                const sentiment = classifyReason(reason);

                return (
                    <li key={i} className="flex items-start gap-2">
                        {sentiment === 'long' && (
                            <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-500" />
                        )}
                        {sentiment === 'short' && (
                            <XCircle className="mt-0.5 size-3.5 shrink-0 text-red-500" />
                        )}
                        {sentiment === 'neutral' && (
                            <MinusCircle className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                        )}
                        <span>{reason}</span>
                    </li>
                );
            })}
        </ul>
    );
}
