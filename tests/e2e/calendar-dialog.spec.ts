import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * The event editor's dialog: what it says when it refuses, where the keyboard
 * goes, and what the fields hold before anybody types.
 *
 * Every case here is a defect that shipped. The editor validated correctly and
 * said nothing: the save answered 422 with `application/json`, and Turbo only
 * reads a form response's body when its content type is HTML-ish, so the
 * failure branch had nothing to render and did nothing at all. A user typing an
 * end before a start got a dialog that simply sat there. The other four are
 * cheaper to state than to describe — a missing hour label, a start time frozen
 * at 09:00, an agenda that would not say how long anything was, and a modal
 * that announced itself as one while letting Tab walk out of it.
 *
 * The refusals are asserted TWICE where they can be, once against the browser's
 * copy of the rule and once against the server's, because they are two separate
 * implementations that have to agree. `disableClientValidation` is what forces
 * the second: it detaches the Stimulus controller so the submit reaches PHP.
 */

const EDITOR = "#event-title";

/** Open the editor on a fresh event from the day view's + button. */
async function openNewEvent(page: Page) {
    await page.goto("/calendar/day");
    await page.locator('[data-calendar--time-grid-target="hours"]').first().waitFor();
    await page.getByRole("button", { name: /New event on/ }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible();
}

/**
 * Take the client-side check out of the way so the server's is what answers.
 *
 * Removing the attribute disconnects the Stimulus controller, which is the
 * whole point: with it attached the form never submits, and the 422 path — the
 * one that was broken — is never exercised.
 */
async function disableClientValidation(page: Page) {
    await page.evaluate(() => {
        document.querySelector('form[action*="event/save"]')?.removeAttribute("data-controller");
    });
}

/** The dialog's focusable ring, in the order Tab walks it. */
function focusablesIn(page: Page) {
    return page.evaluate(() => {
        const dialog = document.querySelector("#modal-backdrop");

        if (!dialog) return 0;

        return [
            ...dialog.querySelectorAll<HTMLElement>(
                'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ].filter((el) => el.getClientRects().length > 0).length;
    });
}

/** Whether the keyboard is currently somewhere inside the dialog. */
function focusIsInDialog(page: Page) {
    return page.evaluate(() => !!document.activeElement?.closest("#modal-backdrop"));
}

test.describe("calendar event dialog", () => {
    test.describe("refusing a save", () => {
        /**
         * The headline defect. Both halves are asserted: that the dialog says
         * something a person can see, and that it says it somewhere a screen
         * reader will read out without being asked.
         */
        test("shows the server's refusal when the end is before the start", async ({ page }) => {
            await openNewEvent(page);

            const day = (await page.locator("#event-starts").inputValue()).slice(0, 10);

            await page.locator(EDITOR).fill("ZZ dialog end-before-start");
            await page.locator("#event-starts").fill(`${day}T18:00`);
            await page.locator("#event-ends").fill(`${day}T08:00`);

            await disableClientValidation(page);

            const response = page.waitForResponse((r) => r.url().includes("/calendar/event/save"));

            await page.getByRole("button", { name: "Save", exact: true }).click();

            expect((await response).status()).toBe(422);

            // Visible, announced, and attached to the field it is about.
            const alert = page.getByRole("alert").first();

            await expect(alert).toBeVisible();
            await expect(alert).toContainText("The end has to come after the start");

            await expect(page.locator("#event-ends")).toHaveAttribute("aria-invalid", "true");
            await expect(page.locator("#event-ends")).toHaveAttribute(
                "aria-describedby",
                "event-ends-error",
            );
            await expect(page.locator("#event-ends-error")).toContainText(
                "The end has to come after the start",
            );

            // And the dialog is still the one the user was working in, carrying
            // what they typed. A refusal that restored the stored values would
            // be throwing away the correction it had just asked for.
            await expect(page.locator(EDITOR)).toHaveValue("ZZ dialog end-before-start");
            await expect(page.locator("#event-ends")).toHaveValue(`${day}T08:00`);
        });

        /** The same rule, in the browser, where it costs no round trip. */
        test("refuses an end before the start without asking the server", async ({ page }) => {
            await openNewEvent(page);

            const day = (await page.locator("#event-starts").inputValue()).slice(0, 10);

            await page.locator(EDITOR).fill("ZZ dialog client side");
            await page.locator("#event-starts").fill(`${day}T18:00`);
            await page.locator("#event-ends").fill(`${day}T08:00`);

            let posted = false;
            page.on("request", (r) => {
                if (r.url().includes("/calendar/event/save")) posted = true;
            });

            await page.getByRole("button", { name: "Save", exact: true }).click();

            await expect(page.getByRole("alert").first()).toContainText(
                "The end has to come after the start",
            );
            await expect(page.locator("#event-ends")).toHaveAttribute("aria-invalid", "true");

            expect(posted).toBe(false);
        });

        /**
         * WCAG 3.3.1: the error is identified in TEXT and tied to its field.
         * The browser's own bubble did none of that, which is why the form
         * carries `novalidate` and renders its own.
         */
        test("names an empty title in text, on the field", async ({ page }) => {
            await openNewEvent(page);

            await page.locator(EDITOR).fill("");
            await page.getByRole("button", { name: "Save", exact: true }).click();

            await expect(page.getByRole("alert").first()).toContainText("Give this event a title");

            await expect(page.locator(EDITOR)).toHaveAttribute("aria-invalid", "true");
            await expect(page.locator(EDITOR)).toHaveAttribute(
                "aria-describedby",
                "event-title-error",
            );
            await expect(page.locator("#event-title-error")).toContainText("Give this event a title");

            // And it stops complaining once the field is filled in, rather than
            // leaving a stale error beside a valid value.
            await page.locator(EDITOR).fill("ZZ dialog title");

            await expect(page.locator(EDITOR)).not.toHaveAttribute("aria-invalid", "true");
        });

        /** The server refuses a blank title too — it used to save "Untitled". */
        test("refuses an empty title server-side", async ({ page }) => {
            await openNewEvent(page);

            await page.locator(EDITOR).fill("");
            await disableClientValidation(page);

            const response = page.waitForResponse((r) => r.url().includes("/calendar/event/save"));

            await page.getByRole("button", { name: "Save", exact: true }).click();

            expect((await response).status()).toBe(422);
            await expect(page.getByRole("alert").first()).toContainText("Give this event a title");
        });
    });

    test.describe("times", () => {
        /**
         * Moving the start moves the end with it. Without this the commonest
         * edit there is — "same meeting, later" — silently produces an end
         * before its own start, and the user is told off for a number they
         * never touched.
         */
        test("keeps the duration when the start moves", async ({ page }) => {
            await openNewEvent(page);

            const day = (await page.locator("#event-starts").inputValue()).slice(0, 10);

            await page.locator("#event-starts").fill(`${day}T09:00`);
            await page.locator("#event-ends").fill(`${day}T10:30`);
            await page.locator("#event-starts").fill(`${day}T14:00`);
            await page.locator("#event-starts").dispatchEvent("change");

            await expect(page.locator("#event-ends")).toHaveValue(`${day}T15:30`);
        });

        /**
         * The default start is the next full hour, not a hard-coded 09:00.
         *
         * Asserted as a RELATION to the browser's own clock rather than against
         * a mocked one: the times are rendered by PHP in the user's zone, so a
         * clock mocked in the page would prove nothing about the value the
         * server chose. The end is an hour after the start, always.
         */
        test("opens at the next full hour, for an hour", async ({ page }) => {
            await openNewEvent(page);

            const start = await page.locator("#event-starts").inputValue();
            const end = await page.locator("#event-ends").inputValue();

            expect(start).toMatch(/T\d\d:00$/);

            const startAt = new Date(start);
            const endAt = new Date(end);

            expect(endAt.getTime() - startAt.getTime()).toBe(60 * 60 * 1000);

            // Within the hour after "now", which is what "the next full hour"
            // means and is loose enough to survive the clock ticking mid-test.
            const now = Date.now();

            expect(startAt.getTime()).toBeGreaterThan(now - 60 * 60 * 1000);
            expect(startAt.getTime()).toBeLessThanOrEqual(now + 60 * 60 * 1000);
        });
    });

    test.describe("the dialog itself", () => {
        /**
         * `role="dialog"` and `aria-modal="true"` were already on the backdrop
         * and are asserted here so they stay. The promise they make is the part
         * that was missing: aria-modal tells a screen reader to ignore
         * everything outside the dialog and does nothing whatever to the tab
         * ring, so the keyboard walked out into content the same attribute had
         * just declared invisible.
         */
        test("is a modal dialog the keyboard cannot leave", async ({ page }) => {
            await openNewEvent(page);

            const backdrop = page.locator("#modal-backdrop");

            await expect(backdrop).toHaveAttribute("role", "dialog");
            await expect(backdrop).toHaveAttribute("aria-modal", "true");

            // Focus is moved into the dialog, onto the field it names.
            await expect(page.locator(EDITOR)).toBeFocused();

            // A full lap of the ring stays inside it. One extra Tab past the
            // last stop is the wrap that was missing.
            const stops = await focusablesIn(page);

            expect(stops).toBeGreaterThan(1);

            for (let i = 0; i <= stops; i++) {
                await page.keyboard.press("Tab");

                expect(await focusIsInDialog(page)).toBe(true);
            }

            // And backwards, off the front of the ring.
            await page.locator("#modal-backdrop button").first().focus();
            await page.keyboard.press("Shift+Tab");

            expect(await focusIsInDialog(page)).toBe(true);
        });

        /** Closing puts the keyboard back where it was, not on <body>. */
        test("returns focus to the trigger on close", async ({ page }) => {
            await page.goto("/calendar/day");
            await page.locator('[data-calendar--time-grid-target="hours"]').first().waitFor();

            const trigger = page.getByRole("button", { name: /New event on/ }).first();

            await trigger.click();
            await expect(page.locator(EDITOR)).toBeVisible();

            await page.keyboard.press("Escape");
            await expect(page.locator("#modal-backdrop")).toBeHidden();

            await expect(trigger).toBeFocused();
        });
    });

    test.describe("the views around it", () => {
        test.beforeEach(() => {
            seed("seed-grid-events");
        });

        test.afterEach(() => {
            seed("seed-grid-events --clear");
        });

        /**
         * Every row in the gutter is labelled, including the first.
         *
         * The labels sit ON their hour line, which is why 00:00's used to be
         * skipped: nudged up by half its height like the other twenty-three, it
         * would have been clipped by the top of the scroller. The result was a
         * gutter running 01:00–23:00 over twenty-four rows, so every row was
         * labelled with the hour below it and the first was labelled not at all.
         */
        test("labels every hour row, starting at midnight", async ({ page }) => {
            await page.goto("/calendar/day");

            const gutter = page.locator('[data-calendar--time-grid-target="hours"] > div').first();

            await expect(gutter).toBeVisible();

            const labels = (await gutter.innerText())
                .split("\n")
                .map((line) => line.trim())
                .filter(Boolean);

            expect(labels).toHaveLength(24);

            // Midnight, on whichever clock this user reads — "12 am" or "00:00".
            expect(labels[0]).toMatch(/^(12\s*am|00:00)$/i);
        });

        /**
         * An agenda row prints a range, because it draws no timeline to read a
         * length off. A grid block answers "how long?" by being that tall and a
         * month cell has no width to spare, so this is the one view that says it
         * in words.
         */
        test("shows a time range on agenda rows", async ({ page }) => {
            await page.goto("/calendar/agenda");

            const timed = page.getByRole("button", { name: /E2E grid timed/ }).first();

            await expect(timed).toBeVisible();

            // A range, not a single time: two clock values with a dash between.
            await expect(timed).toContainText(/\d{1,2}:\d\d\s*[–-]\s*\d{1,2}:\d\d/);
        });
    });
});
