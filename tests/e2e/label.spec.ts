import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Runs authenticated as this worker's own user, signed in by the worker
 * fixture in support/test.ts.
 *
 * Reseeds a fresh inbox plus one visible custom label ("E2E Label") before
 * each test, then exercises the "Label as" attach flow from the toolbar.
 */
const LABEL_NAME = "E2E Label";

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
        // The Name field specifically, not "a text input in the frame": the
        // parent-label picker is a Tom Select, and once an install has more
        // than a handful of labels the widget renders its own search box —
        // another <input type="text"> inside #modal that belongs to the
        // dropdown rather than to the form.
        await expect(modal.locator("#modal").getByLabel("Name")).toBeVisible();

        await modal.getByLabel("Name").fill(created);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeHidden();
        await expect(
            page.locator("#label-list").getByRole("link", { name: created }),
        ).toBeVisible();
    });

    /**
     * The colour field is an expanded choice with a placeholder, and that
     * combination found a latent bug in the form theme: radio_widget only
     * emitted a value attribute when the value was non-empty, so the "no
     * colour" option submitted "on" and the whole form came back invalid with
     * no field to blame. Saving a label with a colour picked is the assertion
     * that keeps it fixed.
     */
    test("saves the colour picked from the swatches", async ({ page }) => {
        await page.goto("/mail/inbox");

        const created = `E2E Coloured ${Date.now()}`;

        await page.locator("#sidebar").getByRole("button", { name: "Create label" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        await modal.getByLabel("Name").fill(created);
        await modal.locator('input[type="radio"][value="violet"]').check({ force: true });
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeHidden();

        // The sidebar dot is the colour, so it is also the proof it was stored
        // — and it reads the same value the chips and the editor do.
        await expect(
            page
                .locator("#label-list .nav-item")
                .filter({ hasText: created })
                .locator(".bg-violet-500"),
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

        // LABEL_NAME is seeded by seed-label, so this collides. No account to
        // pick: labels are user-scoped, so the name alone decides — this used
        // to need the account pinned, because the same name on two accounts
        // was two different labels.
        await modal.getByLabel("Name").fill(LABEL_NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        // A 200 here would let modal_controller close the dialog on
        // turbo:submit-end and swallow the message — hence the 422.
        await expect(modal).toBeVisible();
        await expect(modal).toContainText(
            "A label with this name already exists here.",
        );
    });
});

test.describe("manage labels", () => {
    const SETTINGS_URL = "/settings?section=labels";

    test("renames a label from settings and the sidebar follows", async ({
        page,
    }) => {
        await page.goto(SETTINGS_URL);

        const list = page.locator("#settings-label-list");
        const row = list.locator("li").filter({ hasText: LABEL_NAME });
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: `Edit label "${LABEL_NAME}"` }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        const renamed = `E2E Renamed ${Date.now()}`;
        await modal.getByLabel("Name").fill(renamed);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeHidden();

        // Both regions are refreshed by the one stream response.
        await expect(list.locator("li").filter({ hasText: renamed })).toBeVisible();
        await expect(
            page.locator("#label-list").getByRole("link", { name: renamed }),
        ).toBeVisible();
    });

    test("deletes a label and it leaves both the settings list and the sidebar", async ({
        page,
    }) => {
        await page.goto(SETTINGS_URL);

        const list = page.locator("#settings-label-list");
        const row = list.locator("li").filter({ hasText: LABEL_NAME });
        await expect(row).toBeVisible();

        // data-turbo-confirm surfaces as a native dialog.
        page.once("dialog", (dialog) => dialog.accept());
        await row.getByRole("button", { name: `Delete label "${LABEL_NAME}"` }).click();

        await expect(list.locator("li").filter({ hasText: LABEL_NAME })).toHaveCount(0);
        await expect(
            page.locator("#label-list").getByRole("link", { name: LABEL_NAME }),
        ).toHaveCount(0);
    });

    test("offers no rename or delete for system labels", async ({ page }) => {
        await page.goto(SETTINGS_URL);

        const inbox = page
            .locator("#settings-label-list li")
            .filter({ hasText: "Inbox" })
            .first();
        await expect(inbox).toBeVisible();

        // System labels map onto provider built-ins — visibility only.
        await expect(inbox.getByRole("button", { name: /^Edit label/ })).toHaveCount(0);
        await expect(inbox.getByRole("button", { name: /^Delete label/ })).toHaveCount(0);
        await expect(inbox.getByRole("button", { name: /label$/ })).toHaveCount(1);
    });

    test("rejects a delete without a valid CSRF token", async ({ page, request }) => {
        await page.goto(SETTINGS_URL);

        const id = await page
            .locator("#settings-label-list form[action*='/delete']")
            .first()
            .getAttribute("action");
        expect(id).toBeTruthy();

        const response = await request.post(id!, {
            form: { _token: "not-a-valid-token" },
            maxRedirects: 0,
        });

        expect(response.status()).toBe(403);
    });
});

test.describe("label as", () => {
    test("attaches a custom label to a conversation", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toBeVisible();

        // Select the row so the toolbar's bulk actions (incl. "Label as") appear.
        // The row's checkbox is the avatar now: the real input is sr-only and
        // the label intercepts the click, so .check() cannot reach it. Clicking
        // the control a person would click is the same assertion anyway.
        await row.locator("label:has(input[data-thread-select])").click();

        const actions = page.locator('[data-mail--list-toolbar-target="actions"]');
        await expect(actions).toBeVisible();

        // Open the "Label as" menu and pick the seeded label.
        await actions.getByRole("button", { name: "Label as" }).click();

        const panel = page.locator(
            '[data-controller="mail--label-menu"] [data-mail--label-menu-target="panel"]',
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
        // The row's checkbox is the avatar now: the real input is sr-only and
        // the label intercepts the click, so .check() cannot reach it. Clicking
        // the control a person would click is the same assertion anyway.
        await row.locator("label:has(input[data-thread-select])").click();

        const actions = page.locator('[data-mail--list-toolbar-target="actions"]');
        await actions.getByRole("button", { name: "Label as" }).click();

        const panel = page.locator(
            '[data-controller="mail--label-menu"] [data-mail--label-menu-target="panel"]',
        );
        await panel.getByRole("button", { name: LABEL_NAME }).click();
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toContainText(LABEL_NAME);

        // The label's sidebar entry opens its conversation list. Scoped to the
        // label list: the same label also appears under any expanded account,
        // scoped to that account — a different link to a different view, and
        // whether it is on screen depends on a stored preference this spec has
        // no business depending on.
        await page.locator("#label-list").getByRole("link", { name: LABEL_NAME }).click();
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.trash }),
        ).toBeVisible();
    });
});
