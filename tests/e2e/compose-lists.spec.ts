import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Lists in the compose editor, in a reply.
 *
 * Two things went wrong at once and each hid the other. Tailwind's preflight
 * removes list markers and padding, so a list made with the toolbar was in the
 * markup but drawn as plain lines; and the list was appended to the end of the
 * editor, which in a reply is below the quoted message, far from the caret.
 * compose-formatting.spec.ts only reads the submitted markup, which was right
 * all along, so neither showed there.
 */

// A reply opens inline under the message, not in the dock.
const DOCK = "#compose_inline";
const EDITOR = `${DOCK} [data-compose--compose-toolbar-target="editor"]`;

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

async function openReply(page: import("@playwright/test").Page): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible({ timeout: 10_000 });
    // The quote is collapsed behind "show quoted text" but is in the editor.
    await expect(page.locator(`${EDITOR} [data-quoted]`)).toBeAttached();
}

test.describe("compose lists in a reply", () => {
    test("a bullet list is drawn with bullets and sits above the quote", async ({ page }) => {
        await openReply(page);

        // The empty line the reply leaves above the quote.
        await page.locator(`${EDITOR} > p`).first().click();
        await page.locator(`${DOCK} button[title="Bullet list"]`).click();
        await page.keyboard.type("first point");

        const list = page.locator(`${EDITOR} > ul`);
        await expect(list).toHaveCount(1);
        await expect(list.locator("li")).toHaveText("first point");

        expect(await list.evaluate(el => getComputedStyle(el).listStyleType)).toBe("disc");
        expect(await list.evaluate(el => parseFloat(getComputedStyle(el).paddingLeft))).toBeGreaterThan(0);

        // Before the quote in document order, not after it.
        const order = await page.locator(EDITOR).evaluate(editor => {
            const ul = editor.querySelector(":scope > ul")!;
            const quote = editor.querySelector("[data-quoted]")!;

            return ul.compareDocumentPosition(quote) & Node.DOCUMENT_POSITION_FOLLOWING;
        });
        expect(order, "the list is below the quoted message").toBeTruthy();
    });

    test("a numbered list is drawn with numbers", async ({ page }) => {
        await openReply(page);

        await page.locator(`${EDITOR} > p`).first().click();
        await page.locator(`${DOCK} button[title="Numbered list"]`).click();
        await page.keyboard.type("step one");

        const list = page.locator(`${EDITOR} > ol`);
        await expect(list).toHaveCount(1);
        expect(await list.evaluate(el => getComputedStyle(el).listStyleType)).toBe("decimal");
    });
});
