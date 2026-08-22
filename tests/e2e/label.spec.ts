import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";

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

        await row.getByRole("button", { name: `Delete label "${LABEL_NAME}"` }).click();
        await acceptConfirm(page);

        await expect(list.locator("li").filter({ hasText: LABEL_NAME })).toHaveCount(0);
        await expect(
            page.locator("#label-list").getByRole("link", { name: LABEL_NAME }),
        ).toHaveCount(0);
    });

    /**
     * Delete, from the dialog that edits the label.
     *
     * The report said labels could not be deleted from the UI at all, which was
     * not so — the settings rows above have offered it all along. What was true
     * is the path the report actually walked: the sidebar's pencil opens "Edit
     * label" with Name / Nest under / Colour and nothing but Cancel and Save, so
     * anyone managing labels where they live never met the control.
     */
    test("deletes from the label edit dialog, which is where the pencil leads", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const sidebarRow = page
            .locator("#label-list .nav-item")
            .filter({ hasText: LABEL_NAME })
            .first();
        await expect(sidebarRow).toBeVisible();

        await sidebarRow.getByRole("button", { name: `Edit label "${LABEL_NAME}"` }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();
        await expect(modal.locator("#modal").getByLabel("Name")).toBeVisible();

        // It says what it will do before it does it. Deleting reaches further
        // than the row it was opened from — every account, and every nested
        // label — so the dialog states that rather than leaving it to the
        // confirm nobody reads.
        await expect(modal).toContainText("removes the label from every account");
        await expect(modal).toContainText("is not deleted");

        const remove = modal.getByRole("button", { name: "Delete label" });
        await expect(remove).toBeVisible();

        await remove.click();
        await acceptConfirm(page);

        await expect(modal).toBeHidden();
        await expect(
            page.locator("#label-list").getByRole("link", { name: LABEL_NAME }),
        ).toHaveCount(0);
    });

    /**
     * Destructive and primary must not be adjacent. The dialog's footer is
     * Cancel and Save; delete lives in the body, under a rule, and pressing
     * Save must still save.
     */
    test("the delete control is kept out of the dialog's footer", async ({ page }) => {
        await page.goto("/mail/inbox");

        await page
            .locator("#label-list .nav-item")
            .filter({ hasText: LABEL_NAME })
            .first()
            .getByRole("button", { name: `Edit label "${LABEL_NAME}"` })
            .click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.locator("#modal").getByLabel("Name")).toBeVisible();

        // The submit button and the delete button are not siblings.
        const sharedParent = await modal.evaluate((root) => {
            const save = Array.from(root.querySelectorAll("button")).find(
                (b) => b.textContent?.trim() === "Save",
            );
            const remove = Array.from(root.querySelectorAll("button")).find((b) =>
                b.textContent?.includes("Delete label"),
            );

            return save?.parentElement === remove?.parentElement;
        });

        expect(sharedParent, "delete must not sit in the row that holds Save").toBe(false);

        // And Save still saves rather than being hijacked by the formaction the
        // delete button carries — a hijacked Save would remove the label, so
        // the label still being there afterwards is the assertion. Nothing is
        // renamed to prove it: a unique name would be a label this spec leaves
        // behind for every later run to trip over.
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeHidden();
        await expect(
            page.locator("#label-list").getByRole("link", { name: LABEL_NAME }),
        ).toBeVisible();
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
