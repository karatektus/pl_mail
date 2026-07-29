import { test, expect } from "@playwright/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Runs authenticated via the shared storage state from auth.setup.ts.
 *
 * Reseeds a fresh inbox plus one visible custom label ("E2E Label") before
 * each test, then exercises the "Label as" attach flow from the toolbar.
 */
const LABEL_NAME = "E2E Label";

/**
 * The account seed-label attaches LABEL_NAME to, as shown in the label form's
 * account select (LabelType uses Account::email as the choice label).
 *
 * Pinned explicitly because labels are per-account: the select defaults to the
 * alphabetically first account, which is whichever throwaway IMAP account the
 * account spec left behind — so a test that skips this would create a label on
 * the wrong account instead of colliding with the seeded one.
 */
const LABEL_ACCOUNT = "E2E Mailbox";

test.beforeEach(() => {
    seed("seed-mail", "seed-label");
});

test.describe("create label", () => {
    test("creates a label from the sidebar and lists it", async ({ page }) => {
        await page.goto("/mail/inbox");

        const created = `E2E Created ${Date.now()}`;

        await page
            .locator("#sidebar")
            .getByRole("button", { name: "Create label" })
            .click();

        // Regression guard: the form must actually render inside the modal
        // frame. Without the <turbo-frame id="modal"> wrapper Turbo finds
        // nothing to swap and the dialog stays on the spinner.
        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();
        await expect(modal.locator("#modal input[type='text']")).toBeVisible();

        await modal.getByLabel("Name").fill(created);
        await modal.getByLabel("Account").selectOption({ label: LABEL_ACCOUNT });
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeHidden();
        await expect(
            page.locator("#label-list").getByRole("link", { name: created }),
        ).toBeVisible();
    });

    test("keeps the modal open and shows the error on a duplicate name", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        await page
            .locator("#sidebar")
            .getByRole("button", { name: "Create label" })
            .click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        // LABEL_NAME is seeded by seed-label on LABEL_ACCOUNT, so this collides.
        await modal.getByLabel("Name").fill(LABEL_NAME);
        await modal.getByLabel("Account").selectOption({ label: LABEL_ACCOUNT });
        await modal.getByRole("button", { name: "Save" }).click();

        // A 200 here would let modal_controller close the dialog on
        // turbo:submit-end and swallow the message — hence the 422.
        await expect(modal).toBeVisible();
        await expect(modal).toContainText(
            "A label with this name already exists here.",
        );
    });
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
