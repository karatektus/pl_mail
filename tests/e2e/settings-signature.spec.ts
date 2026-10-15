import { expect } from "@playwright/test";
import { test } from "./support/test";

/**
 * The Signatures section, from the browser's side.
 *
 * The bug this section was rebuilt for could only be seen in a browser: an
 * alias that inherited the account signature rendered a full editor which
 * LOOKED live — the markup's `data-signature-disabled` hook matched no CSS
 * rule, and the greying was applied only by the inherit checkbox's change
 * handler — so the field went grey after an uncheck/check and not before. The
 * PHPUnit suite was green throughout, because it asserted the attribute the
 * markup did set (`contenteditable="false"`) and nothing asserted what the
 * user saw.
 *
 * So the assertions here are about what is on screen and how much of it there
 * is: no editor for an inheriting address, one editor for an account that has
 * not asked for more, and the per-address list folded away until it holds
 * something.
 */

const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

/**
 * The block for ONE account, which is the unit every count here is about.
 *
 * The section renders one of these per account (settings/_signature.html.twig
 * loops `manageableAccounts`), so counting editors across the whole section
 * counts accounts as well as overrides. This spec means "the account I am
 * looking at shows one editor" and used to say "the page shows one editor" —
 * true only while the user owns exactly one account, which is a fact no test
 * here establishes. account.spec.ts adds a second one, and when it landed on
 * this worker first the count came back 2 (or 3, once a re-run against a warm
 * database had left two behind) and this spec failed pointing at signature
 * code it had not touched.
 *
 * Scoped to `.first()` because addAlias() below adds to the first account too,
 * so the alias assertions and the editor counts are about the same account.
 */
function accountBlock(page: import("@playwright/test").Page) {
    return page.locator("#settings-signature > div > div").first();
}

/** Give the account an alias to override, through the aliases panel. */
async function addAlias(page: import("@playwright/test").Page): Promise<string> {
    const address = `sig-${Date.now()}@example.test`;

    await page.goto("/settings?section=aliases");

    const account = page.locator("#settings-alias-list > div > div").first();
    await account.getByPlaceholder(/Add another address/i).fill(address);
    await account.getByRole("button", { name: "Add" }).click();

    await expect(page.getByText(address)).toBeVisible();

    return address;
}

async function removeAlias(
    page: import("@playwright/test").Page,
    address: string,
): Promise<void> {
    await page.goto("/settings?section=aliases");

    // Scoped to the alias list itself: the address also appears in the
    // read-receipt rows further down the same page, so an unscoped lookup
    // never reaches zero and the cleanup fails a test that passed.
    const row = page
        .locator("#settings-alias-list li")
        .filter({ hasText: address });

    if (0 === (await row.count())) {
        return;
    }

    await row.getByRole("button", { name: "Remove" }).first().click();
    await expect(row).toHaveCount(0);
}

/**
 * Take the account's own signature away again.
 *
 * The worker's user is shared by every spec that runs on that worker, and a
 * signature left behind is in every compose window they open — which is how a
 * test here once made compose-formatting.spec.ts's exact-text assertion fail
 * whenever the two happened to share a worker.
 */
async function clearSignature(page: import("@playwright/test").Page): Promise<void> {
    await page.goto("/settings?section=signature");

    const block = accountBlock(page);
    const source = block.getByRole("textbox", { name: /as HTML/ }).first();

    await block.getByRole("button", { name: "HTML", exact: true }).first().click();
    await source.fill("");
    await block.getByRole("button", { name: "Save signature" }).first().click();
    await expect(page.getByText("Saved.")).toBeVisible();
}

test.describe("signature settings", () => {
    test.afterEach(async ({ page }) => {
        await clearSignature(page);
    });

    test("is its own section, reachable from the nav", async ({ page }) => {
        await page.goto("/settings?section=accounts");

        await page.getByRole("link", { name: "Signatures" }).click();

        await expect(page).toHaveURL(/section=signature/);
        await expect(
            page.getByRole("heading", { name: "Signatures" }),
        ).toBeVisible();
        await expect(accountBlock(page).locator(EDITOR)).toHaveCount(1);
    });

    /**
     * The whole point of the overhaul: an account with an alias that has no
     * signature of its own shows ONE editor and one folded disclosure, and the
     * alias's editor is not merely hidden — it is not in the document.
     */
    test("an inheriting address gets one line and no editor", async ({
        page,
    }) => {
        const address = await addAlias(page);

        try {
            await page.goto("/settings?section=signature");

            // One editor on this account — its own, and none for the alias.
            await expect(accountBlock(page).locator(EDITOR)).toHaveCount(1);

            const details = page.locator("#settings-signature details").first();

            // Folded, because nothing overrides yet.
            await expect(details).not.toHaveAttribute("open", /.*/);

            await details.locator("summary").click();

            const row = page
                .locator("[data-signature-alias]")
                .filter({ hasText: address });

            await expect(row).toHaveAttribute(
                "data-signature-state",
                "inherits",
            );
            await expect(row).toContainText("Uses the account signature");

            // Gone, not greyed. Neither an editor nor a disabled one.
            await expect(row.locator("[contenteditable]")).toHaveCount(0);
            await expect(
                page.locator('#settings-signature [contenteditable="false"]'),
            ).toHaveCount(0);
        } finally {
            await removeAlias(page, address);
        }
    });

    /**
     * A signature that already exists as HTML: pasted as source, rendered by
     * the server, and saved as what was shown.
     *
     * In a browser because the claim is about what is on screen. The rendered
     * view has to be the sanitised signature and not the browser's own reading
     * of the source — the `<style>` rule below would turn the text red if the
     * source were simply dropped into the page — and the line under the editor
     * has to name what was taken out.
     */
    test("HTML pasted as source is rendered as it will be saved", async ({
        page,
    }) => {
        await page.goto("/settings?section=signature");

        const block = accountBlock(page);
        const editor = block.locator(EDITOR).first();
        const source = block.getByRole("textbox", { name: /as HTML/ }).first();
        const stamp = `Ada ${Date.now()}`;

        await expect(source).toBeHidden();

        await block.getByRole("button", { name: "HTML", exact: true }).first().click();

        await expect(source).toBeVisible();
        await expect(editor).toBeHidden();

        await source.fill(
            `<style>.sig { color: rgb(255, 0, 0) }</style><p class="sig"><b>${stamp}</b></p><script>window.pwned = true</script>`,
        );

        await block.getByRole("button", { name: "Rendered", exact: true }).first().click();

        await expect(editor).toBeVisible();
        await expect(source).toBeHidden();
        await expect(editor.locator("b")).toHaveText(stamp);
        await expect(editor.locator("style, script")).toHaveCount(0);
        await expect(editor.locator("p")).not.toHaveCSS("color", "rgb(255, 0, 0)");
        await expect(block.getByRole("status").first()).toContainText("<script>, <style>, class");
        expect(await page.evaluate(() => (window as any).pwned)).toBeUndefined();

        // Back in the source view, the source is the cleaned one.
        await block.getByRole("button", { name: "HTML", exact: true }).first().click();
        await expect(source).toHaveValue(`<p><b>${stamp}</b></p>`);

        // Saving from the source view stores what was typed, cleaned the same way.
        await source.fill(`<p><i>${stamp}</i></p><script>window.pwned = true</script>`);
        await block.getByRole("button", { name: "Save signature" }).first().click();

        await expect(page.getByText("Saved.")).toBeVisible();

        await page.goto("/settings?section=signature");
        await expect(accountBlock(page).locator(EDITOR).first().locator("i")).toHaveText(stamp);
        await expect(accountBlock(page).locator(EDITOR).first().locator("script")).toHaveCount(0);
    });

    /**
     * Overriding reveals a usable editor on the FIRST render — no toggle
     * round trip — and reverting takes the row back to one line.
     */
    test("overriding reveals a live editor, reverting takes it away", async ({
        page,
    }) => {
        const address = await addAlias(page);

        try {
            await page.goto("/settings?section=signature");

            const details = page.locator("#settings-signature details").first();
            await details.locator("summary").click();

            const row = page
                .locator("[data-signature-alias]")
                .filter({ hasText: address });

            await row.getByRole("button", { name: /different one/i }).click();
            await expect(page.getByText("edit it below")).toBeVisible();

            const overridden = page
                .locator("[data-signature-alias]")
                .filter({ hasText: address });

            await expect(overridden).toHaveAttribute(
                "data-signature-state",
                "overrides",
            );

            // Typeable straight away, which is what the old panel was not.
            const editor = overridden.locator(EDITOR);

            await expect(editor).toHaveCount(1);
            await expect(editor).toHaveAttribute("contenteditable", "true");

            await editor.click();
            await editor.fill("Just this address");
            await overridden
                .getByRole("button", { name: /Save signature/i })
                .click();

            await expect(page.getByText("Saved.")).toBeVisible();

            // The disclosure now starts OPEN, because configuration exists.
            await page.goto("/settings?section=signature");
            await expect(
                page.locator("#settings-signature details").first(),
            ).toHaveAttribute("open", /.*/);

            const back = page
                .locator("[data-signature-alias]")
                .filter({ hasText: address });

            await expect(back.locator(EDITOR)).toContainText(
                "Just this address",
            );

            // ...and back to inheriting, one line, no editor.
            await back
                .getByRole("button", { name: /account signature/i })
                .click();

            await expect(
                page.getByText("Back to the account signature."),
            ).toBeVisible();

            const reverted = page
                .locator("[data-signature-alias]")
                .filter({ hasText: address });

            await expect(reverted).toHaveAttribute(
                "data-signature-state",
                "inherits",
            );
            await expect(reverted.locator("[contenteditable]")).toHaveCount(0);
        } finally {
            await removeAlias(page, address);
        }
    });
});
