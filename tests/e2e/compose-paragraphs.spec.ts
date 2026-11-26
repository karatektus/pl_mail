import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Paragraph spacing in the compose editor (#782).
 *
 * Enter inside a `<p>` makes a new `<p>`. Tailwind's preflight zeroed their
 * margin in the editor, so Enter looked like a single line break there, while
 * every mail client — our own reading pane included — draws a `<p>` with the
 * browser's 1em above and below. The editor has to show that same gap, or the
 * sender judges their message by a layout nobody receives.
 */

const DOCK = "#compose_inline";
const EDITOR = `${DOCK} [data-compose--compose-toolbar-target="editor"]`;

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

test("Enter starts a paragraph spaced the way the recipient sees it", async ({ page }) => {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible({ timeout: 10_000 });

    // The empty line the reply leaves above the quote.
    await page.locator(`${EDITOR} > p`).first().click();
    await page.keyboard.type("first line");
    await page.keyboard.press("Enter");
    await page.keyboard.type("second line");

    const second = page.locator(`${EDITOR} > p`, { hasText: "second line" });
    await expect(second).toHaveCount(1);

    const style = await second.evaluate(el => {
        const css = getComputedStyle(el);

        return {
            top: parseFloat(css.marginTop),
            bottom: parseFloat(css.marginBottom),
            fontSize: parseFloat(css.fontSize),
        };
    });

    // 1em, the margin a mail client's default stylesheet gives a <p>.
    expect(style.top).toBe(style.fontSize);
    expect(style.bottom).toBe(style.fontSize);
});
