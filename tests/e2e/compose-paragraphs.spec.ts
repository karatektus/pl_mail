import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Lines and paragraphs in the compose editor (#782, #807).
 *
 * Enter makes a new line: a `<div>`, with no margin in the editor or in any
 * mail client, so one Enter is one line on both ends. It used to make a `<p>`,
 * which every mail client draws with 1em above and below — first a gap only
 * the recipient saw (#782), then, once the editor showed it honestly, a gap
 * people read as Enter inserting two lines (#807).
 *
 * A `<p>` that IS a paragraph (pasted, from a template or signature) still
 * carries the 1em the recipient will see.
 */

const DOCK = "#compose_inline";
const EDITOR = `${DOCK} [data-compose--compose-toolbar-target="editor"]`;

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

test("Enter starts a line with no gap above it", async ({ page }) => {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible({ timeout: 10_000 });

    // The empty line the reply leaves above the quote.
    await page.locator(`${EDITOR} > :first-child`).click();
    await page.keyboard.type("first line");
    await page.keyboard.press("Enter");
    await page.keyboard.type("second line");

    const second = page.locator(`${EDITOR} > div`, { hasText: "second line" });
    await expect(second, "Enter made something other than a <div> line").toHaveCount(1);
    await expect(page.locator(`${EDITOR} p`, { hasText: /first line|second line/ })).toHaveCount(0);

    // Directly under the first line: the gap is the line height, nothing more.
    const spacing = await second.evaluate((line) => {
        const above = line.previousElementSibling!;

        return {
            above: above.textContent,
            gap: line.getBoundingClientRect().top - above.getBoundingClientRect().bottom,
            margin: parseFloat(getComputedStyle(line).marginTop),
        };
    });

    expect(spacing.above).toBe("first line");
    expect(spacing.margin).toBe(0);
    expect(spacing.gap).toBeLessThan(1);
});

test("a real paragraph keeps the spacing the recipient sees", async ({ page }) => {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible({ timeout: 10_000 });

    const margin = await page.locator(EDITOR).evaluate((editor) => {
        const paragraph = document.createElement("p");
        paragraph.textContent = "a paragraph";
        editor.prepend(paragraph);

        const css = getComputedStyle(paragraph);

        return { top: parseFloat(css.marginTop), bottom: parseFloat(css.marginBottom), fontSize: parseFloat(css.fontSize) };
    });

    // 1em, the margin a mail client's default stylesheet gives a <p>.
    expect(margin.top).toBe(margin.fontSize);
    expect(margin.bottom).toBe(margin.fontSize);
});
