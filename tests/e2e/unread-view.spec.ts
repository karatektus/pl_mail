import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The unread badge is a link, and its number is a promise about the list it
 * opens.
 *
 * Asked for as "a click on the unread badge should show a view from that label
 * with all the unread mails". The badge already knew the number; what it could
 * not do was take you to the mail it was counting.
 *
 * These assert the number and the rows AGAINST EACH OTHER rather than against
 * fixed values wherever they can, because the failure this guards against is
 * the two drifting apart — the reason the counts had to start counting
 * conversations instead of unread messages in the first place. A repository
 * test pins the same pairing at the query level (UnreadFilteredListsTest); this
 * pins that a person clicking the pill actually gets there.
 *
 * The desktop sidebar specifically. The partial is on the page twice — once for
 * the mobile drawer, once for the column — so an unscoped locator resolves to a
 * hidden element first and every click times out.
 */

const INBOX_BADGE = '#sidebar [data-count-key="role:inbox"]';
const ROWS = '#message-list li[data-controller="mail--message-row"]';

test.beforeEach(() => {
    seed("seed-mail");
});

test("clicking the inbox badge lists exactly the conversations it counted", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

    const badge = page.locator(INBOX_BADGE);

    // Read one, so the unread number and the folder's total differ — otherwise
    // "it filtered" and "it did nothing" produce the same list.
    await mailRow(page, INBOX_SUBJECTS.star).click();
    await expect(mailRow(page, INBOX_SUBJECTS.star))
        .toHaveAttribute("data-unread", "false", { timeout: 5000 });
    await expect(badge).toHaveText("3", { timeout: 5000 });

    const promised = Number((await badge.textContent())?.trim());

    await badge.click();
    await expect(page).toHaveURL(/unread=1/);

    await expect(page.locator(ROWS)).toHaveCount(promised);
    await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);
});

test("the filtered view says so, and the way back out restores the rest", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

    await mailRow(page, INBOX_SUBJECTS.star).click();
    await expect(mailRow(page, INBOX_SUBJECTS.star))
        .toHaveAttribute("data-unread", "false", { timeout: 5000 });

    await page.locator(INBOX_BADGE).click();
    await expect(page.locator(ROWS)).toHaveCount(3);

    // A filtered list and a genuinely quiet one look identical without this.
    await expect(page.getByText("Unread only")).toBeVisible();

    await page.getByRole("link", { name: "Show all" }).click();

    await expect(page).not.toHaveURL(/unread=1/);
    await expect(page.locator(ROWS)).toHaveCount(4);
    await expect(mailRow(page, INBOX_SUBJECTS.star)).toBeVisible();
});

/**
 * Trash and Drafts wear a TOTAL, not an unread count — see
 * SidebarCounts::TOTAL_ROLES. Clicking one to "see the unread" would be asking
 * a question that badge is not answering, so it stays a plain span.
 */
test("a total badge is not a link", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

    const trash = page.locator('#sidebar [data-count-key="role:trash"]');

    await expect(trash).toHaveAttribute("data-badge-kind", "total");
    expect(await trash.evaluate((el) => el.tagName)).toBe("SPAN");

    await expect(page.locator(INBOX_BADGE)).toHaveAttribute("data-badge-kind", "unread");
    expect(await page.locator(INBOX_BADGE).evaluate((el) => el.tagName)).toBe("A");
});

/**
 * The badge is a real link, not a span that answers a mouse. Keyboard and
 * middle-click both depend on that, and a span with a click handler would have
 * passed every other test in this file.
 */
test("the badge can be reached and followed from the keyboard", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

    const badge = page.locator(INBOX_BADGE);

    await badge.focus();
    await expect(badge).toBeFocused();

    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/unread=1/);
});
