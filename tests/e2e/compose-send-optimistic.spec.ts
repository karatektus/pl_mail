import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Send, answered optimistically — the default.
 *
 * The composer used to stay open for the whole cancel window and become its own
 * cancel: nothing moved, and the way back was exactly where the way forward
 * had been. It is a defensible shape and it has one problem, which is the one
 * that got reported — eight seconds of a window that will not close is a long
 * time when you have finished writing and want to get on. "Das warten beim
 * Absenden ist pain."
 *
 * So the default is now the other trade: the composer closes at once, the mail
 * is already where it will live, and the cancel moves to a toast that counts
 * down beside it. The held shape is still there, one setting away, and
 * compose-send-cancel.spec.ts pins it — see
 * User::SETTING_COMPOSE_SEND_FEEDBACK.
 *
 * These are the claims that make it worth the trade:
 *   • the composer is gone immediately, in the dock and inline alike;
 *   • the mail is visible as sent, not as a draft, and not missing;
 *   • the undo is on screen, counting, and it works;
 *   • the toast's life IS the cancel window — an undo that outlives the window
 *     is one that silently fails, and one that dies early takes away a cancel
 *     that still worked.
 */
const DOCK = "#compose_dock";
const INLINE = "#compose_inline";
const TOAST = "#toast-region";

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

test.describe("send closes the composer and puts the undo in a toast", () => {
    test("a dock send closes the window at once and offers the undo", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();

        const window_ = page.locator(`${DOCK} .compose-window`);
        await expect(window_).toBeVisible();

        await window_.locator('input[type="text"], input[type="email"]').first()
            .fill("optimistic@example.test");
        await window_.locator('input[name*="[subject]"]').first().fill("E2E Optimistic");
        await window_.locator('[data-compose--compose-toolbar-target="editor"]').first()
            .fill("Body");

        await window_.getByRole("button", { name: /^Send/ }).first().click();

        // The whole point: no waiting for the cancel window.
        await expect(window_, "the composer is still open after Send").toHaveCount(0, { timeout: 5_000 });

        const toast = page.locator(TOAST);
        await expect(toast).toContainText("Sending");
        await expect(toast.getByRole("button", { name: "Undo" })).toBeVisible();
    });

    /**
     * And the undo actually undoes.
     *
     * A cancel that only dismissed the toast would look identical for the first
     * second and lose the mail — the draft has to come back, which is the
     * answer the user is owed rather than a message saying it was cancelled.
     */
    test("the undo brings the composer back with the message in it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();

        const window_ = page.locator(`${DOCK} .compose-window`);
        await expect(window_).toBeVisible();

        await window_.locator('input[type="text"], input[type="email"]').first()
            .fill("optimistic@example.test");
        await window_.locator('input[name*="[subject]"]').first().fill("E2E Undo Me");
        await window_.locator('[data-compose--compose-toolbar-target="editor"]').first()
            .fill("Body");

        await window_.getByRole("button", { name: /^Send/ }).first().click();
        await expect(window_).toHaveCount(0, { timeout: 5_000 });

        await page.locator(TOAST).getByRole("button", { name: "Undo" }).click();

        const reopened = page.locator(`${DOCK} .compose-window`);
        await expect(reopened).toBeVisible({ timeout: 10_000 });
        await expect(reopened.locator('input[name*="[subject]"]').first())
            .toHaveValue("E2E Undo Me");
    });

    /**
     * An inline reply behaves the same way, which is the half that used to
     * differ.
     *
     * Before the held shape there were two answers to one act — a toast for the
     * dock, a countdown bar in the thread — and the whole reason the shapes
     * were unified was that having two was worse than either. One setting, both
     * surfaces.
     */
    test("an inline reply closes too, and the sent mail is in the conversation", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const window_ = page.locator(`${INLINE} .compose-window`);
        await expect(window_).toBeVisible();

        await window_.locator('[data-compose--compose-toolbar-target="editor"]').first()
            .fill("Inline optimistic body");

        await window_.getByRole("button", { name: /^Send/ }).first().click();

        await expect(window_, "the inline composer stayed open").toHaveCount(0, { timeout: 5_000 });
        await expect(page.locator(TOAST).getByRole("button", { name: "Undo" })).toBeVisible();

        // The reply is in the conversation as a sent message rather than
        // waiting somewhere for the cancel window to expire. Its body is the
        // honest thing to look for: a draft row would be hidden while the
        // editor was open, so finding this text at all means the sent copy has
        // been appended.
        // Present rather than visible: the appended message renders collapsed,
        // so its body lives in the row's snippet, which is `hidden` until the
        // row is opened. Asserting visibility here would be asserting that the
        // conversation expands the newest message — a different claim, and one
        // this test has no opinion about.
        await expect(page.locator('[id^="thread_message_"]').filter({ hasText: "Inline optimistic body" }))
            .toHaveCount(1, { timeout: 10_000 });
    });
});
