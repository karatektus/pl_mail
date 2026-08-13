import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

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
 */

const INBOX_BADGE = '[data-count-key="role:inbox"]';

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
     * the list frame — the category tabs with their own unread numbers, the
     * "1–4 of 4" range — is addressed by nothing, so it kept whatever the last
     * full render had said. The frame is re-read after a write now
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
