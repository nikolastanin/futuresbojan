import type {
    IPrimitivePaneRenderer,
    IPrimitivePaneView,
    ISeriesPrimitive,
    PrimitiveHoveredItem,
    SeriesAttachedParameter,
    Time,
    UTCTimestamp,
} from 'lightweight-charts';

/** One dot: the candle it sits on (its open time, unix seconds), the price it filled at, and whether it was a buy. */
export interface Dot {
    time: number;
    price: number;
    buy: boolean;
}

/** Where a dot is on the chart, in pixels from the top left of the price pane. */
export interface DotSpot {
    x: number;
    y: number;
}

export interface DotColors {
    buy: string;
    sell: string;
    ring: string;
}

const RADIUS = 4.5;
const HOVER_RADIUS = 6.5;
/** How far outside a dot the pointer still counts as on it: a small dot is hard to hit exactly. */
const REACH = 3;

/**
 * Your completed orders as dots on the candles. The chart library's own markers cannot be ringed or
 * sized apart from the zoom, and a dot the colour of the candle behind it is lost, so these are
 * drawn here: a filled circle with a ring round it, the same size at any zoom, a little larger
 * under the pointer. The library asks `hitTest` which dot the pointer is on and tells the
 * crosshair handler (`hoveredInfo.objectId` is the dot's number), which is what the popup hangs
 * on. Attached to the candle series with `attachPrimitive`.
 */
export class TradeDots implements ISeriesPrimitive<Time> {
    private attachedTo: SeriesAttachedParameter<Time> | null = null;
    private dots: Dot[] = [];
    private spots: (DotSpot | null)[] = [];
    private hovered: number | null = null;
    private colors: DotColors = {
        buy: '#34d399',
        sell: '#fb7185',
        ring: '#fafafa',
    };

    private readonly renderer: IPrimitivePaneRenderer = {
        draw: (target) => {
            target.useMediaCoordinateSpace(({ context }) => {
                // The dot under the pointer goes last, so it is on top of any it overlaps.
                const order = this.dots
                    .map((_, i) => i)
                    .sort(
                        (a, b) =>
                            Number(a === this.hovered) -
                            Number(b === this.hovered),
                    );

                for (const i of order) {
                    const spot = this.spots[i];

                    if (!spot) {
                        continue;
                    }

                    const hot = i === this.hovered;

                    context.beginPath();
                    context.arc(
                        spot.x,
                        spot.y,
                        hot ? HOVER_RADIUS : RADIUS,
                        0,
                        Math.PI * 2,
                    );
                    context.fillStyle = this.dots[i].buy
                        ? this.colors.buy
                        : this.colors.sell;
                    context.fill();
                    context.lineWidth = hot ? 2 : 1.5;
                    context.strokeStyle = this.colors.ring;
                    context.stroke();
                }
            });
        },
    };

    private readonly views: readonly IPrimitivePaneView[] = [
        { zOrder: () => 'top', renderer: () => this.renderer },
    ];

    attached(param: SeriesAttachedParameter<Time>): void {
        this.attachedTo = param;
        this.measure();
    }

    detached(): void {
        this.attachedTo = null;
        this.spots = [];
    }

    paneViews(): readonly IPrimitivePaneView[] {
        return this.views;
    }

    /** The library calls this when the zoom, the scroll or the price scale changed: the dots have moved. */
    updateAllViews(): void {
        this.measure();
    }

    hitTest(x: number, y: number): PrimitiveHoveredItem | null {
        let best = -1;
        let bestDistance = Infinity;

        this.spots.forEach((spot, i) => {
            if (!spot) {
                return;
            }

            const distance = Math.hypot(spot.x - x, spot.y - y);

            if (distance <= RADIUS + REACH && distance <= bestDistance) {
                best = i;
                bestDistance = distance;
            }
        });

        return best === -1
            ? null
            : {
                  externalId: String(best),
                  zOrder: 'top',
                  cursorStyle: 'pointer',
                  distance: bestDistance,
                  hitTestPriority: 2,
                  itemType: 'marker',
              };
    }

    /** Replaces the dots; the numbers hitTest hands out are positions in this list. */
    setDots(dots: Dot[]): void {
        this.dots = dots;
        this.hovered = null;
        this.measure();
        this.attachedTo?.requestUpdate();
    }

    setColors(colors: DotColors): void {
        this.colors = colors;
        this.attachedTo?.requestUpdate();
    }

    /** Which dot is under the pointer, to draw it larger; null for none. */
    setHovered(index: number | null): void {
        if (index !== this.hovered) {
            this.hovered = index;
            this.attachedTo?.requestUpdate();
        }
    }

    /** Where a dot is right now, or null if it is off the chart. */
    spotOf(index: number): DotSpot | null {
        return this.spots[index] ?? null;
    }

    private measure(): void {
        const attached = this.attachedTo;

        if (!attached) {
            this.spots = [];

            return;
        }

        const timeScale = attached.chart.timeScale();

        this.spots = this.dots.map((dot) => {
            const x = timeScale.timeToCoordinate(dot.time as UTCTimestamp);
            const y = attached.series.priceToCoordinate(dot.price);

            return x === null || y === null ? null : { x, y };
        });
    }
}
