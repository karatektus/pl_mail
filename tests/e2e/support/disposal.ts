import { expect, type Page } from "@playwright/test";

/**
 * Settings → Mail → Disposal: the switch for the buttons that delete mail for
 * good (a row's flame in Spam and the bin, and the same button above a
 * selection there).
 *
 * It is off for a user who has never touched it, so a spec about deleting for
 * good has to turn it on first, the way a person would — through the page, not
 * through the database, so the form is exercised as well.
 *
 * Specs that turn it on put it back in an afterEach: the worker's user is
 * shared with whatever file runs next, and a user who has the switch on sees a
 * different toolbar in Spam and the bin.
 */
export async function setDeleteNow(page: Page, on: boolean): Promise<void> {
    await page.goto("/settings?section=disposal");

    // Already how it was asked for: clicking the chosen radio changes nothing,
    // so it submits nothing and there is no redirect to wait for.
    if (await page.locator(`input[name="deleteNow"][value="${on ? "1" : "0"}"]`).isChecked()) {
        return;
    }

    const saved = page.waitForResponse(
        (response) =>
            "GET" === response.request().method() &&
            response.url().includes("section=disposal") &&
            null !== response.request().redirectedFrom(),
    );

    await page
        .locator('form[action="/settings/disposal"] label')
        .filter({ hasText: on ? /^\s*On\s*$/ : /^\s*Off\s*$/ })
        .click();

    await saved;

    await expect(
        page.locator(`input[name="deleteNow"][value="${on ? "1" : "0"}"]`),
        `the switch is ${on ? "on" : "off"} after saving`,
    ).toBeChecked();
}
