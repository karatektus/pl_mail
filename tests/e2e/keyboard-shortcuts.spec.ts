import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Gmail's keyboard shortcuts (#36), in a real browser, because the claim is
 * about keys and nothing else can press one.
 *
 * Every shortcut presses a control the page already has, so what is asserted
 * here is the wiring and the three rules that make single keys safe to have:
 * a key acts on the selection, else the open conversation, else the row under
 * the cursor; it does nothing while somebody is typing; and it does nothing at
 * all for somebody who switched the shortcuts off.
 */
const ROWS = "#message-list li[data-mail--message-row-id-value]";
const CURSOR = `${ROWS}[data-kbd-cursor]`;
const COUNT = '[data-mail--list-toolbar-target="selectionCount"]';

async function setShortcuts(page: Page, option: "On" | "Off"): Promise<void> {
    await page.goto("/settings?section=general");

    const group = page.getByRole("radiogroup", { name: "Shortcuts" });

    if (false === (await group.getByRole("radio", { name: option, exact: true }).isChecked())) {
        await Promise.all([
            page.waitForURL(/section=general/),
            group.getByText(option, { exact: true }).click(),
        ]);
    }

    await expect(
        page.getByRole("radiogroup", { name: "Shortcuts" }).getByRole("radio", { name: option, exact: true }),
    ).toBeChecked();
}

async function inbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await expect(page.locator(ROWS).first()).toBeVisible();
    // The controller is on <body>; a key pressed before it has connected is a
    // key nobody was listening for.
    await expect(page.locator('body[data-controller~="ui--shortcuts"]')).toHaveCount(1);
}

test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("keyboard shortcuts", () => {
    test("j and k move a cursor, o opens what it is on and u comes back", async ({ page }) => {
        await inbox(page);

        const ids = await page.locator(ROWS).evaluateAll((rows) =>
            rows.map((row) => row.getAttribute("data-mail--message-row-id-value")));

        await expect(page.locator(CURSOR)).toHaveCount(0);

        await page.keyboard.press("j");
        await expect(page.locator(CURSOR)).toHaveAttribute("data-mail--message-row-id-value", ids[0]!);

        await page.keyboard.press("j");
        await expect(page.locator(CURSOR)).toHaveAttribute("data-mail--message-row-id-value", ids[1]!);

        await page.keyboard.press("k");
        await expect(page.locator(CURSOR)).toHaveAttribute("data-mail--message-row-id-value", ids[0]!);

        await page.keyboard.press("o");
        await expect(page.locator('[data-surface="reading"]')).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/(thread|message)\//);

        // With a conversation open, j is the next one to read.
        await page.keyboard.press("j");
        await expect(page.locator(`[data-mail--message-actions-entity-id-value="${ids[1]}"]`)).toBeVisible();

        await page.keyboard.press("u");
        await expect(page.locator(ROWS).first()).toBeVisible();
        await expect(page.locator(CURSOR)).toHaveAttribute("data-mail--message-row-id-value", ids[1]!);
    });

    test("e archives the row under the cursor, and z takes it back", async ({ page }) => {
        await inbox(page);

        const subject = INBOX_SUBJECTS.archive;
        const row = mailRow(page, subject);
        const id = await row.getAttribute("data-mail--message-row-id-value");

        // Walk to it rather than assume where the seed put it.
        for (let step = 0; step < 20; step++) {
            await page.keyboard.press("j");

            if (id === (await page.locator(CURSOR).getAttribute("data-mail--message-row-id-value"))) {
                break;
            }
        }

        await expect(page.locator(CURSOR)).toHaveAttribute("data-mail--message-row-id-value", id!);

        await page.keyboard.press("e");
        await expect(mailRow(page, subject)).toHaveCount(0);

        // The cursor did not go with it: it is on a row that is still here.
        await expect(page.locator(CURSOR)).toHaveCount(1);

        await page.keyboard.press("z");
        await expect(mailRow(page, subject)).toHaveCount(1);
    });

    test("a selection outranks the cursor", async ({ page }) => {
        await inbox(page);

        const before = await page.locator(ROWS).count();

        await page.keyboard.press("j");
        await page.keyboard.press("x");
        await page.keyboard.press("j");
        await page.keyboard.press("x");
        await expect(page.locator(COUNT)).toHaveText("2");

        // The cursor is on the second ticked row. One request for both, through
        // the toolbar, rather than one row through its own button.
        const posted = page.waitForRequest((request) => request.url().includes("/status/bulk/archive"));
        await page.keyboard.press("e");
        expect((await posted).postDataJSON().ids).toHaveLength(2);

        await expect(page.locator(ROWS)).toHaveCount(before - 2);
    });

    /**
     * `s` on a selection stars the selection.
     *
     * It did nothing: a key presses the control that is there, each row has a
     * star and the toolbar above a selection had none. Found by trying every
     * key in every context after `a` turned out to have the same shape of gap.
     */
    test("s stars everything that is ticked", async ({ page }) => {
        await inbox(page);

        await page.keyboard.press("j");
        await page.keyboard.press("x");
        await page.keyboard.press("j");
        await page.keyboard.press("x");

        const ticked = await page.locator(`${ROWS}:has([data-thread-select]:checked)`).evaluateAll((rows) =>
            rows.map((row) => row.getAttribute("data-mail--message-row-id-value")));
        expect(ticked).toHaveLength(2);

        await page.keyboard.press("s");

        for (const id of ticked) {
            await expect(page.locator(`${ROWS}[data-mail--message-row-id-value="${id}"]`))
                .toHaveAttribute("data-starred", "true");
        }
    });

    test("keys typed into a field are text", async ({ page }) => {
        await inbox(page);

        const before = await page.locator(ROWS).count();

        await page.keyboard.press("j");
        await page.keyboard.press("/");

        const search = page.locator("#search-shell input[name='q']");
        await expect(search).toBeFocused();

        await page.keyboard.type("e#x");
        await expect(search).toHaveValue("e#x");
        await expect(page.locator(ROWS)).toHaveCount(before);
        await expect(page.locator(COUNT)).toHaveText("");
    });

    test("? lists them, g goes somewhere and c writes", async ({ page }) => {
        await inbox(page);

        await page.keyboard.press("?");
        const help = page.locator("[data-shortcuts-help]");
        await expect(help).toBeVisible();
        await expect(help).toContainText("Archive");

        // A dialog owns the keyboard while it is open.
        await page.keyboard.press("j");
        await expect(page.locator(CURSOR)).toHaveCount(0);

        await page.keyboard.press("Escape");
        await expect(help).toBeHidden();

        await page.keyboard.press("g");
        await page.keyboard.press("s");
        await expect(page).toHaveURL(/\/mail\/starred/);

        await expect(page.locator('body[data-controller~="ui--shortcuts"]')).toHaveCount(1);
        await page.keyboard.press("c");
        await expect(page.locator("turbo-frame#compose_dock form").first()).toBeVisible();
    });

    /**
     * `a` answers on every message, not only on one with several recipients.
     *
     * Reported from the demo within a day: r and f worked and a did not. The
     * Reply-all link is only drawn when there is more than one recipient, and
     * `a` pressed a link that was not there. It replies instead, which is what
     * "all" comes to when all is one person.
     */
    test("a replies even where there is nobody else to reply to", async ({ page }) => {
        await inbox(page);
        await mailRow(page, INBOX_SUBJECTS.read).click();

        await expect(page.locator('[data-shortcut="reply"]')).toBeVisible();
        await expect(page.locator('[data-shortcut="reply-all"]'), "this fixture has one recipient").toHaveCount(0);

        await page.keyboard.press("a");
        await expect(page.locator(".compose-window").first()).toBeVisible();
    });

    /**
     * `b` snoozes the conversation that is open.
     *
     * It did nothing there: every row had a snooze menu and the open
     * conversation had none, so there was no control for the key to press. The
     * toolbar has one now, and the mail leaves the inbox for Snoozed.
     */
    test("b snoozes the open conversation", async ({ page }) => {
        await inbox(page);

        const subject = INBOX_SUBJECTS.read;
        await mailRow(page, subject).click();
        await expect(page.locator('[data-shortcut="reply"]')).toBeVisible();

        await page.keyboard.press("b");

        const option = page.locator('[data-surface="reading"] [data-snooze-key="tomorrow"]');
        await expect(option).toBeVisible();
        await option.click();

        // Back on the list, without it.
        await expect(page.locator(ROWS).first()).toBeVisible();
        await expect(mailRow(page, subject)).toHaveCount(0);

        await page.goto("/mail/snoozed");
        await expect(mailRow(page, subject)).toHaveCount(1);
    });

    test("switched off, a key is only a key", async ({ page }) => {
        await setShortcuts(page, "Off");

        try {
            await page.goto("/mail/inbox");
            await expect(page.locator(ROWS).first()).toBeVisible();
            await expect(page.locator('body[data-controller~="ui--shortcuts"]')).toHaveCount(0);

            const before = await page.locator(ROWS).count();

            await page.keyboard.press("j");
            await page.keyboard.press("e");
            await page.keyboard.press("?");

            await expect(page.locator(CURSOR)).toHaveCount(0);
            await expect(page.locator(ROWS)).toHaveCount(before);
            await expect(page.locator("[data-shortcuts-help]")).toHaveCount(0);
        } finally {
            // This worker's user keeps the setting for every spec after this one.
            await setShortcuts(page, "On");
        }
    });
});
