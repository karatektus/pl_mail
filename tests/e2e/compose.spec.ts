import { test, expect } from "@playwright/test";
import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Recipients use Symfony UX Autocomplete (Tom Select under the hood, auto-
 * initialised from the <select> — no custom Stimulus controller). Chips render
 * as `.ts-control .item`.
 *
 * Seeds the E2E account plus one draft (with a known recipient) once. The
 * draft's message id is written to var/e2e-draft-id by app:test:seed-draft.
 */
const RECIPIENT = "draftee@example.test";
const dock = "#compose_dock";

test.beforeAll(() => {
    const cmd =
        process.env.E2E_SEED_CMD ??
        "php bin/console app:test:seed-mail && php bin/console app:test:seed-draft";
    execSync(cmd, { stdio: "inherit", env: { ...process.env, APP_ENV: "test" } });
});

test.describe("compose window", () => {
    test("opens from the Compose button", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const window = page.locator(dock);
        await expect(window.getByText("New Message")).toBeVisible();
        await expect(window.locator('input[name="compose[subject]"]')).toBeVisible();
        await expect(window.locator("#compose-editor")).toBeVisible();
    });

    test("reveals the Cc field on demand", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const cc = page.locator('[data-compose-target="ccField"]');
        await expect(cc).toBeHidden();

        await page.locator(dock).getByRole("button", { name: "Cc", exact: true }).click();
        await expect(cc).toBeVisible();
    });

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
        // Isolates the server-side render: open the draft directly and assert the
        // recipient is present in the To field markup (independent of Tom Select).
        const id = readFileSync("var/e2e-draft-id", "utf8").trim();

        await page.goto(`/compose/edit/${id}`);

        await expect(page.locator(dock)).toContainText(RECIPIENT);
    });
});
