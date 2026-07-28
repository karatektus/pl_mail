import { test, expect } from "@playwright/test";
import { mailRow } from "./support/config";

test("make a reply draft inside a conversation", async ({ page }) => {
    await page.goto("/mail/inbox");
    await mailRow(page, "E2E Read Me").click();

    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await page
        .locator('#compose_inline [data-compose-toolbar-target="editor"]')
        .fill("Reply draft body");

    await page.waitForResponse((r) =>
        r.url().includes("/compose/draft") && r.request().method() === "POST"
    );
});

test("conversation row in Drafts opens the draft and discards live", async ({ page }) => {
    await page.goto("/mail/drafts");

    const row = page.locator("#message-list li").filter({ hasText: "E2E Read Me" });
    await expect(row).toBeVisible();

    await row.click();

    // draft_scope: the row opens the draft in the dock, not the thread.
    const dockEl = page.locator("#compose_dock");
    await expect(dockEl.locator('[data-compose-toolbar-target="editor"]')).toContainText(
        "Reply draft body",
    );

    await dockEl.getByRole("button", { name: "Delete draft" }).click();

    // The thread lives on, but it has no draft left, so it leaves this view.
    await expect(row).toHaveCount(0);
});
