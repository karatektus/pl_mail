import { test, expect } from "./support/test";
import { APP_TIMEZONE, INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { rowAction } from "./support/rows";

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

    // THIS row's menu. Every row renders its own copy of the partial, and the
    // list toolbar renders another for the selection, so a page-wide `.first()`
    // was whichever menu came first in the document — not the one the click
    // just opened. It only looked right because the inbox happened to list this
    // subject first; when it did not, the helper handed back a closed menu
    // belonging to another conversation, and the caller reported the feature
    // inside it as missing.
    //
    // `:not([hidden])` went with it. The dropdown is opened and closed by
    // class, never by the hidden attribute, so that filter excluded nothing and
    // read as a check on open-ness while being none.
    const menu = row.locator('[data-controller="mail--snooze-menu"]');

    // And the helper does not hand back a menu it has not seen open: callers go
    // straight to looking for an entry inside it, so a click that opened nothing
    // surfaced as "the snoozed row offers no way to cancel the snooze" — a
    // sentence about a missing feature, describing a menu that was not up.
    await expect(menu, `the ${subject} row's snooze menu did not open`).toBeVisible();

    return menu;
}

test("a snoozed conversation shows its wake time and can be woken", async ({ page }) => {
    // Long by nature rather than slow by accident: it seeds, opens two menus,
    // waits on two writes and walks three lists. On a two-vCPU runner carrying
    // five hundred other tests that legitimately outgrew the default budget and
    // timed out as a whole — no single assertion was wrong. test.slow() is the
    // sanctioned way to say a test needs more room, and it leaves every
    // assertion inside it on its own honest timeout.
    test.slow();

    await page.goto("/mail/inbox");

    const menu = await openSnoozeMenu(page, INBOX_SUBJECTS.read);
    await expect(menu).toBeVisible();

    // Whatever the menu is offering first — the soonest, by definition.
    const option = menu.locator("[data-snooze-key]:not([hidden])").first();
    const wakeAt = await option.getAttribute("data-mail--message-row-until-param") ?? "";

    // Waited for AND asserted. Navigating before the write lands reads the
    // next list from before it, which fails as "the conversation is not there"
    // — indistinguishable from the feature being broken, and only on some runs.
    // A refused write answers just as promptly as a successful one, so the
    // status is checked rather than assumed.
    const snoozed = page.waitForResponse(
        (response) => response.url().includes("/snooze") && response.request().method() === "POST",
    );

    // NOT force. `force` skips the hit-target check, and skipping it here is
    // what produced a 90s timeout on a full run: the menu was still animating
    // open, so the click went to the option's centre while something else was
    // still over that point, and the event landed on the ROW instead. The trace
    // shows it exactly -- no POST to /snooze at all, and a POST to
    // /status/thread/<id>/read, which is the conversation being opened by a
    // click the test believed it had aimed at a menu entry. An honest click
    // waits for the option to be stable and actually hittable; if something
    // ever does cover it, the failure says so instead of quietly pressing
    // whatever is underneath.
    await option.click();

    expect((await snoozed).status(), "the snooze was refused").toBe(200);

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
    // Read in the zone the PAGE renders in, not the one Node is running in.
    //
    // The row is formatted server-side in the user's zone, which falls back to
    // Europe/Berlin; `getHours()` answers in the runner's. Those are the same
    // thing on a developer's machine and two hours apart on a GitHub runner, so
    // this passed here and failed only on the tag: the mail came back at 18:00,
    // the row said 20:00, and the assertion reported the wrong TIME being shown
    // rather than the wrong CLOCK being read.
    //
    // See APP_TIMEZONE, and compose-scheduled-send's wallClockIn, which is the
    // same lesson learned in the same place.
    const hour = Number(
        new Intl.DateTimeFormat("en-GB", {
            timeZone: APP_TIMEZONE,
            hour: "2-digit",
            hour12: false,
        }).format(new Date(wakeAt)),
    ) % 24;
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

    // Not force, for the reason given at the first menu click above: an entry
    // in a menu that has been asserted visible does not need the hit-target
    // check disabled, and disabling it turns "something was over it" into a
    // click on whatever was underneath.
    await wakeNow.click();
    await woken;

    await page.goto("/mail/inbox");
    await expect(
        mailRow(page, INBOX_SUBJECTS.read),
        "waking it did not put it back in the inbox",
    ).toBeVisible({ timeout: 10_000 });
});
