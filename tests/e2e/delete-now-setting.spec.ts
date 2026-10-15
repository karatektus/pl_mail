import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { setDeleteNow } from "./support/disposal";
import type { Locator, Page } from "@playwright/test";

/**
 * Deleting mail for good is offered only to somebody who switched it on.
 *
 * Settings → Mail → Disposal holds the switch. It is off to begin with, and
 * while it is off neither the flame on a row in Spam nor the button above a
 * selection there is on the page. Turning it on through the page, and turning
 * it off again, is what this walks: the form, the redirect and the list that is
 * drawn after it.
 *
 * DeleteNowSettingTest has the routes refusing without the switch. What only a
 * browser shows is that the page the user lands on afterwards really differs.
 */
const SPAM_SUBJECT = INBOX_SUBJECTS.star;

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    await page.goto("/mail/inbox");

    const id = await mailRow(page, SPAM_SUBJECT).getAttribute("id");
    const response = await ajaxPost(page, "/status/bulk/move-to", {
        ids: [Number(id?.replace("thread_", ""))],
        role: "spam",
        scope: "inbox",
        value: "",
    });

    expect(response.ok(), "putting the fixture in Spam failed").toBe(true);
});

// Put the mailbox back the way the worker found it: the worker's user is shared
// with whatever file runs next, and this one moved mail into Spam.
test.afterAll(() => {
    seed("seed-mail");
});

test.afterEach(async ({ page }) => {
    await setDeleteNow(page, false);
});

async function select(row: Locator) {
    await row.locator("[data-thread-select]").evaluate((box) => {
        (box as HTMLInputElement).checked = true;
        box.dispatchEvent(new Event("change", { bubbles: true }));
    });
}

function actions(page: Page): Locator {
    return page.locator('[data-mail--list-toolbar-target="actions"]');
}

test.describe("the delete-now switch in Settings → Mail → Disposal", () => {
    test("is off to begin with: Spam offers Delete and no Delete forever, on the row or above a selection", async ({ page }) => {
        await page.goto("/settings?section=disposal");

        await expect(page.locator('input[name="deleteNow"][value="0"]'), "off is the chosen answer").toBeChecked();

        await page.goto("/mail/spam");

        const row = mailRow(page, SPAM_SUBJECT);

        await expect(row, "the fixture is in Spam").toBeVisible();

        // The row's actions only exist while the pointer is on it, so an absence
        // is only worth anything once the row is hovered. Move to inbox is the
        // companion: it is there on any row in Spam, so a hover that did not
        // take would fail here rather than pass the absence below for free.
        await row.hover();
        await expect(row.getByRole("button", { name: /inbox/i }), "the row's actions are showing").toBeVisible();
        await expect(row.getByRole("button", { name: "Delete forever" }), "no flame on the row").toHaveCount(0);

        await select(row);

        await expect(actions(page).getByRole("button", { name: "Delete", exact: true }), "the ordinary Delete").toBeVisible();
        await expect(actions(page).getByRole("button", { name: "Delete forever" }), "and no Delete forever").toHaveCount(0);
    });

    test("turned on, the flame is on the row and Delete forever replaces Delete above a selection; turned off again, both go", async ({ page }) => {
        await setDeleteNow(page, true);

        await page.goto("/mail/spam");

        const row = mailRow(page, SPAM_SUBJECT);

        await expect(row).toBeVisible();
        await row.hover();
        await expect(row.getByRole("button", { name: "Delete forever" }), "the flame is on the row").toBeVisible();

        await select(row);

        await expect(actions(page).getByRole("button", { name: "Delete forever" }), "and above a selection").toBeVisible();
        await expect(actions(page).getByRole("button", { name: "Delete", exact: true }), "in place of Delete").toHaveCount(0);

        await setDeleteNow(page, false);

        await page.goto("/mail/spam");

        const again = mailRow(page, SPAM_SUBJECT);

        await expect(again).toBeVisible();
        await again.hover();
        await expect(again.getByRole("button", { name: /inbox/i }), "the row's actions are showing").toBeVisible();
        await expect(again.getByRole("button", { name: "Delete forever" }), "and the flame is gone again").toHaveCount(0);
    });
});
