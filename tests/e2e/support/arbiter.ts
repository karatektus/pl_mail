import type { Page } from "@playwright/test";

/**
 * The page's own copy of assets/touch_gesture.js, read back from inside the page.
 *
 * A gesture refused by the arbiter leaves no trace on screen when the backdrop is
 * in front of the list, so the tests that matter here ask the module directly. The
 * module's compiled URL comes from the asset manifest the app serves, and is imported
 * by that URL: the browser's module map returns the same instance the controllers use,
 * so the surfaces and the claim are the real ones.
 *
 * (The module is not looked up in the resource timeline: that buffer is capped, and on
 * a full inbox the entry is gone.)
 *
 * Fails loudly when the manifest has no such entry, rather than reporting a state.
 */
async function moduleOf(page: Page): Promise<void> {
    await page.evaluate(async () => {
        const manifest = (await (await fetch("/assets/manifest.json")).json()) as Record<string, string>;
        const url = manifest["touch_gesture.js"];

        if (url === undefined) {
            throw new Error("touch_gesture.js is not in the asset manifest");
        }

        (window as unknown as { __arbiter: unknown }).__arbiter = await import(url);
    });
}

/** The name of the first open surface, or null. */
export async function openSurfaceOf(page: Page): Promise<string | null> {
    await moduleOf(page);

    return page.evaluate(() => {
        const arbiter = (window as unknown as { __arbiter: { openSurface(): string | null } }).__arbiter;

        return arbiter.openSurface();
    });
}

/**
 * Whether a pull could claim the sequence right now. A grant is released again at once,
 * so the probe leaves the claim as it found it.
 */
export async function pullIsGranted(page: Page): Promise<boolean> {
    await moduleOf(page);

    return page.evaluate(() => {
        const arbiter = (window as unknown as {
            __arbiter: { claimSequence(kind: string): boolean; releaseSequence(kind: string): boolean };
        }).__arbiter;
        const granted = arbiter.claimSequence("pull");

        if (granted) {
            arbiter.releaseSequence("pull");
        }

        return granted;
    });
}
