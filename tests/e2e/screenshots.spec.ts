import { test, expect } from "./support/test";
import { setWhen } from "./support/datetime";
import { APP_TIMEZONE, TEST_ADMIN, ajaxPost, consoleCommand, login, seed, seedUser } from "./support/config";

/**
 * Captures the README screenshots against the demo mailbox.
 *
 * Not part of the regression suite — it asserts nothing about behaviour, it
 * just drives the UI to the states worth showing and writes PNGs into
 * docs/screenshots/. Hence the opt-in:
 *
 *   npm run test:e2e:screenshots
 *
 * **It will run against the E2E stack, and it poisons it.** This used to say
 * the suite could not run there at all, which is not true — app:test:seed-demo
 * makes exactly the mailbox it wants — and the truth is worse, because it works
 * and then leaves an account called you@example.com on the shared fixture user.
 * account-scope.spec.ts asserts on the FIRST account in the sidebar, so it
 * fails afterwards with "expected E2E Mailbox, received you@example.com", which
 * looks like a regression in account scoping and is not.
 *
 * Point it at an app of its own, or clear the demo account afterwards:
 *
 *   php bin/console dbal:run-sql "DELETE FROM account WHERE email = 'you@example.com'"
 */
const OUT = "docs/screenshots";

test.use({ viewport: { width: 1440, height: 900 } });

/**
 * Screenshot, having first proved the page is the page.
 *
 * `admin.png` was a red Symfony exception page — "Access Denied. The user
 * doesn't have ROLE_ADMIN." — committed to the repository and shown in the
 * README, because this suite pressed the shutter at whatever `/admin` returned
 * and the shared fixture user is not an administrator. Nothing failed: a
 * screenshot of a 403 is a perfectly good screenshot.
 *
 * Every other capture here happens to assert something visible first, which is
 * why only the admin one rotted. This makes it a rule rather than a habit, and
 * it is deliberately a check on the ERROR page rather than on each page's own
 * content: a per-page assertion is one more thing to forget, and the failure
 * worth catching is always the same shape.
 */
async function capture(page: import("@playwright/test").Page, name: string): Promise<void> {
    await expect(
        page.locator('[class^="exception"], [class*=" exception"], .trace'),
        `${name}.png would have been a Symfony error page`,
    ).toHaveCount(0);

    await expect(page).not.toHaveTitle(/exception|forbidden|error/i);

    await page.screenshot({ path: `${OUT}/${name}.png` });
}

/**
 * Put the docked calendar in one position, the way the topbar switch persists
 * it: a POST to the pane-state endpoint with the shell's own token.
 */
async function paneMode(page: import("@playwright/test").Page, mode: "mail" | "split"): Promise<void> {
    const status = await page.evaluate(async (wanted) => {
        const shell = document.querySelector("[data-controller~='ui--split']") as HTMLElement;
        const body = new FormData();
        body.append("_token", shell.getAttribute("data-ui--split-token-value") ?? "");
        body.append("mode", wanted);

        const response = await fetch(shell.getAttribute("data-ui--split-state-url-value") ?? "", {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
        });

        return response.status;
    }, mode);

    expect(status, `the pane could not be put in ${mode}`).toBeLessThan(300);
}

test.describe("README screenshots", () => {
    test.skip(
        undefined === process.env.E2E_SCREENSHOTS,
        'Demo mailbox required — run "npm run test:e2e:screenshots".',
    );

    // The mailbox in the pictures. app:test:seed-demo writes senders, subjects
    // and labels that can be shown without a caption apologising for them —
    // the regression fixtures ("E2E Trash Me") cannot, which is why this suite
    // needed an installation nobody else had.
    test.beforeAll(() => seed("seed-demo"));

    /**
     * With the calendar beside the mail, which is how a desktop opens plMail
     * and what the README's first line promises. The fixture user is kept on
     * the mail alone for the regression suite (app:test:seed-user), so this
     * asks for the default back for the one picture and restores the
     * furniture afterwards. The captures after this one are of other things.
     */
    test("inbox", async ({ page }) => {
        await page.goto("/mail/inbox");
        await paneMode(page, "split");

        try {
            await page.goto("/mail/inbox");
            await expect(page.locator("#message-list li").first()).toBeVisible();
            await expect(page.locator("turbo-frame#calendar-pane-frame [data-pane-min-width]")).toBeVisible();
            await page.waitForTimeout(600);
            await capture(page, "inbox");
        } finally {
            await paneMode(page, "mail");
        }
    });

    test("thread", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page
            .locator("#message-list li")
            .filter({ hasText: "Bookshelf dimensions" })
            .first()
            .click();
        await expect(page.getByText("alcove is 182cm").first()).toBeVisible();
        await page.waitForTimeout(600);
        await capture(page, "thread");
    });

    test("compose", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: /compose/i }).first().click();
        await page.waitForTimeout(900);

        // An empty composer shows the chrome but not the point of it.
        // Recipients are Tom Select chips, not a plain input.
        // Pick from the autocomplete rather than pressing Enter — Enter in the
        // recipient field submits the form and actually sends the message.
        const dock = page.locator("#compose_dock");
        const to = dock.locator(".ts-control").first();
        await to.locator("input").fill("priya");
        await page.locator(".ts-dropdown .option").first().click();

        await dock.locator('input[name="compose[subject]"]')
            .fill("Re: Bookshelf dimensions");
        await dock.locator('[data-compose--compose-toolbar-target="editor"]')
            .fill(
                "That clears it nicely — let's go with the 175cm unit. " +
                "I'll order the trim at the same time so it all arrives together. " +
                "No rush on the photos, whenever you get a chance is fine.",
            );
        await page.waitForTimeout(400);
        await capture(page, "compose");
    });

    /**
     * Dark by the user's setting, not the browser's.
     *
     * This used to emulate prefers-color-scheme: dark and commit a light
     * picture under a caption about dark themes: the theme is resolved on the
     * server from the user's appearance, so the media query never reaches it.
     * The appearance is snapshotted from the export route and posted back
     * whole afterwards, so the captures after this one stay as they were.
     */
    test("inbox dark", async ({ page }) => {
        const exported = await page.request.get("/settings/appearance/export");
        expect(exported.ok()).toBe(true);
        const previous = await exported.json();

        try {
            const dark = await ajaxPost(page, "/settings/appearance", { theme: "dark" });
            expect(dark.ok()).toBe(true);

            await page.goto("/mail/inbox");
            await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
            await expect(page.locator("#message-list li").first()).toBeVisible();
            await page.waitForTimeout(600);
            await capture(page, "inbox-dark");
        } finally {
            await ajaxPost(page, "/settings/appearance", previous);
        }
    });

    test("settings", async ({ page }) => {
        await page.goto("/settings");
        await page.waitForTimeout(900);
        await capture(page, "settings");
    });

    /**
     * The logo's two steps, in the state each is for: an icon picked and
     * wearing its own colours, then the same icon open on the colourway grid.
     *
     * Posted rather than clicked, and snapshotted and restored the way the dark
     * capture above is, so the pictures after these keep the product's default
     * mark. The topbar is in both frames on purpose — the point of choosing an
     * icon is where it ends up.
     */
    test("icons and colourways", async ({ page }) => {
        const exported = await page.request.get("/settings/appearance/export");
        expect(exported.ok()).toBe(true);
        const previous = await exported.json();
        const logo = page.locator("section:has(#logo-motif-label)").first();

        try {
            const horn = await ajaxPost(page, "/settings/appearance", { logoMotif: "blue-horn", logoOriginal: "1" });
            expect(horn.ok()).toBe(true);

            await page.goto("/settings?section=appearance");
            await expect(page.locator('[data-logo-motif="blue-horn"]')).toHaveAttribute("aria-pressed", "true");
            await logo.scrollIntoViewIfNeeded();
            await page.waitForTimeout(600);
            await capture(page, "icons");

            // A duotone from the grid's first rows, so the ringed tile, the
            // Colour switch above it and the topbar are all in one frame.
            const copper = await ajaxPost(page, "/settings/appearance", {
                logoMotif: "blue-horn",
                logoOriginal: "0",
                logoLinked: "0",
                logoStyle: "petrol-copper",
            });
            expect(copper.ok()).toBe(true);

            await page.goto("/settings?section=appearance");
            await expect(page.locator('[data-logo-name="petrol-copper"]')).toHaveAttribute("aria-pressed", "true");
            await page.locator('[data-logo-name="petrol-copper"]').scrollIntoViewIfNeeded();
            await page.waitForTimeout(600);
            await capture(page, "colourways");
        } finally {
            await ajaxPost(page, "/settings/appearance", previous);
        }
    });

    /**
     * The filter editor mid-build, because an empty one shows the chrome and
     * not the point: the sentence underneath and the live count are what the
     * screen is for, and neither exists until there is a rule to describe.
     */
    test("filters", async ({ page }) => {
        // Reached through the list, not by URL: the editor renders into a
        // turbo-frame on the settings page and is a fragment on its own.
        await page.goto("/settings?section=filters");
        await page.locator("#settings-filter-list")
            .getByRole("link", { name: "New filter" })
            .click();

        const editor = page.locator("#filter-editor");
        await expect(editor).toBeVisible();

        await editor.getByLabel("Name").fill("File the receipts");

        const tree = editor.locator('[data-rules--rule-builder-target="tree"]');
        await tree.locator("select").first().selectOption("from");
        await tree.locator("input[data-rule-value]").first().fill("versand@");

        await editor.getByRole("button", { name: "Add condition" }).first().click();
        await tree.locator("select").nth(1).selectOption("subject");
        await tree.locator("input[data-rule-value]").nth(1).fill("order");

        await editor.getByRole("button", { name: "Add action" }).click();

        // File them somewhere that matches the rule's name — a screenshot
        // where the two disagree is one the reader has to stop and re-read.
        const action = editor.locator('[data-rules--rule-builder-target="actionList"] select').nth(1);
        await action.selectOption({ label: "Receipts" });

        // The sentence and the count are the point of the screen, and both
        // come back from the preview endpoint.
        const summary = editor.locator('[data-rules--rule-builder-target="summary"]');
        await expect(summary).toContainText("If");
        await expect(
            editor.locator('[data-rules--rule-builder-target="count"][data-state="ok"]'),
        ).toBeVisible();

        // Scrolled to, or the rule reads as a form with the answer cut off:
        // the editor is taller than the viewport and both readouts sit under
        // the fold.
        await summary.scrollIntoViewIfNeeded();
        await page.mouse.move(900, 600);
        await page.mouse.wheel(0, 160);
        await page.waitForTimeout(400);
        await capture(page, "filters");
    });

    /**
     * The week with something in it. Created through the dialog rather than
     * seeded: an event is a JSCalendar object whose occurrences are
     * materialised on write, and going around the writer to put rows in the
     * database is how a screenshot ends up showing a state the app cannot
     * produce.
     */
    test("calendar", async ({ page }) => {
        // Spread across the week, because every event landing on one day says
        // nothing about a week view. Dates are relative to Monday of the
        // current week, so the picture is the same shape whenever it is taken.
        // Anchored in the APP's zone, not the runner's, and the arithmetic done
        // at UTC noon. Two separate traps, both of which move every event in
        // this picture by a day:
        //
        //   · `toISOString()` answers in UTC while the calendar renders in
        //     APP_TIMEZONE. Run this at 00:20 in Berlin and every date comes
        //     out as yesterday, which walks Monday's events off the week view.
        //   · midnight + setDate() lands on 23:00 or 01:00 across a DST
        //     boundary, and the date can go with it. Noon has an hour of slack
        //     either side, so it never does.
        const today = new Intl.DateTimeFormat("en-CA", { timeZone: APP_TIMEZONE }).format(new Date());
        const monday = new Date(`${today}T12:00:00Z`);
        monday.setUTCDate(monday.getUTCDate() - ((monday.getUTCDay() + 6) % 7));

        const at = (dayOffset: number, time: string): string => {
            const day = new Date(monday);
            day.setUTCDate(day.getUTCDate() + dayOffset);

            return `${day.toISOString().slice(0, 10)}T${time}`;
        };

        const events: Array<[string, string, string]> = [
            ["Standup", at(0, "09:00"), at(0, "09:15")],
            ["Lunch with Priya", at(1, "12:30"), at(1, "13:30")],
            ["Bookshelf delivery", at(2, "08:00"), at(2, "12:00")],
            ["Dentist", at(3, "16:15"), at(3, "17:00")],
            ["Reading group", at(4, "19:00"), at(4, "21:00")],
        ];

        await page.goto("/calendar/week");

        for (const [title, startsAt, endsAt] of events) {
            await page.getByRole("button", { name: "New event", exact: true }).click();

            const modal = page.locator("#modal-backdrop");
            await expect(modal).toBeVisible();

            await modal.locator("#event-title").fill(title);
            await setWhen(modal.locator("#event-starts"), startsAt);
            await setWhen(modal.locator("#event-ends"), endsAt);

            await modal.getByRole("button", { name: "Save" }).click();
            await expect(page.getByRole("button", { name: new RegExp(title) }).first()).toBeVisible();
        }

        // The toast the last save left behind runs for four seconds, and 600ms
        // of waiting put it in the corner of the README's own picture. Waited
        // out rather than dismissed: clicking it is a different picture again,
        // with a half-faded panel mid-transition.
        await expect(page.locator('[data-controller~="ui--toast"]')).toHaveCount(0, {
            timeout: 8_000,
        });

        // And scrolled to the morning, because a week view's picture should have
        // a label on every block in it. The grid opens on the current time, so
        // the hour this suite happens to run at decides whether the 08:00
        // delivery is a labelled event or an unexplained blue rectangle with its
        // title somewhere above the viewport. 7.5/24 puts 07:30 at the top,
        // which is under the earliest event here and above none of them.
        await page.evaluate(() => {
            const scroller = document.querySelector('[data-calendar--time-grid-target="scroller"]');
            const hours = document.querySelector('[data-calendar--time-grid-target="hours"]');

            if (null !== scroller && null !== hours) {
                scroller.scrollTop = ((hours as HTMLElement).offsetHeight * 7.5) / 24;
            }
        });

        await page.waitForTimeout(300);
        await capture(page, "calendar");
    });
});

/**
 * The admin overview, which needs an administrator.
 *
 * Its own describe and its own session, for the reason admin-panels.spec.ts
 * gives: /admin needs ROLE_ADMIN, and granting that to the shared fixture user
 * mid-run deauthenticates every other spec — Symfony treats a token whose roles
 * have changed as stale. Signing in as somebody else is the cheap half of that
 * argument; the expensive half is that this file used to not bother, and shipped
 * a picture of an access-denied page in the README.
 */
test.describe("README screenshots — admin", () => {
    test.skip(
        undefined === process.env.E2E_SCREENSHOTS,
        'Demo mailbox required — run "npm run test:e2e:screenshots".',
    );

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_ADMIN.email, password: TEST_ADMIN.password, admin: true });

        // The demo mailbox again, for this user. The picture is of the admin
        // screen, but the sidebar beside it is in shot — and a sidebar reading
        // "No accounts yet" next to "No labels yet" says the app is empty,
        // which is not what the page is meant to be showing.
        consoleCommand(`app:test:seed-demo --email=${TEST_ADMIN.email}`);
    });

    test("admin", async ({ page }) => {
        await login(page, TEST_ADMIN.email, TEST_ADMIN.password);

        await page.goto("/admin");

        // The page, not merely a 200: capture() catches an error page, and this
        // catches an admin screen that rendered empty. The heading plus a panel
        // from the section that is actually open — /admin lands on System, so
        // asserting on the Users frame waits for a panel this page never draws.
        await expect(page.getByRole("heading", { name: "Admin" })).toBeVisible();
        await expect(page.getByText("Restart long-running processes")).toBeVisible();
        await page.waitForTimeout(1200);

        await capture(page, "admin");
    });
});
