import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The "New" badge, end to end: it appears when mail arrives and is gone the
 * next time you look.
 *
 * "New" means the row has never been PUT IN FRONT OF YOU. Not unread — opening
 * is not required, and a conversation you scrolled past in the list has stopped
 * being new while still being unread. That is the pair of statements these
 * specs hold apart, because they are the pair a browser can actually check: the
 * badge really has to reach the screen once, and really has to be gone after.
 *
 * The reseed puts a fresh, never-listed mailbox in place before each test, which
 * is what makes "arrival" reproducible without waiting on a real sync.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

const NEW_BADGE = "[data-thread-new]";

test.describe("the new-mail marker", () => {
    test("badges mail that has arrived, and drops the badge on the next visit", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);

        // The first render is the one that has to show it. Marking before
        // rendering would retire the badge in the very frame meant to announce
        // it, and nothing after this point could tell the difference.
        await expect(row).toHaveAttribute("data-new", "true");
        await expect(row.locator(NEW_BADGE)).toBeVisible();

        await page.goto("/mail/inbox");

        await expect(row).toHaveAttribute("data-new", "false");
        await expect(row.locator(NEW_BADGE)).toHaveCount(0);
    });

    /**
     * The distinction the whole feature exists for. The row was listed and
     * never opened: it is no longer news, and it is still unread.
     */
    test("a conversation listed but never opened stops being new and stays unread", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toHaveAttribute("data-new", "true");
        await expect(row).toHaveAttribute("data-unread", "true");

        await page.goto("/mail/inbox");

        await expect(row).toHaveAttribute("data-new", "false");
        await expect(row).toHaveAttribute(
            "data-unread",
            "true",
            // If these ever move together, the marker has been folded back
            // into seenAt and the feature is gone.
        );
    });

    /**
     * Back out of a conversation and the list is re-rendered — by the pane
     * controller if the frame came back empty, by the browser otherwise. Either
     * way it must not put badges back on rows that were retired on the way in.
     */
    test("going Back to the list does not resurrect retired badges", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toHaveAttribute("data-new", "true");

        await row.click();
        await expect(page.locator("#message-list")).toBeHidden();

        await page.goBack();

        const listed = mailRow(page, INBOX_SUBJECTS.read);
        await expect(listed).toBeVisible();
        await expect(listed).toHaveAttribute("data-new", "false");
        await expect(listed.locator(NEW_BADGE)).toHaveCount(0);
    });

    /**
     * The dot, which is the same statement about a PLACE rather than about a
     * row: something arrived in here you have not been shown.
     *
     * Asserted on the sidebar's Inbox row rather than on a category tab,
     * because the tab strip is deliberately absent while everything is in
     * Primary — a lone tab is a heading pretending to be a choice — and the
     * seeded mailbox is entirely Primary. The category tabs carry the same
     * marker from the same macro, and NewMailMarkerTest exercises them against
     * a mailbox that has two categories to show.
     */
    test("dots the place holding new mail and clears it once shown", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        // The desktop sidebar and the mobile drawer both render one.
        const dot = page
            .locator('#sidebar [data-new-dot][data-count-key="new:role:inbox"]');

        await expect(dot).toHaveCount(1);
        await expect(dot).toBeVisible();

        await page.goto("/mail/inbox");

        // Rendered at every count and merely hidden at zero, so the sidebar
        // controller can patch it back without a re-render — which is why this
        // asserts hidden rather than absent.
        await expect(dot).toHaveCount(1);
        await expect(dot).toBeHidden();
    });
});
