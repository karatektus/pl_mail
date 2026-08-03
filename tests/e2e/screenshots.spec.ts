import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Captures the README screenshots against the demo mailbox.
 *
 * Not part of the regression suite — it asserts nothing about behaviour, it
 * just drives the UI to the states worth showing and writes PNGs into
 * docs/screenshots/. It also cannot run on the E2E fixtures: it looks for the
 * demo mailbox by subject and sender, and no seed command in this repo
 * produces that data, so on the test stack it can only fail and overwrite the
 * committed PNGs with pictures of an empty inbox.
 *
 * Hence the opt-in. Point the suite at an app holding the demo mailbox and:
 *
 *   npm run test:e2e:screenshots
 */
const OUT = "docs/screenshots";

test.use({ viewport: { width: 1440, height: 900 } });

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

    test("inbox", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/inbox.png` });
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
        await page.screenshot({ path: `${OUT}/thread.png` });
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
        await page.screenshot({ path: `${OUT}/compose.png` });
    });

    test("inbox dark", async ({ page }) => {
        await page.emulateMedia({ colorScheme: "dark" });
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/inbox-dark.png` });
    });

    test("settings", async ({ page }) => {
        await page.goto("/settings");
        await page.waitForTimeout(900);
        await page.screenshot({ path: `${OUT}/settings.png` });
    });

    test("admin", async ({ page }) => {
        await page.goto("/admin");
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT}/admin.png` });
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
        await tree.locator('input[type="text"]').first().fill("versand@");

        await editor.getByRole("button", { name: "Add condition" }).first().click();
        await tree.locator("select").nth(1).selectOption("subject");
        await tree.locator('input[type="text"]').nth(1).fill("order");

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
        await page.screenshot({ path: `${OUT}/filters.png` });
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
        const monday = new Date();
        monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));

        const at = (dayOffset: number, time: string): string => {
            const day = new Date(monday);
            day.setDate(day.getDate() + dayOffset);

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
            await modal.locator("#event-starts").fill(startsAt);
            await modal.locator("#event-ends").fill(endsAt);

            await modal.getByRole("button", { name: "Save" }).click();
            await expect(page.getByRole("button", { name: new RegExp(title) }).first()).toBeVisible();
        }

        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/calendar.png` });
    });
});
