import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * The snooze menu says which day it means, in the order it means it.
 *
 * Reported as "'Dieses Wochenende' stellt in die Vergangenheit zurück", tested
 * on a Saturday. It does not — the maths resolves to the FOLLOWING Saturday,
 * which is right, because a snooze to a weekend that has already started is not
 * a snooze. Two real faults produced that reading:
 *
 *   • The label was a weekday and a time, "Sa., 08:00", which is unambiguous
 *     only inside the coming week. Eight days out it reads as this morning.
 *   • The menu listed the four in a fixed order, so on a Saturday "this
 *     weekend" (next Sat) sat ABOVE "next week" (Monday). Reading down and
 *     taking the first acceptable option got the furthest away.
 *
 * Both are asserted here against the clock the page is actually running on,
 * because a fixture date would test the fixture.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

/**
 * The wake times the OPEN menu is offering, in the order they are on screen.
 *
 * Scoped to the visible one: the list toolbar and the reading pane carry snooze
 * menus of their own besides the rows' shared one (#496), so a document-wide
 * query would compare several interleaved menus with themselves.
 */
async function offered(page: import("@playwright/test").Page): Promise<Array<{ key: string; at: number }>> {
    return page.evaluate(() =>
        [...document.querySelectorAll("[data-snooze-key]")]
            .filter((element) => null !== element.closest('[data-controller="mail--snooze-menu"]:not([hidden])'))
            .filter((element) => false === (element as HTMLElement).hidden)
            .map((element) => ({
                key: (element as HTMLElement).dataset.snoozeKey ?? "",
                at: Date.parse(element.getAttribute("data-mail--message-row-until-param") ?? ""),
            })),
    );
}

/**
 * Opens the snooze menu on a row.
 *
 * `force`, because the row's action icons only exist while the pointer is on
 * the row: any re-layout between the hover and the click — the menu of a
 * previous open leaving the top layer, a live count arriving — takes them away
 * again mid-gesture, and Playwright reports the button as "not stable" and then
 * "not visible".
 */
async function openSnoozeMenu(page: import("@playwright/test").Page, subject: string) {
    const row = mailRow(page, subject);
    await expect(row).toBeVisible();

    await rowAction(row, /snooze/i);

    await expect(page.locator('[data-controller="mail--snooze-menu"]:not([hidden])').first())
        .toBeVisible();
}

test.describe("the snooze menu", () => {
    test("offers only future times, soonest first", async ({ page }) => {
        await page.goto("/mail/inbox");

        await openSnoozeMenu(page, INBOX_SUBJECTS.read);

        const options = await offered(page);
        expect(options.length, "the menu offered nothing").toBeGreaterThan(1);

        const now = Date.now();

        for (const option of options) {
            expect(option.at, `${option.key} is in the past`).toBeGreaterThan(now);
        }

        const times = options.map((option) => option.at);
        expect(times, "the menu is not in chronological order").toEqual([...times].sort((a, b) => a - b));
    });

    /**
     * A weekday alone is unambiguous only inside the coming week.
     *
     * Whichever option is furthest out — on most days "next week", at a weekend
     * "this weekend" — has to name its date, or it reads as the one happening
     * in a few hours.
     */
    test("names the date for anything more than two days out", async ({ page }) => {
        await page.goto("/mail/inbox");

        await openSnoozeMenu(page, INBOX_SUBJECTS.read);

        const options = await offered(page);
        const furthest = options[options.length - 1];

        const soon = Date.now() + 2 * 24 * 60 * 60 * 1000;
        test.skip(furthest.at <= soon, "nothing on this menu is far enough out to need a date");

        const label = await page
            .locator('[data-controller="mail--snooze-menu"]:not([hidden])')
            .locator(`[data-snooze-key="${furthest.key}"] [data-snooze-when]`)
            .first()
            .innerText();

        expect(
            label,
            `"${label}" gives only a weekday for a time ${Math.round((furthest.at - Date.now()) / 86400000)} days away`,
        ).toMatch(/\d{1,2}/);

        // A time alone would satisfy the digit check, so the day number has to
        // be there beyond the "08:00".
        expect(label.replace(/\d{1,2}[:.]\d{2}/, ""), "no date in the label").toMatch(/\d/);
    });
});

/**
 * "Pick a date and time" (#342): the picker the send menu uses, inside the
 * snooze menu, posting through the same handler as a preset.
 */
test.describe("picking a date and time", () => {
    test("unfolds at tomorrow 08:00 and snoozes until the day picked", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await openSnoozeMenu(page, INBOX_SUBJECTS.read);

        const menu = page.locator("#row-snooze-menu");
        await menu.getByRole("menuitem", { name: /Pick a date and time/ }).click();

        const custom = menu.locator('[data-mail--snooze-menu-target="custom"]');
        await expect(custom, "the picker did not unfold").toBeVisible();
        await expect(menu, "unfolding the picker closed the menu").toBeVisible();
        await expect(custom.locator('[data-when-part="time"]')).toHaveText(/\b0?8[:.]00/); // "08:00", or "8:00 am" on a 12-hour clock

        // Three days out keeps the seeded time of day: choosing a day is one tap.
        await custom.locator('button[data-when="day"][data-value="3"]').click();

        const expected = await page.evaluate(() => {
            const at = new Date();
            at.setDate(at.getDate() + 3);
            at.setHours(8, 0, 0, 0);

            return at.getTime();
        });

        const id = (await row.getAttribute("id"))?.replace("thread_", "");
        const posted = page.waitForRequest((request) => request.method() === "POST" && request.url().includes("/snooze"));

        await custom.getByRole("button", { name: "Snooze", exact: true }).click();

        const request = await posted;
        expect(request.url(), "it snoozed a different conversation").toContain(`/status/thread/${id}/snooze`);
        expect(Date.parse(JSON.parse(request.postData() ?? "{}").until), "not the moment picked").toBe(expected);

        await expect(menu).toBeHidden();
        await expect(row).toHaveCount(0);
    });

    test("refuses a moment that has already passed, and posts nothing", async ({ page }) => {
        await page.goto("/mail/inbox");

        await openSnoozeMenu(page, INBOX_SUBJECTS.read);

        const menu = page.locator("#row-snooze-menu");
        await menu.getByRole("menuitem", { name: /Pick a date and time/ }).click();

        // The picker offers nothing before now, so the way to a past moment is
        // a menu left open while the clock moves on. Written straight into the
        // field, as that would leave it.
        await menu.locator('[data-mail--snooze-menu-target="input"]').evaluate((input: HTMLInputElement) => {
            input.value = "2020-01-01T08:00";
            input.dispatchEvent(new Event("change", { bubbles: true }));
        });

        let snoozed = false;
        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/snooze")) {
                snoozed = true;
            }
        });

        await menu.locator('[data-mail--snooze-menu-target="confirm"]').click();

        await expect(menu.getByRole("alert")).toHaveText(/already passed/);
        await expect(menu, "a refused moment closed the menu").toBeVisible();
        expect(snoozed, "a past moment was posted").toBe(false);
    });

    test("folds again when the menu is reopened", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row  = mailRow(page, INBOX_SUBJECTS.read);
        const menu = page.locator("#row-snooze-menu");

        await openSnoozeMenu(page, INBOX_SUBJECTS.read);
        await menu.getByRole("menuitem", { name: /Pick a date and time/ }).click();
        await expect(menu.locator('[data-mail--snooze-menu-target="custom"]')).toBeVisible();

        await page.keyboard.press("Escape");
        await expect(menu).toBeHidden();

        await rowAction(row, /snooze/i);
        await expect(menu).toBeVisible();
        await expect(menu.locator('[data-mail--snooze-menu-target="custom"]')).toBeHidden();
        await expect(menu.getByRole("menuitem", { name: /Pick a date and time/ })).toBeVisible();
    });
});
