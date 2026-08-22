import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * A label put on from the list can be taken off again.
 *
 * The bulk label menu — the one the selection toolbar opens — is shared by
 * every row, so it renders with nothing ticked. That was not cosmetic: the menu
 * decides attach-or-detach from the tick
 * (`attach = button.dataset.attached !== "true"`), so a label that WAS attached
 * showed as unticked and the next click attached it again. Reported as: a label
 * sticks to a conversation and no amount of clicking removes it.
 *
 * The single-target menu in a conversation never had this — the server renders
 * `activeLabels` there — which is why it reads as working right up until you
 * try it from the list.
 *
 * The reload in the middle is the point. Straight after assigning, the tick is
 * correct because the click set it locally; the bug only appears once the menu
 * is rendered fresh, which is what a real user does.
 */
const LABEL_NAME = "E2E Label";

test.beforeEach(() => {
    seed("seed-mail", "seed-label");
});

/**
 * Selects a row.
 *
 * Set-and-dispatch rather than `check()`. The checkbox is `peer sr-only` — a
 * 1px clipped input behind its own styled label — and a forced click on it
 * lands without changing its state, which Playwright reports as "clicking the
 * checkbox did not change its state" on roughly one full run in three. Chasing
 * the visible label instead would make this test about the checkbox, and the
 * subject here is the label menu.
 *
 * The change event is what the row controller listens for
 * (`change->mail--message-row#toggleSelect`), so the same code path runs.
 */
async function select(row: import("@playwright/test").Locator) {
    await row.locator("[data-thread-select]").evaluate((box) => {
        (box as HTMLInputElement).checked = true;
        box.dispatchEvent(new Event("change", { bubbles: true }));
    });
}

/**
 * Opens the selection toolbar's label menu for whatever is selected.
 *
 * Scoped to the toolbar rather than `.first()` on the controller: a thread pane
 * renders its own single-target instance of this menu, and which of the two
 * comes first in the document depends on what is open. Picking the wrong one
 * tests the case that was never broken.
 *
 * Waits for the toolbar to appear first, because it is revealed by the
 * selection it is being asked about — clicking into it before then finds a
 * button that is still hidden.
 */
async function openBulkLabelMenu(page: import("@playwright/test").Page) {
    const toolbar = page.locator("[data-controller='mail--list-toolbar']").first();
    await expect(toolbar).toBeVisible();

    const menu = toolbar.locator('[data-controller="mail--label-menu"]').first();
    await menu.locator("button").first().click();

    const panel = menu.locator('[data-mail--label-menu-target="panel"]');
    await expect(panel).toBeVisible();

    return panel;
}

test("a label assigned from the list can be removed from the list", async ({ page }) => {
    await page.goto("/mail/inbox");

    const row = mailRow(page, INBOX_SUBJECTS.read);
    await expect(row).toBeVisible();

    await select(row);

    const panel = await openBulkLabelMenu(page);
    const entry = panel.locator("[data-label-id]", { hasText: LABEL_NAME }).first();
    await entry.click();

    // The chip on the row is what the user sees; assert on that rather than on
    // the menu, which is the thing under suspicion.
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toContainText(LABEL_NAME, { timeout: 10_000 });

    // Fresh render of the menu — the state the bug lived in.
    await page.reload();
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toContainText(LABEL_NAME);
    await select(mailRow(page, INBOX_SUBJECTS.read));

    const reopened = await openBulkLabelMenu(page);
    const again = reopened.locator("[data-label-id]", { hasText: LABEL_NAME }).first();

    await expect(
        again,
        "the menu opened without ticking a label the selection already carries",
    ).toHaveAttribute("data-attached", "true");

    await again.click();

    await expect(
        mailRow(page, INBOX_SUBJECTS.read),
        "clicking a ticked label did not remove it",
    ).not.toContainText(LABEL_NAME, { timeout: 10_000 });
});
