import { test, expect, type Page } from "./support/test";
import { acceptConfirm } from "./support/confirm";

/**
 * The account form's protocol choice (#289).
 *
 * Only the browser shows the form changing under the toggle: the section
 * heading names the protocol, the port moves to where that protocol starts
 * unless somebody typed one, the
 * delete option appears for POP3 alone, and choosing a provider preset — all
 * of which are IMAP servers — puts the radio back on IMAP. The second test
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
async function chooseProtocol(page: Page, name: "IMAP" | "POP3"): Promise<void> {
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

        // Every preset is an IMAP server; choosing one is choosing IMAP.
        const preset = page.locator('select[name="account[preset]"]');
        const first = await preset.locator("option").nth(1).getAttribute("value");
        await preset.selectOption(first as string);

        await expect(modal.getByLabel("IMAP", { exact: true })).toBeChecked();
        await expect(modal.getByText("Incoming — IMAP")).toBeVisible();
        await expect(page.locator(`${DELETE_MODE} + .ts-wrapper`)).toBeHidden();

        // A port somebody typed is theirs: switching protocol leaves it.
        await page.locator(PORT).fill("110");
        await chooseProtocol(page, "POP3");
        await expect(page.locator(PORT)).toHaveValue("110");
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
