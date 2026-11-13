import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";
import { resetListSettings, saveListSettings } from "./support/message-list";

/**
 * Settings → Inbox → Message list (#608): what a row in the list shows.
 *
 * Two things are claimed. The page's preview is the list: its rows are the
 * row template itself, so a choice that changes the preview changes the list,
 * and the tests below read the preview for the parts the seeded inbox cannot
 * show (a conversation of three, label chips, files). And a choice is the
 * user's, not the tab's: it survives a reload and reaches a context that has
 * never seen this browser.
 *
 * The account corner, sender discs, tab counts and unread emphasis came here
 * from the Appearance page with their tests.
 *
 * Everything restores the defaults on the way out — through the endpoint, so a
 * failed test cannot leave the shared user's list in a shape another spec
 * never asked about.
 */

const PANEL = '[data-controller="settings--message-list"]';
const PREVIEW = '[data-testid="list-preview"]';

/** The value the page is painted with, not the one in the form. */
const cssVar = (page: Page, name: string) =>
    page.evaluate(
        (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(),
        name,
    );

const open = async (page: Page) => {
    await page.goto("/settings/message-list");
    await expect(page.locator(PREVIEW).locator("li").first()).toBeVisible();
};

/**
 * Picks an option by its LABEL — the radios are `peer sr-only`, 1px boxes a
 * forced click can land on without changing — and waits for the save, armed
 * before the click so a fast answer cannot be missed.
 */
const pick = async (page: Page, name: string, value: string) => {
    const radio = page.locator(`${PANEL} input[name="${name}"][value="${value}"]`).first();

    if (true === (await radio.isChecked())) {
        return;
    }

    const saved = page.waitForResponse(
        (r) => r.url().includes("/settings/message-list/save") && r.request().method() === "POST",
    );

    await page.locator(`${PANEL} label:has(input[name="${name}"][value="${value}"])`).first().click();
    await expect(radio).toBeChecked();
    expect((await saved).ok()).toBe(true);
};

/** The preview's list, at one of its three widths (in rem). */
const previewAt = async (page: Page, width: "phone" | "narrow" | "wide") => {
    const rem = { phone: 22, narrow: 42, wide: 80 }[width];

    await page.locator(`label:has(input[name="previewWidth"][value="${rem}"])`).click();

    return page.locator(PREVIEW).locator("ul");
};

/** A preview row by the subject the sample gives it in English. */
const sample = (page: Page, subject: string) =>
    page.locator(PREVIEW).locator("li").filter({ hasText: subject });

test.describe("settings — message list", () => {
    test.afterEach(async ({ page }) => {
        await resetListSettings(page);
    });

    test("is a page under Inbox, with four sample rows", async ({ page }) => {
        await open(page);

        await expect(page.locator(PREVIEW).locator("li")).toHaveCount(4);
        await expect(page.getByRole("heading", { name: "Layout", level: 2 })).toBeVisible();
        await expect(page.getByRole("heading", { name: "Content", level: 2 })).toBeVisible();
        await expect(page.getByRole("heading", { name: "Markers", level: 2 })).toBeVisible();

        // Inert: made-up mail must not be openable.
        expect(await page.locator(PREVIEW).locator("ul").getAttribute("inert")).not.toBeNull();
    });

    test("a choice is saved on the user and comes back after a reload", async ({ page }) => {
        await open(page);
        const html = page.locator("html");

        await pick(page, "preview", "two");
        await expect(html).toHaveAttribute("data-list-preview", "two");

        await page.reload();
        await expect(html).toHaveAttribute("data-list-preview", "two");
        await expect(page.locator(`${PANEL} input[name="preview"][value="two"]`)).toBeChecked();
    });

    /**
     * Per user and synced, not per browser. A brand-new context has nothing
     * of this tab's, so the only way the choice reaches it is off the user.
     */
    test("the account corner is off for the user, not the browser", async ({ page, browser }) => {
        await open(page);
        expect(await cssVar(page, "--list-corner-display")).toBe("block");

        await pick(page, "accountCorner", "0");
        await expect.poll(() => cssVar(page, "--list-corner-display")).toBe("none");

        const second = await browser.newContext({ storageState: await page.context().storageState() });

        try {
            const other = await second.newPage();
            await other.goto(new URL("/mail/inbox", page.url()).toString());

            expect(await cssVar(other, "--list-corner-display")).toBe("none");
        } finally {
            await second.close();
        }
    });

    test("no preview hides it, inline puts it on the subject's line", async ({ page }) => {
        await open(page);
        const list = await previewAt(page, "phone");
        const row = sample(page, "Your weekly digest");

        await pick(page, "preview", "none");
        await expect(row.locator("[data-row-snippet]")).toBeHidden();

        await pick(page, "preview", "inline");
        const subject = (await row.locator("[data-row-subject]").boundingBox())!;
        const snippet = (await row.locator("[data-row-snippet]").boundingBox())!;

        // Presence companion: the list really is a phone's width, where the
        // other previews put the snippet on a line of its own.
        expect((await list.boundingBox())!.width).toBeLessThan(36 * 16);
        expect(snippet.x).toBeGreaterThanOrEqual(subject.x + subject.width - 2);
        expect(Math.abs(snippet.y - subject.y)).toBeLessThan(8);

        await pick(page, "preview", "one");
        const below = (await row.locator("[data-row-snippet]").boundingBox())!;
        expect(below.y).toBeGreaterThan(subject.y + subject.height - 2);
    });

    test("the layout decides whether a wide list is one line", async ({ page }) => {
        await open(page);
        await previewAt(page, "wide");
        const row = sample(page, "Your weekly digest");

        const sameLine = async () => {
            const subject = (await row.locator("[data-row-subject]").boundingBox())!;
            const snippet = (await row.locator("[data-row-snippet]").boundingBox())!;

            return Math.abs(snippet.y - subject.y) < 8;
        };

        await pick(page, "layout", "fit");
        expect(await sameLine()).toBe(true);

        await pick(page, "layout", "stacked");
        expect(await sameLine()).toBe(false);
    });

    test("attachments show as chips, a paperclip, or nothing", async ({ page }) => {
        await open(page);
        const row = sample(page, "Flights for the conference");

        await expect(row.locator("[data-row-attachments]")).toBeHidden();
        await expect(row.locator("[data-row-clip]:visible")).toHaveCount(1);

        await pick(page, "attachments", "chips");
        await expect(row.locator("[data-row-attachments]")).toBeVisible();
        await expect(row.locator("[data-row-attachments]")).toContainText("itinerary.pdf");
        await expect(row.locator("[data-row-clip]:visible")).toHaveCount(0);

        await pick(page, "attachments", "hidden");
        await expect(row.locator("[data-row-attachments]")).toBeHidden();
        await expect(row.locator("[data-row-clip]:visible")).toHaveCount(0);
    });

    /**
     * Chips need the files' names, which a list page reads only for somebody
     * who shows chips (ThreadRows). This is the real inbox, not the preview.
     */
    test("chips in the inbox name the file and open it", async ({ page }) => {
        seed("seed-attachment");
        await saveListSettings(page, { attachments: "chips" });

        await page.goto("/mail/inbox");
        const chip = page.locator("#message-list [data-row-attachments]").getByText("e2e-attachment.txt");

        await expect(chip).toBeVisible();

        // Over the row's own link: a click is the file's, not the conversation's.
        const target = await chip.evaluate((element) => {
            const box = element.getBoundingClientRect();

            return document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)?.closest("a, button")?.tagName ?? null;
        });
        expect(["A", "BUTTON"]).toContain(target);
    });

    test("labels go before the subject, as dots, or on their own", async ({ page }) => {
        await open(page);
        const row = sample(page, "Flights for the conference");

        await expect(row.locator('[data-row-labels="inline"]')).toBeHidden();

        await pick(page, "labels", "inline");
        const chip = row.locator('[data-row-labels="inline"] [data-label-chip]').first();
        await expect(chip).toBeVisible();
        await expect(chip).toContainText("Travel");

        await pick(page, "labels", "dots");
        const dot = (await chip.boundingBox())!;
        expect(dot.width).toBeLessThanOrEqual(9);
        expect(dot.height).toBeLessThanOrEqual(9);

        await pick(page, "labels", "hidden");
        await expect(row.locator("[data-label-chip]:visible")).toHaveCount(0);
    });

    test("sender, date, count and replied arrow follow their choices", async ({ page }) => {
        await open(page);
        const flights = sample(page, "Flights for the conference");
        const contract = sample(page, "Contract draft");

        // Everyone who wrote, then the newest alone.
        await expect(flights.locator('[data-row-sender-name="everyone"]')).toContainText("Anna Berger");
        await pick(page, "sender", "latest");
        await expect(flights.locator('[data-row-sender-name="latest"]')).toBeVisible();
        await expect(flights.locator('[data-row-sender-name="latest"]')).toHaveText("Ben Ortiz");
        await expect(flights.locator('[data-row-sender-name="everyone"]')).toBeHidden();

        await pick(page, "date", "full");
        await expect(contract.locator('[data-row-date="full"]')).toBeVisible();
        await expect(contract.locator('[data-row-date="smart"]')).toBeHidden();

        await expect(flights.locator("[data-row-count]")).toBeVisible();
        await pick(page, "messageCount", "0");
        await expect(flights.locator("[data-row-count]")).toBeHidden();

        await expect(contract.locator("[data-row-replied]")).toBeHidden();
        await pick(page, "replied", "1");
        await expect(contract.locator("[data-row-replied]")).toBeVisible();
        // Presence companion: an unanswered conversation has no arrow to show.
        await expect(sample(page, "Your weekly digest").locator("[data-row-replied]")).toHaveCount(0);
    });

    test("strong unread emphasis paints a bar and subtle paints nothing", async ({ page }) => {
        await open(page);

        await pick(page, "unreadEmphasis", "strong");
        await expect.poll(() => cssVar(page, "--unread-bar-w")).toBe("3px");
        expect(await cssVar(page, "--unread-emphasis")).toBe("1.6");

        await pick(page, "unreadEmphasis", "subtle");
        await expect.poll(() => cssVar(page, "--unread-emphasis")).toBe("0");
        expect(await cssVar(page, "--unread-bar-w")).toBe("0px");
    });

    /**
     * Switching the discs off must not take the row's checkbox with them — the
     * disc IS the checkbox, and a list you cannot select from is a worse bug
     * than a disc you did not want.
     */
    test("sender discs off leaves the row still selectable", async ({ page }) => {
        await saveListSettings(page, { avatars: false });

        await page.goto("/mail/inbox");

        const row = page.locator("#message-list li").first();
        await expect(row).toBeVisible();
        expect(await cssVar(page, "--list-avatar-hide")).toBe("1");

        const box = await row.locator("[data-row-avatar-face]").boundingBox();

        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThanOrEqual(30);
        expect(box!.height).toBeGreaterThanOrEqual(30);

        await settled(page);
        await row.locator("[data-thread-select]").check({ force: true });
        await expect(row.locator("[data-thread-select]")).toBeChecked();
        await row.locator("[data-thread-select]").uncheck({ force: true });
    });

    test("the unread count on the inbox tabs is set on the Categories page", async ({ page }) => {
        // The strip only renders when more than one category holds mail, and
        // the seeded mailbox is all Primary; one thread goes to Social, and the
        // seed puts it back for whoever runs next.
        seed("seed-mail");
        await page.goto("/mail/inbox");

        const id = Number((await mailRow(page, INBOX_SUBJECTS.star).getAttribute("id"))?.replace("thread_", ""));

        expect((await ajaxPost(page, "/status/bulk/category", { ids: [id], category: "social" })).ok()).toBe(true);

        await page.goto("/settings/categories");
        await expect(page.locator("#card-tab-counts")).toBeVisible();
        await pick(page, "tabUnreadCount", "0");

        await page.goto("/mail/inbox");
        const count = page.locator("nav [data-tab-unread]:not(.hidden)").first();

        // Hidden, and still in the page, so it is right the moment it is back.
        await expect(count).toBeAttached();
        await expect(count).toBeHidden();
        await expect(page.locator("nav > a[href*='tab=social']")).toBeVisible();

        seed("seed-mail");
    });
});
