import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Archiving and deleting are filing, not one-way doors.
 *
 * Reported from a write-through of the whole app: a conversation put in the bin
 * could not be got out of it, and an archived one could not be reached from any
 * action anywhere in the interface. The row, the thread toolbar, the overflow
 * menu and the label menu between them offered no "move to inbox" — and the one
 * plausible candidate, Archive, was offered ON already-archived mail, where it
 * does nothing at all and says nothing about having done nothing.
 *
 * So the rule is now placement-driven: anywhere that is not the inbox offers the
 * way back, and only what has already been thrown away once offers delete
 * forever. Archiving is ordinary filing; "delete forever" one click from it
 * would have no undo to catch the mistake.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

/** The row's hover actions only exist once the pointer is on the row. */
async function actionsFor(page: import("@playwright/test").Page, subject: string) {
    const row = mailRow(page, subject);
    await expect(row).toBeVisible();
    await row.hover();

    return row;
}

/**
 * Clicks a row action and waits for the write to land.
 *
 * Navigating straight after the click reads the next list from before the
 * write, which fails as "the conversation is not there" — indistinguishable
 * from the feature being broken, and only on some runs.
 */
async function act(page: import("@playwright/test").Page, button: import("@playwright/test").Locator, endpoint: string) {
    const landed = page.waitForResponse(
        (response) => response.url().includes(endpoint) && response.request().method() === "POST",
    );

    await button.click();
    await landed;
}

test.describe("getting mail back", () => {
    test("an archived conversation offers the way back, and not Archive again", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = await actionsFor(page, INBOX_SUBJECTS.archive);
        await act(page, row.getByRole("button", { name: /archive/i }), "/archive");

        await page.goto("/mail/archive");

        const archived = await actionsFor(page, INBOX_SUBJECTS.archive);

        // The action that does nothing here is gone...
        await expect(
            archived.getByRole("button", { name: /^archive/i }),
            "Archive is still offered on mail that is already archived",
        ).toHaveCount(0);

        // ...and deleting for good is not offered from ordinary filing.
        await expect(
            archived.getByRole("button", { name: /forever/i }),
            "delete forever is one click from archive, with no undo",
        ).toHaveCount(0);

        // ...and the way back is.
        await act(page, archived.getByRole("button", { name: /inbox/i }), "/restore");

        await page.goto("/mail/inbox");
        await expect(
            mailRow(page, INBOX_SUBJECTS.archive),
            "the conversation did not come back to the inbox",
        ).toBeVisible({ timeout: 10_000 });
    });

    test("a trashed conversation can be restored, and offers delete forever", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = await actionsFor(page, INBOX_SUBJECTS.read);

        // `exact` on the label rather than a regex: "Delete forever" also
        // matches /delete/i, and a locator that can resolve to either of two
        // buttons — one reversible, one not — is not one to leave in a suite.
        await act(page, row.getByRole("button", { name: "Delete", exact: true }), "/trash");

        await page.goto("/mail/trash");

        const trashed = await actionsFor(page, INBOX_SUBJECTS.read);

        // Both are offered here, and this is the only place delete forever is.
        await expect(trashed.getByRole("button", { name: /forever/i })).toHaveCount(1);

        await act(page, trashed.getByRole("button", { name: /inbox/i }), "/restore");

        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible({ timeout: 10_000 });
    });
});
