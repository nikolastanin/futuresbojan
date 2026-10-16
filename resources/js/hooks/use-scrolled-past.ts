import { useEffect, useState } from 'react';

/**
 * Whether an element has scrolled up out of sight. Hand the returned function to the element's
 * `ref`. An element that is still below the bottom of the screen has not been scrolled past, and
 * neither has one that is only partly out of view.
 */
export function useScrolledPast<T extends Element>(): [
    (element: T | null) => void,
    boolean,
] {
    const [element, setElement] = useState<T | null>(null);
    const [past, setPast] = useState(false);

    useEffect(() => {
        if (!element) {
            return;
        }

        const observer = new IntersectionObserver(([entry]) => {
            setPast(
                !entry.isIntersecting &&
                    entry.boundingClientRect.bottom <
                        (entry.rootBounds?.top ?? 0),
            );
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, [element]);

    return [setElement, past];
}
