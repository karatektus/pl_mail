import { test, expect, type Page } from "./support/test";

/**
 * Runs authenticated as this worker's own user, signed in by the worker
 * fixture in support/test.ts.
 *
 * Adding a password (IMAP) account only persists the row — no IMAP
 * connection is attempted at creation time — so a fake host is fine and the
 * test needs no mail server.
 *
 * The account is REMOVED again when the test is done, and that is not
 * housekeeping — it is what keeps the rest of the suite honest. A mail account
 * is durable, user-wide state, and several sections count accounts rather than
 * naming one:
 *
 *   • settings/_signature.html.twig renders one editor per account, so a
 *     leaked account turned "the Signatures section shows one editor" into
 *     "…shows two", and settings-signature.spec.ts failed with no change to
 *     the signature code at all.
 *   • the compose From dropdown lists every account's addresses, so
 *     compose-signature-and-options.spec.ts — which switches to the LAST
 *     option and expects the signature to follow — switched to a leaked
 *     account that had no signature, and found none.
 *
 * Both looked like product bugs in code this file never touches, and both only
 * appeared when this spec happened to land on a worker before them. Hence the
 * cleanup, and hence it runs in a `finally`: a test that fails half way
 * through has still created the row, and must not hand the mess to the next
 * file on this worker.
 */

/** Drop an account created by this spec, by the label it was given. */
async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings?section=accounts");

    // Addresses are normalised to lowercase on save, so match the label the
    // same case-insensitive way the creation test asserts it.
    const row = page
        .locator("#settings-account-list li")
        .filter({ hasText: new RegExp(label, "i") });

    if (0 === (await row.count())) {
        return;
    }

    // data-turbo-confirm surfaces as a native dialog.
    page.once("dialog", (dialog) => dialog.accept());
    await row.getByRole("button", { name: "Remove account" }).first().click();

    await expect(row).toHaveCount(0);
}

test.describe("mail account creation", () => {
    test("adds an IMAP account from the settings modal", async ({ page }) => {
        // Unique per run so re-runs against a non-fresh DB stay unambiguous.
        const stamp = Date.now();
        const label = `E2E IMAP ${stamp}`;
        const username = `imap-${stamp}@example.test`;
        const host = `imap-${stamp}.example.test`;

        try {
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
            await expect(
                page.getByText("Account added successfully"),
            ).toBeVisible();

            const list = page.locator("#settings-account-list");
            // The list renders the account's primary alias, and addresses are
            // normalised to lowercase on save — so match case-insensitively rather
            // than asserting the casing that happened to be typed.
            await expect(list).toContainText(new RegExp(label, "i"));
            await expect(list).toContainText(host);
        } finally {
            await removeAccount(page, label);
        }
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
