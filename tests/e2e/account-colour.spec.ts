import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { acceptConfirm } from "./support/confirm";

/**
 * Changing the colour an account wears, in a real browser.
 *
 * The route is covered in phpunit (AccountColourTest) — ownership, CSRF, the
 * palette's bounds, OAuth accounts. What cannot move there is everything that
 * needs a stylesheet and a layout engine:
 *
 *   - the menu must not be cut off by the card it opens from. The card around
 *     the account list is overflow-hidden and only as tall as its rows, so the
 *     first version, which hung below its button, was cut off at the card's
 *     edge on the last row. The markup was right and every phpunit assertion
 *     passed; the swatches were simply not there to click. Since the custom
 *     colour form (#638) made it taller it opens in the top layer, where no
 *     card can clip it, so it is checked against the window instead.
 *   - the sidebar dot has to repaint to the colour that was picked, which is a
 *     computed style, and has to do it without a reload — the picker streams
 *     the list back.
 *   - the choice has to still be there after a reload.
 *
 * An OAuth account cannot be created through the UI, so that half of the
 * feature stays in phpunit, where it is staged directly.
 */

/** Adds a password account through the settings modal, as account.spec.ts does. */
async function addAccount(page: Page, label: string, stamp: number): Promise<void> {
    await page.goto("/settings/accounts");

    await page
        .locator("section")
        .filter({ has: page.locator("#settings-account-list") })
        .getByRole("button", { name: "Add account" })
        .click();

    const imapHost = page.locator('input[name="account[imapHost]"]');
    await expect(imapHost).toBeVisible();

    await page.locator('input[name="account[email]"]').fill(label);
    await page.locator('input[name="account[username]"]').fill(`colour-${stamp}@example.test`);
    await page.locator('input[name="account[password]"]').fill("hunter2");
    await imapHost.fill(`colour-${stamp}.example.test`);
    await page.locator('input[name="account[imapPort]"]').fill("993");
    await page.locator('select[name="account[imapEncryption]"]').selectOption("ssl");
    // Sending is on by default and needs its server (#628).
    await page.locator('input[name="account[smtpHost]"]').fill(`smtp-${stamp}.example.test`);
    await page.locator("#modal button[type=submit]").click();

    // Not the 5s default: POST /account/new measured 4-8s on a fresh stack
    // (the GET that opens the modal is 0.1s), so the shared helper's assertion
    // passes or fails on whichever side of five seconds that run landed.
    await expect(page.getByText("Account added successfully")).toBeVisible({ timeout: 20_000 });
}

/** Drop it again — an account is durable, user-wide state the next spec sees. */
async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings/accounts");

    const row = page
        .locator("#settings-account-list li")
        .filter({ hasText: new RegExp(label, "i") });

    for (let remaining = await row.count(); remaining > 0; remaining -= 1) {
        await row.getByRole("button", { name: "Remove account" }).first().click();
        await acceptConfirm(page);
        await expect(row).toHaveCount(remaining - 1);
    }
}

test.describe("account colour", () => {
    test("a colour picked on the row repaints the sidebar dot and survives a reload", async ({
        page,
    }) => {
        // Creating the account alone can take most of the default 30s.
        test.setTimeout(60_000);

        const stamp = Date.now();
        const label = `E2E Colour ${stamp}`;

        try {
            await addAccount(page, label, stamp);
            await page.goto("/settings/accounts");

            const row = page
                .locator("#settings-account-list li")
                .filter({ hasText: new RegExp(label, "i") });
            await row.getByRole("button", { name: /Change the colour of/ }).click();

            const menu = row.getByRole("group", { name: /Change the colour of/ });
            await expect(menu).toBeVisible();

            // In the top layer, so no overflow-hidden parent clips it, and
            // inside the window.
            expect(await menu.evaluate((el: Element) => el.matches(":popover-open"))).toBe(true);
            const menuBox = await menu.boundingBox();
            const viewport = page.viewportSize();
            expect(menuBox, "the menu is laid out").not.toBeNull();
            expect(menuBox!.y).toBeGreaterThanOrEqual(0);
            expect(menuBox!.y + menuBox!.height).toBeLessThanOrEqual(viewport!.height);

            // The whole palette and the custom swatch (#638), one checked.
            const radios = menu.locator('input[type="radio"][name="colour"]');
            await expect(radios).toHaveCount(9);
            await expect(menu.locator('input[name="colour"]:checked')).toHaveCount(1);

            const current = await menu.locator('input[name="colour"]:checked').getAttribute("value");
            const target = "3" === current ? "4" : "3";
            const radio = menu.locator(`input[name="colour"][value="${target}"]`);
            const swatch = radio.locator("xpath=following-sibling::span[@data-swatch]");
            const swatchColour = await swatch.evaluate(
                (el: Element) => getComputedStyle(el).backgroundColor,
            );

            // Picking previews on the sidebar dot; Save commits it.
            await swatch.click();
            const dot = page.locator(
                `#sidebar [role="img"][aria-label*="${label}" i]`,
            );
            await expect(dot).toHaveCSS("background-color", swatchColour);
            await menu.getByRole("button", { name: "Save" }).click();
            await expect(page.getByText("Account colour changed")).toBeVisible();

            // The sidebar dot is the colour that was picked, and no reload
            // happened in between: the list was streamed back.
            await expect(dot).toHaveCSS("background-color", swatchColour);

            // And it is stored, not merely painted.
            await page.reload();
            await expect(dot).toHaveCSS("background-color", swatchColour);

            await page.goto("/settings/accounts");
            await row.getByRole("button", { name: /Change the colour of/ }).click();
            await expect(
                row
                    .getByRole("group", { name: /Change the colour of/ })
                    .locator('input[name="colour"]:checked'),
            ).toHaveAttribute("value", target);
        } finally {
            await removeAccount(page, label);
        }
    });
    /**
     * #638: a colour of the user's own. The hex field and the colour well are
     * wired together and the contrast readout is worked out from the theme,
     * all in the browser — and the dot is an inline style, which only a
     * layout engine turns into the colour asserted here.
     */
    test("a custom colour typed as a hex code repaints the sidebar dot", async ({ page }) => {
        test.setTimeout(60_000);

        const stamp = Date.now();
        const label = `E2E Custom ${stamp}`;

        try {
            await addAccount(page, label, stamp);
            await page.goto("/settings/accounts");

            const row = page
                .locator("#settings-account-list li")
                .filter({ hasText: new RegExp(label, "i") });

            await row.getByRole("button", { name: /Change the colour of/ }).click();
            const menu = row.getByRole("group", { name: /Change the colour of/ });
            await expect(menu).toBeVisible();

            const dot = page.locator(`#sidebar [role="img"][aria-label*="${label}" i]`);
            const before = await dot.evaluate((el: Element) => getComputedStyle(el).backgroundColor);

            await menu.getByRole("radio", { name: "Custom colour" }).check({ force: true });

            const hex = menu.getByRole("textbox", { name: "Hex colour code" });
            await expect(hex).toBeFocused();
            // Without the #, as it comes from most palette sites.
            await hex.fill("e69f00");
            await expect(menu.locator('input[type="color"]')).toHaveValue("#e69f00");

            // Orange: below 3:1 on the plain light background, above it on the
            // dark one. The rows say so in words, not by colour alone.
            const light = menu.locator('[data-mode="light"]');
            const dark = menu.locator('[data-mode="dark"]');
            await expect(light.getByText("Faint on this theme")).toBeVisible();
            await expect(dark.getByText("Easy to see")).toBeVisible();

            // Previewed before it is saved, and put back by Cancel.
            await expect(dot).toHaveCSS("background-color", "rgb(230, 159, 0)");
            await menu.getByRole("button", { name: "Cancel" }).click();
            await expect(menu).toBeHidden();
            await expect(dot).toHaveCSS("background-color", before);

            await row.getByRole("button", { name: /Change the colour of/ }).click();
            await menu.getByRole("radio", { name: "Custom colour" }).check({ force: true });
            await hex.fill("#e69f00");
            await menu.getByRole("button", { name: "Save" }).click();
            await expect(page.getByText("Account colour changed")).toBeVisible();

            await expect(dot).toHaveCSS("background-color", "rgb(230, 159, 0)");

            await page.reload();
            await expect(dot).toHaveCSS("background-color", "rgb(230, 159, 0)");
        } finally {
            await removeAccount(page, label);
        }
    });
});
