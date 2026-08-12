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

function menu(page: Page) {
    return page.locator(`${DOCK} [data-ui--dropdown-target="menu"]`).first();
}

async function openSendOptions(page: Page) {
    await page.locator(DOCK).getByRole("button", { name: "Send options" }).click();
    await expect(menu(page)).toBeVisible();

    return menu(page);
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
});

/**
 * On a phone the send pill is not rendered at all — Send lives in the header,
 * where it is always on screen — so there is no chevron for a menu to open off
 * the edge of. Asserted rather than assumed: the failure this guards against is
 * a menu positioned `right-0` inside a fullscreen window, which would be the
 * first thing to hang off the viewport.
 */
test.describe("scheduled send on a phone", () => {
    /**
     * Sized rather than emulated: `test.use({ ...devices })` inside a describe
     * forces a new worker and Playwright refuses it. The breakpoint under test
     * is a width, and this is the width.
     */
    const PHONE = { width: 393, height: 851 };

    test("the send-options chevron is not on screen to open off it", async ({ page }) => {
        await page.setViewportSize(PHONE);
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();

        await expect(page.locator(DOCK).getByRole("button", { name: "Send options" })).toBeHidden();

        // Send itself is still reachable, in the header.
        await expect(
            page.locator(`${DOCK} .compose-window`).getByRole("button", { name: "Send" }),
        ).toBeVisible();
    });

    /**
     * And if the pill is ever brought below md, the menu it opens must stay
     * inside the viewport. Kept as a measurement rather than a comment so the
     * day someone unhides it, this fails instead of shipping.
     */
    test("were the menu shown, it would fit the viewport", async ({ page }) => {
        await page.setViewportSize(PHONE);
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();

        const fits = await page.evaluate(() => {
            const pill = document.querySelector(
                "#compose_dock [data-controller~='compose--schedule']",
            ) as HTMLElement | null;

            if (null === pill) {
                return { present: false, fits: true };
            }

            const menu = pill.querySelector(
                "[data-ui--dropdown-target='menu']",
            ) as HTMLElement | null;

            if (null === menu) {
                return { present: false, fits: true };
            }

            // Show the whole pill for the measurement, then put it back.
            const hiddenClasses = ["hidden"];
            const removed = hiddenClasses.filter((c) => pill.classList.contains(c));
            removed.forEach((c) => pill.classList.remove(c));
            menu.hidden = false;

            const box = menu.getBoundingClientRect();
            const fits = box.left >= -0.5 && box.right <= window.innerWidth + 0.5;

            menu.hidden = true;
            removed.forEach((c) => pill.classList.add(c));

            return { present: true, fits, left: box.left, right: box.right, width: window.innerWidth };
        });

        expect(fits, JSON.stringify(fits)).toMatchObject({ fits: true });
    });

    /**
     * The badge and its cancel button on a phone, which is the one surface a
     * scheduled draft is guaranteed to have: the send pill is desktop-only, so
     * on a phone the Drafts row is the ONLY place a hold can be seen or called
     * off at all.
     *
     * Measured, not merely located. The last round shipped a panel that was off
     * screen at this width while the spec passed, because the spec asserted
     * existence. So: the whole pill inside the viewport, the button big enough
     * to hit, and the row not pushed into a sideways scroll by it.
     */
    test("the scheduled badge and its cancel button fit, and work, at 393px", async ({ page }) => {
        const subject = "Scheduled, seen on a phone";

        // Set the hold at desktop width — the pill is not rendered below md.
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
