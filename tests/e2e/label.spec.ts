import { test, expect } from "@playwright/test";
import { execSync } from "node:child_process";
import { INBOX_SUBJECTS, mailRow } from "./support/config";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Reseeds a fresh inbox plus one visible custom label ("E2E Label") before
 * each test, then exercises the "Label as" attach flow from the toolbar.
 */
const LABEL_NAME = "E2E Label";

test.beforeEach(() => {
    const seed =
        process.env.E2E_SEED_CMD ??
        "php bin/console app:test:seed-mail && php bin/console app:test:seed-label";
    execSync(seed, { stdio: "inherit", env: { ...process.env, APP_ENV: "test" } });
});

test.describe("label as", () => {
    test("attaches a custom label to a conversation", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toBeVisible();

        // Select the row so the toolbar's bulk actions (incl. "Label as") appear.
        await row.locator('input[type="checkbox"]').check();

        const actions = page.locator('[data-list-toolbar-target="actions"]');
        await expect(actions).toBeVisible();

        // Open the "Label as" menu and pick the seeded label.
        await actions.getByRole("button", { name: "Label as" }).click();

        const panel = page.locator(
            '[data-controller="label-menu"] [data-label-menu-target="panel"]',
        );
        await expect(panel).toBeVisible();
        await panel.getByRole("button", { name: LABEL_NAME }).click();

        // The now-fixed _label.stream re-renders the row with the label chip.
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toContainText(LABEL_NAME);
    });

    test("shows the labelled conversation under its label view", async ({
                                                                            page,
                                                                        }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toBeVisible();
        await row.locator('input[type="checkbox"]').check();

        const actions = page.locator('[data-list-toolbar-target="actions"]');
        await actions.getByRole("button", { name: "Label as" }).click();

        const panel = page.locator(
            '[data-controller="label-menu"] [data-label-menu-target="panel"]',
        );
        await panel.getByRole("button", { name: LABEL_NAME }).click();
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toContainText(LABEL_NAME);

        // The label's sidebar entry opens its conversation list.
        await page.getByRole("link", { name: LABEL_NAME }).click();
        await expect(
            page
                .locator('#message-list li[data-controller="message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.trash }),
        ).toBeVisible();
    });
});
