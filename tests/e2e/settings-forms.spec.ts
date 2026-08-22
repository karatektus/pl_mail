import { test, expect } from "./support/test";
import { acceptConfirm } from "./support/confirm";

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
 * suite starts hiding regressions. Names carry a Date.now() suffix for the same
 * reason — a fixed name accumulates rows until the locator matches several at
 * once and the spec dies on strict mode.
 *
 * The app-password test also absorbed the former app-password.spec.ts, which
 * covered the same lifecycle but additionally pinned the show-once guarantee.
 * Those assertions are kept below; the duplicate file (and its duplicate
 * browser context and colliding describe name) is gone.
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

        const list = page.locator("#settings-app-password-list");
        await expect(list.locator("code").first()).toHaveText(
            /^plmail_[0-9a-f]{64}$/,
        );

        const row = page.locator("li").filter({ hasText: name });
        await expect(row).toHaveCount(1);

        // Show-once is the whole security property, so it is asserted rather
        // than assumed: after a reload the row survives, the plaintext is gone
        // for good, and only the masked hint is left. Nothing else in the suite
        // covers this.
        await page.reload();

        const reloaded = page.locator("#settings-app-password-list");
        // Scoped to the row this test created, not to the list. The masked hint
        // has the same shape for every app password ever generated, so a
        // list-wide match is a strict-mode violation the moment a previous run
        // leaves one behind — which is a failure about the fixtures rather than
        // about show-once, and it reads as this feature being broken.
        const own = reloaded.locator("li").filter({ hasText: name });

        await expect(own).toHaveCount(1);
        await expect(own.getByText(/^plmail_[0-9a-f]{64}$/)).toHaveCount(0);
        await expect(own.getByText(/^plmail_[0-9a-f]{6}…$/)).toBeVisible();

        await reloaded
            .locator("li")
            .filter({ hasText: name })
            .getByRole("button", { name: "Revoke" })
            .click();
        await acceptConfirm(page);

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
