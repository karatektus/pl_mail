import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * A sent mail is spaced the way it was written.
 *
 * The editor makes a paragraph of every line and, under the app's base
 * styles, draws it with no margin. A mail program draws a bare `<p>` with a
 * line of space above and below, so every Enter arrived as a blank line and
 * every blank line as two, in the recipient's client and in plMail's own
 * reading pane, while the mail looked right as it was written. The editor's
 * clean-up now writes `margin: 0` on each of the writer's paragraphs, and
 * MailBodySanitizer does the same on the server.
 *
 * The spacing is measured where nothing but a mail program's defaults apply:
 * the submitted markup alone, in a page of its own.
 */

const DOCK = "#compose_dock";
const EDITOR = `${DOCK} [data-compose--compose-toolbar-target="editor"]`;
const HIDDEN = `${DOCK} [data-compose--compose-toolbar-target="hiddenInput"]`;
const LINE = 20;

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

/**
 * A new message, opened as one is for somebody with a signature: an empty
 * paragraph to write in above it (ReplyDraftBuilder, SignatureProvider), with
 * the caret in it. That paragraph is what makes every Enter a paragraph; an
 * empty editor makes the browser's own <div> lines instead.
 */
async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(EDITOR)).toBeVisible();

    await page.locator(EDITOR).evaluate((editor) => {
        const paragraph = document.createElement("p");
        const signature = document.createElement("div");

        paragraph.append(document.createElement("br"));
        signature.setAttribute("data-pl-signature", "1");
        signature.textContent = "Best regards";
        editor.replaceChildren(paragraph, signature);
        (editor as HTMLElement).focus();
        window.getSelection()!.collapse(paragraph, 0);
    });
}

/** The distance from the top of one line to the top of another, in a page with a mail program's defaults. */
async function distanceInAMailProgram(page: Page, html: string, from: string, to: string): Promise<number> {
    const reader = await page.context().newPage();

    await reader.setContent(`<!DOCTYPE html><html><body style="font: 16px/${LINE}px sans-serif">${html}</body></html>`);

    const distance = await reader.evaluate(([first, last]) => {
        const top = (text: string) => {
            const range = document.createRange();
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);

            for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
                if (node.textContent?.trim() === text) {
                    range.selectNodeContents(node);

                    return range.getBoundingClientRect().top;
                }
            }

            throw new Error(`no line "${text}"`);
        };

        return top(last) - top(first);
    }, [from, to]);

    await reader.close();

    return distance;
}

test("lines and a blank line between them arrive spaced as they were written", async ({ page }) => {
    await openCompose(page);

    await page.keyboard.type("Hello Pavel,");
    await page.keyboard.press("Enter");
    await page.keyboard.press("Enter");
    await page.keyboard.type("The panoramas.");

    // Two lines down: one blank line between them, as typed. Before the fix it
    // was two lines and two margins of a line's height each.
    const html = await page.locator(HIDDEN).inputValue();
    expect(html, "the lines are paragraphs, as they are for the writer").toContain("<p");
    const distance = await distanceInAMailProgram(page, html, "Hello Pavel,", "The panoramas.");

    expect(distance, html).toBeLessThanOrEqual(2 * LINE + 1);
    expect(distance, html).toBeGreaterThanOrEqual(2 * LINE - 1);
});

/** The quote under a reply is the sender's mail: its paragraphs keep their own spacing. */
test("a paragraph in the quoted original is left as the sender made it", async ({ page }) => {
    await openCompose(page);

    await page.keyboard.type("Passt!");

    // A quote as a reply carries it, after the writer's line.
    await page.locator(EDITOR).evaluate((editor) => {
        const quote = document.createElement("div");
        const paragraph = document.createElement("p");

        quote.setAttribute("data-quoted", "1");
        paragraph.textContent = "The original.";
        quote.append(paragraph);
        editor.append(quote);
        editor.dispatchEvent(new Event("input", { bubbles: true }));
    });

    const html = await page.locator(HIDDEN).inputValue();

    expect(html, html).toContain("<p>The original.</p>");
    expect(html, html).toMatch(/<p style="margin: 0px;">[^<]*Passt!/);
});
