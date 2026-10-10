import { test, expect } from "./support/test";

/**
 * The folders modal on an IMAP account's row.
 *
 * Runs against the account every worker's user is given by `app:test:seed-mail`,
 * which the worker fixture in support/test.ts seeds before anything else.
 * That account points at a host that does not exist, so nothing ever syncs and
 * it has no folder rows — the modal's list is empty, and that is what this
 * spec asserts. What it covers is what a browser adds over
 * AccountFolderSyncTest: the row offers the button, the modal opens through
 * Turbo with its title and its explanation, and it closes.
 *
 * Switching a folder is not here, because the folders it would switch do not
 * exist. AccountFolderSyncTest stages them and drives the real controller and
 * templates, which is where that behaviour is pinned.
 */
const ACCOUNT = "E2E Mailbox";

test.describe("account folders modal", () => {
    test("opens from the account row, explains itself, and closes", async ({ page }) => {
        await page.goto("/settings?section=accounts");

        const row = page
            .locator("#settings-account-list li")
            .filter({ hasText: ACCOUNT });

        await row
            .getByRole("button", { name: "Choose which folders to sync" })
            .click();

        const modal = page.locator("#modal-backdrop");

        await expect(modal).toContainText(`Folders — ${ACCOUNT}`);
        await expect(modal).toContainText("Switch a folder off and plMail stops fetching it");
        await expect(modal).toContainText("No folders yet");

        await modal.getByRole("button", { name: "Close" }).click();

        await expect(modal).toBeHidden();
    });
});
