import type { Page } from "@playwright/test";

/**
 * Raising an on-screen keyboard in a browser that has none.
 *
 * Headless Chromium's visual viewport never shrinks, so no gesture available to
 * Playwright puts a keyboard on the screen. What a keyboard *is*, to the code
 * that has to answer one, is a visual viewport shorter than the layout viewport
 * plus a `resize` announcing it — and that is what these stage.
 *
 * The real `VisualViewport` object is kept and only its `height` getter
 * overridden, so the listeners the app added to it are the ones that run. A
 * substitute object would be a test of a stub.
 *
 * Used by compose-keyboard.spec.ts (the dock card on a tablet, which has to
 * move up and cap its height) and compose-mobile.spec.ts (the fullscreen phone
 * window, which sizes itself from the visual viewport and has to stop reserving
 * the home indicator once the keyboard is over it).
 */
export async function raiseKeyboard(page: Page, px: number): Promise<void> {
    await page.evaluate((height) => {
        const viewport = window.visualViewport!;

        Object.defineProperty(viewport, "height", {
            configurable: true,
            get: () => window.innerHeight - height,
        });

        viewport.dispatchEvent(new Event("resize"));
    }, px);
}

/**
 * Pinch zoom, which is NOT a keyboard however much the arithmetic resembles
 * one: `visualViewport.height` shrinks with the scale while `window.innerHeight`
 * does not, so a naive layout-minus-visual subtraction reports about half the
 * screen as keyboard with no keyboard on it. `offsetTop` moves with the pan, so
 * it is staged too — a phantom that varies as you pan is the failure this
 * reproduces.
 *
 * Zoom is deliberately left available in plMail (the viewport meta in
 * app.html.twig keeps it for WCAG 1.4.4), so this is a state a user reaches.
 */
export async function pinchZoom(
    page: Page,
    { scale, offsetTop = 0 }: { scale: number; offsetTop?: number },
): Promise<void> {
    await page.evaluate((state) => {
        const viewport = window.visualViewport!;

        Object.defineProperty(viewport, "scale", {
            configurable: true,
            get: () => state.scale,
        });
        Object.defineProperty(viewport, "height", {
            configurable: true,
            get: () => window.innerHeight / state.scale,
        });
        Object.defineProperty(viewport, "offsetTop", {
            configurable: true,
            get: () => state.offsetTop,
        });

        viewport.dispatchEvent(new Event("resize"));
    }, { scale, offsetTop });
}

/**
 * A keyboard on a browser that shrinks the LAYOUT viewport for it — what
 * `interactive-widget=resizes-content` asks for, which Android has always done
 * and which iOS support has moved on.
 *
 * The difference that matters is what is left over. Here the layout viewport
 * has already stopped above the keyboard, so `innerHeight` and
 * `visualViewport.height` differ only by the accessory bar — the floating strip
 * of browser chrome above the keys, which draws OVER the page rather than
 * shortening it. A fullscreen window sized to the visual viewport then stops
 * short of the page behind it and lets it show through, which is the strip that
 * turned up in an iPhone screenshot.
 *
 * `innerHeight` cannot be assigned, so the layout viewport is staged by
 * resizing the browser window itself and the visual viewport is overridden the
 * accessory bar's worth shorter.
 */
export async function raiseKeyboardResizingLayout(
    page: Page,
    { keyboard, accessoryBar }: { keyboard: number; accessoryBar: number },
): Promise<void> {
    const size = page.viewportSize()!;

    // The layout viewport shrinks: this is the half the browser does for us.
    await page.setViewportSize({ width: size.width, height: size.height - keyboard });

    await page.evaluate((bar) => {
        const viewport = window.visualViewport!;

        Object.defineProperty(viewport, "height", {
            configurable: true,
            get: () => window.innerHeight - bar,
        });

        viewport.dispatchEvent(new Event("resize"));
    }, accessoryBar);
}

/** Put the keyboard away, or undo a zoom. */
export async function lowerKeyboard(page: Page): Promise<void> {
    await page.evaluate(() => {
        const viewport = window.visualViewport!;

        // Deleting the own properties raiseKeyboard and pinchZoom defined
        // uncovers the prototype's real getters again. Delete is a no-op for a
        // property that was never staged, so this undoes either.
        const staged = viewport as unknown as Record<string, unknown>;

        delete staged.height;
        delete staged.scale;
        delete staged.offsetTop;

        viewport.dispatchEvent(new Event("resize"));
    });
}

/**
 * The `--keyboard-inset` the compose controller publishes on <html>, in pixels.
 * An empty string means the property is not set at all — which is what a closed
 * compose window has to leave behind.
 */
export async function publishedInset(page: Page): Promise<string> {
    return page.evaluate(() =>
        getComputedStyle(document.documentElement)
            .getPropertyValue("--keyboard-inset")
            .trim(),
    );
}
