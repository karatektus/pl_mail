import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Who a message was addressed to, and getting it onto the clipboard.
 *
 * Three things are pinned here, and each of them was once wrong:
 *
 *   The recipient is readable without opening anything. The trigger used to
 *   name at most one recipient and, whenever the row's to_addresses column was
 *   empty, said only "Details" — which on IMAP was every message ever synced,
 *   because the address attribute was iterated in a way that yields nothing.
 *
 *   An empty recipient list is stated, not omitted. A message really can have
 *   no To: header (a bcc delivery, a list that hides its members), and a
 *   header row that silently drops the line reads as a bug rather than as a
 *   fact about the mail.
 *
 *   The copy buttons write to the clipboard and say that they did. Playwright
 *   grants clipboard permission, and 127.0.0.1 is a secure context, so this
 *   exercises the real navigator.clipboard path rather than the execCommand
 *   fallback behind it.
 */

const DETAILS = '[data-controller="mail--message-details"]';
const PANEL = '[data-mail--message-details-target="panel"]';

test.beforeAll(() => {
    seed("seed-header-cases");
});

test.beforeEach(async ({ page }) => {
    await page.context().grantPermissions(["clipboard-read", "clipboard-write"]);
});

/** Opens a seeded thread by subject and waits for its message header. */
async function openThread(page: Page, subject: string): Promise<void> {
    await page.goto("/mail/inbox");
    await page.locator("#message-list li").filter({ hasText: subject }).first().click();
    await expect(page.locator(DETAILS).first()).toBeVisible();
}

function readClipboard(page: Page): Promise<string> {
    return page.evaluate(() => navigator.clipboard.readText());
}

test.describe("message header recipients", () => {
    test("names the recipient without anything being opened", async ({ page }) => {
        await openThread(page, "E2E Read Me");

        // The summary is the trigger's own label, so it is on screen the
        // moment the message is: no popover, no second click.
        await expect(page.locator(DETAILS).first()).toContainText("to E2E Tester");
        await expect(page.locator(PANEL).first()).toBeHidden();
    });

    /**
     * The row this covers has empty recipient columns and a To: header — the
     * shape every IMAP message synced before the capture fix is in. The header
     * reads the bag rather than announcing that the mail went to nobody.
     */
    test("falls back to the stored headers when the columns are empty", async ({ page }) => {
        await openThread(page, "E2E Header Only");

        const details = page.locator(DETAILS).first();

        // Two named recipients, and the third address (a Cc) counted rather
        // than named.
        await expect(details).toContainText("to Header Only, Second Reader +1");

        await details.getByRole("button", { name: "Details" }).click();
        await expect(details.locator(PANEL)).toContainText("header-only@e2e.test");
        await expect(details.locator(PANEL)).toContainText("copied@e2e.test");

        // Reply-To is stored the way webklex spells it (`reply_to`). The panel
        // used to look only for `reply-to`, so it never showed on IMAP mail.
        await expect(details.locator(PANEL)).toContainText("reply@e2e.test");
    });

    test("states an absent recipient list instead of omitting it", async ({ page }) => {
        await openThread(page, "E2E Undisclosed");

        const details = page.locator(DETAILS).first();

        await expect(details).toContainText("to undisclosed recipients");

        await details.getByRole("button", { name: "Details" }).click();

        // Present as a row, not merely absent: the label and the stated
        // emptiness both.
        const panel = details.locator(PANEL);
        await expect(panel).toBeVisible();
        await expect(panel.locator("dt", { hasText: /^to$/ })).toBeVisible();
        await expect(panel).toContainText("undisclosed recipients");
    });
});

test.describe("message header clipboard", () => {
    test("copies one address, and shows that it did", async ({ page }) => {
        await openThread(page, "E2E Read Me");

        const details = page.locator(DETAILS).first();
        await details.getByRole("button", { name: "Details" }).click();

        const copyFrom = details.getByRole("button", {
            name: "Copy address sender@e2e.test",
        });
        await copyFrom.click();

        expect(await readClipboard(page)).toBe("sender@e2e.test");

        // The visible confirmation: the copy glyph becomes a check mark, and
        // the screen-reader label says so.
        await expect(copyFrom.locator("i")).toHaveClass(/fa-check/);
        await expect(copyFrom).toContainText("Copied");

        // …and puts itself back.
        await expect(copyFrom.locator("i")).toHaveClass(/fa-copy/, { timeout: 5_000 });
    });

    test("copies the whole header block", async ({ page }) => {
        await openThread(page, "E2E Read Me");

        const details = page.locator(DETAILS).first();
        await details.getByRole("button", { name: "Details" }).click();

        await details.getByRole("button", { name: "Copy headers" }).click();

        const copied = await readClipboard(page);

        // The lines the panel itself displays, in its order — not a dump of
        // every header the row happens to store.
        expect(copied).toContain("From: E2E Sender <sender@e2e.test>");
        expect(copied).toMatch(/\nTo: .*E2E Tester/);
        expect(copied).toContain("Subject: E2E Read Me");
        expect(copied).toMatch(/\nDate: /);

        // Escaped once, not twice: the angle brackets are angle brackets.
        expect(copied).not.toContain("&lt;");
    });

    test("copies the stated absence rather than an empty To line", async ({ page }) => {
        await openThread(page, "E2E Undisclosed");

        const details = page.locator(DETAILS).first();
        await details.getByRole("button", { name: "Details" }).click();
        await details.getByRole("button", { name: "Copy headers" }).click();

        expect(await readClipboard(page)).toContain("To: undisclosed recipients");
    });
});
