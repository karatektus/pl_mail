import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import type { Locator, Page } from "@playwright/test";

/**
 * "Move to": the target label on, the label of the list you were in off.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * WHY THIS HAS TO BE A BROWSER TEST
 * ─────────────────────────────────
 * MoveToServiceTest has which label comes off in every view, and BulkMoveToTest
 * has what the route refuses. Neither can see the part that is new here: the
 * server is told which list the person was looking at BY THE PAGE. The picker
 * in the reading pane is rendered by a request with no list behind it, so it
 * reads the view off the list toolbar at the moment it is used — and if that
 * read comes back empty the move still answers 200, still shows its toast, and
 * quietly behaves like a move from nowhere. From a label view that means the
 * label is never taken off, which is the whole feature.
 *
 * So each test moves from a real list and then goes and LOOKS in both places:
 * gone from where it was, present under the target.
 */
const LABEL_NAME = "E2E Label";

test.beforeEach(() => {
    seed("seed-mail", "seed-label");
});

/**
 * Selects a row. Set-and-dispatch rather than `check()` — the checkbox is a
 * clipped `sr-only` input, and label-bulk-toggle.spec.ts holds the account of
 * why a forced click on it is not reliable.
 */
async function select(row: Locator) {
    await row.locator("[data-thread-select]").evaluate((box) => {
        (box as HTMLInputElement).checked = true;
        box.dispatchEvent(new Event("change", { bubbles: true }));
    });
}

/** Opens a "Move to" picker and picks one destination from it. */
async function moveTo(scope: Locator, destination: string | RegExp) {
    await scope.getByRole("button", { name: "Move to" }).click();

    const menu = scope.getByRole("menu", { name: "Move to" });
    await expect(menu).toBeVisible();

    await menu.getByRole("menuitem", { name: destination }).click();

    // Single-select: the pick IS the action, so the picker is gone again
    // without a confirm button having existed.
    await expect(menu).toBeHidden();
}

function listToolbar(page: Page): Locator {
    return page.locator("[data-controller='mail--list-toolbar']").first();
}

function toast(page: Page, text: string | RegExp): Locator {
    return page.locator("#toast-region [role='status']").filter({ hasText: text });
}

/** The seeded label's id, read from the page that offers it as a destination. */
async function labelId(page: Page): Promise<string> {
    const entry = listToolbar(page)
        .locator("[data-controller='mail--move-menu'] [data-label-id]", { hasText: LABEL_NAME })
        .first();

    return (await entry.getAttribute("data-label-id")) ?? "";
}

/** Puts the seeded label on a conversation that stays in the Inbox. */
async function label(page: Page, subject: string): Promise<string> {
    await page.goto("/mail/inbox");

    const id      = await labelId(page);
    const thread  = await mailRow(page, subject).getAttribute("data-mail--message-row-id-value");
    const labelled = await ajaxPost(page, "/status/bulk/label", { ids: [Number(thread)], labelId: Number(id) });

    expect(labelled.ok(), "the fixture could not be labelled").toBe(true);

    return id;
}

test.describe("from the Inbox", () => {
    test("a selection leaves the Inbox and shows up under the label", async ({ page }) => {
        await page.goto("/mail/inbox");

        await select(mailRow(page, INBOX_SUBJECTS.read));
        await select(mailRow(page, INBOX_SUBJECTS.star));

        await moveTo(listToolbar(page), LABEL_NAME);

        await expect(toast(page, `Moved to ${LABEL_NAME}`)).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);

        // Untouched: the move was the selection, not the list.
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(1);

        await page.goto(`/mail/label/${await labelId(page)}`);

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(1);
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(1);

        // And gone for good, not just hidden by a stream: a fresh Inbox.
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(1);
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);
    });

    test("Undo brings the conversation back without the label", async ({ page }) => {
        await page.goto("/mail/inbox");

        await select(mailRow(page, INBOX_SUBJECTS.read));
        await moveTo(listToolbar(page), LABEL_NAME);

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);

        await toast(page, `Moved to ${LABEL_NAME}`).getByRole("button", { name: "Undo" }).click();

        // Back in the list it left, with no reload — the row is re-read into
        // its place — and not wearing the label the move had given it.
        const row = mailRow(page, INBOX_SUBJECTS.read);

        await expect(row).toHaveCount(1);
        await expect(row).not.toContainText(LABEL_NAME);

        await page.goto(`/mail/label/${await labelId(page)}`);
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);
    });

    test("the open conversation is moved from the reading pane, which then closes", async ({ page }) => {
        await page.goto("/mail/inbox");

        await mailRow(page, INBOX_SUBJECTS.archive).locator("a").first().click();

        const sheet = page.locator("div.mail-sheet");
        await expect(sheet).toBeVisible();

        await moveTo(sheet, LABEL_NAME);

        await expect(toast(page, `Moved to ${LABEL_NAME}`)).toBeVisible();

        // The way archive ends: the pane showing a conversation that is no
        // longer in the list behind it closes, and the row is gone.
        await expect(sheet).toBeHidden();
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);

        await page.goto(`/mail/label/${await labelId(page)}`);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(1);
    });

    test("the Inbox is not offered as a destination while looking at the Inbox", async ({ page }) => {
        await page.goto("/mail/inbox");

        await select(mailRow(page, INBOX_SUBJECTS.read));
        await listToolbar(page).getByRole("button", { name: "Move to" }).click();

        const menu = listToolbar(page).getByRole("menu", { name: "Move to" });

        await expect(menu.getByRole("menuitem", { name: LABEL_NAME })).toBeVisible();
        await expect(menu.getByRole("menuitem", { name: "Inbox" })).toHaveCount(0);

        // Offered to a mailbox that has never deleted anything, too: these two
        // are asked for by role, so they do not wait for their labels to exist.
        await expect(menu.getByRole("menuitem", { name: "Spam" })).toBeVisible();
        await expect(menu.getByRole("menuitem", { name: "Trash" })).toBeVisible();
    });

    test("moving to Trash bins the conversation", async ({ page }) => {
        await page.goto("/mail/inbox");

        await select(mailRow(page, INBOX_SUBJECTS.trash));
        await moveTo(listToolbar(page), "Trash");

        await expect(toast(page, "Moved to Trash")).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(0);

        await page.goto("/mail/trash");
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(1);
    });
});

test.describe("from the bin", () => {
    test("a conversation moved to a label leaves the bin, and Trash is not offered there", async ({ page }) => {
        // Binned through the feature itself, so the fixture is whatever
        // "Move to Trash" really produces.
        await page.goto("/mail/inbox");
        await select(mailRow(page, INBOX_SUBJECTS.trash));
        await moveTo(listToolbar(page), "Trash");
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(0);

        await page.goto("/mail/trash");

        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toHaveCount(1);
        await select(row);

        await listToolbar(page).getByRole("button", { name: "Move to" }).click();

        const menu = listToolbar(page).getByRole("menu", { name: "Move to" });

        // Already here. Spam is still somewhere else, so it stays.
        await expect(menu.getByRole("menuitem", { name: "Spam" })).toBeVisible();
        await expect(menu.getByRole("menuitem", { name: "Trash" })).toHaveCount(0);

        await menu.getByRole("menuitem", { name: LABEL_NAME }).click();

        await expect(toast(page, `Moved to ${LABEL_NAME}`)).toBeVisible();
        await expect(row, "moved to a label and still in the bin").toHaveCount(0);

        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(0);

        await page.goto(`/mail/label/${await labelId(page)}`);
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(1);

        // Not the inbox: it was filed, not restored.
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(0);
    });

    test("the open conversation is not offered the bin it is already in", async ({ page }) => {
        await page.goto("/mail/inbox");
        await select(mailRow(page, INBOX_SUBJECTS.trash));
        await moveTo(listToolbar(page), "Trash");
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toHaveCount(0);

        await page.goto("/mail/trash");
        await mailRow(page, INBOX_SUBJECTS.trash).locator("a").first().click();

        const sheet = page.locator("div.mail-sheet");
        await expect(sheet).toBeVisible();

        await sheet.getByRole("button", { name: "Move to" }).click();

        const menu = sheet.getByRole("menu", { name: "Move to" });

        await expect(menu.getByRole("menuitem", { name: "Inbox" })).toBeVisible();
        await expect(menu.getByRole("menuitem", { name: "Trash" })).toHaveCount(0);
    });
});

test.describe("what is offered", () => {
    test("a label the conversation already has is not a destination", async ({ page }) => {
        await label(page, INBOX_SUBJECTS.read);
        await page.goto("/mail/inbox");

        // In the list: hidden for a row that has it, offered again as soon as
        // the selection includes one that does not.
        await select(mailRow(page, INBOX_SUBJECTS.read));

        const button = listToolbar(page).getByRole("button", { name: "Move to" });
        const menu   = listToolbar(page).getByRole("menu", { name: "Move to" });

        await button.click();
        await expect(menu.getByRole("menuitem", { name: "Trash" })).toBeVisible();
        await expect(menu.getByRole("menuitem", { name: LABEL_NAME })).toHaveCount(0);
        await button.click();

        await select(mailRow(page, INBOX_SUBJECTS.star));

        await button.click();
        await expect(menu.getByRole("menuitem", { name: LABEL_NAME })).toBeVisible();
        await button.click();

        // And in the reading pane, for the conversation that is open.
        await mailRow(page, INBOX_SUBJECTS.read).locator("a").first().click();

        const sheet = page.locator("div.mail-sheet");
        await expect(sheet).toBeVisible();

        await sheet.getByRole("button", { name: "Move to" }).click();

        const paneMenu = sheet.getByRole("menu", { name: "Move to" });

        await expect(paneMenu.getByRole("menuitem", { name: "Trash" })).toBeVisible();
        await expect(paneMenu.getByRole("menuitem", { name: LABEL_NAME })).toHaveCount(0);
    });
});

test.describe("from a label", () => {
    test("a selection moved to the Inbox loses the label it was listed under", async ({ page }) => {
        const id = await label(page, INBOX_SUBJECTS.read);

        await page.goto(`/mail/label/${id}`);

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toHaveCount(1);

        await select(row);

        // The label being looked at is the one entry that would do nothing.
        await listToolbar(page).getByRole("button", { name: "Move to" }).click();
        const menu = listToolbar(page).getByRole("menu", { name: "Move to" });
        await expect(menu.getByRole("menuitem", { name: LABEL_NAME })).toHaveCount(0);
        await menu.getByRole("menuitem", { name: "Inbox" }).click();

        await expect(toast(page, "Moved to Inbox")).toBeVisible();
        await expect(row, "the conversation is still listed under the label it was moved out of").toHaveCount(0);

        await page.goto("/mail/inbox");

        const inInbox = mailRow(page, INBOX_SUBJECTS.read);

        await expect(inInbox).toHaveCount(1);
        await expect(inInbox, "the label of the view it was moved from is still on it").not.toContainText(LABEL_NAME);
    });

    test("the open conversation is moved out of the label from the reading pane", async ({ page }) => {
        const id = await label(page, INBOX_SUBJECTS.star);

        await page.goto(`/mail/label/${id}`);
        await mailRow(page, INBOX_SUBJECTS.star).locator("a").first().click();

        const sheet = page.locator("div.mail-sheet");
        await expect(sheet).toBeVisible();

        // The pane's picker is rendered with no list behind it; that it knows
        // to move OUT of this label is read off the toolbar of the list
        // underneath. An empty read here moves from nowhere and leaves the
        // label on — see the header.
        await moveTo(sheet, "Inbox");

        await expect(sheet).toBeHidden();
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);

        await page.reload();
        await expect(
            mailRow(page, INBOX_SUBJECTS.star),
            "the label was not taken off — the pane did not say which list it was opened from",
        ).toHaveCount(0);

        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(1);
    });

    test("Undo puts the label back", async ({ page }) => {
        const id = await label(page, INBOX_SUBJECTS.read);

        await page.goto(`/mail/label/${id}`);

        await select(mailRow(page, INBOX_SUBJECTS.read));
        await moveTo(listToolbar(page), "Inbox");

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);

        await toast(page, "Moved to Inbox").getByRole("button", { name: "Undo" }).click();

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(1);
    });
});
