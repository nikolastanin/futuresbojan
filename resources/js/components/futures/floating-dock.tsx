import type { ReactNode } from 'react';

/**
 * A strip that sticks to the top of the screen, all the way across the page, while `show` is true.
 * It is solid, so nothing of the page shows beside or through it: the page simply scrolls up
 * underneath it, as under a header. It takes no room in the layout, so nothing shifts when it
 * appears or goes. Put it straight inside the page's content area, before the page's own
 * container: inside a column with a gap it would add a gap of its own. What goes in the strip
 * brings its own padding.
 */
export function FloatingDock({
    show,
    children,
}: {
    show: boolean;
    children: ReactNode;
}) {
    return (
        <div className="pointer-events-none sticky top-0 z-30 h-0">
            {show && (
                <div className="pointer-events-auto absolute inset-x-0 top-0 border-b border-border bg-card shadow-md">
                    {children}
                </div>
            )}
        </div>
    );
}
