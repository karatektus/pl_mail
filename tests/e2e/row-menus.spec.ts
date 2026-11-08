import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * The rows' snooze and spam menus are one shared menu each, opened for the row
 * whose button was pressed (#496).
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * What is pinned here is what the change must not have changed — the menu opens
 * beside the right button, acts on the right conversation, and can be used from
 * the keyboard — and the one thing it set out to change: a row no longer
 * carries a copy of either menu. snoozed-list.spec.ts and snooze-menu.spec.ts
 * cover what the snooze options themselves offer.
 */

test.beforeEach(() => {
    seed("seed-mail");
});

test.afterAll(() => {
    seed("seed-mail");
});

const ROWS = '#message-list li[data-controller="mail--message-row"]';

test.describe("the rows' shared menus", () => {
    test("a row carries the buttons and not the menus", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator(ROWS).first()).toBeVisible();

        await expect(page.locator(`${ROWS} [data-controller~="mail--snooze-menu"]`), "a snooze menu inside a row").toHaveCount(0);
        await expect(page.locator(`${ROWS} [data-controller~="mail--spam-menu"]`), "a spam menu inside a row").toHaveCount(0);
        await expect(page.locator("#row-snooze-menu")).toHaveCount(1);
        await expect(page.locator("#row-spam-menu")).toHaveCount(1);

        const snooze = mailRow(page, INBOX_SUBJECTS.read).locator('[aria-controls="row-snooze-menu"]');
        await expect(snooze).toHaveAttribute("aria-controls", "row-snooze-menu");
        await expect(snooze).toHaveAttribute("aria-expanded", "false");
    });

    test("snooze opens beside the row's button and snoozes that conversation", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await rowAction(row, "Snooze");

        const menu   = page.locator("#row-snooze-menu");
        const button = row.locator('[aria-controls="row-snooze-menu"]');

        await expect(menu).toBeVisible();
        await expect(button).toHaveAttribute("aria-expanded", "true");
        await expect(menu.getByRole("menuitem", { name: /Unsnooze/ }), "not snoozed, so nothing to wake").toBeHidden();

        // Beside it: the menu's top edge just under the button, or its bottom
        // just over it when the row is near the bottom of the window.
        const at   = await button.boundingBox();
        const box  = await menu.boundingBox();
        const near = Math.min(Math.abs((box?.y ?? 0) - ((at?.y ?? 0) + (at?.height ?? 0))), Math.abs(((box?.y ?? 0) + (box?.height ?? 0)) - (at?.y ?? 0)));
        expect(near, "the menu is not next to the button that opened it").toBeLessThan(12);

        const id = (await row.getAttribute("id"))?.replace("thread_", "");
        const posted = page.waitForRequest((request) => request.method() === "POST" && request.url().includes("/snooze"));

        await menu.getByRole("menuitem", { name: /Tomorrow/ }).click();

        const request = await posted;
        expect(request.url(), "it snoozed a different conversation").toContain(`/status/thread/${id}/snooze`);
        expect(JSON.parse(request.postData() ?? "{}").until, "no wake time went with it").toBeTruthy();

        await expect(menu).toBeHidden();
        await expect(row).toHaveCount(0);
    });

    test("one menu at a time, and the same button closes its own", async ({ page }) => {
        await page.goto("/mail/inbox");

        const first  = mailRow(page, INBOX_SUBJECTS.read);
        const second = mailRow(page, INBOX_SUBJECTS.star);
        const menu   = page.locator("#row-snooze-menu");

        await rowAction(first, "Snooze");
        await expect(menu).toBeVisible();

        await rowAction(second, "Snooze");
        await expect(menu).toBeVisible();
        await expect(first.locator('[aria-controls="row-snooze-menu"]')).toHaveAttribute("aria-expanded", "false");
        await expect(second.locator('[aria-controls="row-snooze-menu"]')).toHaveAttribute("aria-expanded", "true");

        await second.hover();
        await second.locator('[aria-controls="row-snooze-menu"]').click();
        await expect(menu).toBeHidden();
    });

    test("from the keyboard: Enter opens with the focus inside, arrows move, Escape hands it back", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row    = mailRow(page, INBOX_SUBJECTS.read);
        const button = row.locator('[aria-controls="row-snooze-menu"]');

        await row.hover();
        await button.focus();
        await page.keyboard.press("Enter");

        const menu = page.locator("#row-snooze-menu");
        await expect(menu).toBeVisible();

        const focused = () => page.evaluate(() => document.activeElement?.closest("#row-snooze-menu")?.contains(document.activeElement) === true
            ? (document.activeElement as HTMLElement).dataset.snoozeKey ?? "other"
            : "outside");

        const firstKey = await focused();
        expect(firstKey, "the focus did not go into the menu").not.toBe("outside");

        await page.keyboard.press("ArrowDown");
        expect(await focused(), "the arrow did not move the focus").not.toBe(firstKey);

        await page.keyboard.press("Escape");
        await expect(menu).toBeHidden();
        await expect(button).toBeFocused();
    });

    test("spam opens for its row and closes on a click elsewhere", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await rowAction(row, "Spam");

        const menu = page.locator("#row-spam-menu");
        await expect(menu.getByText(/Move to Spam/).first()).toBeVisible();
        await expect(row.getByTestId("spam-button")).toHaveAttribute("aria-expanded", "true");

        await page.locator("h1, [data-list-region='tabs'], body").first().click({ position: { x: 2, y: 2 } });
        await expect(menu).toBeHidden();
        await expect(row.getByTestId("spam-button")).toHaveAttribute("aria-expanded", "false");
    });
});
