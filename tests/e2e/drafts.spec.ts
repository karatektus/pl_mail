import { test, expect, type Page, type Response } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The Drafts list and the compose window that opens out of it.
 *
 * Every draft here is written through the UI — a draft only reaches the list
 * once the autosave has saved it, so each spec types a body past the compose
 * window's min-chars threshold and waits for the POST.
 */
const dock = "#compose_dock";

/** Writes a draft in the floating dock and waits for it to be saved. */
async function composeDraft(page: Page, subject: string): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).click();

    const dockEl = page.locator(dock);
    await dockEl.locator(".ts-control input").first().fill("draftee@example.test");
    await dockEl.locator(".ts-control input").first().press("Enter");
    await dockEl.locator('input[name="compose[subject]"]').fill(subject);
    await dockEl.locator('[data-compose--compose-toolbar-target="editor"]').fill("Draft body");

    await page.waitForResponse((r: Response) =>
        r.url().includes("/compose/draft") && r.request().method() === "POST"
    );
}

// The seeded inbox is read-only here — only the drafts these tests write need
// resetting — so seed-mail runs once and clear-drafts runs per test.
test.beforeAll(() => {
    seed("seed-mail");
});

test.beforeEach(() => {
    // Every test writes a draft through the UI, and those land on the user's
    // default account rather than the one seed-mail owns and wipes. Left in
    // place they accumulate across runs and the subject filters below match
    // several rows at once.
    seed("clear-drafts");
});

test.describe("drafts list", () => {
    test("lists a draft as soon as it is saved", async ({ page }) => {
        await composeDraft(page, "Listed Draft");

        await page.goto("/mail/drafts");

        await expect(
            page.locator("#message-list li").filter({ hasText: "Listed Draft" }),
        ).toBeVisible();
    });

    test("opens a draft row in the compose dock and discards it live", async ({ page }) => {
        await composeDraft(page, "Discarded Draft");

        await page.goto("/mail/drafts");
        const row = page.locator("#message-list li").filter({ hasText: "Discarded Draft" });
        await row.click();

        const dockEl = page.locator(dock);
        await expect(dockEl.locator('input[name="compose[subject]"]')).toHaveValue(
            "Discarded Draft",
        );

        await dockEl.getByRole("button", { name: "Delete draft" }).click();

        // Live: no reload between the discard and the row going.
        await expect(row).toHaveCount(0);

        await page.reload();
        await expect(row).toHaveCount(0);
    });

    /**
     * A draft answering a conversation. The row stands for the whole thread,
     * so it opens the draft rather than the thread only because this is the
     * Drafts list, and discarding takes the row out of this view while leaving
     * the conversation itself alone.
     */
    test("opens a reply draft from its conversation row and discards it live", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await page
            .locator('#compose_inline [data-compose--compose-toolbar-target="editor"]')
            .fill("Reply draft body");
        await page.waitForResponse((r: Response) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/drafts");
        const row = page.locator("#message-list li").filter({ hasText: INBOX_SUBJECTS.read });
        await row.click();

        const dockEl = page.locator(dock);
        await expect(dockEl.locator('[data-compose--compose-toolbar-target="editor"]')).toContainText(
            "Reply draft body",
        );

        await dockEl.getByRole("button", { name: "Delete draft" }).click();
        await expect(row).toHaveCount(0);

        // The conversation is untouched — only its draft is gone.
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
    });

    /**
     * The list preview must show what the message SAYS.
     *
     * `bodyText` is already plain text — plainTextBody() strips the markup and
     * then decodes the entities — so the `|striptags` the row template ran over
     * it a second time was not removing markup, it was removing the user's
     * writing. A body typed literally as `<b>bold</b>` was previewed as "bold",
     * and an `<img …>` typed as text was deleted from the preview entirely, so
     * the list misreported the message. Twig's autoescaping is what makes
     * dropping the filter safe: escaped, the brackets are shown; stripped, they
     * were obeyed.
     */
    test("the list preview shows literal angle brackets rather than eating them", async ({
        page,
    }) => {
        const body = 'literal <b>bold</b> & "quotes" <img src=x> ümlauts äöüß 日本語';

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);
        await dockEl.locator(".ts-control input").first().fill("preview@example.test");
        await dockEl.locator(".ts-control input").first().press("Enter");
        await dockEl.locator('input[name="compose[subject]"]').fill("Preview markup");

        // insertText, not fill(): the body is a contenteditable, and typing is
        // what makes the browser escape the brackets into text the way a real
        // user's keystrokes do.
        await dockEl.locator('[data-compose--compose-toolbar-target="editor"]').click();
        await page.keyboard.insertText(body);

        await page.waitForResponse((r: Response) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/drafts");

        const row = page.locator('li[id^="thread_"]').filter({ hasText: "Preview markup" }).first();

        await expect(row).toContainText("<b>bold</b>");
        await expect(row).toContainText("<img src=x>");
        await expect(row).toContainText("ümlauts äöüß 日本語");
    });
});
