import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Something that floats at the top of the screen, over the page, while `show` is true. It takes no
 * room in the layout, so nothing shifts when it appears or goes, and it holds its place at the top
 * as the page scrolls. Put it straight inside the page's content area, before the page's own
 * container: inside a column with a gap it would add a gap of its own. `className` places the
 * floating box across the width (`left-4`, `right-4`).
 */
export function FloatingDock({
    show,
    className,
    children,
}: {
    show: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className="pointer-events-none sticky top-0 z-30 h-0">
            {show && (
                <div
                    className={cn(
                        'pointer-events-auto absolute top-2',
                        className,
                    )}
                >
                    {children}
                </div>
            )}
        </div>
    );
}
