import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, mailRow, seed } from "./support/config";
import { execSync } from "node:child_process";

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

/**
 * What the database says about a subject, straight out of Postgres.
 *
 * Asserting on pixels alone is what let the original bug ship: every spec here
 * navigated with page.goto(), which asks the server fresh every time, so
 * "badge gone" and "row marked listed" were indistinguishable. They are not the
 * same statement, and the tests below need to be able to tell them apart.
 */
function isListed(subject: string): boolean {
    const compose = process.env.E2E_COMPOSE ?? "docker compose -f compose.test.yaml";

    // Scoped to THIS worker's user, not just the subject. Every parallel slot
    // seeds the same four subjects into a mailbox of its own, so a bare
    // `where subject = …` reads another worker's rows and answers about mail
    // this test never touched — which it did, intermittently, at workers > 1.
    const out = execSync(
        `${compose} exec -T database psql -U app -d app_test -t -A -c ` +
            `"select count(*) from message_thread t ` +
            `join account a on a.id = t.account_id ` +
            `join \\"user\\" u on u.id = a.usr_id ` +
            `where u.email = '${TEST_USER.email}' ` +
            `and t.subject = '${subject}' and t.listed_at is not null"`,
        { encoding: "utf8" },
    ).trim();

    return Number(out) > 0;
}

/**
 * The reported bug: "when i tried the 'new' feature the tag survived a reload
 * and tab navigation. i dont think thats correct."
 *
 * It did, and nothing in the suite caught it, because nothing in the suite
 * navigated the way a person does. page.goto() is a browser load: Turbo is not
 * involved, no link is hovered, and the request reaches the server as an
 * ordinary visit that the server duly marks. Clicking a link is a different
 * mechanism entirely — Turbo 8 prefetches on hover, the server refuses to
 * retire badges on a prefetch (rightly: a hover is not a visit), and then Turbo
 * SERVES THAT PREFETCHED RESPONSE for the click. There is no second request to
 * mark. The badge was never retired, on any navigation, ever.
 *
 * So these specs click. That is the whole point of them, and any rewrite that
 * turns a click back into a goto puts the bug back beyond reach of the suite.
 */
test.describe("the badge and real navigation", () => {
    test("a list reached by CLICKING a nav link still retires its badges", async ({
        page,
    }) => {
        // Land somewhere that is not the inbox, so the inbox rows are untouched.
        await page.goto("/mail/starred");

        expect(
            isListed(INBOX_SUBJECTS.read),
            "precondition: the seeded inbox has not been shown yet",
        ).toBe(false);

        const inboxLink = page.locator('#sidebar a[href="/mail/inbox"]').first();

        // Hover first, and let the prefetch actually fire. Without this the
        // click is a plain fetch and the test proves nothing about the bug.
        await inboxLink.hover();
        await page.waitForTimeout(500);

        expect(
            isListed(INBOX_SUBJECTS.read),
            "hovering is not looking — a prefetch must never retire a badge",
        ).toBe(false);

        await inboxLink.click();

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toHaveAttribute("data-new", "true");

        // The client confirms the display; the badge is retired for real.
        await expect
            .poll(() => isListed(INBOX_SUBJECTS.read), {
                message: "the rows reached the screen, so the server has to be told",
                timeout: 5_000,
            })
            .toBe(true);
    });

    test("the badge does not survive a reload", async ({ page }) => {
        await page.goto("/mail/starred");

        const inboxLink = page.locator('#sidebar a[href="/mail/inbox"]').first();
        await inboxLink.hover();
        await page.waitForTimeout(500);
        await inboxLink.click();

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-new", "true");
        await expect.poll(() => isListed(INBOX_SUBJECTS.read), { timeout: 5_000 }).toBe(true);

        await page.reload();

        const reloaded = mailRow(page, INBOX_SUBJECTS.read);
        await expect(reloaded).toHaveAttribute("data-new", "false");
        await expect(reloaded.locator(NEW_BADGE)).toHaveCount(0);
    });

    test("the badge does not survive navigating away and back", async ({ page }) => {
        await page.goto("/mail/starred");

        const inboxLink = page.locator('#sidebar a[href="/mail/inbox"]').first();
        const starredLink = page.locator('#sidebar a[href="/mail/starred"]').first();

        await inboxLink.hover();
        await page.waitForTimeout(500);
        await inboxLink.click();

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-new", "true");
        await expect.poll(() => isListed(INBOX_SUBJECTS.read), { timeout: 5_000 }).toBe(true);

        await starredLink.hover();
        await starredLink.click();
        await expect(page.locator("#message-list")).toBeVisible();

        // Back to the inbox the same way it was reached the first time — hover,
        // prefetch, click. This is the exact sequence that used to redraw the
        // badge indefinitely.
        await inboxLink.hover();
        await page.waitForTimeout(500);
        await inboxLink.click();

        const second = mailRow(page, INBOX_SUBJECTS.read);
        await expect(second).toHaveAttribute("data-new", "false");
        await expect(second.locator(NEW_BADGE)).toHaveCount(0);
    });
});

/**
 * "search can remove 'new' tags."
 *
 * A row read in a result list has been shown, and search now goes through the
 * same collect-render-then-mark path as every other list.
 */
test.describe("search", () => {
    test("finding a conversation in search retires its badge", async ({ page }) => {
        await page.goto(`/mail/search?q=${encodeURIComponent(INBOX_SUBJECTS.read)}`);

        const result = mailRow(page, INBOX_SUBJECTS.read);
        await expect(result).toBeVisible();
        await expect(result).toHaveAttribute("data-new", "true");

        await expect
            .poll(() => isListed(INBOX_SUBJECTS.read), { timeout: 5_000 })
            .toBe(true);

        await page.goto("/mail/inbox");

        const inInbox = mailRow(page, INBOX_SUBJECTS.read);
        await expect(inInbox).toHaveAttribute("data-new", "false");
        await expect(inInbox.locator(NEW_BADGE)).toHaveCount(0);
    });
});
