import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Pasted markup arrives as content, not as somebody else's stylesheet.
 *
 * CODE-REVIEW.md U-02. The composer handled pasted IMAGES and let everything
 * else through untouched, so three sentences out of Word or a web page brought
 * that document's fonts, background colours, nested tables and class names into
 * the body — and, since this is what goes on the wire, the recipient saw all of
 * it. The `<style>` block was the worst of them: it restyled the whole composer
 * rather than only the text that had just been pasted.
 *
 * The assertions are two-sided on purpose. Flattening to plain text would pass
 * every "no styling survived" check and would also throw away the thing people
 * are usually pasting for, so the structure has to be shown surviving in the
 * same test that shows the presentation being dropped.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

/** Paste real clipboard HTML, the way a browser delivers it. */
async function pasteHtml(page: import("@playwright/test").Page, html: string): Promise<void> {
    await page.locator(EDITOR).first().click();

    await page.evaluate((markup) => {
        const editor = document.querySelector(
            '[data-compose--compose-toolbar-target="editor"]',
        ) as HTMLElement;

        const data = new DataTransfer();
        data.setData("text/html", markup);
        data.setData("text/plain", "fallback");

        editor.dispatchEvent(new ClipboardEvent("paste", {
            clipboardData: data,
            bubbles: true,
            cancelable: true,
        }));
    }, html);
}

test.describe("pasting into the composer", () => {
    test("drops the presentation and keeps the content", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(".compose-window").first()).toBeVisible();

        await pasteHtml(
            page,
            '<style>body { background: #ff0000 }</style>'
            + '<div class="WordSection1" style="font-family:Calibri;background:#ffff00" bgcolor="#ffff00" width="600">'
            + '<p style="font-size:48px"><b>Angebot</b> für <a href="https://example.test/x">das Projekt</a></p>'
            + '<ul><li>Erster Punkt</li><li>Zweiter Punkt</li></ul>'
            + '</div>',
        );

        const body = await page.locator(EDITOR).first().innerHTML();

        // The presentation is gone — including the stylesheet, which would
        // otherwise have restyled the composer itself.
        expect(body).not.toContain("<style");
        expect(body).not.toContain("background");
        expect(body).not.toContain("font-family");
        expect(body).not.toContain("WordSection1");
        expect(body).not.toContain("bgcolor");
        expect(body).not.toContain("48px");

        // The content is not.
        expect(body).toContain("Angebot");
        expect(body).toContain("<b>");
        expect(body).toContain("<ul>");
        expect(body).toContain("Zweiter Punkt");
        expect(body).toContain("https://example.test/x");
    });

    /**
     * The editor is still an editor afterwards.
     *
     * A paste handler that replaces the body instead of inserting into it is an
     * easy mistake and a destructive one — it would take out the signature and
     * the quoted original along with the caret position.
     */
    test("inserts into the body rather than replacing it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(".compose-window").first()).toBeVisible();

        const editor = page.locator(EDITOR).first();
        await editor.click();
        await page.keyboard.type("Vorher");

        await pasteHtml(page, "<p>Eingefügt</p>");

        const text = await editor.innerText();
        expect(text).toContain("Vorher");
        expect(text).toContain("Eingefügt");
    });
});
