import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Where a list row stops being three stacked lines and becomes one.
 *
 * The row used to go to one line at Tailwind's stock 36rem, which is a list
 * about 575px wide: with the reading pane open, or on a big phone, the avatar,
 * the sender column and the date column took ~450px of it and the subject was
 * left ~120px — "Facture pour Co…" — with a preview wrapped into two cramped
 * lines beside it. The stacked layout gives both the full width and is the one a
 * phone already gets, so one line now starts at 75rem (`--container-rowwide`
 * in app.css; the `@rowwide:` variant in _thread_row.html.twig). 58rem was the
 * first value and was still too early: a 2560px window with the reading pane
 * open gives a list of ~65rem, where real subjects were cut after ~30 chars.
 *
 * Under 75rem the row is not one shape either: three lines below 36rem, and
 * from there up two — sender and subject sharing the first line, the preview on
 * the second. "Stacked" in the tests below means the property both of those
 * have and the one-line row does not: the preview is under the subject.
 *
 * Measured on the LIST's width, not the viewport's: the row is a container
 * query on #message-list. The viewports below are chosen so the list lands on
 * each side of the line (sidebar and gutters take ~250px).
 */

const SUBJECT = INBOX_SUBJECTS.star;

const geometry = async (page: Page) => {
    const row = mailRow(page, SUBJECT);
    // The row's own hooks, the same family as `data-row-snippet`, which the
    // preview-lines setting already hangs on. None of the three is anything a
    // role or a name could find — they are spans of text in one list item —
    // and a utility class like `truncate` says how a thing is styled, not
    // what it is.
    const sender = row.locator("[data-row-sender]");
    const subject = row.locator("[data-row-subject]");
    const snippet = row.locator("[data-row-snippet]");

    return {
        list: (await page.locator("#message-list").boundingBox())!,
        row: (await row.boundingBox())!,
        sender: (await sender.boundingBox())!,
        subject: (await subject.boundingBox())!,
        snippet: (await snippet.boundingBox())!,
    };
};

const openInbox = async (page: Page, width: number) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("/mail/inbox");
    await expect(mailRow(page, SUBJECT)).toBeVisible();
};

test.describe("list row layout around the one-line breakpoint", () => {
    test.beforeAll(() => {
        seed("seed-mail");
    });

    test("a list in the middle band keeps the preview under the subject and gives the subject the width", async ({ page }) => {
        // List ≈ 850px: wider than the old 36rem line, narrower than 75rem.
        await openInbox(page, 1100);
        const g = await geometry(page);

        // Presence companion: the list really is in the band under test.
        expect(g.list.width).toBeGreaterThan(36 * 16);
        expect(g.list.width).toBeLessThan(75 * 16);

        // Stacked: the preview sits BELOW the subject, not beside it …
        expect(g.snippet.y).toBeGreaterThan(g.subject.y + g.subject.height - 2);
        // … and the subject is not squeezed into a corner of the row.
        expect(g.subject.width).toBeGreaterThan(g.row.width * 0.5);
    });

    test("between 36rem and 75rem the sender shares the first line with the subject", async ({ page }) => {
        // The in-between shape: two lines, not three. Sender and subject on the
        // first, the preview alone on the second and starting back under the
        // sender, so it has the row's whole width.
        await openInbox(page, 1100);
        const g = await geometry(page);

        expect(g.list.width).toBeGreaterThan(36 * 16);
        expect(g.list.width).toBeLessThan(75 * 16);

        expect(Math.abs(g.sender.y - g.subject.y)).toBeLessThan(4);
        expect(g.subject.x).toBeGreaterThanOrEqual(g.sender.x + g.sender.width - 2);
        expect(g.snippet.y).toBeGreaterThan(g.subject.y + g.subject.height - 2);
        expect(g.snippet.x).toBeLessThan(g.subject.x);
    });

    test("a list under 36rem keeps all three lines", async ({ page }) => {
        // A phone. Presence companion for the test above: the two-line shape is
        // a band, not simply what every list under 75rem now gets.
        await openInbox(page, 480);
        const g = await geometry(page);

        expect(g.list.width).toBeLessThan(36 * 16);

        expect(g.subject.y).toBeGreaterThan(g.sender.y + g.sender.height - 2);
        expect(g.snippet.y).toBeGreaterThan(g.subject.y + g.subject.height - 2);
    });

    test("a list past the first 58rem guess still keeps the preview under the subject", async ({ page }) => {
        // List ≈ 1050px (~65rem): the width a 2560px window with the reading
        // pane open gives. One line here cut real subjects after ~30
        // characters, which is why 58rem was raised to 75rem.
        await openInbox(page, 1300);
        const g = await geometry(page);

        // Presence companion: the list is above the OLD line, so a regression
        // to 58rem is what this fails on.
        expect(g.list.width).toBeGreaterThan(58 * 16);
        expect(g.list.width).toBeLessThan(75 * 16);

        expect(g.snippet.y).toBeGreaterThan(g.subject.y + g.subject.height - 2);
    });

    test("a wide list keeps subject and preview on one line", async ({ page }) => {
        // List ≈ 1350px.
        await openInbox(page, 1600);
        const g = await geometry(page);

        expect(g.list.width).toBeGreaterThan(75 * 16);
        // Same line: the preview starts to the right of the subject's end and
        // within a line's height of its top.
        expect(g.snippet.x).toBeGreaterThanOrEqual(g.subject.x + g.subject.width - 2);
        expect(Math.abs(g.snippet.y - g.subject.y)).toBeLessThan(8);
    });

    test("the hover actions are reachable below the one-line breakpoint", async ({ page }) => {
        // They were `hidden @xl:flex`, so moving the breakpoint without moving
        // them would have taken archive, delete and snooze away from every list
        // that is now stacked on a pointer device.
        await openInbox(page, 1100);
        const row = mailRow(page, SUBJECT);

        await row.hover();
        await expect(row.getByRole("button", { name: "Archive" })).toBeVisible();
        await expect(row.getByRole("button", { name: "Delete" })).toBeVisible();
    });

    test("the two-line preview setting stays out of the one-line layout", async ({ page }) => {
        await openInbox(page, 1600);

        // What AppearanceRenderer writes on <html> for previewLines = 2, set
        // directly so the spec does not depend on the saved setting.
        await page.evaluate(() => {
            const root = document.documentElement.style;
            root.setProperty("--list-preview-display", "-webkit-box");
            root.setProperty("--list-preview-lines", "2");
            root.setProperty("--list-preview-wrap", "normal");
        });

        const snippet = mailRow(page, SUBJECT).locator("[data-row-snippet]");
        await expect(snippet).toHaveCSS("white-space", "nowrap");
        await expect(snippet).toHaveCSS("-webkit-line-clamp", "1");

        // Presence companion: the same setting DOES take effect when stacked,
        // so the assertion above is not true of an element the setting never
        // reached.
        await page.setViewportSize({ width: 1100, height: 900 });
        await expect(snippet).toHaveCSS("white-space", "normal");
        await expect(snippet).toHaveCSS("-webkit-line-clamp", "2");
    });
});
