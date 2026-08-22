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
 * Scoped to the visible one: every row in the list carries its own snooze menu,
 * so a document-wide query returns four options per row and the ordering
 * assertion below compares a list of five interleaved menus with itself.
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
