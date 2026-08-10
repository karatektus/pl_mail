import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * The themed select, as something other than a mouse meets it.
 *
 * v0.0.22 replaced every user-facing single select with a Tom Select, because
 * a native popup is the one part of a form control CSS cannot reach. The
 * native `<select>` stays underneath as the source of truth, and the widget on
 * top is what the label now names — so everything a screen reader and a
 * keyboard need moved with it, and nothing in the suite was holding that.
 *
 * What it cost to have nobody watching: ten calendar specs went red on the
 * same line for five releases, and the message they failed with ("Element is
 * not a <select> element") describes the test's gesture, not the widget's
 * state. Either answer — a genuinely broken control, or a test aimed at an
 * element that is no longer the one the label names — produces that failure,
 * and telling the two apart afterwards took a browser. This file is the
 * difference, stated up front, so the next such failure reads as one or the
 * other on sight.
 *
 * The event editor's "Repeat" picker is the subject because it is a short list
 * with no search box — the configuration where the focusable element is the
 * widget itself rather than an inner text input, which is the one every
 * calendar spec drives — and because nothing it does reaches the database
 * until Save.
 *
 * The editor arrives in a turbo-frame that renders more than once and moves
 * focus into itself as it settles — so every case here opens it through
 * `editor()` below and waits for that to finish first. A spec about who has
 * the focus cannot share a page with something else still deciding.
 */

/**
 * The editor's own address, which is what a browser with no JavaScript gets
 * when it follows the same link — no frame, no modal, just the page. Only the
 * degradation case below wants it; everything else goes through the modal,
 * because the modal is where people meet this widget.
 */
const EDITOR_PAGE = "/calendar/event/new";

/**
 * Open the event editor and wait until the editor on screen is the one this
 * click asked for.
 *
 * The obvious waits are both wrong here, and the second one expensively so.
 *
 * Waiting for the form, or for a field's value, is true too early: the frame
 * arrives with an editor already rendered inside it, so an enhanced "Repeat"
 * widget is on the page before anything is clicked. The click fetches another
 * one over the top of it, and that swap takes the focus with it — the modal
 * moves focus to the head of the dialog on `turbo:frame-load`. Arriving in the
 * middle of a test, that is indistinguishable from the widget dropping focus
 * by itself, and it is worth knowing that it is not: the false reading of it
 * is a keyboard-accessibility bug that was never there.
 *
 * `networkidle` never fires at all: the calendar holds a Mercure stream open
 * for live updates, so this page's network is never quiet by design.
 *
 * What is left is the frame's own load event, counted from before the click —
 * the one signal that says "the editor you are looking at is yours".
 */
async function editor(page: Page) {
    await page.goto("/calendar/week");

    await page.evaluate(() => {
        const counter = window as unknown as { __modalLoads: number };
        counter.__modalLoads = 0;

        document.addEventListener("turbo:frame-load", (event) => {
            if ("modal" === (event.target as HTMLElement).id) counter.__modalLoads += 1;
        });
    });

    await page.getByRole("button", { name: "New event", exact: true }).click();

    await expect
        .poll(() => page.evaluate(() => (window as unknown as { __modalLoads: number }).__modalLoads))
        .toBeGreaterThan(0);

    const modal = page.locator("#modal-backdrop");

    // Stimulus enhances the select after Turbo has put it there, so the widget
    // — not the select — is what says both halves are done.
    await expect(modal.locator("#event-repeat-ts-control")).toBeVisible();

    return modal;
}

test.describe("the themed select widget", () => {
    /**
     * The accessible name survived the swap, and it names something operable.
     *
     * `getByLabel` is the test-side spelling of "what does a screen reader
     * announce for this field": Tom Select moves the label onto the widget it
     * builds, so if the answer were nothing — or a `<div>` with no role — the
     * field would be unusable without a mouse, and this is where that shows.
     */
    test("the label names a combobox, not an unlabelled div", async ({ page }) => {
        const control = (await editor(page)).getByLabel("Repeat", { exact: true });

        await expect(control).toHaveAttribute("role", "combobox");
        await expect(control).toHaveAttribute("aria-expanded", "false");
        await expect(control).toHaveAttribute("aria-haspopup", "listbox");

        // The name as the platform computes it, rather than as the markup
        // spells it — which is the only version that matters to a reader.
        expect(await control.ariaSnapshot()).toContain('combobox "Repeat"');
    });

    /**
     * A keyboard alone can open it, move through it and commit a choice, and
     * the choice lands on the native control the form actually posts.
     *
     * The last clause is the one with teeth. The widget could look right and
     * write nowhere, and every visual check would still pass while every save
     * silently kept the old value.
     */
    test("a keyboard can open it and choose from it", async ({ page }) => {
        const control = (await editor(page)).getByLabel("Repeat", { exact: true });
        const native = page.locator("select#event-repeat");

        await expect(native).toHaveValue("none");

        await control.focus();
        await expect(control).toBeFocused();

        await page.keyboard.press("ArrowDown");
        await expect(control).toHaveAttribute("aria-expanded", "true");
        // Without this a reader hears a list open and never hears which row it
        // is on, because there is no focus inside the panel to follow.
        await expect(control).toHaveAttribute("aria-activedescendant", /.+/);

        await page.keyboard.press("ArrowDown");
        await page.keyboard.press("Enter");

        await expect(control).toHaveText("Every week");
        await expect(native).toHaveValue("weekly");
    });

    /**
     * The native `change` event still fires.
     *
     * Several call sites are `onchange="…requestSubmit()"` or
     * `data-action="change->…"` — a filter row that reloads, the appearance
     * panel's live preview. A widget that wrote the value without announcing
     * it would leave all of those looking frozen.
     */
    test("choosing an option fires change on the select underneath", async ({ page }) => {
        const modal = await editor(page);
        const native = page.locator("select#event-repeat");

        await native.evaluate((element) => {
            element.addEventListener("change", () => {
                (element as HTMLSelectElement).dataset.e2eChanged = (element as HTMLSelectElement).value;
            });
        });

        await modal.getByLabel("Repeat", { exact: true }).click();
        await page
            .locator('[id="event-repeat-ts-dropdown"]')
            .getByRole("option", { name: "Every day", exact: true })
            .click();

        await expect(native).toHaveAttribute("data-e2e-changed", "daily");
    });

    /**
     * Clicking the label focuses the field, which is what a `<label>` is for
     * and the behaviour most easily lost when the element it points at stops
     * being a form control — as it did here, since a `for` on a `<div>` is
     * inert and Tom Select has to reproduce the effect itself.
     */
    test("clicking the label focuses the widget", async ({ page }) => {
        const modal = await editor(page);
        const control = modal.getByLabel("Repeat", { exact: true });

        await modal.getByLabel("Title").focus();
        await page.locator("#event-repeat-ts-label").click();

        await expect(control).toBeFocused();
    });

    /**
     * With JavaScript off the native control is what renders, and it is a
     * working one — the claim v0.0.22 made for its own change, asserted here
     * because a progressive enhancement that only works enhanced is not one.
     */
    test("degrades to a working native select with no JavaScript", async ({ browser, workerAuth }) => {
        // A context of its own, because `javaScriptEnabled` is fixed when the
        // context is made and the shared one is already running. It borrows
        // this worker's session file rather than signing in again.
        const context = await browser.newContext({
            javaScriptEnabled: false,
            storageState: workerAuth,
            baseURL: test.info().project.use.baseURL,
        });

        try {
            const page = await context.newPage();
            await page.goto(EDITOR_PAGE);

            const native = page.locator("select#event-repeat");

            // A real select, still named by its own label, still carrying
            // every choice — so the page is usable by whoever arrives with no
            // JavaScript, which for the public booking pages is the norm.
            await expect(native).toBeVisible();
            await expect(page.locator('label[for="event-repeat"]')).toHaveText("Repeat");
            await expect(native.locator("option")).toHaveCount(5);

            await native.selectOption("weekly");
            await expect(native).toHaveValue("weekly");
        } finally {
            await context.close();
        }
    });
});
