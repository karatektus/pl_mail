import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Runs authenticated as this worker's own user, signed in by the worker
 * fixture in support/test.ts.
 *
 * A fresh, deterministic inbox is reseeded before each test via the
 * `app:test:seed-mail` console command (Gmail-style messages: label
 * mutations only, no IMAP folder). Per-test reseeding keeps the cases
 * fully independent and retry-safe even though they mutate shared data.
 *
 * Override E2E_CONSOLE if `php` isn't on PATH (e.g. "symfony console").
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("mail UI actions", () => {
    /**
     * The row's who column lists everyone who has written in the conversation,
     * oldest first. It used to show the newest sender, so answering a mail
     * relabelled the whole conversation as coming from you.
     */
    test("keeps the correspondent in the row after you reply", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toContainText("E2E Sender");

        await row.click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await page
            .locator('#compose_inline [data-compose--compose-toolbar-target="editor"]')
            .fill("Reply draft body");
        await page.waitForResponse((r) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/inbox");

        // The same row, not a new one called "Re: …". The reply joins the
        // conversation it answers, so the list keeps showing the thread's own
        // subject and simply counts one message more — looking for a "Re:" row
        // was looking for a row this app never renders.
        const replied = mailRow(page, INBOX_SUBJECTS.read);
        await expect(replied).toContainText("E2E Sender, me");
    });

    test("stars a conversation and it shows in the Starred view", async ({
                                                                             page,
                                                                         }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: "Star this message" }).click();

        // _star.stream replaces the row; the toggle flips to "Unstar".
        await expect(
            row.getByRole("button", { name: "Unstar this message" }),
        ).toBeVisible();

        // And the conversation now appears under Starred.
        await page.goto("/mail/starred");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.star }),
        ).toBeVisible();
    });

    test("archives a conversation and it leaves the inbox", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(row).toBeVisible();

        // Hover-only actions need the row hovered first.
        await row.hover();
        await row.getByRole("button", { name: "Archive", exact: true }).click();

        // _archive.stream removes the row.
        await expect(row).toHaveCount(0);

        // Still gone after a reload (Inbox label removed, DB is source of truth).
        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("deletes a conversation and it moves to Trash", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toBeVisible();

        await row.hover();
        await row.getByRole("button", { name: "Delete", exact: true }).click();

        // _delete.stream removes the row from the inbox.
        await expect(row).toHaveCount(0);

        // Trash adds the Trash-role label, so it surfaces in the Trash view.
        await page.goto("/mail/trash");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.trash }),
        ).toBeVisible();
    });

    test("marks a conversation as read", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toBeVisible();
        await expect(row).toHaveAttribute("data-unread", "true");

        await row.hover();

        // Wait for the status POST itself, then reload — the read-state stream
        // has a known template typo (flagged separately), so assert on the
        // persisted outcome rather than the inline swap.
        const readPost = page.waitForResponse(
            (r) =>
                r.request().method() === "POST" &&
                /\/status\/thread\/\d+\/read$/.test(r.url()),
        );
        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
        await readPost;

        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute(
            "data-unread",
            "false",
        );
    });

    test("marks a read conversation back to unread", async ({ page }) => {
        await page.goto("/mail/inbox");

        // Round-trip on a seeded (unread) thread: read, then unread. Reloading
        // between steps keeps this independent of the read-stream inline swap.
        const subject = INBOX_SUBJECTS.read;
        const readEndpoint = /\/status\/thread\/\d+\/read$/;

        // Step 1 — mark read.
        let row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "true");
        await row.hover();
        let post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
        await post;
        await page.reload();

        // Step 2 — the same row now offers "Mark as unread".
        row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "false");
        await row.hover();
        post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await row
            .getByRole("button", { name: "Mark as unread", exact: true })
            .click();
        await post;
        await page.reload();

        await expect(mailRow(page, subject)).toHaveAttribute("data-unread", "true");
    });

    // ── Bulk toolbar actions ─────────────────────────────────────────────────
    //
    // Written against observable outcomes rather than the endpoint, so they
    // hold however the bulk mutation is implemented — per-row streams or a
    // frame reload. (They carried a notice saying they would fail until the
    // toolbar handlers stopped being console.log stubs, with an instruction to
    // delete it once green. They are green.)

    const allRows = (page: Page) =>
        page.locator('#message-list li[data-controller="mail--message-row"]');

    /**
     * The four rows this spec seeded are on screen.
     *
     * NOT a count of the whole list, which is what these tests used to open
     * with. `seed-mail` wipes and refills one account — mailbox@e2e.test — but
     * the inbox is unified, and the same user also owns the demo mailbox the
     * screenshot tour seeds (a second account, ten threads, never cleaned up).
     * So the moment anybody ran the screenshots, every one of these tests
     * failed on its FIRST line, by exactly ten, having tested nothing.
     *
     * The precondition that matters is only that the seed landed. What the
     * bulk actions then do to the rest of the mailbox is asserted after the
     * fact, where "none left" is true however many there were.
     */
    const expectSeededRows = async (page: Page) => {
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toBeVisible();
        }
    };

    const selectAll = async (page: Page) => {
        await page
            .getByRole("checkbox", { name: "Select all conversations" })
            .click();
        // Actions slot swaps in only once ≥1 row is selected.
        await expect(page.locator('[data-mail--list-toolbar-target="actions"]')).toBeVisible();
    };

    const bulkAction = (page: Page, name: string) =>
        page
            .locator('[data-mail--list-toolbar-target="actions"]')
            .getByRole("button", { name, exact: true });

    test("bulk-archives every selected conversation", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Archive").click();

        await expect(allRows(page)).toHaveCount(0);
        await page.reload();
        await expect(allRows(page)).toHaveCount(0);
    });

    test("bulk-deletes every selected conversation into Trash", async ({
                                                                           page,
                                                                       }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Delete").click();

        await expect(allRows(page)).toHaveCount(0);

        await page.goto("/mail/trash");
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(
                page
                    .locator('#message-list li[data-controller="mail--message-row"]')
                    .filter({ hasText: subject }),
            ).toBeVisible();
        }
    });

    test("bulk-marks every selected conversation as read", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Mark as read").click();

        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "false",
            );
        }
    });

    test("bulk-marks every selected conversation as unread", async ({ page }) => {
        // Seeded threads start unread, so make them read first, then unread.
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Mark as read").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "false",
            );
        }

        await selectAll(page);
        await bulkAction(page, "Mark as unread").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "true",
            );
        }
    });

    // ── Still pending: needs more than wiring ────────────────────────────────

    // Blocked: "Label as" never fires the POST from the UI. The only rendered
    // menu is the list-toolbar bulk instance, whose _resolveTargets() reads
    // `[data-thread-select]:checked` — but the row checkbox has no
    // `data-thread-select`/`value`, so it finds zero targets. And no template
    // renders _label_menu with a targetId (single-target mode). Minimal fix:
    // add `data-thread-select value="{{ rowId }}"` to the row checkbox (unblocks
    // bulk), or render _label_menu with targetId in _thread_content.html.twig
    // (unblocks single-target). This one also needs a seeded custom label to
    // click, so it stays fixme until both land.
    test.fixme("labels a conversation via the Label-as menu", async ({
                                                                         page,
                                                                     }) => {
        await page.goto("/mail/inbox");
        // TODO(app): wire a working label-menu target, then seed a custom label.
    });
});
