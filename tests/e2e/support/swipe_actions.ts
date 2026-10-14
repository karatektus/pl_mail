import { expect, type Page } from "@playwright/test";

/**
 * Settings → Mail → Swipe actions: what a swipe in each direction does on a row.
 *
 * A swipe spec that needs a direction set to something other than its default
 * has to set it the way a person would, through the page, so the select and its
 * save are exercised as well. The worker's user is shared with whatever file
 * runs next, so such a spec puts the direction back in an afterEach.
 */
export async function setSwipeAction(page: Page, direction: "Swipe right" | "Swipe left", option: string): Promise<void> {
    await page.goto("/settings?section=swipe-actions");

    const field = direction === "Swipe right" ? "swipeRight" : "swipeLeft";

    // Already the option asked for: choosing it again is no change, so no save follows and nothing
    // is waited for. A cleanup that resets a direction it never moved (a test that failed first) is safe.
    const current = await page.locator(`select[name="${field}"] option[selected]`).textContent();

    if (current?.trim() === option) {
        return;
    }

    const saved = page.waitForResponse(
        (response) =>
            "GET" === response.request().method() &&
            response.url().includes("section=swipe-actions") &&
            null !== response.request().redirectedFrom(),
    );

    // The themed select, through its combobox and the list it names. The native
    // <select> underneath carries options too, so the option is found inside the
    // list the combobox controls, not anywhere on the page.
    const control = page.getByRole("combobox", { name: direction, exact: true });

    if ((await control.getAttribute("aria-expanded")) !== "true") {
        await control.click();
    }

    const listboxId = await control.getAttribute("aria-controls");

    expect(listboxId, "the combobox names its list").toBeTruthy();

    await page.locator(`[id="${listboxId}"]`).getByRole("option", { name: option, exact: true }).click();

    await saved;

    await expect(
        page.locator(`select[name="${field}"] option[selected]`),
        `${direction} is ${option} after saving`,
    ).toHaveText(option);
}
