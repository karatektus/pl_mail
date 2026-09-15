import { test, expect } from "./support/test";
import { settled } from "./support/motion";

/**
 * A drag survives the list being re-rendered underneath it.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * WHAT WENT WRONG
 * ───────────────
 * The inbox stopped answering the mouse. No mousedown, mouseover, click or
 * keydown reached the document — but a programmatic click on a tab still ran
 * the whole Turbo chain and rendered correctly, so nothing was wrong with the
 * page's JavaScript. Chrome still believed a drag was in progress and was
 * eating the input upstream of DOM dispatch.
 *
 * The mechanism is small. Every listener in mail--dnd is on <body> and depends
 * on the event bubbling up from the row. A refresh landing mid-drag — new mail
 * over Mercure is the common one — replaces the list, and an event dispatched
 * at a detached node has nothing to bubble THROUGH. dragend is never heard,
 * #teardown never runs, and `data-dnd-active` stays on <html>, which Turbo
 * does not replace.
 *
 * WHY THESE DISPATCH EVENTS INSTEAD OF DRIVING THE MOUSE
 * ─────────────────────────────────────────────────────
 * dnd.spec.ts says at length that a hand-built DragEvent proves nothing about
 * the gesture, and it is right — a synthetic dragstart passes just as well
 * against a row that is not `draggable`, which is the failure that file exists
 * to catch. It owns the gesture. This file owns what happens to the bookkeeping
 * afterwards, and for that a real drag is the wrong instrument rather than the
 * expensive one: there is no way to hold a genuine drag open and re-render the
 * list underneath it from the same script, because the browser will not deliver
 * script-driven input while it owns a drag session. The two files are the two
 * halves, and neither claim is testable by the other's method.
 */

/** The marks a drag writes into the DOM, all of which must be gone after it. */
async function marks(page: import("@playwright/test").Page) {
    return page.evaluate(() => ({
        active: document.documentElement.dataset.dndActive ?? null,
        refused: document.querySelectorAll("[data-dnd-refused]").length,
        over: document.querySelectorAll("[data-dnd-over]").length,
    }));
}

/**
 * A DragEvent the controller will accept.
 *
 * dataTransfer is faked because a constructed DragEvent has none, and
 * #onDragStart writes to it — Firefox cancels a drag with an empty one, so the
 * real handler cannot simply skip it.
 */
const FIRE = `
    const fire = (el, type) => {
        const event = new DragEvent(type, { bubbles: true, cancelable: true });

        Object.defineProperty(event, "dataTransfer", {
            value: { setData() {}, setDragImage() {}, effectAllowed: "", dropEffect: "" },
        });

        el.dispatchEvent(event);
    };
`;

test.describe("a drag interrupted by a re-render", () => {
    test("is torn down even when its row has left the DOM", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        const result = await page.evaluate(
            FIRE +
                `(() => {
            const row = document.querySelector("[data-dnd-thread]");

            fire(row, "dragstart");

            const started = document.documentElement.dataset.dndActive ?? null;

            // The refresh, in the one respect that matters: the drag source
            // stops being in the document. Nothing is bubbled to <body> from
            // here on.
            row.remove();

            const stranded = document.documentElement.dataset.dndActive ?? null;

            fire(row, "dragend");

            return { started, stranded, ended: document.documentElement.dataset.dndActive ?? null };
        })()`,
        );

        expect(result.started, "the drag never started").toBe("true");

        // The middle reading is the point of the test: removing the row does
        // NOT clean up by itself, so the last reading is the listener bound to
        // the row doing work that the one on <body> cannot.
        expect(result.stranded, "removing the row cleaned up on its own").toBe("true");
        expect(result.ended, "a detached row's dragend was not heard").toBeNull();

        await expect.poll(async () => (await marks(page)).refused).toBe(0);
    });

    test("leaves no drop-target styling behind when a frame renders", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        const dirty = await page.evaluate(
            FIRE +
                `(() => {
            fire(document.querySelector("[data-dnd-thread]"), "dragstart");

            return {
                active: document.documentElement.dataset.dndActive ?? null,
                refused: document.querySelectorAll("[data-dnd-refused]").length,
            };
        })()`,
        );

        expect(dirty.active).toBe("true");
        expect(dirty.refused, "no target was marked, so nothing is being proven").toBeGreaterThan(0);

        await page.evaluate(() =>
            document.dispatchEvent(new CustomEvent("turbo:before-frame-render", { bubbles: true })),
        );

        expect(await marks(page)).toEqual({ active: null, refused: 0, over: 0 });

        // And the page still answers, which is the symptom this whole file is
        // about — asserted through a real click rather than a dispatched one,
        // since a dispatched click is exactly what went on working while the
        // tab was unusable.
        await page.locator("[data-dnd-thread]").first().click();
        await expect(page).not.toHaveURL(/\/login/);
    });

    test("holds the list refresh for its duration, and takes it afterwards", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        // The pane re-reads the list frame as a fragment; that request is the
        // thing a drag must not provoke while it is in flight.
        const fragments: string[] = [];
        page.on("request", (request) => {
            if (/\/mail\/(inbox|list)/.test(request.url())) {
                fragments.push(request.url());
            }
        });

        await page.evaluate(FIRE + `fire(document.querySelector("[data-dnd-thread]"), "dragstart");`);
        await page.waitForTimeout(1_000);

        const during = fragments.length;

        await page.evaluate(FIRE + `fire(document.querySelector("[data-dnd-thread]"), "dragend");`);

        // Deferred, not dropped: mail--mail-pane#release takes the refresh it
        // was holding, so the mail that arrived during the drag appears as soon
        // as the drag is over.
        await expect.poll(() => fragments.length, { timeout: 5_000 }).toBeGreaterThan(during);
        expect(during, "the list refreshed while a drag was in flight").toBe(0);
    });
});
