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
 * phone already gets, so one line now starts at 58rem (`--container-rowwide`
 * in app.css; the `@rowwide:` variant in _thread_row.html.twig).
 *
 * Measured on the LIST's width, not the viewport's: the row is a container
 * query on #message-list. The viewports below are chosen so the list lands on
 * each side of the line (sidebar and gutters take ~250px).
 */

const SUBJECT = INBOX_SUBJECTS.star;

const geometry = async (page: Page) => {
    const row = mailRow(page, SUBJECT);
    const subject = row.locator("span.truncate", { hasText: SUBJECT }).first();
    const snippet = row.locator("[data-row-snippet]");

    return {
        list: (await page.locator("#message-list").boundingBox())!,
        row: (await row.boundingBox())!,
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

    test("a list in the middle band is stacked and gives the subject the width", async ({ page }) => {
        // List ≈ 850px: wider than the old 36rem line, narrower than 58rem.
        await openInbox(page, 1100);
        const g = await geometry(page);

        // Presence companion: the list really is in the band under test.
        expect(g.list.width).toBeGreaterThan(36 * 16);
        expect(g.list.width).toBeLessThan(58 * 16);

        // Stacked: the preview sits BELOW the subject, not beside it …
        expect(g.snippet.y).toBeGreaterThan(g.subject.y + g.subject.height - 2);
        // … and the subject is not squeezed into a corner of the row.
        expect(g.subject.width).toBeGreaterThan(g.row.width * 0.5);
    });

    test("a wide list keeps subject and preview on one line", async ({ page }) => {
        // List ≈ 1250px.
        await openInbox(page, 1500);
        const g = await geometry(page);

        expect(g.list.width).toBeGreaterThan(58 * 16);
        // Same line: the preview starts to the right of the subject's end and
        // within a line's height of its top.
        expect(g.snippet.x).toBeGreaterThanOrEqual(g.subject.x + g.subject.width - 2);
        expect(Math.abs(g.snippet.y - g.subject.y)).toBeLessThan(8);
    });

    test("the hover actions are reachable in the stacked band", async ({ page }) => {
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
        await openInbox(page, 1500);

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
