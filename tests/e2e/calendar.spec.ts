import { expect, type Locator, type Page } from "@playwright/test";
import { setWhen } from "./support/datetime";
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
 * them. Neither of the obvious alternatives works: the week shows seven days of
 * a series that may run longer, and the month grid renders only the first three
 * events of each day, so a count taken from either is a count of what fits.
 * Agenda is thirty days from today and draws all of them.
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

    // The chip opens the DETAILS panel now — reading an event is the common
    // case, and it used to be the one you could not do without opening a form.
    // Edit is one step in, so the specs that drive the editor click through it.
    await modal.getByRole("link", { name: "Edit" }).click();

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
 * Push both ends of the editor's times out by a couple of hours, and leave the
 * event exactly as long as it was.
 *
 * Relative to whatever the fields already hold rather than to a literal clock:
 * the two fields are read in the calendar's zone and the chips are rendered in
 * the reader's, so the only assertion that holds in every install is that the
 * chip moved, not that it moved to 11:00.
 *
 * **Both values are read BEFORE either is written, and that is the whole point
 * of the shape below.** The editor moves the end by itself when the start moves
 * — calendar--event-form#shiftEnd, bound to the start field's `change`, which is
 * the behaviour every other calendar has and which Playwright's `fill` triggers
 * exactly as a person does. A loop that read each field just before writing it
 * therefore read an end the browser had ALREADY pushed out by `hours` and pushed
 * it out by `hours` again: a one-hour event asked to move two hours came out
 * three hours long, moved by two at the start and by four at the end.
 *
 * That silent lengthening is what made "moves one occurrence" fail in CI for two
 * hours out of every day, deterministically enough to survive the retry. A new
 * event starts at the next full hour on the calendar's clock, so the tripled
 * occurrence ran from 22:00 or 23:00 to 01:00 or 02:00 — across local midnight —
 * and an occurrence that touches two days is drawn on both of them by
 * CalendarRangeReader::groupByLocalDay, which is correct and is why the agenda
 * then held thirty-one chips for thirty occurrences. Writing the end from the
 * value it had before anything touched the start keeps the duration, and a
 * one-hour event that begins on the hour cannot reach the next day.
 */
async function shiftTimesBy(modal: Locator, hours: number) {
    // The hidden inputs carry the value under calendar--when's picker; this
    // spec is about the series save, not the picker, so it sets them directly.
    const starts = modal.locator("#event-starts");
    const ends   = modal.locator("#event-ends");

    const movedStart = new Date(await starts.inputValue());
    const movedEnd   = new Date(await ends.inputValue());

    movedStart.setHours(movedStart.getHours() + hours);
    movedEnd.setHours(movedEnd.getHours() + hours);

    // The start first, so the end written after it is the one that stands: the
    // order the other way round would have shiftEnd overwrite the end that was
    // just filled in.
    await setWhen(starts, localValue(movedStart));
    await setWhen(ends, localValue(movedEnd));
}

/**
 * Put a new event at noon for an hour, on the day the editor offered.
 *
 * The editor offers the next full hour, so without this the recurring cases
 * below test a different event depending on when the suite runs — and the
 * agenda they count chips in is thirty days long, which is long enough to reach
 * a daylight-saving change for two months of every year. Run late in the
 * evening in October, the series sat at 00:00 and "moving all events" put it at
 * 02:00 to 03:00: on the last Sunday of the month that hour happens twice, the
 * occurrence runs from the first 02:00 to the second, and its chip reads
 * "2:00 – 2:00" among twenty-eight that read "2:00 – 3:00". That is the right
 * hour-long event and one label too many for a case that asserts they all match.
 * In March the same hour does not exist and the chip moves to 3:00 instead.
 *
 * Noon, and the 14:00 the cases shift it to, are hours no zone changes its
 * clock in, so every occurrence of the series carries the same label whatever
 * the date and whatever the time of day.
 */
async function moveToMidday(modal: Locator) {
    const starts = modal.locator("#event-starts");
    const day    = (await starts.inputValue()).slice(0, 10);

    // The start first, for the reason shiftTimesBy() gives.
    await setWhen(starts, `${day}T12:00`);
    await setWhen(modal.locator("#event-ends"), `${day}T13:00`);
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
        }

        // Asserted after the loop, not inside it. Inside, the first pass that
        // left anything behind threw — out of the hook, past the two remaining
        // attempts — so the retry the comment above describes never happened
        // and the loop was decoration. Worse, the throw is attributed to the
        // test whose hook it is, so a leak left by one case is reported as a
        // failure of the case that just passed. That is a bad half-hour for
        // whoever reads the report.
        await page.goto("/calendar/agenda");
        await expect(chipsOf(page), "cleanup left chips behind").toHaveCount(0);
    });

    test("creates an event and shows it in the week", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.getByRole("button", { name: "New event", exact: true }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        await modal.getByLabel("Title").fill(TITLE);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByRole("button", { name: new RegExp(TITLE) }).first()).toBeVisible();

        // And it SAYS so. A save used to redirect in silence, which is
        // indistinguishable from a form that did nothing the moment the event
        // lands somewhere the view does not read — see
        // CalendarSaveVisibilityTest for the case where that happened, and for
        // why "the chip is visible" alone was not enough to catch it.
        await expect(page.locator('#toast-region [data-flash-toast="success"]')).toContainText(
            "Event saved",
        );
    });

    test("switches between day, week and month", async ({ page }) => {
        await page.goto("/calendar/week");

        // The label and the URL segment part ways at the week: it is the seven
        // days from today, so it is called that, and `week` stays its address.
        for (const [label, segment] of [["Day", "day"], ["Month", "month"], ["7 days", "week"]]) {
            await page.getByRole("link", { name: label, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`/calendar/${segment}`));
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

        // Asserted in the MONTH view, not the week it was created in.
        //
        // While the week ran Monday to Sunday, a daily event was on every
        // REMAINING day of it — six chips on a Monday and exactly one on a
        // Sunday — so this failed one day in seven, which is the worst shape a
        // test can have: it looks like whatever was committed that day broke
        // the calendar. A month has remaining days whatever the weekday, and
        // the claim — a repeating event appears on more than one day — does not
        // depend on what the week view happens to start on.
        await page.getByRole("link", { name: "Month", exact: true }).click();
        await expect(page).toHaveURL(/\/calendar\/month/);

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
        await moveToMidday(modal);
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
 * Counted in the agenda view, for the reason chipsOf() gives above: the week
 * shows only seven days and the month grid draws only the first three events of
 * a day, so agenda is the only view whose chip count is a count of what exists
 * rather than of what fits.
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

        // Through the details panel: a chip opens that now, with Edit one
        // step in. See openChip() above.
        await modal.getByRole("link", { name: "Edit" }).click();

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

    /**
     * The mark of a merged chip is split between its calendars' colours, and
     * the split runs ALONG the mark. In a month cell wide enough for the title
     * the mark stands upright and is split top to bottom; in one too narrow
     * for words the chip is the mark alone, lying flat — and split top to
     * bottom there it was two hairlines stacked in a bar a few pixels tall,
     * where the two colours belong side by side. Reported from a phone-width
     * month.
     */
    test("splits a flat month bar's colours side by side, an upright one's top to bottom", async ({ page }) => {
        seed("seed-duplicate-event");

        const mark = () => meetingChips(page).first().locator("span[aria-hidden='true'][style*='gradient']");
        const split = async () =>
            mark().evaluate((el) => {
                const box = el.getBoundingClientRect();

                return {
                    flat: box.width > box.height,
                    image: getComputedStyle(el).backgroundImage,
                };
            });

        await page.setViewportSize({ width: 1400, height: 900 });
        await page.goto("/calendar/month");
        await expect(meetingChips(page).first()).toBeVisible();

        const upright = await split();

        expect(upright.flat, "beside a title the mark stands upright").toBe(false);
        // Top to bottom is a gradient's default, and a browser reports the
        // computed value without it — so upright is "a gradient, not to the
        // right" rather than a literal `to bottom`.
        expect(upright.image).toContain("linear-gradient(");
        expect(upright.image).not.toContain("to right");

        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto("/calendar/month");
        await expect(mark()).toBeVisible();

        const flat = await split();

        expect(flat.flat, "in a cell too narrow for words the mark lies flat").toBe(true);
        expect(flat.image).toContain("to right");
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
