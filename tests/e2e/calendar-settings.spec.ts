import { expect } from "@playwright/test";
import { test } from "./support/test";

/**
 * Managing calendars from settings.
 *
 * A browser spec rather than a controller test because the rules worth holding
 * are rendered ones: which buttons a row offers. The default calendar has no
 * delete and no hide button, and a provisioned one has no delete at all — those
 * are enforced server-side too, but a user only ever meets them as an absence,
 * and an absence is exactly what a unit test does not notice going missing.
 *
 * Creates and removes its own calendar. The per-worker user arrives with the
 * provisioned ones (Personal, plus one per mail account), so the assertions
 * below are written against "the row named X" rather than against counts.
 */

const NAME = `E2E calendar ${Date.now()}`;
const RENAMED = `${NAME} renamed`;

test.describe("calendar settings", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/settings?section=calendars");
    });

    test.afterEach(async ({ page }) => {
        // Named on the row it belongs to: the aria-label carries the calendar's
        // name, so this cannot delete the wrong one if a previous run left
        // something behind.
        for (const name of [NAME, RENAMED]) {
            await page.goto("/settings?section=calendars");

            const remove = page.getByRole("button", { name: `Delete calendar "${name}"` });

            if ((await remove.count()) > 0) {
                page.once("dialog", (dialog) => dialog.accept());
                await remove.first().click();
                await expect(page.getByText(name, { exact: true })).toHaveCount(0);
            }
        }
    });

    test("lists the calendars the user already has", async ({ page }) => {
        await expect(page.getByRole("heading", { name: "Calendars" })).toBeVisible();

        // Provisioned with the user, so it is there before this spec does
        // anything — and it is the default, which is what the badge says.
        await expect(page.getByText("Personal", { exact: true })).toBeVisible();
        await expect(page.getByText("Default", { exact: true })).toBeVisible();
    });

    test("creates a calendar, then renames it", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        // Waiting on the field's VALUE, not on the dialog being visible. The
        // backdrop keeps the previous form until Turbo swaps the frame, so a
        // fill that only waited for "visible" typed into the last dialog and
        // then submitted the new one — which reads as a save that did nothing.
        await expect(modal.getByLabel("Name")).toHaveValue("");

        await modal.getByLabel("Name").fill(NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByText(NAME, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: `Edit calendar "${NAME}"` }).click();
        await expect(modal).toBeVisible();

        // The edit form arrives filled in, which is both the wait and a claim.
        await expect(modal.getByLabel("Name")).toHaveValue(NAME);

        await modal.getByLabel("Name").fill(RENAMED);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByText(RENAMED, { exact: true })).toBeVisible();
        await expect(page.getByText(NAME, { exact: true })).toHaveCount(0);
    });

    /**
     * A name is what tells one calendar from another, so an empty one comes
     * back as an error on the field. The modal must stay open — it closes on
     * any successful submit, so a 200 here would look like a silent save.
     */
    test("refuses a calendar with no name", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await modal.getByLabel("Name").fill("");
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeVisible();
    });

    /**
     * Hiding takes a calendar out of every view at once, so the one new events
     * land on cannot be hidden — they would vanish on save and read as a
     * failure. The button is simply not rendered for it.
     */
    test("the default calendar cannot be hidden or deleted", async ({ page }) => {
        await expect(page.getByRole("button", { name: "Delete calendar \"Personal\"" })).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Hide calendar" }).first()).toBeVisible();

        const personalRow = page.locator("li", { hasText: "Personal" }).first();
        await expect(personalRow.getByRole("button", { name: "Hide calendar" })).toHaveCount(0);
    });

    test("moves the default to another calendar", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("Name")).toHaveValue("");
        await modal.getByLabel("Name").fill(NAME);
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(page.getByText(NAME, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: `Make "${NAME}" the calendar new events land on` }).click();

        // The badge moves with it, and exactly one row carries it: two defaults
        // means a new event lands wherever row order puts it.
        await expect(page.getByText("Default", { exact: true })).toHaveCount(1);

        const row = page.locator("li", { hasText: NAME }).first();
        await expect(row.getByText("Default", { exact: true })).toBeVisible();

        // Put it back, so the delete in afterEach is allowed to run.
        await page.getByRole("button", { name: 'Make "Personal" the calendar new events land on' }).click();
        await expect(page.getByRole("button", { name: `Make "${NAME}" the calendar new events land on` })).toBeVisible();
    });
});
