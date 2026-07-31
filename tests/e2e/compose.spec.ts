import { test, expect } from "@playwright/test";
import { seed } from "./support/config";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Recipients use Symfony UX Autocomplete (Tom Select under the hood, auto-
 * initialised from the <select> — no custom Stimulus controller). Chips render
 * as `.ts-control .item`.
 *
 * Seeds the E2E account once; the drafts these specs exercise are created
 * through the UI rather than seeded. `clear-drafts` goes with it: a draft
 * written through the UI is filed on the user's default account, which is not
 * necessarily the one seed-mail wipes, so without it every run leaves another
 * "E2E Draft" behind and the reopen spec's locator turns ambiguous.
 */
const RECIPIENT = "draftee@example.test";
const dock = "#compose_dock";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

test.describe("compose window", () => {
    test("opens from the Compose button", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const window = page.locator(dock);
        await expect(window.getByText("New Message")).toBeVisible();
        await expect(window.locator('input[name="compose[subject]"]')).toBeVisible();
        // Target the Stimulus hook, not the generated id: the editor's id is
        // derived from the form field name and moves whenever the form does.
        await expect(
            window.locator('[data-compose--compose-toolbar-target="editor"]'),
        ).toBeVisible();
    });

    test("reveals the Cc field on demand", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const cc = page.locator('[data-compose--compose-target="ccField"]');
        await expect(cc).toBeHidden();

        await page.locator(dock).getByRole("button", { name: "Cc", exact: true }).click();
        await expect(cc).toBeVisible();
    });

    test("tabs from the recipients straight to the subject", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);
        const toInput = dockEl.locator(".ts-control input").first();

        await toInput.click();
        // Nothing typed, so Tom Select leaves the keystroke alone — it must
        // land on the subject rather than on the Cc/Bcc buttons next to To.
        await toInput.press("Tab");

        await expect(dockEl.locator('input[name="compose[subject]"]')).toBeFocused();
    });

    test("tabs through a revealed Cc field on the way to the subject", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);
        await dockEl.getByRole("button", { name: "Cc", exact: true }).click();

        const toInput = dockEl.locator(".ts-control input").first();
        await toInput.click();
        await toInput.press("Tab");

        await expect(
            page.locator('[data-compose--compose-target="ccField"]').locator(".ts-control input"),
        ).toBeFocused();
    });

    test("commits the highlighted suggestion on Tab", async ({ page }) => {
        // Suggestions come from Contacts, and the suite seeds none — so make
        // one the way the app does, by saving a draft addressed to it.
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const firstWindow = page.locator(dock);
        await firstWindow.locator(".ts-control input").first().fill(RECIPIENT);
        await firstWindow.locator(".ts-control input").first().press("Enter");
        await firstWindow.locator('[data-compose--compose-toolbar-target="editor"]').fill("Draft body");
        await page.waitForResponse((r) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const toControl = page.locator(dock).locator(".ts-control").first();
        const toInput = toControl.locator("input");
        await toInput.fill(RECIPIENT.split("@")[0]);

        // Wait for the suggestion to be the active option, then commit it.
        await expect(page.locator(".ts-dropdown .active")).toContainText(RECIPIENT);
        await toInput.press("Tab");

        await expect(toControl.locator(".item")).toContainText(RECIPIENT);
    });

    test("attaches a file to the draft", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);

        await dockEl.locator('input[type="file"]').setInputFiles({
            name: "e2e-note.txt",
            mimeType: "text/plain",
            buffer: Buffer.from("attached by the e2e suite"),
        });

        const chip = dockEl.getByRole("link", { name: "e2e-note.txt" });
        await expect(chip).toBeVisible();

        // And it can be taken off again.
        await dockEl.getByRole("button", { name: "Remove attachment" }).click();
        await expect(chip).toBeHidden();
    });

    // PHP ships upload_max_filesize=2M / post_max_size=8M, which silently ate
    // anything bigger before frankenphp/conf.d/10-app.ini raised them. That
    // file is baked into the image, so a container older than the bump still
    // refuses this upload — `npm run test:env:up` rebuilds before starting.
    test("attaches a file larger than PHP's stock upload limit", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);

        await dockEl.locator('input[type="file"]').setInputFiles({
            name: "e2e-big.bin",
            mimeType: "application/octet-stream",
            buffer: Buffer.alloc(3 * 1024 * 1024, 7),
        });

        await expect(dockEl.getByRole("link", { name: "e2e-big.bin" })).toBeVisible();
        await expect(dockEl.getByText("3.0 MB")).toBeVisible();
    });

    // `allow_options_create => true` never reached Tom Select — this version of
    // the bundle renders it as a valueless boolean attribute that Stimulus reads
    // back as false — so ContactAutocompleteField now passes `create` through
    // tom_select_options directly. Guards that path.
    test("adds a typed recipient as a chip", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        // First .ts-control is To (Cc/Bcc are hidden until revealed).
        const toControl = page.locator(dock).locator(".ts-control").first();
        await toControl.locator("input").fill("someone@example.test");
        await toControl.locator("input").press("Enter");

        await expect(toControl.locator(".item")).toContainText("someone@example.test");
    });

    test("restores the recipient when a draft is reopened", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);
        const toControl = dockEl.locator(".ts-control").first();
        await toControl.locator("input").fill(RECIPIENT);
        await toControl.locator("input").press("Enter");
        await expect(toControl.locator(".item")).toContainText(RECIPIENT);

        await dockEl.locator('input[name="compose[subject]"]').fill("E2E Draft");

        // The autosave only mints a draft once the body clears min-chars, so a
        // subject on its own would never produce the POST awaited below.
        await dockEl.locator('[data-compose--compose-toolbar-target="editor"]').fill("Draft body");

        await page.waitForResponse((r) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/drafts");
        await page.getByRole("link", { name: "E2E Draft" }).click();

        await expect(page.locator(dock)).toContainText(RECIPIENT);
    });
});
