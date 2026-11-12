import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { acceptConfirm } from "./support/confirm";

/**
 * Mail, calendar and contacts switched on and off per account (#575), in a
 * real browser.
 *
 * What the switches do on the server side is in phpunit (AccountServicesTest).
 * What needs a browser:
 *
 *   - the switches open in a modal and are reachable at all. The first version
 *     hung them from a menu on the row, and the account list's overflow-hidden
 *     card cut that off above a single account.
 *   - switching streams the list back: the row says what the account is used
 *     for without a reload.
 *   - an account with mail off and no mail leaves the mail sidebar, and comes
 *     back when mail is switched on again.
 *   - "Remove synced mail from plMail" is offered while mail is off, and not
 *     while it is on.
 */

async function addAccount(page: Page, label: string, stamp: number): Promise<void> {
    await page.goto("/settings/accounts");

    await page
        .locator("section")
        .filter({ has: page.locator("#settings-account-list") })
        .getByRole("button", { name: "Add account" })
        .click();

    const imapHost = page.locator('input[name="account[imapHost]"]');
    await expect(imapHost).toBeVisible();

    await page.locator('input[name="account[email]"]').fill(label);
    await page.locator('input[name="account[username]"]').fill(`services-${stamp}@example.test`);
    await page.locator('input[name="account[password]"]').fill("hunter2");
    await imapHost.fill(`services-${stamp}.example.test`);
    await page.locator('input[name="account[imapPort]"]').fill("993");
    await page.locator('select[name="account[imapEncryption]"]').selectOption("ssl");
    await page.locator("#modal button[type=submit]").click();

    // Not the 5s default, for the reason account-colour.spec.ts gives.
    await expect(page.getByText("Account added successfully")).toBeVisible({ timeout: 20_000 });
}

async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings/accounts");

    const row = page.locator("#settings-account-list li").filter({ hasText: new RegExp(label, "i") });

    for (let remaining = await row.count(); remaining > 0; remaining -= 1) {
        await row.getByRole("button", { name: "Remove account" }).first().click();
        await acceptConfirm(page);
        await expect(row).toHaveCount(remaining - 1);
    }
}

test.describe("account services", () => {
    test("mail switched off leaves calendar and contacts on, and comes back on", async ({ page }) => {
        test.setTimeout(90_000);

        const stamp = Date.now();
        const label = `E2E Services ${stamp}`;

        try {
            await addAccount(page, label, stamp);
            await page.goto("/settings/accounts");

            const row = page.locator("#settings-account-list li").filter({ hasText: new RegExp(label, "i") });
            const open = row.getByRole("button", { name: /Mail, calendar and contacts for/ });

            await open.click();

            const modal = page.locator("#modal");
            const mail = modal.getByRole("switch", { name: /Switch off Mail for/ });
            await expect(mail).toBeVisible();
            await expect(mail).toHaveAttribute("aria-checked", "true");
            await expect(modal.getByRole("button", { name: "Remove synced mail from plMail" })).toHaveCount(0);

            await mail.click();
            await expect(page.getByText("Mail switched off. What is already here stays.")).toBeVisible();

            // Streamed back, no reload: the row now says what it is used for.
            await expect(row.locator("[data-account-services]")).toHaveText(/Used for Calendar, Contacts/);

            // A new account holds no mail, so with mail off it has no place in
            // the mail sidebar.
            await page.goto("/mail/inbox");
            await expect(page.locator("#account-list")).not.toContainText(new RegExp(label, "i"));

            await page.goto("/settings/accounts");
            await open.click();
            await expect(modal.getByRole("button", { name: "Remove synced mail from plMail" })).toBeVisible();

            await modal.getByRole("switch", { name: /Switch on Mail for/ }).click();
            await expect(page.getByText("Mail switched on")).toBeVisible();
            await expect(row.locator("[data-account-services]")).toHaveCount(0);

            await page.goto("/mail/inbox");
            await expect(page.locator("#account-list")).toContainText(new RegExp(label, "i"));
        } finally {
            await removeAccount(page, label);
        }
    });
});
