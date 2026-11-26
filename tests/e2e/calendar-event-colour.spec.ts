import { expect, type Locator, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * An event's own colour, picked in the editor (#616).
 *
 * What only a browser shows: that the dot in the title field opens the
 * popover, that the search finds a colour and Enter picks it, and that the
 * colour comes back in the next editor and on the chip. The storage and sync
 * claims are PHP's — CalendarEventWriterTest, CalDavEventConverterTest.
 *
 * Seeds and removes its own event, as calendar-alerts does.
 */

const TITLE = `E2E colour ${Date.now()}`;

function chipsOf(page: Page) {
    return page.getByRole("button", { name: new RegExp(TITLE) });
}

/** Submit and wait for the write, for the reason calendar-alerts gives. */
async function submit(page: Page, modal: Locator, button: string) {
    const written = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname.startsWith("/calendar/event")
            && "POST" === response.request().method(),
    );

    await modal.getByRole("button", { name: button }).click();

    expect((await written).status(), "the editor refused the change").toBeLessThan(400);

    await expect(page.locator("#modal-backdrop")).toBeHidden();
}

async function openEditor(page: Page) {
    await chipsOf(page).first().click();

    const modal = page.locator("#modal-backdrop");

    await modal.getByRole("link", { name: "Edit" }).click();
    await expect(modal.getByLabel("Title")).toHaveValue(new RegExp(TITLE));

    return modal;
}

test.describe("calendar event colour", () => {
    test.afterEach(async ({ page }) => {
        await page.goto("/calendar/agenda");

        if ((await chipsOf(page).count()) === 0) {
            return;
        }

        const modal = await openEditor(page);

        await submit(page, modal, "Delete");
        await expect(chipsOf(page)).toHaveCount(0);
    });

    test("a colour found by searching is saved, shown again and drawn", async ({ page }) => {
        await page.goto("/calendar/week");
        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();
        await modal.getByLabel("Title").fill(TITLE);

        await modal.getByRole("button", { name: "Colour: Calendar colour" }).click();

        const search = page.getByRole("combobox", { name: "Search colours" });
        await expect(search).toBeFocused();
        await search.fill("turq");
        await expect(page.getByRole("option", { name: /Turquoise/ }).first()).toBeVisible();
        await search.press("Enter");

        await expect(modal.getByRole("button", { name: "Colour: Turquoise" })).toBeVisible();

        await submit(page, modal, "Save");

        // The chip's bar is drawn in the event's colour, not the calendar's.
        await expect(chipsOf(page).first().locator("[style*='64, 224, 208'], [style*='#40e0d0']").first()).toBeAttached();

        const reopened = await openEditor(page);

        await expect(reopened.getByRole("button", { name: "Colour: Turquoise" })).toBeVisible();

        await reopened.getByRole("button", { name: "Cancel" }).click();
    });
});
