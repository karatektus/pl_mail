import type { CDPSession, Page } from "@playwright/test";

/**
 * One finger on a touch screen, driven through the DevTools protocol.
 *
 * `page.mouse` cannot stand in for it. A mouse drag fires mouse events and never
 * touch events, and the gesture code reads touch events, so a mouse test would
 * pass for a gesture that was never exercised. And a synthetic `new TouchEvent`
 * dispatched from the page skips what the browser itself decides about a touch:
 * whether touch-action lets it pan, whether a touchmove is cancelable, whether
 * a native scroll starts. So the touch goes through `Input.dispatchTouchEvent`,
 * as a finger would, and the browser decides the rest.
 *
 * Chromium only, as the suite is. The page needs a touch-capable context
 * (`hasTouch`, which the Pixel 5 device sets).
 *
 * The finger stays down between calls, so a test can look at the screen while
 * the touch is still in progress, which is where a pull shows its release state.
 */
export class Finger {
    private x: number;
    private y: number;

    private constructor(
        private readonly cdp: CDPSession,
        x: number,
        y: number,
    ) {
        this.x = x;
        this.y = y;
    }

    /** Put a finger down at (x, y). */
    static async down(page: Page, x: number, y: number): Promise<Finger> {
        const cdp = await page.context().newCDPSession(page);

        await cdp.send("Input.dispatchTouchEvent", { type: "touchStart", touchPoints: [{ x, y }] });

        return new Finger(cdp, x, y);
    }

    /**
     * Move by (dx, dy) from where the finger is, in `steps` touchmove events of
     * equal size. The steps are real events, so a test can count them.
     */
    async move(dx: number, dy: number, steps = 6): Promise<void> {
        for (let i = 1; i <= steps; i++) {
            await this.cdp.send("Input.dispatchTouchEvent", {
                type: "touchMove",
                touchPoints: [{ x: this.x + (dx * i) / steps, y: this.y + (dy * i) / steps }],
            });
        }

        this.x += dx;
        this.y += dy;
    }

    /**
     * Put a second finger down at (x, y), beside the first, which stays where it is.
     * One touchstart carries every point that is down, as the browser sends it.
     */
    async addFinger(x: number, y: number): Promise<void> {
        await this.cdp.send("Input.dispatchTouchEvent", {
            type: "touchStart",
            touchPoints: [
                { x: this.x, y: this.y },
                { x, y },
            ],
        });
    }

    /** Lift the finger, and detach the session. */
    async up(): Promise<void> {
        await this.cdp.send("Input.dispatchTouchEvent", { type: "touchEnd", touchPoints: [] });
        await this.cdp.detach();
    }

    /**
     * The system takes the touch: a call, a notification, a gesture of its own. The
     * browser sends touchcancel, never touchend, so the page must not treat it as a
     * finger that lifted. CDP's touchCancel is the browser's own event for this.
     */
    async cancel(): Promise<void> {
        await this.cdp.send("Input.dispatchTouchEvent", { type: "touchCancel", touchPoints: [] });
        await this.cdp.detach();
    }
}

/**
 * Record whether each touchmove on the page is cancelable.
 *
 * The listener is passive, so it observes the events without changing what the
 * browser does with them: a blocking recorder would make the whole sequence
 * cancelable and hide the very thing being measured.
 */
export async function recordTouchMoves(page: Page): Promise<void> {
    await page.evaluate(() => {
        const win = window as unknown as { __touchMoves: boolean[] };

        win.__touchMoves = [];
        document.addEventListener("touchmove", (event) => win.__touchMoves.push(event.cancelable), { passive: true });
    });
}

/** The `cancelable` flag of every touchmove recorded since recordTouchMoves(). */
export async function touchMoveCancelable(page: Page): Promise<boolean[]> {
    return page.evaluate(() => (window as unknown as { __touchMoves: boolean[] }).__touchMoves);
}
