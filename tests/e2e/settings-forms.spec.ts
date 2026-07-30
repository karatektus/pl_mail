import { test, expect } from "@playwright/test";

/**
 * The two settings forms that are Symfony forms rather than hand-written
 * markup: adding a send-as alias, and naming an app password.
 *
 * Both used to read the request bag directly with no CSRF token, so the point
 * of the conversion was the token — and a token is exactly the thing that
 * looks fine in a screenshot and rejects every real submit. These drive the
 * round trip through the browser, which is the only place the token is real.
 *
 * Each test cleans up what it creates: the alias and app-password lists are
 * not reseeded between runs, and leftovers from a previous run are how a green
 * suite starts hiding regressions.
 */
test.describe("app passwords", () => {
    test("generates one, shows the secret once, and revokes it", async ({
        page,
    }) => {
        const name = `E2E Key ${Date.now()}`;

        await page.goto("/settings?section=app-passwords");

        await page.getByPlaceholder(/What is it for/i).fill(name);
        await page.getByRole("button", { name: "Generate" }).click();

        // The secret is handed over exactly once, on this response.
        await expect(page.getByText(/Copy it now|copy/i).first()).toBeVisible();
        const row = page.locator("li").filter({ hasText: name });
        await expect(row).toHaveCount(1);

        page.once("dialog", (dialog) => dialog.accept());
        await row.getByRole("button", { name: "Revoke" }).click();

        await expect(page.locator("li").filter({ hasText: name })).toHaveCount(
            0,
        );
    });

    test("refuses an empty name", async ({ page }) => {
        await page.goto("/settings?section=app-passwords");

        const before = await page.locator("li").count();

        // Browser validation is not in play — the field is not required in the
        // markup, so this reaches the server and comes back as a toast.
        await page.getByRole("button", { name: "Generate" }).click();

        await expect(page.locator("li")).toHaveCount(before);
    });
});

test.describe("aliases", () => {
    test("adds a send-as address and removes it again", async ({ page }) => {
        const address = `alias-${Date.now()}@example.test`;

        await page.goto("/settings?section=aliases");

        const account = page.locator("#settings-alias-list > div > div").first();
        await account.getByPlaceholder(/Add another address/i).fill(address);
        await account.getByRole("button", { name: "Add" }).click();

        await expect(page.getByText(address)).toBeVisible();

        const row = page.locator("li").filter({ hasText: address });
        await row.getByRole("button", { name: "Remove" }).click();

        await expect(page.getByText(address)).toHaveCount(0);
    });

    test("refuses a malformed address", async ({ page }) => {
        await page.goto("/settings?section=aliases");

        const account = page.locator("#settings-alias-list > div > div").first();
        await account
            .getByPlaceholder(/Add another address/i)
            .fill("not-an-address");
        await account.getByRole("button", { name: "Add" }).click();

        await expect(page.getByText("not-an-address")).toHaveCount(0);
    });
});
