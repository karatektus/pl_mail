import { test, expect } from "./support/test";
import { type Page } from "@playwright/test";
import { acceptConfirm } from "./support/confirm";

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
            .getByRole("button", { name: "Folders", exact: true })
            .click();

        const modal = page.locator("#modal-backdrop");

        await expect(modal).toContainText(`Folders — ${ACCOUNT}`);
        await expect(modal).toContainText("Switch a folder off and plMail stops fetching it");
        await expect(modal).toContainText("No folders yet");

        await modal.getByRole("button", { name: "Close" }).click();

        await expect(modal).toBeHidden();
    });

    /**
     * New folder (#221) as far as a browser can take it without a mail server:
     * the form opens inside the modal, and a name with the server's separator
     * in it is refused there, with the reason, before any server is asked —
     * which is the only reason this works against an account whose host does
     * not exist. The modal stays open on the form, keeping what was typed.
     */
    test("refuses a folder name with the separator in it, inside the modal", async ({ page }) => {
        await page.goto("/settings?section=accounts");

        await page
            .locator("#settings-account-list li")
            .filter({ hasText: ACCOUNT })
            .getByRole("button", { name: "Folders", exact: true })
            .click();

        const modal = page.locator("#modal-backdrop");

        await modal.getByRole("link", { name: "New folder" }).click();
        await expect(modal).toContainText(`New folder — ${ACCOUNT}`);

        await modal.getByLabel("Name").fill("v1/2");
        await modal.getByRole("button", { name: "Create folder" }).click();

        await expect(modal.locator("[data-folder-error]")).toContainText("cannot contain “/”");
        await expect(modal.getByLabel("Name")).toHaveValue("v1/2");
    });

    /**
     * Labels as IMAP keywords (#221 step 2): off by default, switched in the
     * folder list, and with the server not asked yet (this account's host does
     * not exist) the list says so rather than claiming either answer.
     */
    test("switches labels as keywords on and off in the folder list", async ({ page }) => {
        await page.goto("/settings?section=accounts");

        await page
            .locator("#settings-account-list li")
            .filter({ hasText: ACCOUNT })
            .getByRole("button", { name: "Folders", exact: true })
            .click();

        const modal = page.locator("#modal-backdrop");
        const toggle = modal.getByRole("switch", { name: "Labels as keywords" });

        await expect(toggle).toHaveAttribute("aria-checked", "false");
        await toggle.click();
        await expect(toggle).toHaveAttribute("aria-checked", "true");
        await expect(modal.locator("[data-folder-keywords-state]")).toContainText("Not asked yet");

        await toggle.click();
        await expect(toggle).toHaveAttribute("aria-checked", "false");
    });

    /**
     * The same dialog from the sidebar: the account's ⋯ menu offers New
     * folder… as words, and it opens the form for exactly that account. The
     * button is there without a hover, which is what makes it reachable on a
     * phone at all.
     */
    test("opens New folder from the account's menu in the sidebar", async ({ page }) => {
        await page.goto("/mail/inbox");

        const menu = page.getByRole("button", { name: `Folders of ${ACCOUNT}` }).first();

        await expect(menu).toBeVisible();
        await menu.click();
        await page.getByRole("menuitem", { name: "New folder…" }).first().click();

        const modal = page.locator("#modal-backdrop");

        await expect(modal).toContainText(`New folder — ${ACCOUNT}`);
        await expect(modal.getByLabel("Name")).toBeVisible();
    });

    /**
     * With two IMAP accounts, one account's menu covers the row below it, and
     * that row must not show through or beside it: the menu spans its own
     * row's width, is opaque, sits above the next row's ⋯, and is named for
     * its account (found by that name above). Opening the other account's ⋯ closes the first; Escape and a
     * click elsewhere close it too.
     */
    test("an account menu covers the next account's row and closes cleanly", async ({ page }) => {
        const stamp = Date.now();
        const second = `Second ${stamp}`;

        await addAccount(page, second, stamp);

        try {
            await page.goto("/mail/inbox");

            const first = page.getByRole("button", { name: `Folders of ${ACCOUNT}` }).first();
            const other = page.getByRole("button", { name: `Folders of ${second}` }).first();
            const firstMenu = page.getByRole("menu", { name: `Folders of ${ACCOUNT}` }).first();
            const otherMenu = page.getByRole("menu", { name: `Folders of ${second}` }).first();

            await first.click();
            await expect(firstMenu).toBeVisible();

            // As wide as the account row it hangs from, and on top of whatever
            // is under its middle — never the other account's ⋯.
            const row = await first.locator("xpath=ancestor::div[contains(@class,'nav-item')][1]").boundingBox();
            const box = await firstMenu.boundingBox();
            expect(Math.abs((box?.width ?? 0) - (row?.width ?? 0))).toBeLessThan(2);

            const otherBox = await other.boundingBox();
            if (otherBox && box && otherBox.y < box.y + box.height && otherBox.y + otherBox.height > box.y) {
                const onTop = await page.evaluate(
                    ([x, y]) => document.elementFromPoint(x, y)?.closest('[role="menu"]')?.getAttribute("aria-label") ?? null,
                    [otherBox.x + otherBox.width / 2, otherBox.y + otherBox.height / 2],
                );
                expect(onTop).toBe(`Folders of ${ACCOUNT}`);
            }

            await page.keyboard.press("Escape");
            await expect(firstMenu).toBeHidden();

            await first.click();
            await expect(firstMenu).toBeVisible();
            await page.locator("main, #main, body").first().click({ position: { x: 900, y: 500 } });
            await expect(firstMenu).toBeHidden();

            await first.click();
            await page.keyboard.press("Escape");
            await other.click();
            await expect(otherMenu).toBeVisible();
            await first.click();
            await expect(firstMenu).toBeVisible();
            await expect(otherMenu).toBeHidden();
        } finally {
            await removeAccount(page, second);
        }
    });
});

/** Adds a password IMAP account through the settings modal, as accounts-sidebar.spec.ts does. */
async function addAccount(page: Page, label: string, stamp: number): Promise<void> {
    await page.goto("/settings?section=accounts");

    await page
        .locator("section")
        .filter({ has: page.locator("#settings-account-list") })
        .getByRole("button", { name: "Add account" })
        .click();

    const imapHost = page.locator('input[name="account[imapHost]"]');
    await expect(imapHost).toBeVisible();

    await page.locator('input[name="account[email]"]').fill(label);
    await page.locator('input[name="account[username]"]').fill(`folders-${stamp}@example.test`);
    await page.locator('input[name="account[password]"]').fill("hunter2");
    await imapHost.fill(`folders-${stamp}.example.test`);
    await page.locator('input[name="account[imapPort]"]').fill("993");
    await page.locator('select[name="account[imapEncryption]"]').selectOption("ssl");
    await page.locator("#modal button[type=submit]").click();

    await expect(page.getByText("Account added successfully")).toBeVisible();
}

async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings?section=accounts");

    const row = page.locator("#settings-account-list li").filter({ hasText: new RegExp(label, "i") });

    for (let remaining = await row.count(); remaining > 0; remaining -= 1) {
        await row.getByRole("button", { name: "Remove account" }).first().click();
        await acceptConfirm(page);
        await expect(row).toHaveCount(remaining - 1);
    }
}
