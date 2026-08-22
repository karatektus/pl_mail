import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The Snoozed list says when each conversation comes back, and lets you cancel.
 *
 * Reported together with the menu findings, and the combination is what made it
 * serious: a conversation could be snoozed to a date the reader could not see,
 * in a folder they believed offered no way out — so it looked like mail that
 * had been put somewhere and lost.
 *
 * Half of that was already true and unfound: the menu does offer "wake now" on
 * an already-snoozed row. What was genuinely missing is the wake time. The row
 * showed the ARRIVAL time, which every other list shows and which is the one
 * thing this list is not about — there was no way to tell a mail returning in
 * an hour from one returning in a fortnight.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

async function openSnoozeMenu(page: import("@playwright/test").Page, subject: string) {
    const row = mailRow(page, subject);
    await expect(row).toBeVisible();
    await row.hover();
    await row.getByRole("button", { name: /snooze/i }).click({ force: true });

    return page.locator('[data-controller="mail--snooze-menu"]:not([hidden])').first();
}

test("a snoozed conversation shows its wake time and can be woken", async ({ page }) => {
    await page.goto("/mail/inbox");

    const menu = await openSnoozeMenu(page, INBOX_SUBJECTS.read);
    await expect(menu).toBeVisible();

    // Whatever the menu is offering first — the soonest, by definition.
    const option = menu.locator("[data-snooze-key]:not([hidden])").first();
    const wakeAt = await option.getAttribute("data-mail--message-row-until-param") ?? "";

    await option.click({ force: true });

    // Deliberately NOT asserting the row leaves the inbox here. It does not,
    // and that is its own reported finding — the row is removed locally by some
    // actions and not others, and the pager and empty state do not follow
    // either. Folding it into this test would make a snooze failure and a list
    // refresh failure indistinguishable.
    await page.goto("/mail/snoozed");

    const row = mailRow(page, INBOX_SUBJECTS.read);
    await expect(row).toBeVisible({ timeout: 10_000 });

    // The wake time, not the arrival time — compared against the instant the
    // menu actually posted, because "contains a digit" is satisfied by the
    // arrival time too and would pass with this fix reverted. Both clock
    // formats are accepted: which one renders is a user setting, and the claim
    // here is about WHICH TIME is shown rather than how it is written.
    const hour = new Date(wakeAt).getHours();
    const shown = await row.innerText();

    // `0?` because the hour is zero-padded on screen and not in a Date: at
    // 18:00 the menu's first option is "later today" and the row reads "18:00",
    // but a run that crosses 18:00 gets "tomorrow" instead and the row reads
    // "08:00" while this computed 8. Without the optional zero the test passes
    // all afternoon and fails in the evening, which is the worst shape a test
    // can have.
    expect(
        shown,
        `the row shows the arrival time rather than the ${hour}:00 it comes back at`,
    ).toMatch(new RegExp(`\\b0?(${hour}:00|${hour % 12 || 12}:00\\s*[AP]M)`, "i"));

    // And the way out exists.
    const snoozedMenu = await openSnoozeMenu(page, INBOX_SUBJECTS.read);

    // By its name, not `.last()`: if the entry were missing — which is the
    // thing being asserted — `.last()` would silently be "next week" and the
    // test would snooze the mail further while reporting success.
    const wakeNow = snoozedMenu.getByRole("button", { name: /unsnooze/i });

    await expect(
        wakeNow,
        "the snoozed row offers no way to cancel the snooze",
    ).toBeVisible();

    const woken = page.waitForResponse(
        (response) => response.url().includes("/snooze") && response.request().method() === "POST",
    );

    await wakeNow.click({ force: true });
    await woken;

    await page.goto("/mail/inbox");
    await expect(
        mailRow(page, INBOX_SUBJECTS.read),
        "waking it did not put it back in the inbox",
    ).toBeVisible({ timeout: 10_000 });
});
