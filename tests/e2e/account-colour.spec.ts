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
 *   - the menu has to be INSIDE the card it opens from. The card around the
 *     account list is overflow-hidden and only as tall as its rows, so the
 *     first version, which hung below its button, was cut off at the card's
 *     edge on the last row. The markup was right and every phpunit assertion
 *     passed; the swatches were simply not there to click.
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
    await page.goto("/settings?section=accounts");

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
    await page.locator("#modal button[type=submit]").click();

    // Not the 5s default: POST /account/new measured 4-8s on a fresh stack
    // (the GET that opens the modal is 0.1s), so the shared helper's assertion
    // passes or fails on whichever side of five seconds that run landed.
    await expect(page.getByText("Account added successfully")).toBeVisible({ timeout: 20_000 });
}

/** Drop it again — an account is durable, user-wide state the next spec sees. */
async function removeAccount(page: Page, label: string): Promise<void> {
    await page.goto("/settings?section=accounts");

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
            await page.goto("/settings?section=accounts");

            const row = page
                .locator("#settings-account-list li")
                .filter({ hasText: new RegExp(label, "i") });
            const card = page
                .locator("section")
                .filter({ has: page.locator("#settings-account-list") });

            await row.getByRole("button", { name: /Change the colour of/ }).click();

            const menu = row.getByRole("group", { name: /Change the colour of/ });
            await expect(menu).toBeVisible();

            // Inside the card: a menu cut off by its overflow-hidden parent
            // still has a bounding box, but one that runs past the card.
            const menuBox = await menu.boundingBox();
            const cardBox = await card.boundingBox();
            expect(menuBox, "the menu is laid out").not.toBeNull();
            expect(cardBox, "the card is laid out").not.toBeNull();
            expect(menuBox!.y).toBeGreaterThanOrEqual(cardBox!.y);
            expect(menuBox!.y + menuBox!.height).toBeLessThanOrEqual(cardBox!.y + cardBox!.height);

            // The whole palette, with one entry marked as the current one.
            await expect(menu.locator('button[name="colour"]')).toHaveCount(8);
            await expect(menu.locator('button[aria-pressed="true"]')).toHaveCount(1);

            const current = await menu
                .locator('button[aria-pressed="true"]')
                .getAttribute("value");
            const target = "3" === current ? "4" : "3";
            const swatch = menu.locator(`button[value="${target}"]`);
            const swatchColour = await swatch.evaluate(
                (el: Element) => getComputedStyle(el).backgroundColor,
            );

            await swatch.click();
            await expect(page.getByText("Account colour changed")).toBeVisible();

            // The sidebar dot is the colour that was picked, and no reload
            // happened in between: the list was streamed back.
            const dot = page.locator(
                `#sidebar [role="img"][aria-label*="${label}" i]`,
            );
            await expect(dot).toHaveCSS("background-color", swatchColour);

            // And it is stored, not merely painted.
            await page.reload();
            await expect(dot).toHaveCSS("background-color", swatchColour);

            await page.goto("/settings?section=accounts");
            await row.getByRole("button", { name: /Change the colour of/ }).click();
            await expect(
                row
                    .getByRole("group", { name: /Change the colour of/ })
                    .locator('button[aria-pressed="true"]'),
            ).toHaveAttribute("value", target);
        } finally {
            await removeAccount(page, label);
        }
    });
});
