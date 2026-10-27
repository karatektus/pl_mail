import { test, expect, type Page } from "./support/test";
import { acceptConfirm } from "./support/confirm";

/**
 * The account form's protocol choice (#289).
 *
 * Only the browser shows the form changing under the toggle: the section
 * heading names the protocol, the port moves to where that protocol starts
 * unless somebody typed one, the
 * delete option appears for POP3 alone, and a provider preset fills the
 * chosen protocol's server — or, for a provider with no POP3 server, puts the
 * radio back on IMAP and disables POP3 until the provider is cleared. A JMAP test does the same
 * for JMAP, and shows SMTP folding away under it. The last test
 * saves a POP3 account and switches it to IMAP on the edit form, which is the
 * path IncomingProtocolSwitchTest covers on the server side.
 *
 * Like account.spec.ts, saving only writes the row, so a fake host is fine,
 * and the account is removed in a `finally` so no other spec counts it.
 */

const HOST = 'input[name="account[imapHost]"]';
const PORT = 'input[name="account[imapPort]"]';
const DELETE_MODE = 'select[name="account[pop3DeleteMode]"]';

async function openAddForm(page: Page): Promise<void> {
    await page.goto("/settings?section=accounts");

    await page
        .locator("section")
        .filter({ has: page.locator("#settings-account-list") })
        .getByRole("button", { name: "Add account" })
        .click();

    await expect(page.locator(HOST)).toBeVisible();
}

/** The segment of the protocol toggle; the radio behind it is visually hidden. */
async function chooseProtocol(page: Page, name: "IMAP" | "POP3" | "JMAP"): Promise<void> {
    await page.locator("#modal").getByRole("radiogroup").getByText(name, { exact: true }).click();
}

/**
 * The delete mode is a TomSelect-enhanced select, picked as a person picks it.
 *
 * By the combobox's accessible name rather than support/select.ts's
 * getByLabel: this field is required, so its label reads "After fetching *"
 * and an exact label match finds nothing. And the open and the pick are
 * retried together rather than clicked once: when the widget already has
 * focus its panel is open, and a click closes it again. Retrying only the
 * open was not enough — on the v0.4.0 release PR's run the panel read as open,
 * shut before the option was clicked, and that click waited out the test.
 */
async function chooseDeleteMode(page: Page, option: string): Promise<void> {
    const control = page.locator("#modal").getByRole("combobox", { name: "After fetching", exact: true });

    await expect(async () => {
        if ((await control.getAttribute("aria-expanded")) !== "true") {
            await control.click();
        }

        const listbox = await control.getAttribute("aria-controls");

        await page.locator(`[id="${listbox}"]`).getByRole("option", { name: option, exact: true }).click({ timeout: 1000 });
        await expect(control).toHaveText(option, { timeout: 1000 });
    }).toPass({ timeout: 15_000 });
}

async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings?section=accounts");

    const row = page.locator("#settings-account-list li").filter({ hasText: new RegExp(label, "i") });

    if (0 === (await row.count())) {
        return;
    }

    await row.getByRole("button", { name: "Remove account" }).first().click();
    await acceptConfirm(page);

    await expect(row).toHaveCount(0);
}

test.describe("account form — incoming protocol", () => {
    test("POP3 renames the section, moves the port and shows what happens to the server's copy", async ({ page }) => {
        await openAddForm(page);

        const modal = page.locator("#modal");

        await expect(modal.getByText("Incoming — IMAP")).toBeVisible();
        await expect(page.locator(PORT)).toHaveValue("993");
        await expect(page.locator(`${DELETE_MODE} + .ts-wrapper`)).toBeHidden();

        await chooseProtocol(page, "POP3");

        await expect(modal.getByText("Incoming — POP3")).toBeVisible();
        await expect(page.locator(PORT)).toHaveValue("995", { timeout: 2000 });
        await expect(page.locator(HOST)).toHaveAttribute("placeholder", "pop.example.com");
        await expect(page.locator(`${DELETE_MODE} + .ts-wrapper`)).toBeVisible();
        await expect(page.locator(DELETE_MODE)).toHaveValue("keep");

        // A provider with a POP3 server fills it, and POP3 stays chosen.
        const preset = page.locator('select[name="account[preset]"]');
        await preset.selectOption("gmx");

        await expect(modal.getByLabel("POP3", { exact: true })).toBeChecked();
        await expect(page.locator(HOST)).toHaveValue("pop.gmx.net");
        await expect(page.locator(PORT)).toHaveValue("995");

        // One without — iCloud has none — puts the radio back on IMAP, and
        // POP3 cannot be chosen while it is the provider.
        await preset.selectOption("icloud");

        await expect(modal.getByLabel("IMAP", { exact: true })).toBeChecked();
        await expect(modal.getByLabel("POP3", { exact: true })).toBeDisabled();
        await expect(modal.getByText("Incoming — IMAP")).toBeVisible();
        await expect(page.locator(HOST)).toHaveValue("imap.mail.me.com");
        await expect(page.locator(`${DELETE_MODE} + .ts-wrapper`)).toBeHidden();

        // A provider that has POP3 gives it back.
        await preset.selectOption("gmx");
        await expect(modal.getByLabel("POP3", { exact: true })).toBeEnabled();
    });

    test("JMAP fills a provider's JMAP server, and SMTP folds away until it is asked for", async ({ page }) => {
        await openAddForm(page);

        const modal = page.locator("#modal");

        // A port somebody typed is theirs: switching protocol leaves it.
        await page.locator(PORT).fill("110");
        await chooseProtocol(page, "POP3");
        await expect(page.locator(PORT)).toHaveValue("110");
        await page.locator(PORT).fill("");

        const smtpHost = page.locator('input[name="account[smtpHost]"]');

        await chooseProtocol(page, "JMAP");

        await expect(modal.getByText("Outgoing — JMAP")).toBeVisible();
        await expect(smtpHost).toBeHidden();

        // JMAP is HTTPS, so there is no encryption to choose, and the session
        // URL waits until it is asked for.
        const encryption = page.locator('select[name="account[imapEncryption]"]');
        const sessionUrl = page.locator('input[name="account[jmapSessionUrl]"]');

        await expect(encryption).toBeHidden();
        await expect(sessionUrl).toBeHidden();
        await modal.getByRole("button", { name: "My server's JMAP session is somewhere else" }).click();
        await expect(sessionUrl).toBeVisible();

        await page.locator('select[name="account[preset]"]').selectOption("fastmail");

        await expect(modal.getByLabel("JMAP", { exact: true })).toBeChecked();
        await expect(page.locator(HOST)).toHaveValue("api.fastmail.com");
        await expect(page.locator(PORT)).toHaveValue("443");
        await expect(sessionUrl).toHaveValue("https://api.fastmail.com/jmap/session");
        await expect(page.locator('input[name="account[jmapTokenAuth]"]')).toBeChecked();
        await expect(smtpHost).toBeHidden();
        await expect(smtpHost).toHaveValue("");

        await modal.getByRole("button", { name: "My server does not send over JMAP — use SMTP" }).click();
        await expect(smtpHost).toBeVisible();

        // Back to IMAP: the provider's IMAP server, and its SMTP beside it.
        await chooseProtocol(page, "IMAP");

        await expect(modal.getByText("Outgoing — SMTP")).toBeVisible();
        await expect(page.locator(HOST)).toHaveValue("imap.fastmail.com");
        await expect(page.locator(PORT)).toHaveValue("993");
        await expect(smtpHost).toHaveValue("smtp.fastmail.com");
    });

    test("the plMail preset is JMAP with the host left to fill in", async ({ page }) => {
        await openAddForm(page);

        const modal = page.locator("#modal");

        // From IMAP: plMail has no IMAP, so choosing it is choosing JMAP.
        await page.locator('select[name="account[preset]"]').selectOption("plmail");

        await expect(modal.getByLabel("JMAP", { exact: true })).toBeChecked();
        await expect(modal.getByLabel("IMAP", { exact: true })).toBeDisabled();
        await expect(modal.getByLabel("POP3", { exact: true })).toBeDisabled();
        await expect(page.locator(HOST)).toHaveValue("");
        await expect(page.locator(PORT)).toHaveValue("443");
        await expect(page.locator('input[name="account[jmapTokenAuth]"]')).not.toBeChecked();
        await expect(page.locator('input[name="account[jmapSessionUrl]"]')).toBeHidden();
        await expect(page.locator('input[name="account[smtpHost]"]')).toBeHidden();
        await expect(modal.getByText("Another plMail: its host name")).toBeVisible();
    });

    test("a POP3 account saves its delete mode and can be switched to IMAP", async ({ page }) => {
        const stamp = Date.now();
        const label = `E2E POP3 ${stamp}`;

        try {
            await openAddForm(page);

            const modal = page.locator("#modal");

            await page.locator('input[name="account[email]"]').fill(label);
            await page.locator('input[name="account[username]"]').fill(`pop3-${stamp}@example.test`);
            await page.locator('input[name="account[password]"]').fill("hunter2");
            await chooseProtocol(page, "POP3");
            await page.locator(HOST).fill(`pop-${stamp}.example.test`);
            await page.locator(PORT).fill("995");
            await page.locator('select[name="account[imapEncryption]"]').selectOption("ssl");
            await chooseDeleteMode(page, "Delete it from the server");

            await modal.locator('button[type="submit"]').click();
            await expect(page.getByText("Account added successfully")).toBeVisible();

            const row = page.locator("#settings-account-list li").filter({ hasText: new RegExp(label, "i") });
            await row.locator('[data-ui--modal-src-value$="/edit"]').first().click();

            await expect(modal.getByLabel("POP3", { exact: true })).toBeChecked();
            await expect(modal.getByText("Incoming — POP3")).toBeVisible();
            await expect(page.locator(DELETE_MODE)).toHaveValue("delete");

            await chooseProtocol(page, "IMAP");
            await expect(page.locator(PORT)).toHaveValue("993");
            await modal.locator('button[type="submit"]').click();
            await expect(page.locator("#modal " + HOST)).toHaveCount(0);

            await row.locator('[data-ui--modal-src-value$="/edit"]').first().click();
            await expect(modal.getByLabel("IMAP", { exact: true })).toBeChecked();
            await expect(modal.getByText("Incoming — IMAP")).toBeVisible();
        } finally {
            await removeAccount(page, label);
        }
    });
});
