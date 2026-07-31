import { test, expect, type Page, type Response } from "@playwright/test";
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

test.beforeEach(() => {
    // clear-drafts as well as seed-mail: these drafts are written through the
    // UI, so they land on the user's default account rather than the one
    // seed-mail owns and wipes. Left in place they accumulate across runs and
    // the subject filters below match several rows at once.
    seed("seed-mail", "clear-drafts");
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
});
