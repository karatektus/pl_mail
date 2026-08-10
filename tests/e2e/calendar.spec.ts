import { expect, type Locator, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";
import { choose } from "./support/select";

/**
 * The calendar in both of its shapes.
 *
 * The pane cases are the ones worth having. A docked pane that shares its row
 * with the mail panes can go wrong in ways a page cannot — it can fail to give
 * width back, it can forget its size on reload, and a dialog opened from inside
 * it can be clipped by the pane's own backdrop-filter. All three have precedent
 * in this codebase.
 *
 * Seeds and removes its own events: the suite is not idempotent, and an event
 * left behind changes what the next run's assertions count.
 */

const TITLE = `E2E event ${Date.now()}`;

/**
 * The chips this spec's event put on the calendar.
 *
 * Counted in the agenda view, which is where every recurring case below reads
 * them. Neither of the obvious alternatives works: the week is seven days from
 * Monday, so a daily series created "today" has as little as one occurrence
 * left in it when the suite runs on a Sunday and "leaves its siblings" has no
 * siblings; and the month grid renders only the first three events of each day,
 * so a count taken from it is a count of what fits. Agenda is thirty days from
 * today and draws all of them.
 */
function chipsOf(page: Page) {
    return page.getByRole("button", { name: new RegExp(TITLE) });
}

/**
 * What the repeat picker calls each frequency on screen.
 *
 * `choose` picks by the words a person reads rather than by the option's
 * value, because since v0.0.22 the thing the label names is a themed combobox
 * and not the `<select>` underneath it — see tests/e2e/support/select.ts. The
 * cases below still say "daily", which is the word the behaviour is about, so
 * the translation of that word into the one on screen lives here once.
 */
const REPEAT = {
    none: "Does not repeat",
    daily: "Every day",
} as const;

/**
 * Submit the editor and wait until the page under it is the new one.
 *
 * The wait matters more than it looks. Both buttons navigate the whole page
 * (data-turbo-frame="_top"), and until that lands the dialog is still open over
 * the PREVIOUS render — whose chips are all still where they were. A spec that
 * reads them straight after the click reads the state it was trying to change,
 * and every assertion about "one of them moved" passes or fails on timing.
 */
async function submit(page: Page, modal: Locator, button: string) {
    await modal.getByRole("button", { name: button }).click();

    await expect(page.locator("#modal-backdrop")).toBeHidden();
}

/**
 * Open the editor on one chip and wait until it is that chip's editor.
 *
 * The wait is on a field's value, not on the dialog being visible. The modal
 * keeps the PREVIOUS dialog's markup until Turbo swaps the frame, so a spec
 * that waits for #modal-backdrop goes on to type into the last event's form.
 */
async function openChip(page: Page, index: number) {
    await chipsOf(page).nth(index).click();

    const modal = page.locator("#modal-backdrop");
    await expect(modal.getByLabel("Title")).toHaveValue(new RegExp(TITLE));

    return modal;
}

/** A datetime-local field's value, as that field wants it written back. */
function localValue(when: Date) {
    const pad = (part: number) => String(part).padStart(2, "0");

    return [
        `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}`,
        `${pad(when.getHours())}:${pad(when.getMinutes())}`,
    ].join("T");
}

/**
 * Push both ends of the editor's times out by a couple of hours.
 *
 * Relative to whatever the fields already hold rather than to a literal clock:
 * the two fields are read in the calendar's zone and the chips are rendered in
 * the reader's, so the only assertion that holds in every install is that the
 * chip moved, not that it moved to 11:00.
 */
async function shiftTimesBy(modal: Locator, hours: number) {
    for (const field of ["Starts", "Ends"]) {
        const input = modal.getByLabel(field);
        const moved = new Date(await input.inputValue());

        moved.setHours(moved.getHours() + hours);

        await input.fill(localValue(moved));
    }
}

test.describe("calendar", () => {
    test.afterEach(async ({ page }) => {
        // Delete anything this spec created, so a second run starts where the
        // first did. A recurring event puts one chip on every day, and its
        // editor now defaults to "This event" — so the series scope has to be
        // chosen explicitly here, or the cleanup removes one occurrence per
        // attempt and gives up with the rest still on the calendar. This loops
        // on "is any still there" rather than on a count.
        //
        // Never waits for networkidle: the app holds a Mercure EventSource
        // open for the whole session, so the network is never idle and the
        // wait only ever ends in a timeout.
        for (let attempt = 0; attempt < 3; attempt++) {
            await page.goto("/calendar/agenda");

            if ((await chipsOf(page).count()) === 0) {
                return;
            }

            const modal = await openChip(page, 0);
            const series = modal.getByRole("radio", { name: "All events" });

            if (await series.isVisible()) {
                await series.check();
            }

            await submit(page, modal, "Delete");
            await expect(chipsOf(page)).toHaveCount(0);
        }
    });

    test("creates an event and shows it in the week", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        await modal.getByLabel("Title").fill(TITLE);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByRole("button", { name: new RegExp(TITLE) }).first()).toBeVisible();
    });

    test("switches between day, week and month", async ({ page }) => {
        await page.goto("/calendar/week");

        for (const view of ["Day", "Month", "Week"]) {
            await page.getByRole("link", { name: view, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`/calendar/${view.toLowerCase()}`));
        }
    });

    test("a repeating event appears on more than one day", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await modal.getByLabel("Title").fill(TITLE);
        await choose(modal, "Repeat", REPEAT.daily);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page).toHaveURL(/\/calendar\/week/);

        // A daily event inside a seven-day window is on every remaining day of
        // it, so more than one chip is the whole assertion.
        const chips = page.getByRole("button", { name: new RegExp(TITLE) });
        await expect(chips.first()).toBeVisible();
        expect(await chips.count()).toBeGreaterThan(1);
    });

    /**
     * The choice is offered only where there is one to make.
     *
     * A one-off event has exactly one instance, so "this event" and "all
     * events" would name the same thing — and almost every event is a one-off,
     * so an extra radio on all of them is noise on the common case.
     */
    test("offers no this-or-all choice on an event that does not repeat", async ({ page }) => {
        await createEvent(page, { repeat: "none" });

        const modal = await openChip(page, 0);

        await expect(modal.getByRole("radio", { name: "This event" })).toHaveCount(0);
        await expect(modal.getByRole("radio", { name: "All events" })).toHaveCount(0);
    });

    test("shows the choice on an event that does repeat", async ({ page }) => {
        await createEvent(page, { repeat: "daily" });

        const modal = await openChip(page, 1);

        await expect(modal.getByRole("radio", { name: "This event" })).toBeChecked();
        await expect(modal.getByRole("radio", { name: "All events" })).not.toBeChecked();
    });

    /**
     * The case this whole feature exists for. Every chip of a daily series
     * reads identically — same time, same title — so "exactly one of them
     * changed" is expressible as a count of distinct labels, without depending
     * on which clock the viewer's chips are drawn in.
     *
     * Before the per-instance path existed, saving here rewrote the series and
     * every chip moved together, which is the failure this guards.
     */
    test("moving this event moves one occurrence and leaves its siblings", async ({ page }) => {
        await createEvent(page, { repeat: "daily" });

        const before = await chipsOf(page).allInnerTexts();
        expect(new Set(before).size).toBe(1);

        const modal = await openChip(page, 1);
        await shiftTimesBy(modal, 2);
        await modal.getByRole("radio", { name: "This event" }).check();
        await submit(page, modal, "Save");

        await expect(chipsOf(page)).toHaveCount(before.length);

        const after = await chipsOf(page).allInnerTexts();
        expect(new Set(after).size).toBe(2);
        expect(after.filter((label) => label === before[0])).toHaveLength(before.length - 1);
    });

    /**
     * The other half of the choice, and the behaviour that used to be the only
     * one there was. Asserted on the labels rather than on a count: "all
     * events" writes the series from the times the editor posted, and those are
     * the times of the occurrence it was opened on — so the series starts on
     * that day and the occurrences before it are no longer part of it.
     */
    test("moving all events moves the whole series", async ({ page }) => {
        await createEvent(page, { repeat: "daily" });

        const before = await chipsOf(page).allInnerTexts();

        const modal = await openChip(page, 1);
        await shiftTimesBy(modal, 2);
        await modal.getByRole("radio", { name: "All events" }).check();
        await submit(page, modal, "Save");

        const after = await chipsOf(page).allInnerTexts();
        expect(new Set(after).size).toBe(1);
        expect(after[0]).not.toBe(before[0]);
    });

    /**
     * Deleting one instance is an exclusion on the series, not a delete: the
     * event and every other occurrence of it stay exactly as they were.
     */
    test("deleting this event takes one occurrence off and leaves the rest", async ({ page }) => {
        await createEvent(page, { repeat: "daily" });

        const before = await chipsOf(page).count();
        expect(before).toBeGreaterThan(1);

        const modal = await openChip(page, 1);
        await modal.getByRole("radio", { name: "This event" }).check();
        await submit(page, modal, "Delete");

        await expect(chipsOf(page)).toHaveCount(before - 1);
    });

    test("deleting all events takes the whole series off", async ({ page }) => {
        await createEvent(page, { repeat: "daily" });

        expect(await chipsOf(page).count()).toBeGreaterThan(1);

        const modal = await openChip(page, 1);
        await modal.getByRole("radio", { name: "All events" }).check();
        await submit(page, modal, "Delete");

        await expect(chipsOf(page)).toHaveCount(0);
    });

    /**
     * Creates this spec's event and leaves the page on the agenda, which is
     * where the recurring cases count chips. Its own helper because five cases
     * need the same three steps and none of them is what they are testing.
     */
    async function createEvent(page: Page, { repeat }: { repeat: keyof typeof REPEAT }) {
        await page.goto("/calendar/agenda");

        await page.getByRole("button", { name: "New event", exact: true }).first().click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("Title")).toHaveValue("");

        await modal.getByLabel("Title").fill(TITLE);
        await choose(modal, "Repeat", REPEAT[repeat]);
        await submit(page, modal, "Save");

        await expect(chipsOf(page).first()).toBeVisible();
    }
});

/**
 * One meeting that legitimately exists twice.
 *
 * An invitation is extracted from mail onto the account's calendar with the
 * organiser's UID while the provider auto-adds the same meeting to a calendar
 * plMail mirrors — two correct rows, one UID, and the user seeing it twice.
 * Neither row may go, so the duplication is answered on the screen.
 *
 * Seeded by a console command rather than through the UI, and that is forced:
 * a UID is minted server-side and the editor has no field for it, so nothing a
 * user can click produces two rows that share one. See
 * App\Command\Test\SeedDuplicateEventCommand.
 *
 * Counted in the agenda view, for the reason chipsOf() gives above: the week is
 * seven days from Monday and the month grid draws only the first three events
 * of a day, so agenda is the only view whose chip count is a count of what
 * exists rather than of what fits.
 */
test.describe("a meeting on two calendars", () => {
    const TITLE = "E2E duplicated meeting";
    const SECOND = "E2E Duplicate B";

    function meetingChips(page: Page) {
        return page.getByRole("button", { name: new RegExp(TITLE) });
    }

    /**
     * Open the merged chip and wait until it is that chip's editor.
     *
     * On a field's VALUE rather than on the dialog being visible: the modal
     * keeps the previous dialog's markup until Turbo swaps the frame, so a spec
     * that waits for #modal-backdrop goes on to act on the last event's form.
     */
    async function openMeeting(page: Page) {
        await meetingChips(page).first().click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("Title")).toHaveValue(new RegExp(TITLE));

        return modal;
    }

    test.afterEach(() => {
        // Its own calendars, so clearing them cannot disturb anything else the
        // suite seeded — and a spec that failed halfway leaves no stale chip
        // for the next run to count.
        seed("seed-duplicate-event --clear");
    });

    test("draws one chip, not two", async ({ page }) => {
        seed("seed-duplicate-event");

        await page.goto("/calendar/agenda");

        await expect(meetingChips(page)).toHaveCount(1);
    });

    test("ticks every calendar it is on, and disables the one that takes no changes", async ({ page }) => {
        seed("seed-duplicate-event --read-only");

        await page.goto("/calendar/agenda");

        const modal = await openMeeting(page);
        const locked = modal.getByRole("checkbox", { name: new RegExp(SECOND) });

        await expect(modal.getByRole("checkbox", { name: /E2E Duplicate A/ })).toBeChecked();
        await expect(locked).toBeDisabled();
        await expect(locked).not.toBeChecked();

        // There is one calendar control and it is this list — the dropdown is
        // gone, never shown beside a control that contradicts it. See the
        // header comment on _event_modal.html.twig.
        await expect(modal.getByLabel("Calendar", { exact: true })).toHaveCount(0);
    });

    /**
     * The promise the help text makes. Unticking a copy leaves it saying the
     * old thing, which by the merge rule makes the two disagree — and a
     * disagreement is drawn rather than hidden, so the next render has two
     * chips where it had one.
     */
    test("unticking a copy leaves it alone, and the calendar then draws two chips", async ({ page }) => {
        seed("seed-duplicate-event");

        await page.goto("/calendar/agenda");
        await expect(meetingChips(page)).toHaveCount(1);

        const modal = await openMeeting(page);

        await modal.getByRole("checkbox", { name: new RegExp(SECOND) }).uncheck();
        await modal.getByLabel("Title").fill(`${TITLE} (moved room)`);
        await submit(page, modal, "Save");

        await page.goto("/calendar/agenda");

        await expect(meetingChips(page)).toHaveCount(2);
        await expect(page.getByRole("button", { name: `${TITLE} (moved room)` })).toHaveCount(1);
    });

    /** With every copy ticked it stays one meeting, renamed on both calendars. */
    test("an edit with every copy ticked keeps it one chip", async ({ page }) => {
        seed("seed-duplicate-event");

        await page.goto("/calendar/agenda");

        const modal = await openMeeting(page);

        await modal.getByLabel("Title").fill(`${TITLE} (moved room)`);
        await submit(page, modal, "Save");

        await page.goto("/calendar/agenda");

        await expect(meetingChips(page)).toHaveCount(1);
        await expect(page.getByRole("button", { name: `${TITLE} (moved room)` })).toHaveCount(1);
    });

    /**
     * The other direction, and the reason the list is every calendar rather
     * than only the ones a copy is already on: ticking an empty calendar puts
     * the meeting there.
     *
     * The chip count is the assertion that distinguishes a copy from a
     * duplicate. The new row carries the meeting's UID, so EventClusterer
     * merges the two and the agenda still draws one — a row minted with a UID
     * of its own would look identical in the editor and draw a second chip at
     * the same hour of the same day, for ever.
     *
     * Reopening is how the row is confirmed to exist without reading the
     * database: a box is ticked when, and only when, the meeting is on that
     * calendar.
     */
    test("puts the meeting on a calendar it was not on, and it stays one chip", async ({ page }) => {
        seed("seed-duplicate-event --single");

        await page.goto("/calendar/agenda");
        await expect(meetingChips(page)).toHaveCount(1);

        const modal = await openMeeting(page);
        const second = modal.getByRole("checkbox", { name: new RegExp(SECOND) });

        await expect(second).not.toBeChecked();
        await second.check();
        await submit(page, modal, "Save");

        await page.goto("/calendar/agenda");

        await expect(meetingChips(page)).toHaveCount(1);

        const reopened = await openMeeting(page);

        await expect(reopened.getByRole("checkbox", { name: new RegExp(SECOND) })).toBeChecked();
        await expect(reopened.getByRole("checkbox", { name: /E2E Duplicate A/ })).toBeChecked();
    });

    /**
     * A destination that accepts no writes back is offered rather than hidden,
     * so the list stays a true statement of where the meeting could be — and
     * disabled, because it cannot be one of those places.
     */
    test("offers a calendar that takes no changes as a destination, and disables it", async ({ page }) => {
        seed("seed-duplicate-event --single --read-only");

        await page.goto("/calendar/agenda");

        const modal = await openMeeting(page);
        const locked = modal.getByRole("checkbox", { name: new RegExp(SECOND) });

        await expect(locked).toBeDisabled();
        await expect(locked).not.toBeChecked();
    });

    test("a delete with every copy ticked takes it off both calendars", async ({ page }) => {
        seed("seed-duplicate-event");

        await page.goto("/calendar/agenda");

        const modal = await openMeeting(page);
        await submit(page, modal, "Delete");

        await page.goto("/calendar/agenda");

        await expect(meetingChips(page)).toHaveCount(0);
    });
});

test.describe("calendar pane", () => {
    /**
     * The switch has three positions — mail, split, calendar — and cycles
     * through them in that order, wrapping. Which one it is in is a stored user
     * preference, so it survives from whichever test ran last: every case here
     * puts it where it needs it rather than assuming.
     *
     * Driven by the shell attribute rather than by counting clicks, because
     * counting only works from a known start and the whole problem is that
     * there is not one.
     */
    async function setPaneMode(page: Page, mode: "mail" | "split" | "calendar") {
        const shell = page.locator("[data-calendar-mode]");

        for (let press = 0; press < 3; press++) {
            if ((await shell.getAttribute("data-calendar-mode")) === mode) {
                return;
            }

            await page.locator("[data-calendar-toggle]").click();
        }

        await expect(shell).toHaveAttribute("data-calendar-mode", mode);
    }

    async function ensurePaneClosed(page: Page) {
        await page.goto("/mail/inbox");
        await setPaneMode(page, "mail");
        await expect(page.locator('[data-ui--split-target="wrapper"]')).toBeHidden();
    }

    test("docks beside the mail and gives the width back when closed", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        const mainPane = page.locator(".main-pane").first();
        const widthWithoutPane = (await mainPane.boundingBox())!.width;

        await setPaneMode(page, "split");

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        const widthWithPane = (await mainPane.boundingBox())!.width;
        expect(widthWithPane).toBeLessThan(widthWithoutPane);

        // The mail is still there beside it — the pane took width, not the page.
        await expect(page.locator("#message-list")).toBeVisible();

        await setPaneMode(page, "mail");
        await expect(paneFrame).toBeHidden();
        expect((await mainPane.boundingBox())!.width).toBeCloseTo(widthWithoutPane, 0);
    });

    /**
     * The third position, and the reason the boolean became a switch: a
     * full-width calendar without navigating away from the mail. The mail is
     * still in the DOM behind it, which is what makes coming back a class
     * change rather than a page load.
     */
    test("cycles mail, split, calendar and back", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        const shell = page.locator("[data-calendar-mode]");
        const messageList = page.locator("#message-list");
        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        const toggle = page.locator("[data-calendar-toggle]");

        await toggle.click();
        await expect(shell).toHaveAttribute("data-calendar-mode", "split");
        await expect(messageList).toBeVisible();
        await expect(paneFrame).toBeVisible();

        await toggle.click();
        await expect(shell).toHaveAttribute("data-calendar-mode", "calendar");
        await expect(paneFrame).toBeVisible();
        await expect(messageList).toBeHidden();

        // The calendar has the row, and it really is the whole row.
        const shellBox = (await shell.boundingBox())!;
        const paneBox = (await page.locator('[data-ui--split-target="pane"]').boundingBox())!;
        expect(paneBox.width).toBeGreaterThan(shellBox.width * 0.7);

        // Nothing navigated: still the inbox, with the mail waiting behind it.
        await expect(page).toHaveURL(/\/mail\/inbox/);

        await toggle.click();
        await expect(shell).toHaveAttribute("data-calendar-mode", "mail");
        await expect(messageList).toBeVisible();
        await expect(paneFrame).toBeHidden();
    });

    /**
     * The handle reaches the same two ends the switch does. Dragging past the
     * pane's maximum keeps moving, with resistance, and letting go past the
     * threshold hands the row to the calendar — which is what the resistance
     * was telling you was there.
     */
    test("dragging the handle past its limit switches to the full calendar", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const shell = page.locator("[data-calendar-mode]");
        const handle = page.locator('[data-ui--split-target="handle"]');
        await expect(handle).toBeVisible();

        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        // Well past the ceiling: the threshold is 140px of raw overshoot, and
        // the ceiling on a 1440px window is a few hundred px to the left.
        await page.mouse.move(20, box.y + box.height / 2, { steps: 16 });
        await page.mouse.up();

        await expect(shell).toHaveAttribute("data-calendar-mode", "calendar");
        await expect(page.locator("#message-list")).toBeHidden();
    });

    /**
     * A view that needs seven columns gets the room for them.
     *
     * The pane draws the same grid the page does now, which means it can be
     * asked to draw a week in 380px — seven slivers under an axis nobody can
     * read. Choosing Week widens it instead, animated, and choosing Day again
     * leaves the width alone: a width nobody asked for is annoying, one that
     * shrinks back the moment you look at a single day is worse.
     */
    test("widening happens by itself when the pane is asked for a week", async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const pane = page.locator('[data-ui--split-target="pane"]');
        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        await page.locator('[data-ui--split-target="handle"]').dblclick();
        await expect(pane).toHaveCSS("width", "380px");

        await paneFrame.getByRole("link", { name: "Week" }).click();

        // The transition is 180ms, so poll rather than reading once.
        await expect
            .poll(async () => (await pane.boundingBox())!.width, { timeout: 2_000 })
            .toBeGreaterThanOrEqual(720);

        // And Day does not take it back. `exact`, because "Today" sits in the
        // same toolbar and matches a loose "Day".
        const day = paneFrame.getByRole("link", { name: "Day", exact: true });

        await day.click();
        await expect(day).toHaveAttribute("aria-current", "page");

        expect((await pane.boundingBox())!.width).toBeGreaterThanOrEqual(720);
    });

    /** And the other end: dragging it shut hands the row back to the mail. */
    test("dragging the handle past the pane's minimum closes it", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const shell = page.locator("[data-calendar-mode]");
        const handle = page.locator('[data-ui--split-target="handle"]');

        // From the default width, so the drag has a known distance to cover —
        // and waited for, because the reset is animated: grabbing the handle
        // mid-transition freezes the pane wherever it had got to, which is the
        // right behaviour and the wrong starting point for a fixed-distance
        // drag.
        await handle.dblclick();
        await expect(page.locator('[data-ui--split-target="pane"]')).toHaveCSS("width", "380px");

        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(
            box.x + box.width / 2 + 400,
            box.y + box.height / 2,
            { steps: 16 },
        );
        await page.mouse.up();

        await expect(shell).toHaveAttribute("data-calendar-mode", "mail");
        await expect(page.locator('[data-ui--split-target="wrapper"]')).toBeHidden();
    });

    test("remembers its width across a reload", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const pane = page.locator('[data-ui--split-target="pane"]');
        await expect(pane).toBeVisible();

        const handle = page.locator('[data-ui--split-target="handle"]');

        // Width is a stored preference, so a previous run can leave the pane
        // already at its ceiling with no room to widen. Double-click resets it
        // to the default first, which makes this test start from a known place
        // rather than from wherever the last one finished.
        await handle.dblclick();
        await expect(pane).toHaveCSS("width", "380px");

        const before = (await pane.boundingBox())!;

        // Drag left, which widens a right-hand pane.
        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 120, box.y + box.height / 2, { steps: 10 });

        // The write happens on release and is deliberately not awaited by the
        // UI, so wait for it here rather than racing the reload against it.
        const persisted = page.waitForResponse(
            async (response) =>
                response.url().includes("/calendar/pane-state") &&
                "POST" === response.request().method() &&
                (await response.json()).width > Math.round(before.width),
        );

        await page.mouse.up();
        await persisted;

        const dragged = (await pane.boundingBox())!.width;
        expect(dragged).toBeGreaterThan(before.width);

        // The width is rendered inline on the next paint, so the reload is the
        // assertion, not the drag.
        await page.reload();
        await expect(pane).toBeVisible();
        expect((await pane.boundingBox())!.width).toBeCloseTo(dragged, 0);
    });

    test("the event dialog is not clipped by the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        await paneFrame.getByLabel("New event").click();

        // backdrop-filter on an ancestor makes it a containing block for fixed
        // positioning, so a dialog rendered inside the pane would be inset to
        // it instead of covering the viewport. Width is the tell.
        const backdrop = page.locator("#modal-backdrop");
        await expect(backdrop).toBeVisible();

        const paneWidth = (await page.locator('[data-ui--split-target="pane"]').boundingBox())!.width;
        expect((await backdrop.boundingBox())!.width).toBeGreaterThan(paneWidth * 2);
    });

    test("reaches the month view from inside the pane", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const paneFrame = page.locator("turbo-frame#calendar-pane-frame");
        await expect(paneFrame).toBeVisible();

        // Icons, not words, at this width — but all four views are reachable,
        // and switching one stays inside the frame rather than navigating the
        // whole page away from the mail.
        await paneFrame.getByRole("link", { name: "Month" }).click();

        await expect(paneFrame.getByRole("link", { name: "Month" })).toHaveAttribute(
            "aria-current",
            "page",
        );
        await expect(page.locator("#message-list")).toBeVisible();
    });

    /**
     * The clamp still holds for a drag that stays inside the rubber band.
     *
     * Deliberately not "drag as far left as possible": that is now a way of
     * asking for the full-width calendar, and it has its own test. What this
     * pins is the settle — go past the ceiling by less than the threshold, let
     * go, and the pane comes back to a width that leaves the mail its minimum.
     */
    test("will not squeeze the mail pane below its minimum", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const mainPane = page.locator('[data-ui--split-target="main"]');
        const pane = page.locator('[data-ui--split-target="pane"]');
        const handle = page.locator('[data-ui--split-target="handle"]');
        await expect(handle).toBeVisible();

        await handle.dblclick();
        await expect(pane).toHaveCSS("width", "380px");

        // How far left the ceiling is from here, worked out the way the
        // controller does — the two panes' combined width less the mail's
        // minimum, capped at the pane's own maximum. Overshooting it by 100
        // engages the band and stays under the 140px threshold, so releasing
        // settles rather than switching.
        const paneWidth = (await pane.boundingBox())!.width;
        const combined = paneWidth + (await mainPane.boundingBox())!.width;
        const ceiling = Math.min(900, combined - 340);

        const box = (await handle.boundingBox())!;
        const from = box.x + box.width / 2;

        await page.mouse.move(from, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(from - (ceiling - paneWidth) - 100, box.y + box.height / 2, { steps: 12 });
        await page.mouse.up();

        // The settle is animated, so give it the transition's length.
        await expect
            .poll(async () => (await mainPane.boundingBox())!.width, { timeout: 2_000 })
            .toBeGreaterThanOrEqual(339);
    });

    test("centres its grip in the gap between the panes", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);
        await setPaneMode(page, "split");

        const mainPane = page.locator('[data-ui--split-target="main"]');
        const pane = page.locator('[data-ui--split-target="pane"]');
        const grip = page.locator('[data-ui--split-target="handle"] span');

        const mainBox = (await mainPane.boundingBox())!;
        const paneBox = (await pane.boundingBox())!;
        const gripBox = (await grip.boundingBox())!;

        // The row's own gap falls to the left of the wrapper, so a handle only
        // one gutter wide sits off to the calendar's side of the gap.
        const gapCentre = (mainBox.x + mainBox.width + paneBox.x) / 2;
        expect(gripBox.x + gripBox.width / 2).toBeCloseTo(gapCentre, 0);
    });

    /**
     * The rows below the list respond to the LIST's width, not the window's.
     * Before the container query they switched on the viewport, so a wide
     * window with a wide calendar pane left a list narrower than a phone still
     * rendering one-line rows, truncated to nothing.
     */
    test("stacks the mail rows once the pane has taken enough width", async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await ensurePaneClosed(page);

        // The content block is what changes shape: a column when the list is
        // narrow, a row when it is wide. Read on flex-direction rather than on
        // visibility — the row's parts sit in zero-height positioning contexts
        // that Playwright calls hidden either way.
        const layout = page.locator("[data-row-layout]").first();

        await expect(layout).toHaveCSS("flex-direction", "row");

        await setPaneMode(page, "split");
        await expect(page.locator("turbo-frame#calendar-pane-frame")).toBeVisible();

        // Drag the pane out to its limit, which is where the list is tightest.
        const handle = page.locator('[data-ui--split-target="handle"]');
        const box = (await handle.boundingBox())!;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(20, box.y + box.height / 2, { steps: 12 });
        await page.mouse.up();

        await expect(layout).toHaveCSS("flex-direction", "column");
    });

    /**
     * The same control at every width. On a phone there is no room to dock
     * beside the mail, so the pane takes the row instead — but it is still the
     * pane, on the same page, and closing it puts the mail back. Navigating to
     * a separate calendar page could not do that.
     */
    test("takes over the row on a phone instead of navigating away", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await ensurePaneClosed(page);

        const mailPane = page.locator(".main-pane").first();
        await expect(mailPane).toBeVisible();

        // Split, not calendar: below lg the row cannot hold both, so the middle
        // position already looks like the last one. That is the case under
        // test — the switch keeps three stops whatever the width, and the width
        // decides what the middle one looks like.
        await setPaneMode(page, "split");

        await expect(page.locator("turbo-frame#calendar-pane-frame")).toBeVisible();
        await expect(mailPane).toBeHidden();

        // Still /mail/inbox — nothing navigated.
        await expect(page).toHaveURL(/\/mail\/inbox/);

        await setPaneMode(page, "mail");
        await expect(mailPane).toBeVisible();
    });

    /** The drawer keeps a plain link to the full page, for a real destination. */
    test("the drawer still links to the calendar page", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await ensurePaneClosed(page);

        await page.getByRole("button", { name: /menu|sidebar/i }).first().click();
        await page.locator("#sidebar-drawer-inner").getByRole("link", { name: "Calendar" }).click();

        await expect(page).toHaveURL(/\/calendar$/);
        await expect(page.locator("#message-list")).toHaveCount(0);
    });
});
