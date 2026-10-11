import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";
import { setDeleteNow } from "./support/disposal";
import type { Locator, Page } from "@playwright/test";

/**
 * Above a selection in the bin, Delete is Delete forever.
 *
 * Reported as: select mail in the bin, press Delete, and the rows leave the
 * list — and are back on the next load. The toolbar offered the trash Delete
 * there, which posts a trash for mail that already carries Trash. The server
 * changes nothing and still answers with the stream that removes the rows, so
 * the list says "deleted" about mail that is exactly where it was. Rows, the
 * open conversation and the per-message menu already leave that button out of
 * the bin; the toolbar above a selection was the one place that did not.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build.
 *
 * The same Delete forever as in Spam — see bulk-purge.spec.ts for the question,
 * the count and the cancel path, which are not repeated here. This is about
 * what the BIN offers, and that what it offers does what it says.
 */
const IN_BIN = [INBOX_SUBJECTS.star, INBOX_SUBJECTS.archive, INBOX_SUBJECTS.trash];
const STAYS = INBOX_SUBJECTS.read;

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    // Off for anybody who has not chosen it (delete-now-setting.spec.ts); this
    // spec is about what the bin offers once somebody has.
    await setDeleteNow(page, true);

    await page.goto("/mail/inbox");

    const ids: number[] = [];

    for (const subject of IN_BIN) {
        const id = await mailRow(page, subject).getAttribute("id");

        ids.push(Number(id?.replace("thread_", "")));
    }

    // The way a person gets mail into the bin, so what is deleted is what the
    // bin really holds.
    const response = await ajaxPost(page, "/status/bulk/trash", { ids, scope: "inbox", value: "" });

    expect(response.ok(), "putting the fixtures in the bin failed").toBe(true);
});

// Put the mailbox back the way the worker found it, for the reason
// bulk-purge.spec.ts gives: the worker's user is shared with the next file.
test.afterAll(() => {
    seed("seed-mail");
});

// And the switch put back off, for the same reason.
test.afterEach(async ({ page }) => {
    await setDeleteNow(page, false);
});

/** Set-and-dispatch rather than `check()` — see bulk-purge.spec.ts. */
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

test.describe("a selection in the bin", () => {
    test("offers Delete forever instead of Delete", async ({ page }) => {
        await page.goto("/mail/trash");

        await select(mailRow(page, IN_BIN[0]));

        await expect(actions(page).getByRole("button", { name: "Delete forever" })).toBeVisible();
        await expect(
            actions(page).getByRole("button", { name: "Delete", exact: true }),
            "a Delete that reports mail removed from the bin it is still in",
        ).toHaveCount(0);
    });

    test("is deleted for good, and only what was ticked", async ({ page }) => {
        await page.goto("/mail/trash");

        await select(mailRow(page, IN_BIN[0]));
        await select(mailRow(page, IN_BIN[1]));

        await actions(page).getByRole("button", { name: "Delete forever" }).click();
        await acceptConfirm(page);

        await expect(mailRow(page, IN_BIN[0])).toHaveCount(0);
        await expect(mailRow(page, IN_BIN[1])).toHaveCount(0);
        await expect(mailRow(page, IN_BIN[2]), "mail that was not ticked is still in the bin").toBeVisible();

        // Gone on a fresh load too — which is the half the old button failed.
        await page.reload();
        await expect(mailRow(page, IN_BIN[0])).toHaveCount(0);
        await expect(mailRow(page, IN_BIN[1])).toHaveCount(0);
        await expect(mailRow(page, IN_BIN[2])).toBeVisible();

        await page.goto("/mail/inbox");
        await expect(mailRow(page, IN_BIN[0]), "and it did not come back to the inbox").toHaveCount(0);
        await expect(mailRow(page, STAYS), "the inbox is untouched").toBeVisible();
    });

    // See the same test in bulk-purge.spec.ts for why this waits a fixed time.
    test("# above a selection in the bin does nothing", async ({ page }) => {
        await page.goto("/mail/trash");

        await select(mailRow(page, IN_BIN[0]));
        await expect(actions(page).getByRole("button", { name: "Delete forever" })).toBeVisible();

        await page.keyboard.press("#");
        await page.waitForTimeout(1500);

        await expect(page.locator("#confirm-dialog"), "no question was asked").toBeHidden();

        await page.reload();
        await expect(mailRow(page, IN_BIN[0]), "and the mail is still in the bin").toBeVisible();
    });
});
