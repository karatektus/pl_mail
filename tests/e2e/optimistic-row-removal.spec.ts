import type { Locator, Page, Route } from "@playwright/test";
import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";
import { setDeleteNow } from "./support/disposal";
import { rowAction } from "./support/rows";

/**
 * A row leaves the list when its action is chosen, not when the server answers,
 * and comes back where it was when the request fails (#335).
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * WHY THE REQUEST IS HELD
 * ───────────────────────
 * Against the test stack the server answers in a few milliseconds, so "the row
 * went before the answer" and "the row went with the answer" look the same.
 * Each test here holds the request in page.route() until it has looked at the
 * list, and only then lets it through — or answers it with a 500 itself, which
 * is the failure path: the server never sees the request, so the mail is
 * untouched and the row has to be put back by the page alone.
 *
 * `hidden`, not removed: the row stays in the document while it is away (see
 * assets/optimistic_rows.js), so "away" is asserted with toBeHidden() and
 * "really gone" with toHaveCount(0), which only the server's stream achieves.
 */

test.beforeEach(() => {
    seed("seed-mail");
});

// Put the mailbox back for whatever runs next on this worker's user: these
// tests archive, bin and restore the fixture threads.
test.afterAll(() => {
    seed("seed-mail");
});

/**
 * Hold the next POST to a matching URL until the returned function is called.
 *
 * Resolves `held` once the request has arrived, so a test can wait for it to
 * be in flight before looking at the list. The release either sends it on to
 * the server or answers it with an error without the server seeing it.
 */
async function hold(page: Page, url: RegExp): Promise<{ held: Promise<void>; release: (outcome: "pass" | "fail") => Promise<void> }> {
    let arrived!: () => void;
    let route!: Route;

    const held = new Promise<void>((resolve) => { arrived = resolve; });

    await page.route(url, async (incoming) => {
        if ("POST" !== incoming.request().method()) {
            return incoming.fallback();
        }

        route = incoming;
        arrived();
    }, { times: 1 });

    return {
        held,
        async release(outcome) {
            await held;

            if ("pass" === outcome) {
                await route.continue();

                return;
            }

            await route.fulfill({ status: 500, contentType: "text/html", body: "boom" });
        },
    };
}

/** The ids of the rows on screen, top to bottom — hidden ones included. */
async function order(page: Page): Promise<string[]> {
    return page.locator('#message-list li[data-controller="mail--message-row"]').evaluateAll(
        (rows) => rows.map((row) => row.id),
    );
}

/** Tick a row the way the other bulk specs do (see bulk-purge.spec.ts). */
async function select(row: Locator): Promise<void> {
    await row.locator("[data-thread-select]").evaluate((box) => {
        (box as HTMLInputElement).checked = true;
        box.dispatchEvent(new Event("change", { bubbles: true }));
    });
}

function toolbar(page: Page): Locator {
    return page.locator('[data-mail--list-toolbar-target="actions"]');
}

/** The error toast requestFailed() raises. */
function errorToast(page: Page): Locator {
    return page.locator('[role="alert"]').filter({ has: page.locator("[data-toast-message]") });
}

/** The purge test's steps, run with Delete forever switched on. */
async function purgeAfterDeclining(page: Page): Promise<void> {
    await page.goto("/mail/inbox");

    const id = Number((await mailRow(page, INBOX_SUBJECTS.trash).getAttribute("id"))?.replace("thread_", ""));
    const binned = await ajaxPost(page, "/status/bulk/trash", { ids: [id], scope: "inbox", value: "" });
    expect(binned.ok(), "putting the fixture in the bin failed").toBe(true);

    await page.goto("/mail/trash");

    const row = mailRow(page, INBOX_SUBJECTS.trash);
    await expect(row).toBeVisible();

    await rowAction(row, /^Delete forever$/);

    const dialog = page.locator("#confirm-dialog");
    await expect(dialog).toBeVisible();
    await expect(row, "nothing is hidden while the question is open").toBeVisible();

    await dialog.getByRole("button", { name: "Cancel" }).click();
    await expect(dialog).toBeHidden();
    await expect(row, "a declined question leaves it where it is").toBeVisible();

    const request = await hold(page, /\/status\/thread\/\d+\/purge$/);

    await rowAction(row, /^Delete forever$/);
    await acceptConfirm(page);
    await request.held;

    await expect(row, "hidden once the question is answered").toBeHidden();

    await request.release("pass");
    await expect(row).toHaveCount(0);
}

test.describe("a row the action takes out of the list", () => {
    test("archive: gone before the answer, gone for good after it, with Undo", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(row).toBeVisible();

        const request = await hold(page, /\/status\/thread\/\d+\/archive$/);

        await rowAction(row, "Archive");
        await request.held;

        await expect(row, "hidden while the server has not answered").toBeHidden();
        await expect(mailRow(page, INBOX_SUBJECTS.read), "and nothing else went with it").toBeVisible();

        await request.release("pass");

        await expect(row, "taken out by the answer").toHaveCount(0);
        await expect(page.getByRole("button", { name: "Undo" })).toBeVisible();

        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("archive that fails: the row comes back where it was, with a toast", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(row).toBeVisible();

        const before  = await order(page);
        const request = await hold(page, /\/status\/thread\/\d+\/archive$/);

        await rowAction(row, "Archive");
        await request.held;
        await expect(row).toBeHidden();

        await request.release("fail");

        await expect(row, "back after the failure").toBeVisible();
        await expect(errorToast(page)).toBeVisible();
        expect(await order(page), "in the place it stood").toEqual(before);

        // And the list refresh that follows the write did not take it away
        // again: the server still has it in the inbox.
        await page.waitForTimeout(1_000);
        await expect(row).toBeVisible();
    });

    test("delete for good asks first, and a declined question leaves the row alone", async ({ page }) => {
        // The row's Delete forever is offered only to somebody who has asked
        // for it (Settings → Mail → Disposal), and is switched back off below
        // for the specs that share this worker's user.
        await setDeleteNow(page, true);

        try {
            await purgeAfterDeclining(page);
        } finally {
            await setDeleteNow(page, false);
        }
    });

    test("restore from the bin leaves the bin at once", async ({ page }) => {
        await page.goto("/mail/inbox");

        const id = Number((await mailRow(page, INBOX_SUBJECTS.trash).getAttribute("id"))?.replace("thread_", ""));
        const binned = await ajaxPost(page, "/status/bulk/trash", { ids: [id], scope: "inbox", value: "" });
        expect(binned.ok()).toBe(true);

        await page.goto("/mail/trash");

        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toBeVisible();

        const request = await hold(page, /\/status\/thread\/\d+\/restore$/);

        await rowAction(row, "Move to inbox");
        await request.held;
        await expect(row).toBeHidden();

        await request.release("pass");
        await expect(row).toHaveCount(0);

        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.trash)).toBeVisible();
    });
});

test.describe("the ticked rows a toolbar action takes out of the list", () => {
    test("delete: hidden at once, and only those", async ({ page }) => {
        await page.goto("/mail/inbox");

        const first  = mailRow(page, INBOX_SUBJECTS.trash);
        const second = mailRow(page, INBOX_SUBJECTS.archive);

        await select(first);
        await select(second);

        const request = await hold(page, /\/status\/bulk\/trash$/);

        await toolbar(page).getByRole("button", { name: "Delete", exact: true }).click();
        await request.held;

        await expect(first).toBeHidden();
        await expect(second).toBeHidden();
        await expect(mailRow(page, INBOX_SUBJECTS.read), "a row that was not ticked stays").toBeVisible();

        await request.release("pass");

        await expect(first).toHaveCount(0);
        await expect(second).toHaveCount(0);
    });

    test("delete that fails: the rows come back in place and still ticked", async ({ page }) => {
        await page.goto("/mail/inbox");

        const first  = mailRow(page, INBOX_SUBJECTS.trash);
        const second = mailRow(page, INBOX_SUBJECTS.archive);

        await select(first);
        await select(second);

        const before  = await order(page);
        const request = await hold(page, /\/status\/bulk\/trash$/);

        await toolbar(page).getByRole("button", { name: "Delete", exact: true }).click();
        await request.held;
        await expect(first).toBeHidden();

        await request.release("fail");

        await expect(first).toBeVisible();
        await expect(second).toBeVisible();
        await expect(errorToast(page)).toBeVisible();
        expect(await order(page)).toEqual(before);

        await expect(first.locator("[data-thread-select]"), "the selection is kept for a second try").toBeChecked();
        await expect(second.locator("[data-thread-select]")).toBeChecked();
    });

    test("mark as read is not one of them: the row waits for the answer", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toBeVisible();

        const request = await hold(page, /\/status\/thread\/\d+\/read$/);

        await rowAction(row, /^Mark as (read|unread)$/);
        await request.held;

        await expect(row, "a row the action does not take away is never hidden").toBeVisible();

        await request.release("pass");
        await expect(row).toBeVisible();
    });
});
