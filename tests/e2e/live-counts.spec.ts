import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, consoleCommand, mailRow, seed } from "./support/config";

/**
 * The numbers beside the list keep up with the list.
 *
 * The report: tick two read conversations, press "Mark as unread", and the rows
 * correctly turn bold while "Inbox 5" stays at 5 and the category tab stays at
 * 2. Only a reload corrected them. The counts were right server-side the whole
 * time — nothing on the page was asking for them.
 *
 * There was machinery for this already and it was wired to the wrong thing:
 * ui--sidebar#refreshCounts runs on Mercure sync events, and a bulk action is
 * not a sync. The toolbar has always announced itself — the mail pane holds its
 * list refresh on `mail--list-toolbar:writing` and releases on `:written` — so
 * the fix was to have the badges listen to the announcement that was already
 * being made.
 *
 * These are live-UI tests on purpose. A controller test would have passed
 * throughout: the counts endpoint and the server-rendered badge were never
 * wrong, and the whole defect lived in the seconds after a click.
 *
 * The second describe below is the same defect reported a second time, and the
 * reason the fix above was not enough: the badges were wired to the BULK
 * toolbar's announcement specifically, so they kept up with the one path
 * almost nobody uses and with none of the paths people actually read mail
 * through. Reported as the counter updating "nicht zuverlässig" — unreliably
 * rather than never — because navigating between folders re-renders the badges
 * server-side, so whether it looked broken depended on whether you happened to
 * click away after reading. Measured before the fix: a full minute on the
 * inbox after reading a mail, with no sync event, no counts request, and the
 * badge still one too high while the server had the right number all along.
 */

const INBOX_BADGE = '[data-count-key="role:inbox"]';
const TOOLBAR = '[data-controller="mail--list-toolbar"]';

/** No reload anywhere in this file. That is the entire point of it. */
async function inboxBadge(page: import("@playwright/test").Page): Promise<string> {
    return ((await page.locator(INBOX_BADGE).first().textContent()) ?? "").trim();
}

/** The avatar is the row's checkbox — the real input is sr-only. */
function tick(page: import("@playwright/test").Page, subject: string) {
    return mailRow(page, subject).locator("label:has(input[data-thread-select])").click();
}

test.beforeEach(() => {
    seed("seed-mail");
});

/**
 * A single sync event moves the badge, even inside the rate-limit window.
 *
 * The window collapses a burst — a sync run publishes one event per mailbox per
 * account — and it used to DROP anything that landed inside it, on the
 * reasoning that "a missed counts update is corrected by the next one, and
 * there is always a next one". That holds for a mailbox syncing on a timer and
 * is false for the case a person actually watches: the demo's Receive button
 * publishes exactly one event, and if it fell inside the window the badge
 * simply never moved. Nothing came afterwards to correct it.
 *
 * Driven by dispatching the event the hub would deliver, rather than by waiting
 * for a real sync: what is under test is the sidebar's handling of it, and a
 * real sync would take the rate limit out of the picture by being slow.
 */
test("a lone sync event still moves the badge after a recent refresh", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
    await expect(page.locator(INBOX_BADGE).first()).toHaveText("4");

    // A write, which refreshes the counts immediately and so starts the window.
    await tick(page, INBOX_SUBJECTS.read);
    await page.locator(TOOLBAR).getByRole("button", { name: /read/i }).first().click();
    await expect(page.locator(INBOX_BADGE).first()).toHaveText("3");

    // Now the server changes underneath, and one event announces it — inside
    // the window that has just been started.
    consoleCommand(`app:test:seed-mail --email=${TEST_USER.email}`);

    await page.evaluate(() => {
        document.dispatchEvent(new CustomEvent("core--mercure:account-synced", { detail: {} }));
    });

    // Back to four, because the seed restored the unread ones. The wait is
    // generous on purpose: the whole point is that the refresh is DEFERRED to
    // the end of the window rather than dropped.
    await expect(page.locator(INBOX_BADGE).first(), "a lone sync event was dropped").toHaveText("4", {
        timeout: 20_000,
    });
});

test.describe("counters after a bulk action", () => {
    test("marking two conversations read drops the sidebar badge without a reload", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        // The fixture seeds four unread conversations.
        expect(await inboxBadge(page), "the fixture has to start with a badge").toBe("4");

        await tick(page, INBOX_SUBJECTS.read);
        await tick(page, INBOX_SUBJECTS.star);

        await page.getByRole("button", { name: "Mark as read" }).first().click();

        // toPass rather than a fixed wait: the assertion is that it arrives on
        // its own, and how fast is not the contract. Well inside any polling
        // interval, so a badge that is right here is right because the action
        // said so and not because a poll came round.
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("2", { timeout: 5000 });
    });

    /**
     * The direction the report actually took, and the harder one: marking
     * unread puts numbers back rather than taking them away, so a badge that
     * simply never moved would have looked correct in the read case if the
     * fixture had started at zero.
     */
    test("marking them unread again puts the badge back up", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        await tick(page, INBOX_SUBJECTS.read);
        await tick(page, INBOX_SUBJECTS.star);
        await page.getByRole("button", { name: "Mark as read" }).first().click();
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("2", { timeout: 5000 });

        // Same two rows, back the other way.
        await tick(page, INBOX_SUBJECTS.read);
        await tick(page, INBOX_SUBJECTS.star);
        await page.getByRole("button", { name: "Mark as unread" }).first().click();

        await expect(page.locator(INBOX_BADGE).first()).toHaveText("4", { timeout: 5000 });
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "true");
    });

    /**
     * The other half of the report, and the half no turbo-stream covers.
     *
     * A bulk action's response redraws the ROWS it touched. Everything else in
     * the list frame — the category tabs with their pills and sender hints,
     * the "1–4 of 4" range — is addressed by nothing, so it kept whatever the
     * last full render had said. The frame is re-read after a write now
     * (mail--mail-pane#release), which is what this pins.
     */
    test("the whole list frame is re-read, not just the rows the streams touched", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        // Count the fragment fetches the pane makes. The header is its
        // signature — see mail_pane_controller's FRAGMENT_HEADER.
        let fragmentFetches = 0;
        page.on("request", (request) => {
            if (request.headers()["x-list-fragment"] !== undefined) {
                fragmentFetches++;
            }
        });

        await tick(page, INBOX_SUBJECTS.trash);
        await page.getByRole("button", { name: "Mark as read" }).first().click();

        await expect
            .poll(() => fragmentFetches, { timeout: 5000 })
            .toBeGreaterThan(0);
    });
});

/**
 * How a person actually reads mail. None of these go through the toolbar.
 */
test.describe("counters after reading one mail", () => {
    test("opening an unread conversation drops the sidebar badge", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        expect(await inboxBadge(page), "the fixture has to start with a badge").toBe("4");

        // Read it the way a person does: open it. mail--thread-read marks the
        // conversation read on connect.
        await mailRow(page, INBOX_SUBJECTS.star).click();

        // The row losing its unread state first is what makes the badge
        // assertion below a statement about the COUNTER: the write landed, the
        // stream redrew the row, and the only thing left that could be wrong
        // is the number beside it.
        await expect(mailRow(page, INBOX_SUBJECTS.star))
            .toHaveAttribute("data-unread", "false", { timeout: 5000 });

        await expect(page.locator(INBOX_BADGE).first()).toHaveText("3", { timeout: 5000 });
    });

    /**
     * The envelope button on the row, which is a different controller
     * (mail--message-row) posting to the same endpoint — and was equally
     * silent. Both directions, because a badge that simply stopped moving
     * would pass the read case alone.
     */
    test("the row's own mark-read button moves the badge, and back again", async ({ page }) => {
        // The row action strip is `hidden @xl:flex` — a container query on the
        // list pane — so it does not exist at all in a narrow window.
        await page.setViewportSize({ width: 1600, height: 900 });
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        expect(await inboxBadge(page)).toBe("4");

        // `invisible group-hover:visible`, hence the hover.
        await mailRow(page, INBOX_SUBJECTS.star).hover();
        await mailRow(page, INBOX_SUBJECTS.star)
            .getByRole("button", { name: "Mark as read" })
            .click();

        await expect(mailRow(page, INBOX_SUBJECTS.star))
            .toHaveAttribute("data-unread", "false", { timeout: 5000 });
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("3", { timeout: 5000 });

        // The same button, now the other way round.
        await mailRow(page, INBOX_SUBJECTS.star).hover();
        await mailRow(page, INBOX_SUBJECTS.star)
            .getByRole("button", { name: "Mark as unread" })
            .click();

        await expect(page.locator(INBOX_BADGE).first()).toHaveText("4", { timeout: 5000 });
    });

    /**
     * Re-opening mail that was ALREADY read must not fire a counts request.
     *
     * mail--thread-read marks read on every open, so the naive fix put a
     * request behind every click on a mail — including the commonest click
     * there is, which cannot change a single number. The sidebar's whole
     * rate-limiting apparatus exists because this endpoint was once being
     * asked thirty-two times in ten seconds; this keeps that honest.
     */
    test("re-opening an already-read conversation asks for nothing", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        // Every seeded thread starts unread, so this one has to be read first
        // — which is also the honest shape of the case: the second time you
        // open a mail is when nothing can have changed.
        await mailRow(page, INBOX_SUBJECTS.archive).click();
        await expect(mailRow(page, INBOX_SUBJECTS.archive))
            .toHaveAttribute("data-unread", "false", { timeout: 5000 });
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("3", { timeout: 5000 });

        // Counting starts only now, with the mail already read.
        let countsRequests = 0;
        page.on("request", (request) => {
            if (request.url().includes("/sidebar/counts")) {
                countsRequests++;
            }
        });

        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.archive).click();
        await page.waitForTimeout(2000);

        expect(countsRequests, "re-reading read mail moves no number").toBe(0);
    });

    /**
     * One write, one request — however many sidebars are listening.
     *
     * The sidebar partial is on the page twice, once for the mobile drawer and
     * once for the desktop column, so every write is announced to two
     * controller instances in the same tick. Both then asked for the counts
     * separately: the dedupe they shared keyed on "a request is on the wire",
     * and the second caller reached it by awaiting the first one's request —
     * which cleared that flag in its own `finally` on the way out. The re-check
     * was therefore looking for something that, by the only route that reaches
     * it, is guaranteed to be gone.
     *
     * So opening a mail — the commonest action in the app — asked the server
     * for the same numbers twice, every time. Reported as exactly that.
     *
     * Two is what this catches; the assertion is written as "exactly one"
     * rather than "at most one" so that dropping the refresh altogether fails
     * it too. The test above covers the other side, that a click which changes
     * nothing asks for nothing.
     */
    test("one write asks for the counts once, not once per sidebar", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        let countsRequests = 0;
        page.on("request", (request) => {
            if (request.url().includes("/sidebar/counts")) {
                countsRequests++;
            }
        });

        // Unread, so opening it is a real write: the row un-bolds and the
        // badge has to come down with it.
        await mailRow(page, INBOX_SUBJECTS.archive).click();
        await expect(mailRow(page, INBOX_SUBJECTS.archive))
            .toHaveAttribute("data-unread", "false", { timeout: 5000 });
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("3", { timeout: 5000 });

        // Long enough that a second request would have been made by now — the
        // duplicate went out immediately after the first one settled, not on a
        // timer.
        await page.waitForTimeout(2000);

        expect(countsRequests, "one write, one counts request").toBe(1);
    });
});
