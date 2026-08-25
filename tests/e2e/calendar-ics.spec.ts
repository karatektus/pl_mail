import { expect } from "@playwright/test";
import { test } from "./support/test";
import { consoleCommand } from "./support/config";
import { choose } from "./support/select";
import { acceptConfirm } from "./support/confirm";

/**
 * iCalendar as a person handles it: a file in, a file out, an address followed.
 *
 * A browser spec because all three are things a user does with the *chrome*
 * rather than with a service — an upload control, a download whose headers
 * decide whether the browser saves it or renders it, and a modal that has to
 * stay open when an address is refused. None of those is visible to a PHPUnit
 * test of the classes underneath, and each of them has broken before in ways
 * that leave the feature looking like it works: a form missing `enctype` posts
 * the file's name and the server sees an empty upload; a download link Turbo
 * intercepts swaps an .ics into the page and downloads nothing.
 *
 * Import and export are asserted against each other on purpose. The round trip
 * is the claim the whole feature rests on — a calendar exported and re-imported
 * is the same calendar, not a second copy of it — and it is also the strongest
 * single check available here, because it fails if either half loses the UID.
 *
 * ── Why the successful subscribe is not here ──────────────────────────────
 *
 * The two subscribe tests below are both refusals, and that is a limit of the
 * environment rather than an omission. A feed is fetched server-side, and every
 * address this stack can actually reach is loopback or a private range — which
 * IntegrationUrlValidator refuses, correctly, as the SSRF guard it is. The same
 * reasoning is written down in tests/Support/Calendar/ScriptedCalendarSyncDriver:
 * pointing a spec at a real server, or weakening the guard for a test, was
 * rejected there for this feature's sake too. The success path is covered by
 * IcsControllerTest, which drives the same controller over a scripted transport.
 *
 * What these two DO hold is that the path exists and is wired: the button opens
 * the form, the form reaches the controller, the controller reaches the
 * validator and the fetch, and a refusal comes back inside the dialog instead of
 * as a 500 or a silent close.
 */

const CALENDAR = `E2E ICS ${Date.now()}`;
const UID = `e2e-ics-${Date.now()}@plmail.test`;
const TITLE = "E2E imported meeting";

/**
 * The premise, made rather than assumed — the same reason
 * calendar-settings.spec.ts provisions here. A freshly seeded worker owns no
 * calendars at all, and the import picker needs at least one that accepts
 * writes.
 */
function provisionCalendars(): void {
    consoleCommand("app:backfill calendars");
}

/** A minimal but realistic file: one timed meeting and one all-day holiday. */
function fixture(): Buffer {
    return Buffer.from(
        [
            "BEGIN:VCALENDAR",
            "VERSION:2.0",
            "PRODID:-//plMail E2E//EN",
            "BEGIN:VEVENT",
            `UID:${UID}`,
            "DTSTAMP:20260101T000000Z",
            "DTSTART:20260812T090000Z",
            "DTEND:20260812T100000Z",
            `SUMMARY:${TITLE}`,
            "LOCATION:Room 3",
            "END:VEVENT",
            "BEGIN:VEVENT",
            `UID:allday-${UID}`,
            "DTSTAMP:20260101T000000Z",
            "DTSTART;VALUE=DATE:20260813",
            "DTEND;VALUE=DATE:20260814",
            "SUMMARY:E2E imported holiday",
            "END:VEVENT",
            "END:VCALENDAR",
            "",
        ].join("\r\n"),
        "utf8",
    );
}

test.describe("calendar files and feeds", () => {
    test.beforeAll(provisionCalendars);

    test.beforeEach(async ({ page }) => {
        await page.goto("/settings?section=calendars");
    });

    test.afterEach(async ({ page }) => {
        // The calendar goes and its events go with it, by the cascade on the
        // join column — which is also what makes this cleanup complete rather
        // than a best effort at finding rows the run created.
        await page.goto("/settings?section=calendars");

        const remove = page.getByRole("button", { name: `Delete calendar "${CALENDAR}"` });

        if ((await remove.count()) > 0) {
            await remove.first().click();
            await acceptConfirm(page);
            await expect(page.getByText(CALENDAR, { exact: true })).toHaveCount(0);
        }
    });

    /**
     * The round trip, end to end and through the chrome: a file uploaded
     * through the modal, and the same calendar downloaded back out carrying
     * what went in.
     */
    test("imports an .ics and exports the same calendar back out", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: "New calendar" }).click();
        await expect(modal.getByLabel("Name")).toHaveValue("");
        await modal.getByLabel("Name").fill(CALENDAR);
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(page.getByText(CALENDAR, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: "Import a calendar file" }).click();

        // Waiting on the control the form owns rather than on the backdrop: the
        // backdrop keeps the previous dialog until Turbo swaps the frame, so a
        // fill that only waited for "visible" acts on the form that was there
        // before — which reads as an import that did nothing.
        await expect(modal.getByLabel("Calendar file")).toBeVisible();

        await modal.getByLabel("Calendar file").setInputFiles({
            name: "e2e.ics",
            mimeType: "text/calendar",
            buffer: fixture(),
        });
        await choose(modal, "Add them to", CALENDAR);
        await modal.getByRole("button", { name: "Import" }).click();

        // The toast is the only place the counts are said, and saying them is
        // the point: "0 added" on a file full of events is what an import that
        // silently matched everything looks like, and it must be
        // distinguishable from one that failed.
        await expect(page.getByText("2 added, 0 updated, 0 skipped.")).toBeVisible();

        const download = page.getByRole("link", { name: `Download "${CALENDAR}" as a calendar file` });

        await expect(download).toBeVisible();

        const href = await download.getAttribute("href");
        expect(href).not.toBeNull();

        // Fetched through the page's own context, so it carries the session
        // cookie — this is the same request the browser would make on a click,
        // without needing a download event to inspect the bytes.
        const exported = await page.request.get(href as string);

        expect(exported.status()).toBe(200);
        expect(exported.headers()["content-type"]).toContain("text/calendar");
        expect(exported.headers()["content-disposition"]).toContain("attachment");

        const body = await exported.text();

        // One document, not one per event: a file with several VCALENDAR blocks
        // imports as its first event everywhere.
        expect(body.match(/BEGIN:VCALENDAR/g)).toHaveLength(1);
        expect(body).toContain(`UID:${UID}`);
        expect(body).toContain(`SUMMARY:${TITLE}`);
        // An all-day event is a DATE. Written as a midnight date-time it shifts
        // by a day for anybody east or west of the writer.
        expect(body).toContain("DTSTART;VALUE=DATE:20260813");

        // Re-importing what was just exported must update rather than duplicate:
        // this is the assertion that fails if either half loses the UID.
        await page.getByRole("button", { name: "Import a calendar file" }).click();
        await expect(modal.getByLabel("Calendar file")).toBeVisible();
        await modal.getByLabel("Calendar file").setInputFiles({
            name: "again.ics",
            mimeType: "text/calendar",
            buffer: Buffer.from(body, "utf8"),
        });
        await choose(modal, "Add them to", CALENDAR);
        await modal.getByRole("button", { name: "Import" }).click();

        await expect(page.getByText("0 added, 2 updated, 0 skipped.")).toBeVisible();
    });

    /**
     * The imported event is on the calendar, and its editor offers the file
     * back. Both halves matter: an event that imports into a row nothing draws
     * is an import that appears to have failed, and a download link Turbo
     * intercepts swaps an .ics into the page instead of saving it.
     */
    test("an imported event is on the calendar and can be downloaded on its own", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: "New calendar" }).click();
        await expect(modal.getByLabel("Name")).toHaveValue("");
        await modal.getByLabel("Name").fill(CALENDAR);
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(page.getByText(CALENDAR, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: "Import a calendar file" }).click();
        await expect(modal.getByLabel("Calendar file")).toBeVisible();
        await modal.getByLabel("Calendar file").setInputFiles({
            name: "e2e.ics",
            mimeType: "text/calendar",
            buffer: fixture(),
        });
        await choose(modal, "Add them to", CALENDAR);
        await modal.getByRole("button", { name: "Import" }).click();
        await expect(page.getByText("2 added, 0 updated, 0 skipped.")).toBeVisible();

        // The agenda, not the day grid, and for the same reason
        // calendar.spec.ts counts its chips there: the time grid draws a chip
        // as a draggable control, so a plain click is a drag gesture of zero
        // length rather than an unambiguous open. The agenda's chip is a link
        // to the editor and nothing else.
        await page.goto("/calendar/agenda/2026-08-12");

        // By role, not by text. A chip's accessible name carries the time as
        // well as the title, and the title also appears in the tooltip beside
        // it — a text locator picks whichever the DOM offers first, and the
        // tooltip opens nothing.
        const chip = page.getByRole("button", { name: new RegExp(TITLE) }).first();

        await expect(chip).toBeVisible();
        await chip.click();

    // The chip opens the DETAILS panel now — reading an event is the common
    // case, and it used to be the one you could not do without opening a form.
    // Edit is one step in, so the specs that drive the editor click through it.
    await modal.getByRole("link", { name: "Edit" }).click();

        // Waited on the field's value rather than on the dialog being visible:
        // the backdrop keeps the previous dialog's markup until Turbo swaps the
        // frame, so anything read straight after the click is read off the form
        // that was there before.
        await expect(modal.getByLabel("Title")).toHaveValue(TITLE);
        await expect(modal.getByRole("link", { name: "Download .ics" })).toBeVisible();

        const href = await modal.getByRole("link", { name: "Download .ics" }).getAttribute("href");
        const exported = await page.request.get(href as string);

        expect(exported.status()).toBe(200);

        const body = await exported.text();

        expect(body).toContain(`UID:${UID}`);
        expect(body).not.toContain(`UID:allday-${UID}`);
    });

    /**
     * The SSRF guard, met from the form. The dialog has to stay open with the
     * sentence in it: the modal closes on any successful submit, so a 200 here
     * would read as a subscription that worked.
     */
    test("refuses a calendar address pointing inside the network", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: "Subscribe to an address" }).click();
        await expect(modal.getByLabel("Calendar address")).toBeVisible();

        await modal.getByLabel("Calendar address").fill("https://127.0.0.1/holidays.ics");
        await modal.getByRole("button", { name: "Subscribe" }).click();

        await expect(modal.getByRole("alert")).toContainText("private network");
        await expect(modal.getByLabel("Calendar address")).toHaveValue("https://127.0.0.1/holidays.ics");
    });

    /**
     * An address nothing answers at. Proves the whole path is wired — form to
     * controller to normaliser to fetch — and that a subscription that could not
     * be read leaves no connection behind for the user to find and delete before
     * they can paste the corrected address.
     *
     * webcal:// deliberately, because that is what a "Subscribe" button copies:
     * a form that refused the scheme outright would never reach the fetch this
     * test is about.
     */
    test("an address that answers nothing leaves no connection behind", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: "Subscribe to an address" }).click();
        await expect(modal.getByLabel("Calendar address")).toBeVisible();

        await modal.getByLabel("Calendar address").fill("webcal://e2e-nothing-here.invalid/holidays.ics");
        await modal.getByRole("button", { name: "Subscribe" }).click();

        await expect(modal.getByRole("alert")).toBeVisible();

        await page.goto("/settings?section=calendars");
        await expect(page.getByText("e2e-nothing-here", { exact: false })).toHaveCount(0);
    });
});
