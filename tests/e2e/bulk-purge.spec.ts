import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";
import type { Locator, Page } from "@playwright/test";

/**
 * A ticked selection in Spam is deleted for good, past the bin.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * WHY THIS HAS TO BE A BROWSER TEST
 * ─────────────────────────────────
 * BulkPurgeTest has what the route destroys and what it refuses. It cannot see
 * the three things the page contributes: that the toolbar shows Delete forever
 * in Spam and Delete everywhere else, that the question is asked BEFORE the
 * request and names how many, and that the rows leave the list from the answer
 * — the stream is addressed by ids the controller had to capture before the
 * purge, and only a page can show that they reached the right rows.
 *
 * The spam is made the way a person makes it, by moving mail there, so that
 * what is deleted is what the Spam list really holds.
 */
const IN_SPAM = [INBOX_SUBJECTS.star, INBOX_SUBJECTS.archive, INBOX_SUBJECTS.trash];
const STAYS = INBOX_SUBJECTS.read;

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    await page.goto("/mail/inbox");

    const ids: number[] = [];

    for (const subject of IN_SPAM) {
        const id = await mailRow(page, subject).getAttribute("id");

        ids.push(Number(id?.replace("thread_", "")));
    }

    const response = await ajaxPost(page, "/status/bulk/move-to", { ids, role: "spam", scope: "inbox", value: "" });

    expect(response.ok(), "putting the fixtures in Spam failed").toBe(true);
});

// Put the mailbox back the way the worker found it. This spec moves mail into
// Spam and deletes some of it, and the worker's user is shared with whatever
// file runs next: dnd.spec.ts seeds only its labels and expects "E2E Archive Me"
// to be in the inbox, so it failed — deterministically, in the same run order,
// and never alone — for as long as this left that thread in Spam. `seed-mail`
// wipes the account's threads before it seeds, so this is a full reset.
test.afterAll(() => {
    seed("seed-mail");
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

/** The buttons above a selection, which are only shown once something is ticked. */
function actions(page: Page): Locator {
    return page.locator('[data-mail--list-toolbar-target="actions"]');
}

test.describe("deleting a selection in Spam for good", () => {
    test("asks how many, then removes the ticked rows and only those", async ({ page }) => {
        await page.goto("/mail/spam");

        for (const subject of IN_SPAM) {
            await expect(mailRow(page, subject), `${subject} is not in Spam to begin with`).toBeVisible();
        }

        await select(mailRow(page, IN_SPAM[0]));
        await select(mailRow(page, IN_SPAM[1]));

        await actions(page).getByRole("button", { name: "Delete forever" }).click();

        const dialog = page.locator("#confirm-dialog");

        await expect(dialog).toBeVisible();
        await expect(dialog, "the question names how many").toContainText("these 2 conversations");
        await expect(dialog, "and carries no raw placeholder").not.toContainText("%count%");

        await acceptConfirm(page);

        await expect(mailRow(page, IN_SPAM[0])).toHaveCount(0);
        await expect(mailRow(page, IN_SPAM[1])).toHaveCount(0);
        await expect(mailRow(page, IN_SPAM[2]), "spam that was not ticked is still there").toBeVisible();

        // Not merely hidden from this page: gone from the list on a fresh load,
        // and not parked in the bin either, which is what Delete would have done.
        await page.reload();
        await expect(mailRow(page, IN_SPAM[0])).toHaveCount(0);
        await expect(mailRow(page, IN_SPAM[2])).toBeVisible();

        await page.goto("/mail/trash");
        await expect(mailRow(page, IN_SPAM[0]), "it went past the bin").toHaveCount(0);
        await expect(mailRow(page, IN_SPAM[1])).toHaveCount(0);

        await page.goto("/mail/inbox");
        await expect(mailRow(page, STAYS), "and the inbox is untouched").toBeVisible();
    });

    test("one ticked row is asked about in the singular", async ({ page }) => {
        await page.goto("/mail/spam");

        await select(mailRow(page, IN_SPAM[0]));
        await actions(page).getByRole("button", { name: "Delete forever" }).click();

        await expect(page.locator("#confirm-dialog")).toContainText("this conversation");

        await acceptConfirm(page);

        await expect(mailRow(page, IN_SPAM[0])).toHaveCount(0);
    });

    test("declining the question deletes nothing", async ({ page }) => {
        await page.goto("/mail/spam");

        await select(mailRow(page, IN_SPAM[0]));
        await actions(page).getByRole("button", { name: "Delete forever" }).click();

        const dialog = page.locator("#confirm-dialog");

        await expect(dialog).toBeVisible();
        await dialog.getByRole("button", { name: "Cancel" }).click();
        await expect(dialog).toBeHidden();

        await expect(mailRow(page, IN_SPAM[0]), "the row is still on screen").toBeVisible();

        await page.reload();
        await expect(mailRow(page, IN_SPAM[0]), "and still in Spam on the server").toBeVisible();
    });

    /**
     * `#` presses the toolbar's Delete, so with that button gone from Spam the
     * key has nothing to press. It used to move the selection to the bin; what
     * it must NOT become is a way round the question above, and it does not
     * fall back to a row's button or the reading pane's either — those are not
     * consulted while something is ticked.
     *
     * A fixed wait, because the claim is that nothing happens: there is no
     * event to wait for, and the failure being ruled out (the selection going
     * to the bin or being deleted) lands well inside it.
     */
    test("# above a selection in Spam does nothing", async ({ page }) => {
        await page.goto("/mail/spam");

        await select(mailRow(page, IN_SPAM[0]));
        await expect(actions(page).getByRole("button", { name: "Delete forever" })).toBeVisible();

        await page.keyboard.press("#");
        await page.waitForTimeout(1500);

        await expect(page.locator("#confirm-dialog"), "no question was asked").toBeHidden();
        await expect(mailRow(page, IN_SPAM[0]), "and the row is still in Spam").toBeVisible();

        await page.goto("/mail/trash");
        await expect(mailRow(page, IN_SPAM[0]), "it did not go to the bin either").toHaveCount(0);
    });

    test("Spam offers Delete forever instead of Delete, and the inbox keeps Delete", async ({ page }) => {
        await page.goto("/mail/spam");

        await select(mailRow(page, IN_SPAM[0]));

        await expect(actions(page).getByRole("button", { name: "Delete forever" })).toBeVisible();
        await expect(
            actions(page).getByRole("button", { name: "Delete", exact: true }),
            "the trash button would only move spam to a second place to delete it from",
        ).toHaveCount(0);

        await page.goto("/mail/inbox");

        await select(mailRow(page, STAYS));

        await expect(actions(page).getByRole("button", { name: "Delete", exact: true })).toBeVisible();
        await expect(
            actions(page).getByRole("button", { name: "Delete forever" }),
            "nothing outside Spam can be deleted for good from here",
        ).toHaveCount(0);
    });
});
