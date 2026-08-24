import { expect, type Locator, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * Reminders in the event editor.
 *
 * The browser is where two things are visible that no PHP test can see: that
 * the boxes are reachable by their accessible names at all, and that a reminder
 * ticked in one dialog comes back ticked in the next one. The second is the
 * whole round trip — save writes a JSCalendar `alerts` map, the editor rebuilds
 * its list from that map, and the two agree — and it is exactly what breaks
 * when the key the checkbox posts stops matching the key the reader derives.
 *
 * Deliberately no assertion here about an alert *arriving*. Delivery goes out
 * over Web Push and SMTP, neither of which a Playwright page can observe, and a
 * spec that faked one would be asserting its own fixture. The delivery
 * guarantees live where they can be pinned: DueAlertReaderTest for what is due,
 * AlertDelivererTest for firing exactly once.
 *
 * Seeds and removes its own event: the suite is not idempotent, and an event
 * left behind changes what the next run's assertions count.
 */

const TITLE = `E2E alerts ${Date.now()}`;

function chipsOf(page: Page) {
    return page.getByRole("button", { name: new RegExp(TITLE) });
}

/**
 * Submit the editor and wait until the page under it is the new one.
 *
 * The wait matters more than it looks: the button navigates the whole page
 * (data-turbo-frame="_top"), and until that lands the dialog is still open over
 * the previous render — so a spec that reads the calendar straight after the
 * click reads the state it was trying to change.
 */
async function submit(page: Page, modal: Locator, button: string) {
    // Waited for, not inferred from the dialog going away.
    //
    // The modal being hidden says the client has moved on, which is not the
    // same as the server having written anything — and every caller here goes
    // straight on to REOPEN the editor and read what was saved. Racing that
    // GET against the POST returns the event as it was, so a reminder that had
    // just been added was simply absent: 6 checkboxes where 7 were expected,
    // on all fourteen polls, which reads as the feature not saving at all.
    //
    // Every button routed through this helper posts under /calendar/event —
    // save, delete, move — so one predicate covers them.
    const written = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname.startsWith("/calendar/event")
            && "POST" === response.request().method(),
    );

    await modal.getByRole("button", { name: button }).click();

    expect((await written).status(), "the editor refused the change").toBeLessThan(400);

    await expect(page.locator("#modal-backdrop")).toBeHidden();
}

/**
 * Open the editor on this spec's chip and wait until it is that chip's editor.
 *
 * The wait is on a field's value rather than on the dialog being visible: the
 * modal keeps the previous dialog's markup until Turbo swaps the frame.
 */
async function openEditor(page: Page) {
    await chipsOf(page).first().click();

    const modal = page.locator("#modal-backdrop");
    await expect(modal.getByLabel("Title")).toHaveValue(new RegExp(TITLE));

    return modal;
}

async function createEvent(page: Page) {
    await page.goto("/calendar/week");
    await page.getByRole("button", { name: "New event", exact: true }).click();

    const modal = page.locator("#modal-backdrop");
    await expect(modal).toBeVisible();

    await modal.getByLabel("Title").fill(TITLE);
    await submit(page, modal, "Save");

    await expect(chipsOf(page).first()).toBeVisible();
}

test.describe("calendar alerts", () => {
    test.afterEach(async ({ page }) => {
        await page.goto("/calendar/agenda");

        if ((await chipsOf(page).count()) === 0) {
            return;
        }

        const modal = await openEditor(page);

        await submit(page, modal, "Delete");
        await expect(chipsOf(page)).toHaveCount(0);
    });

    test("offers the common reminders on a new event, none of them set", async ({ page }) => {
        await page.goto("/calendar/week");
        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        const boxes = modal.getByRole("group", { name: "Reminders" }).getByRole("checkbox");

        await expect(boxes).toHaveCount(6);
        await expect(modal.getByRole("checkbox", { name: "10 minutes before" })).not.toBeChecked();

        await modal.getByRole("button", { name: "Cancel" }).click();
    });

    /**
     * The round trip, and the reason the checkbox posts a key rather than an
     * offset: what the editor renders next time has to be resolved from the
     * stored alert by the same rule that produced the value.
     */
    test("a reminder ticked in the editor comes back ticked", async ({ page }) => {
        await createEvent(page);

        const opened = await openEditor(page);

        await opened.getByRole("checkbox", { name: "10 minutes before" }).check();
        await submit(page, opened, "Save");

        const reopened = await openEditor(page);

        await expect(reopened.getByRole("checkbox", { name: "10 minutes before" })).toBeChecked();

        await reopened.getByRole("button", { name: "Cancel" }).click();
    });

    /**
     * Unticking has to mean something.
     *
     * Regression guard: every other caller of CalendarEventWriter::write()
     * leaves alerts unstated and must not lose them, so "nothing stated" means
     * "keep them". The editor is the one caller that states an empty list, and
     * if it ever stops doing so a reminder becomes impossible to remove through
     * the only UI that can set one.
     */
    test("unticking the last reminder removes it", async ({ page }) => {
        await createEvent(page);

        const opened = await openEditor(page);

        await opened.getByRole("checkbox", { name: "10 minutes before" }).check();
        await submit(page, opened, "Save");

        const second = await openEditor(page);

        await second.getByRole("checkbox", { name: "10 minutes before" }).uncheck();
        await submit(page, second, "Save");

        const third = await openEditor(page);

        await expect(third.getByRole("checkbox", { name: "10 minutes before" })).not.toBeChecked();

        await third.getByRole("button", { name: "Cancel" }).click();
    });

    /**
     * The general case. A typed number becomes an alert of its own and is
     * offered as a seventh box the next time the dialog opens, which is what
     * makes it removable without a second control.
     */
    test("an arbitrary number of minutes becomes a reminder of its own", async ({ page }) => {
        await createEvent(page);

        const opened = await openEditor(page);

        await opened.getByLabel("Something else, in minutes").fill("45");
        await submit(page, opened, "Save");

        const reopened = await openEditor(page);

        await expect(reopened.getByRole("group", { name: "Reminders" }).getByRole("checkbox")).toHaveCount(7);
        await expect(reopened.getByRole("checkbox", { name: "45 minutes before the start" })).toBeChecked();

        await reopened.getByRole("button", { name: "Cancel" }).click();
    });
});
