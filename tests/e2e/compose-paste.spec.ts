import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Pasting into the composer keeps what was copied, and not the page it came
 * from (CODE-REVIEW.md U-02, #807).
 *
 * The first version dropped every style and class and kept the tags. Most apps
 * do not say "bold" with a tag, so Google Docs pastes came out all bold with
 * their italics gone, Pages and TextEdit lost their formatting entirely, and
 * every line out of Docs, Word or Pages got a paragraph's gap around it. Now
 * the formatting is read off the pasted document — inline styles and its own
 * stylesheet — and the source page's layout is what goes.
 *
 * The clipboards below are cut-down copies of what those apps really put
 * there. The assertions stay two-sided: what must survive, and what must not.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

/** A 1×1 PNG: the picture of the selection some apps add beside the HTML. */
const PNG_BASE64 =
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==";

async function openCompose(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(".compose-window").first()).toBeVisible();

    const editor = page.locator(EDITOR).first();
    await editor.click();

    return editor;
}

/** Paste a clipboard the way a browser delivers it, optionally with a picture. */
async function paste(page: Page, html: string | null, withPicture = false): Promise<void> {
    await page.evaluate(
        ([markup, picture, base64]) => {
            const editor = document.querySelector(
                '[data-compose--compose-toolbar-target="editor"]',
            ) as HTMLElement;

            const data = new DataTransfer();

            if (null !== markup) {
                data.setData("text/html", markup as string);
                data.setData("text/plain", "fallback");
            }

            if (picture) {
                const bytes = Uint8Array.from(atob(base64 as string), (c) => c.charCodeAt(0));
                data.items.add(new File([bytes], "selection.png", { type: "image/png" }));
            }

            editor.dispatchEvent(new ClipboardEvent("paste", {
                clipboardData: data,
                bubbles: true,
                cancelable: true,
            }));
        },
        [html, withPicture, PNG_BASE64] as const,
    );
}

test.describe("pasting into the composer", () => {
    test("keeps the formatting and drops the source page", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(
            page,
            '<style>body { background: #ff0000 }</style>'
            + '<div class="WordSection1 hidden" style="width:600px;position:absolute;line-height:3" bgcolor="#ffff00" width="600">'
            + '<p style="margin:0 0 20px;color:#000000;background-color:#ffffff">'
            + '<span style="font-weight:bold;font-family:Georgia;font-size:18px">Angebot</span> für '
            + '<a href="https://example.test/x" class="btn">das Projekt</a>, '
            + '<span style="background-color:#ffff00;color:#cc0000">markiert</span></p>'
            + '<ul><li>Erster Punkt</li><li>Zweiter Punkt</li></ul>'
            + '</div>',
        );

        const body = await editor.innerHTML();

        // The page is gone: its stylesheet, its classes (a pasted `hidden`
        // would have hidden the text in the editor), its layout, and the black
        // and white it took for granted.
        expect(body).not.toContain("<style");
        expect(body).not.toContain("class=");
        expect(body).not.toContain("WordSection1");
        expect(body).not.toContain("bgcolor");
        expect(body).not.toContain("width");
        expect(body).not.toContain("position");
        expect(body).not.toContain("line-height");
        expect(body).not.toContain("margin");
        expect(body).not.toMatch(/color:\s*(#000000|rgb\(0, 0, 0\))/);
        expect(body).not.toMatch(/background-color:\s*(#ffffff|rgb\(255, 255, 255\))/);

        // The formatting is not: bold set with CSS, the font and size, the
        // highlight and the red.
        await expect(editor.getByText("Angebot")).toHaveCSS("font-weight", "700");
        await expect(editor.getByText("Angebot")).toHaveCSS("font-family", "Georgia");
        await expect(editor.getByText("Angebot")).toHaveCSS("font-size", "18px");
        await expect(editor.getByText("markiert")).toHaveCSS("background-color", "rgb(255, 255, 0)");
        await expect(editor.getByText("markiert")).toHaveCSS("color", "rgb(204, 0, 0)");
        expect(body).toContain("<ul>");
        expect(body).toContain("https://example.test/x");
    });

    /**
     * Docs wraps the selection in `<b style="font-weight:normal">`. Stripped of
     * its style, that tag made the whole paste bold; and every line is a
     * margin-less `<p>`, which turned single spacing into double.
     */
    test("a Google Docs paste is not all bold, keeps its italics and its spacing", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(
            page,
            '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1a2b">'
            + '<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;"><span style="font-size:11pt;font-family:Arial;color:#000000;font-weight:700;font-style:normal;">Bold line</span></p>'
            + '<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;"><span style="font-size:11pt;font-family:Arial;color:#000000;font-weight:400;font-style:italic;">Plain italic line</span></p>'
            + "</b>",
        );

        const body = await editor.innerHTML();

        expect(body).not.toContain("docs-internal-guid");
        expect(body).not.toContain("<p");
        await expect(editor.getByText("Bold line")).toHaveCSS("font-weight", "700");
        await expect(editor.getByText("Plain italic line")).toHaveCSS("font-weight", "400");
        await expect(editor.getByText("Plain italic line")).toHaveCSS("font-style", "italic");

        // Line under line: nothing between them but the line height.
        const gap = await editor.getByText("Plain italic line").evaluate((second) => {
            const first = [...second.ownerDocument.querySelectorAll("span")].find((s) => "Bold line" === s.textContent)!;

            return second.getBoundingClientRect().top - first.getBoundingClientRect().bottom;
        });
        expect(gap).toBeLessThan(8);
    });

    /** Pages, TextEdit and Word say bold with a class and a stylesheet. */
    test("formatting carried by the copied stylesheet survives without its classes", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(
            page,
            "<html><head><style>p.p1{margin:0;font:12px Helvetica} span.s1{font-weight:bold} span.s2{font-style:italic}</style></head>"
            + '<body><p class="p1"><span class="s1">Fett</span> und <span class="s2">kursiv</span></p></body></html>',
        );

        expect(await editor.innerHTML()).not.toContain("class=");
        await expect(editor.getByText("Fett")).toHaveCSS("font-weight", "700");
        await expect(editor.getByText("kursiv")).toHaveCSS("font-style", "italic");
    });

    /** Word's empty paragraph is one blank line, not a gap on top of two gaps. */
    test("a Word blank line stays one blank line", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(
            page,
            '<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style><!-- p.MsoNormal {margin:0cm;font-size:11.0pt;} --></style></head>'
            + "<body><!--StartFragment--><p class=MsoNormal>Erste Zeile<o:p></o:p></p>"
            + "<p class=MsoNormal><o:p>&nbsp;</o:p></p>"
            + "<p class=MsoNormal>Nach einer Leerzeile<o:p></o:p></p><!--EndFragment--></body></html>",
        );

        const body = await editor.innerHTML();

        expect(body).not.toContain("o:p");
        expect(body).not.toContain("StartFragment");
        expect(body).not.toContain("<p");
        expect(body).toMatch(/Erste Zeile<\/div><div><br><\/div><div[^>]*>Nach einer Leerzeile/);
    });

    /**
     * Some apps put a picture of the selection on the clipboard beside the
     * HTML. It is the same selection again, flattened, and pasting it turned a
     * paragraph into a screenshot of the paragraph.
     */
    test("text beats the picture of it that came along", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(page, '<p style="margin:0"><b>Fett</b> aus Word</p>', true);

        await expect(editor).toContainText("Fett aus Word");
        await expect(editor.locator("img")).toHaveCount(0);
    });

    /** A screenshot, or "Copy image" in a browser: the picture is the content. */
    test("a picture with no text is uploaded as an inline image", async ({ page }) => {
        const editor = await openCompose(page);

        await paste(page, '<img src="https://example.test/picture.png">', true);

        await expect(editor.locator('img[src^="/mail/attachment/"]')).toHaveCount(1, { timeout: 10_000 });
        await expect(editor.locator('img[src^="https://"]')).toHaveCount(0);
    });

    /**
     * A paste handler that replaces the body instead of inserting into it is
     * an easy mistake and a destructive one — it would take out the signature
     * and the quoted original along with the caret position.
     */
    test("inserts into the body rather than replacing it", async ({ page }) => {
        const editor = await openCompose(page);
        await page.keyboard.type("Vorher");

        await paste(page, "<p>Eingefügt</p>");

        const text = await editor.innerText();
        expect(text).toContain("Vorher");
        expect(text).toContain("Eingefügt");
    });

    /**
     * The toolbar's plain paste, for phones, which have no Shift-paste. It
     * reads the clipboard itself, so the test grants that and puts formatted
     * HTML beside the text: the button has to take the text.
     */
    test("Paste as plain text takes the text, one line per line", async ({ page, context }) => {
        await context.grantPermissions(["clipboard-read", "clipboard-write"]);
        const editor = await openCompose(page);

        await page.evaluate(() => navigator.clipboard.write([new ClipboardItem({
            "text/html":  new Blob(["<p><b>Fett</b></p>"], { type: "text/html" }),
            "text/plain": new Blob(["Zeile eins\nZeile zwei\n\nNach einer Leerzeile"], { type: "text/plain" }),
        })]));

        const button = page.getByRole("button", { name: "Paste as plain text" }).first();

        if (false === await button.isVisible()) {
            await page.locator('[data-compose--compose-target="formatToggle"]:visible').first().click();
        }

        await button.click();

        await expect(editor).toContainText("Nach einer Leerzeile");
        const body = await editor.innerHTML();
        expect(body).not.toContain("<b>");
        // One line each and one empty line for the blank one. Whether the
        // first line gets a <div> of its own depends on what the caret was in;
        // the browser's own plain paste decides, as it does for Shift-paste.
        expect(body).toMatch(/Zeile eins(<\/div>)?<div>Zeile zwei<\/div><div><br><\/div><div>Nach einer Leerzeile<\/div>/);
    });
});
