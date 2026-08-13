import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Send later, from the send pill's chevron.
 *
 * The chevron was a `type="button"` with no `data-action` at all — labelled for
 * the accessibility audit, inert to the click. What it opens now is a menu of
 * three presets plus a native date/time field, and the two things worth driving
 * a browser for are the ones no unit test reaches: the preset labels are filled
 * in by the controller after the page loads (a menu rendered by Twig would name
 * "tomorrow morning" in the container's timezone), and the schedule survives the
 * round trip into the Drafts list as a badge the user can find again.
 *
 * Modelled on compose-undo-reopen.spec.ts, and cancelling shares its mechanism:
 * the same undo endpoint, which lowers the flag SendMessageHandler reads and
 * clears the hold.
 */

const DOCK = "#compose_dock";
const VALID = "scheduled-send@example.test";
const SUBJECT = "Scheduled subject";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

function toInput(page: Page) {
    return page.locator(`${DOCK} .ts-control input`).first();
}

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
}

async function fillDock(page: Page, subject = SUBJECT): Promise<void> {
    await toInput(page).fill(VALID);
    await toInput(page).press("Enter");
    await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill(subject);
    await page
        .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
        .fill("Scheduled body text, long enough to save.");
}

/**
 * The pill by placement, not by position.
 *
 * There are two of them in the DOM now — the same partial rendered `md:hidden`
 * in the window header for the phone and `hidden md:flex` in the action bar for
 * the desktop — so "the first dropdown menu in the dock" no longer means the
 * schedule one, and "the visible Send options button" is a different element at
 * 393px than at 1280px. Everything below names which one it means.
 */
/**
 * A wall clock `seconds` from now, in the spelling `datetime-local` uses.
 *
 * The browser's own zone, deliberately: these tests run with the container's
 * timezone as the configured one, so the two agree, and the point of the
 * exercise is the distance from now rather than the zone arithmetic (which
 * ScheduledSendResolverTest covers against real instants).
 */
function wallClockIn(seconds: number): string {
    const at = new Date(Date.now() + seconds * 1000);
    const pad = (value: number) => String(value).padStart(2, "0");

    return (
        `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}` +
        `T${pad(at.getHours())}:${pad(at.getMinutes())}`
    );
}

function pill(page: Page, placement: "bar" | "header" = "bar") {
    return page.locator(`${DOCK} [data-compose-send-pill="${placement}"]`);
}

function menu(page: Page, placement: "bar" | "header" = "bar") {
    return pill(page, placement).locator('[data-ui--dropdown-target="menu"]');
}

async function openSendOptions(page: Page, placement: "bar" | "header" = "bar") {
    await pill(page, placement).getByRole("button", { name: "Send options" }).click();
    await expect(menu(page, placement)).toBeVisible();

    return menu(page, placement);
}

test.describe("scheduled send", () => {
    test("the chevron opens a menu whose presets carry a resolved time", async ({ page }) => {
        await openCompose(page);

        const options = await openSendOptions(page);

        await expect(options.getByText("Tomorrow morning")).toBeVisible();
        await expect(options.getByText("Tomorrow afternoon")).toBeVisible();

        // The times themselves are written by the controller, never by Twig —
        // the server has no business resolving "tomorrow morning" into an hour.
        // An empty span here means the menu shipped labels with no effect the
        // user can read.
        const when = options.locator("[data-schedule-when]").first();

        await expect(when).not.toBeEmpty();
        await expect(when).toHaveText(/\d{1,2}[:.]\d{2}/);
    });

    test("Escape closes it, like every other dropdown", async ({ page }) => {
        await openCompose(page);
        await openSendOptions(page);

        await page.keyboard.press("Escape");

        await expect(menu(page)).toBeHidden();
    });

    test("a preset schedules the send, and the Drafts list says so", async ({ page }) => {
        await openCompose(page);
        await fillDock(page);

        const options = await openSendOptions(page);

        await options.getByText("Tomorrow morning").click();

        // The toast names the time rather than just confirming something
        // happened — a schedule you cannot read back is a schedule you have to
        // take on trust.
        const toast = page.locator("#toast-region").getByText(/Scheduled for/);
        await expect(toast).toBeVisible({ timeout: 10_000 });

        // The window closes; nothing has been sent.
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        // And the draft is still a draft, now badged with when it will go.
        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: SUBJECT })
            .first();

        await expect(row).toBeVisible();
        await expect(row.locator("[data-scheduled-badge]")).toBeVisible();
        await expect(row.locator("[data-scheduled-badge]")).toContainText("Scheduled");

        // Wide, the badge does not cost the preview its line — only the
        // stacked row trades one for the other.
        await expect(row.getByText("Scheduled body text")).toBeVisible();
    });

    test("reopening a scheduled draft offers to call it off", async ({ page }) => {
        const subject = "Scheduled then cancelled";

        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Tomorrow morning").click();
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({
            timeout: 10_000,
        });

        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        await expect(row.locator("[data-scheduled-badge]")).toBeVisible();
        await row.click();

        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            subject,
        );

        // The menu is where the schedule was set, so it is where it is called
        // off — and unlike the toast it is still here tomorrow.
        const reopened = await openSendOptions(page);

        await expect(reopened.getByText("Scheduled to send")).toBeVisible();
        await reopened.getByRole("button", { name: "Cancel scheduled send" }).click();

        // The draft comes back open (the undo stream reopens what it cancels)…
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible({ timeout: 10_000 });

        // …and the hold is gone from the list.
        await page.goto("/mail/drafts");

        const after = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        await expect(after).toBeVisible();
        await expect(after.locator("[data-scheduled-badge]")).toHaveCount(0);
    });

    /**
     * The toast is the positive feedback the whole of this wave is about, and
     * the way back out is on it: the same undo endpoint the ten-second send
     * guard uses, labelled for a hold rather than for a send already flying.
     */
    test("the toast itself can call the hold off, and reopens the draft", async ({ page }) => {
        const subject = "Scheduled then undone from the toast";

        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Tomorrow morning").click();

        const toast = page.locator("#toast-region").getByText(/Scheduled for/);
        await expect(toast).toBeVisible({ timeout: 10_000 });

        // Not "Undo": there is nothing under way to undo yet.
        await page
            .locator("#toast-region")
            .getByRole("button", { name: "Cancel send" })
            .click();

        // The composer comes back with what was written still in it…
        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            subject,
            { timeout: 10_000 },
        );

        // …and the hold is off the row.
        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        await expect(row).toBeVisible();
        await expect(row.locator("[data-scheduled-badge]")).toHaveCount(0);
    });

    /**
     * And the same call-off from the row, which is where a hold set on Friday
     * is actually found again on Monday.
     *
     * The row is an <li> under an absolutely-positioned overlay <a>, so the two
     * things worth driving a browser for are that the click reaches the button
     * at all and that it does NOT also open the composer — and that the row
     * updates where it stands, without the page going anywhere.
     */
    test("the hold can be called off from the Drafts row, in place", async ({ page }) => {
        const subject = "Scheduled then cancelled from the row";

        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Tomorrow morning").click();
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({
            timeout: 10_000,
        });

        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        const badge = row.locator("[data-scheduled-badge]");
        await expect(badge).toBeVisible();

        // Pin the URL: if the overlay anchor takes the click, this navigates.
        const before = page.url();

        await row.locator("[data-cancel-schedule]").click();

        await expect(page.locator("#toast-region").getByText(/Scheduled send cancelled/)).toBeVisible({
            timeout: 10_000,
        });

        // Redrawn where it stood — same row, no badge, no navigation, and the
        // composer was never opened over it.
        await expect(row).toBeVisible();
        await expect(row.locator("[data-scheduled-badge]")).toHaveCount(0);
        expect(page.url()).toBe(before);
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        // And it stayed cancelled server-side, not just on screen.
        await page.reload();
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: subject })
                .first()
                .locator("[data-scheduled-badge]"),
        ).toHaveCount(0);
    });

    test("pick date & time reveals a native input, and refuses a time already gone", async ({
        page,
    }) => {
        await openCompose(page);
        await fillDock(page, "Scheduled custom");

        const options = await openSendOptions(page);

        await options.getByText("Pick date & time").click();

        // Native, deliberately — no picker library anywhere in this codebase.
        const field = options.locator('input[type="datetime-local"]');
        await expect(field).toBeVisible();

        // Seeded rather than left empty, and bounded so the browser refuses the
        // out-of-range times before the server has to.
        await expect(field).not.toHaveValue("");
        await expect(field).toHaveAttribute("min", /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        await expect(field).toHaveAttribute("max", /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);

        await field.fill("2020-01-01T09:00");
        await options.getByRole("button", { name: "Schedule send" }).click();

        // Refused in place, with the window still open and nothing scheduled.
        await expect(options.getByText(/already passed/)).toBeVisible();
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toHaveCount(0);
    });

    /**
     * THE PATH A PERSON ACTUALLY TAKES, and the one this file used to leave
     * untested.
     *
     * Every passing test above set the hold from a PRESET. The custom picker
     * appeared only in the test above this one, which drives it to a REFUSAL —
     * so "pick a date and time, and have the message actually be scheduled" was
     * asserted nowhere, and the feature was broken in exactly that gap while the
     * suite stayed green.
     *
     * The time is minutes out rather than tomorrow because that is what a human
     * types, and because the interesting bugs live near the floor rather than
     * near the ceiling.
     */
    test("a custom date and time schedules the send, all the way to the Drafts list", async ({
        page,
    }) => {
        const subject = "Scheduled from the custom picker";

        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Pick date & time").click();

        const field = options.locator('input[type="datetime-local"]');
        await expect(field).toBeVisible();

        await field.fill(wallClockIn(10 * 60));
        await options.getByRole("button", { name: "Schedule send" }).click();

        // The same three consequences a preset has. Not "a request was made":
        // the broken version made a request too, and that is precisely why the
        // absence of this assertion cost nothing to break.
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({
            timeout: 10_000,
        });
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        await expect(row).toBeVisible();
        await expect(row.locator("[data-scheduled-badge]")).toBeVisible();

        // Server-side, not merely on screen — a badge painted by a Turbo stream
        // and never persisted would pass everything above.
        await page.reload();
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: subject })
                .first()
                .locator("[data-scheduled-badge]"),
        ).toBeVisible();
    });

    /**
     * The floor, refused in the browser rather than swallowed by the server.
     *
     * This is the exact click that was reported as "Schedule send does
     * nothing": subject and body present, the next whole minute typed into the
     * picker, Schedule send pressed. Under ScheduledSendResolver::MIN_SECONDS
     * the server refused it, reported the refusal as a ROOT form error, and
     * re-rendered a compose window that had nowhere to render one — so the menu
     * closed, the composer sat there unchanged, no toast appeared, and nothing
     * was ever scheduled or sent. A request WAS made, which is what made it look
     * like a server bug rather than a missing message.
     *
     * Both halves are asserted: the refusal is now local and legible, and
     * nothing is posted at all.
     */
    test("a time inside the one-minute floor is refused in the window, not silently", async ({
        page,
    }) => {
        await openCompose(page);
        await fillDock(page, "Scheduled too soon");

        const posts: string[] = [];
        page.on("request", (request) => {
            if ("POST" === request.method() && request.url().includes("/compose/schedule")) {
                posts.push(request.url());
            }
        });

        const options = await openSendOptions(page);
        await options.getByText("Pick date & time").click();

        const field = options.locator('input[type="datetime-local"]');
        await expect(field).toBeVisible();

        // Forty seconds out, which is what "the next whole minute" usually is.
        await field.fill(wallClockIn(40));
        await options.getByRole("button", { name: "Schedule send" }).click();

        // Said, in the menu, in words that are true of the time chosen — the old
        // wording claimed it had already passed, which it plainly had not.
        const refusal = options.locator('[data-compose--schedule-target="error"]');

        await expect(refusal).toBeVisible();
        await expect(refusal).toHaveText(/at least a minute/);

        // Announced, too. The refusal IS the entire response to the click.
        await expect(refusal).toHaveAttribute("role", "alert");

        // The window stays, with everything still in it, and nothing went out.
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toHaveCount(0);
        expect(posts, "the refusal is local — no round trip at all").toHaveLength(0);
    });
});

/**
 * Scheduling from a phone.
 *
 * It did not exist. The send pill was `hidden md:flex`, so below md there was
 * only a plain Send icon in the header and no chevron at all — the Drafts row
 * was the only place a hold could be seen, and there was no way to set one from
 * the device in the first place. The pill is rendered at every width now, so
 * everything this describe block used to assert is inverted: not "the chevron
 * is not on screen" but "the chevron is on screen, big enough to hit, and the
 * menu it opens fits".
 *
 * And it is in the HEADER below md, which is where the removed Send icon used
 * to live and where the user asked for it back. The action-bar copy is
 * `hidden md:flex` behind it, so the count of Sends on screen is still one.
 *
 * Measured, not located. The header menu opens DOWNWARD (`top-full`) and
 * anchors `right-0`; the bar's opens upward and anchors `left-0`. Neither
 * direction is obviously right — each is wrong in the other's place, which is
 * why both are measured here rather than assumed.
 */
test.describe("scheduled send on a phone", () => {
    /**
     * Sized rather than emulated: `test.use({ ...devices })` inside a describe
     * forces a new worker and Playwright refuses it. The breakpoint under test
     * is a width, and this is the width.
     */
    const PHONE = { width: 393, height: 851 };

    async function openComposeAt(page: Page, size: { width: number; height: number }) {
        await page.setViewportSize(size);
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
    }

    for (const size of [PHONE, { width: 320, height: 568 }]) {
        test(`the pill and its menu fit at ${size.width}px`, async ({ page }) => {
            await openComposeAt(page, size);

            // Everything below the header lives in the `collapsible` block, so
            // "above its top edge" is "in the header" without depending on the
            // header having a hook of its own.
            const body = page.locator(`${DOCK} [data-compose--compose-target="collapsible"]`);
            const send = page
                .locator(`${DOCK} .compose-window`)
                .getByRole("button", { name: "Send", exact: true });
            const chevron = page
                .locator(`${DOCK} .compose-window`)
                .getByRole("button", { name: "Send options" });

            // Still exactly one of each on screen. `getByRole` matches the
            // accessibility tree, so the `hidden md:flex` copy in the action bar
            // is not counted — and if it ever stops being hidden, this is what
            // says so.
            await expect(send).toHaveCount(1);
            await expect(chevron).toHaveCount(1);
            await expect(send).toBeVisible();
            await expect(chevron).toBeVisible();

            // And the one on screen is the HEADER one, which is what the user
            // asked for: the pill where the old Send icon was.
            await expect(pill(page, "header")).toBeVisible();
            await expect(pill(page, "bar")).toBeHidden();

            const bodyBox = (await body.boundingBox())!;
            const sendBox = (await send.boundingBox())!;

            expect(
                sendBox.y + sendBox.height,
                `the pill sits in the header: ${JSON.stringify({ bodyBox, sendBox })}`,
            ).toBeLessThanOrEqual(bodyBox.y + 0.5);

            // …and the header itself has not been pushed sideways to hold it.
            expect(sendBox.x + sendBox.width).toBeLessThanOrEqual(size.width + 0.5);

            // 44px both ways. The last round shipped an 11px hit area here.
            const chevronBox = (await chevron.boundingBox())!;

            expect(chevronBox.width, JSON.stringify(chevronBox)).toBeGreaterThanOrEqual(44);
            expect(chevronBox.height, JSON.stringify(chevronBox)).toBeGreaterThanOrEqual(44);

            await chevron.click();

            const options = menu(page, "header");

            await expect(options).toBeVisible();

            const box = (await options.boundingBox())!;

            // Inside the viewport on both axes …
            expect(box.x, JSON.stringify(box)).toBeGreaterThanOrEqual(-0.5);
            expect(box.x + box.width, JSON.stringify(box)).toBeLessThanOrEqual(size.width + 0.5);
            expect(box.y, JSON.stringify(box)).toBeGreaterThanOrEqual(-0.5);
            expect(box.y + box.height, JSON.stringify(box)).toBeLessThanOrEqual(size.height + 0.5);

            // … and big enough to be worth opening. A menu clipped to 0px tall
            // is inside the viewport too, which is how the more-options menu
            // shipped unreadable last round.
            expect(box.width, JSON.stringify(box)).toBeGreaterThan(180);
            expect(box.height, JSON.stringify(box)).toBeGreaterThan(80);

            // DOWNWARD. A header pill that kept the action bar's `bottom-full`
            // opens off the top of the phone — which the containment checks
            // above would catch, but not say why. This says why.
            expect(
                box.y,
                `the menu opens below the pill: ${JSON.stringify({ sendBox, box })}`,
            ).toBeGreaterThanOrEqual(sendBox.y + sendBox.height - 0.5);

            // The presets are filled in by the controller, on a phone as on a
            // desktop — a menu of labels with no times is a menu of buttons
            // whose effect cannot be read.
            await expect(options.locator("[data-schedule-when]").first()).toHaveText(
                /\d{1,2}[:.]\d{2}/,
            );
        });
    }

    /**
     * And it is not merely drawn: a preset actually sets the hold from a phone.
     *
     * This is also the one test that would catch the two `schedule_at` fields
     * disagreeing. Both pills carry one, both are named the same and both are
     * in one form, so if the untouched copy were submitted alongside this one
     * PHP would keep the LAST in document order — the action bar's, empty — and
     * the schedule would come back as "send now". The fields ship `disabled`
     * and compose--schedule arms only its own; a regression there lands here as
     * a message sent instead of held.
     */
    test("a preset schedules the send from the phone itself", async ({ page }) => {
        const subject = "Scheduled from a phone";

        await openComposeAt(page, PHONE);
        await fillDock(page, subject);

        const options = await openSendOptions(page, "header");

        await options.getByText("Tomorrow morning").click();

        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({
            timeout: 10_000,
        });
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        await expect(row.locator("[data-scheduled-badge]")).toBeVisible();
    });

    /**
     * The badge and its cancel button on a phone — no longer the ONLY place a
     * hold can be called off (the pill's menu is there too now), but still the
     * place a hold set on Friday is found again on Monday.
     *
     * Measured, not merely located. The last round shipped a panel that was off
     * screen at this width while the spec passed, because the spec asserted
     * existence. So: the whole pill inside the viewport, the button big enough
     * to hit, and the row not pushed into a sideways scroll by it.
     */
    test("the scheduled badge and its cancel button fit, and work, at 393px", async ({ page }) => {
        const subject = "Scheduled, seen on a phone";

        // Set the hold at desktop width; the phone is what this measures.
        await page.setViewportSize({ width: 1280, height: 900 });
        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Tomorrow morning").click();
        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({
            timeout: 10_000,
        });

        await page.setViewportSize(PHONE);
        await page.goto("/mail/drafts");

        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: subject })
            .first();

        const badge = row.locator("[data-scheduled-badge]");
        const cancel = row.locator("[data-cancel-schedule]");

        await expect(badge).toBeVisible();
        await expect(cancel).toBeVisible();

        const badgeBox = await badge.boundingBox();
        const cancelBox = await cancel.boundingBox();

        expect(badgeBox, "the badge has a box at all").not.toBeNull();
        expect(cancelBox, "so does the cancel button").not.toBeNull();

        expect(badgeBox!.x, JSON.stringify(badgeBox)).toBeGreaterThanOrEqual(-0.5);
        expect(badgeBox!.x + badgeBox!.width).toBeLessThanOrEqual(PHONE.width + 0.5);
        expect(cancelBox!.x + cancelBox!.width).toBeLessThanOrEqual(PHONE.width + 0.5);

        // Big enough to hit with a thumb. Not the 44px ideal — this is a chip
        // inside a list row — but it must not be a 6px hairline.
        expect(cancelBox!.width).toBeGreaterThanOrEqual(16);
        expect(cancelBox!.height).toBeGreaterThanOrEqual(14);

        // The row must not have grown a sideways scroll to hold it.
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - window.innerWidth,
        );
        expect(overflow, "the page scrolls sideways").toBeLessThanOrEqual(1);

        await page.screenshot({ path: "test-results/drafts-scheduled-phone-light.png" });

        // The theme is an attribute on <html>, not the OS preference —
        // emulateMedia({ colorScheme }) renders the light theme back, which is
        // how the first version of this shot came out identical to the one
        // above. The badge is bg-info/10 on text-info, and "10% of the info
        // colour" is exactly the kind of tint that survives one theme and
        // disappears in the other.
        await page.evaluate(() => document.documentElement.setAttribute("data-theme", "dark"));
        await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
        await expect(badge).toBeVisible();
        await page.screenshot({ path: "test-results/drafts-scheduled-phone-dark.png" });

        // And it is not decoration: the tap calls the hold off, on the phone
        // where it is the only way to.
        await page.goto("/mail/drafts");
        await cancel.click();

        await expect(
            page.locator("#toast-region").getByText(/Scheduled send cancelled/),
        ).toBeVisible({ timeout: 10_000 });
        await expect(row.locator("[data-scheduled-badge]")).toHaveCount(0);
    });
});
