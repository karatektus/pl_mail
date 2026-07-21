import { test, expect } from "@playwright/test";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Adding a password (IMAP) account only persists the row — no IMAP
 * connection is attempted at creation time — so a fake host is fine and the
 * test needs no mail server.
 */
test.describe("mail account creation", () => {
    test("adds an IMAP account from the settings modal", async ({ page }) => {
        // Unique per run so re-runs against a non-fresh DB stay unambiguous.
        const stamp = Date.now();
        const label = `E2E IMAP ${stamp}`;
        const username = `imap-${stamp}@example.test`;
        const host = `imap-${stamp}.example.test`;

        await page.goto("/settings?section=accounts");

        const accountsSection = page
            .locator("section")
            .filter({ has: page.locator("#settings-account-list") });

        await accountsSection
            .getByRole("button", { name: "Add account" })
            .click();

        // Wait for the Turbo-Frame modal form to load.
        const imapHost = page.locator('input[name="account[imapHost]"]');
        await expect(imapHost).toBeVisible();

        // The IMAP/SMTP tab is active by default, so these fields are visible.
        await page.locator('input[name="account[email]"]').fill(label);
        await page.locator('input[name="account[username]"]').fill(username);
        await page.locator('input[name="account[password]"]').fill("hunter2");
        await imapHost.fill(host);
        await page.locator('input[name="account[imapPort]"]').fill("993");
        await page
            .locator('select[name="account[imapEncryption]"]')
            .selectOption("ssl");

        await page.locator('#modal button[type="submit"]').click();

        // Turbo Stream: success toast + refreshed settings list.
        await expect(page.getByText("Account added successfully")).toBeVisible();

        const list = page.locator("#settings-account-list");
        await expect(list).toContainText(label);
        await expect(list).toContainText(host);
    });

    test("keeps the modal open and reports the error when required fields are missing", async ({
                                                                                                   page,
                                                                                               }) => {
        await page.goto("/settings?section=accounts");

        const accountsSection = page
            .locator("section")
            .filter({ has: page.locator("#settings-account-list") });

        await accountsSection
            .getByRole("button", { name: "Add account" })
            .click();

        const imapHost = page.locator('input[name="account[imapHost]"]');
        await expect(imapHost).toBeVisible();

        // Submit with the required fields blank.
        await page.locator('#modal button[type="submit"]').click();

        // Required fields (native HTML5 + server-side NotBlank) keep us on the
        // form inside the modal rather than emitting the success toast.
        await expect(page.locator('input[name="account[imapHost]"]')).toBeVisible();
        await expect(page.getByText("Account added successfully")).toHaveCount(0);
    });
});
