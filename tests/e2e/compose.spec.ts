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
 * through the UI rather than seeded.
 */
const RECIPIENT = "draftee@example.test";
const dock = "#compose_dock";

test.beforeAll(() => {
    seed("seed-mail");
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
            window.locator('[data-compose-toolbar-target="editor"]'),
        ).toBeVisible();
    });

    test("reveals the Cc field on demand", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const cc = page.locator('[data-compose-target="ccField"]');
        await expect(cc).toBeHidden();

        await page.locator(dock).getByRole("button", { name: "Cc", exact: true }).click();
        await expect(cc).toBeVisible();
    });

    // KNOWN BROKEN — app bug, not test drift. ContactAutocompleteField sets
    // `allow_options_create => true`, but the bundle renders that as a valueless
    // boolean attribute (`data-…-allow-options-create-value=""`), which Stimulus
    // reads as false. Tom Select is therefore built with `create: null`, so the
    // "Add <address>" row never renders and a recipient who is not already a
    // Contact cannot be entered at all. Verified in-browser:
    //   ts.settings.create === null, dropdown shows only "No results found".
    // Un-fixme once the create option reaches Tom Select.
    test.fixme("adds a typed recipient as a chip", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        // First .ts-control is To (Cc/Bcc are hidden until revealed).
        const toControl = page.locator(dock).locator(".ts-control").first();
        await toControl.locator("input").fill("someone@example.test");
        await toControl.locator("input").press("Enter");

        await expect(toControl.locator(".item")).toContainText("someone@example.test");
    });

    // KNOWN BROKEN — blocked on the same create-option bug as the test above.
    test.fixme("restores the recipient when a draft is reopened", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const dockEl = page.locator(dock);
        const toControl = dockEl.locator(".ts-control").first();
        await toControl.locator("input").fill(RECIPIENT);
        await toControl.locator("input").press("Enter");
        await expect(toControl.locator(".item")).toContainText(RECIPIENT);

        await dockEl.locator('input[name="compose[subject]"]').fill("E2E Draft");

        await page.waitForResponse((r) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/drafts");
        await page.getByRole("link", { name: "E2E Draft" }).click();

        await expect(page.locator(dock)).toContainText(RECIPIENT);
    });
});
