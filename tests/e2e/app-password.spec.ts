import { test, expect } from "@playwright/test";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Covers the whole app-password lifecycle from the settings UI: generate,
 * see the secret exactly once, confirm it is not recoverable on reload, and
 * revoke.
 */
const SETTINGS_URL = "/settings?section=app-passwords";
const TOKEN_NAME = "E2E Client";

test.describe("app passwords", () => {
    test("generates a password, shows it once, then revokes it", async ({
        page,
    }) => {
        await page.goto(SETTINGS_URL);

        const list = page.locator("#settings-app-password-list");
        await expect(list).toBeVisible();

        // ── Generate ──────────────────────────────────────────────────────
        await list.getByPlaceholder(/What is it for/i).fill(TOKEN_NAME);
        await list.getByRole("button", { name: "Generate" }).click();

        // The plaintext secret is shown exactly once, on this response only.
        const secret = list.locator("code").first();
        await expect(secret).toBeVisible();
        await expect(secret).toHaveText(/^plmail_[0-9a-f]{64}$/);

        await expect(list.getByText(TOKEN_NAME)).toBeVisible();

        // ── Not recoverable ───────────────────────────────────────────────
        // After a reload the row remains but the secret is gone for good;
        // only the masked hint is left.
        await page.reload();

        const reloaded = page.locator("#settings-app-password-list");
        await expect(reloaded.getByText(TOKEN_NAME)).toBeVisible();
        await expect(reloaded.getByText(/^plmail_[0-9a-f]{64}$/)).toHaveCount(0);
        await expect(reloaded.getByText(/^plmail_[0-9a-f]{6}…$/)).toBeVisible();

        // ── Revoke ────────────────────────────────────────────────────────
        page.once("dialog", (dialog) => dialog.accept());

        const row = reloaded.locator("li").filter({ hasText: TOKEN_NAME });
        await row.getByRole("button", { name: "Revoke" }).click();

        await expect(
            page.locator("#settings-app-password-list").getByText(TOKEN_NAME),
        ).toHaveCount(0);
    });
});
