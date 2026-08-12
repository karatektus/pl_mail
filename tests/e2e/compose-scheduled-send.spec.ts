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
        const toast = page.locator("#toast-region").getByText(/Send scheduled for/);
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
    });

    test("reopening a scheduled draft offers to call it off", async ({ page }) => {
        const subject = "Scheduled then cancelled";

        await openCompose(page);
        await fillDock(page, subject);

        const options = await openSendOptions(page);
        await options.getByText("Tomorrow morning").click();
        await expect(page.locator("#toast-region").getByText(/Send scheduled for/)).toBeVisible({
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
        await expect(page.locator("#toast-region").getByText(/Send scheduled for/)).toHaveCount(0);
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
});
