import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * A number typed into a message is drawn by the message's own typeface.
 *
 * The report was that numbers in the compose window "have too much spacing",
 * and it was neither spacing nor a number: it was the font. The composer body
 * names the vendored emoji family first —
 *
 *     [data-compose--compose-toolbar-target="editor"] {
 *         font-family: 'Noto Color Emoji', ui-sans-serif, system-ui, sans-serif;
 *     }
 *
 * — on the stated grounds that "only emoji code points resolve here". They did
 * not. Noto Color Emoji covers `#`, `*` and 0-9 as well, because those are the
 * BASES of the keycap sequences 1️⃣ 2️⃣ #️⃣, and a family named first in a stack
 * is asked about every character in the run. So every digit anybody typed came
 * back at emoji metrics — a ten-digit phone number measured 170px in a composer
 * that draws the same ten in 84 — while the letters beside them, which that font
 * does not cover, fell through to the next family and looked perfectly normal.
 * That asymmetry is why it reads as letter-spacing rather than as a font.
 *
 * assets/styles/app.css cuts those code points out of the @font-face slice that
 * carries them and gives the emoji picker a keycap-only family of its own, so
 * the sentence in the comment is now true. This file is what keeps it true, and
 * it asks the question the way the reader sees it: NOT "which font did the
 * browser pick", which no API answers honestly, but "is a digit the width a
 * digit should be".
 *
 * The measurement is a ratio against the same string in the app's own text
 * stack, so it says nothing about which fonts the machine running it has
 * installed — only that the editor and the rest of the page agree. Letters are
 * measured the same way in the same run, as the control that fails if the probe
 * itself is broken.
 */

/** Type into the composer body and measure what the browser drew. */
async function widthsIn(page: Page, text: string) {
    return page.evaluate(async (probe) => {
        const editor = document.querySelector<HTMLElement>(
            '[data-compose--compose-toolbar-target="editor"]',
        )!;

        // Written straight into the body rather than typed: five characters is
        // what arms the autosave, and a spec about metrics has no business
        // minting drafts the suite then has to clean up.
        editor.innerHTML = '<div id="type-probe"></div>';
        const host = document.getElementById("type-probe")!;
        host.textContent = probe;

        // The comparison is drawn off screen in the app's OWN text stack, the
        // one --app-font-family resolves to, so the number below is a ratio
        // between two things on this machine and not a pixel count from mine.
        const control = document.createElement("span");
        control.style.cssText =
            "position:fixed;left:-9999px;top:0;white-space:pre;visibility:hidden;" +
            `font-family:var(--app-font-family);font-size:${getComputedStyle(editor).fontSize}`;
        control.textContent = probe;
        document.body.appendChild(control);

        await document.fonts.ready;

        // A Range, not the element's own box: a block fills its parent's width
        // whatever is in it, and would report the composer rather than the text.
        const range = document.createRange();
        range.selectNodeContents(host);

        const drawn = range.getBoundingClientRect().width;
        const expected = control.getBoundingClientRect().width;

        control.remove();
        editor.innerHTML = "";

        return { drawn, expected };
    }, text);
}

test.beforeAll(() => {
    seed("seed-mail");
});

test.describe("composer typography", () => {
    test("draws digits at text width, not emoji width", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(
            page.locator('[data-compose--compose-toolbar-target="editor"]'),
        ).toBeVisible();

        // A phone number, which is what the report was written on.
        const digits = await widthsIn(page, "0170299037");
        // The control: characters the emoji font never covered, so these were
        // right all along and must stay right.
        const letters = await widthsIn(page, "abcdefghij");

        // Both to the same tolerance. Twice the width was the failure; 1px of
        // subpixel disagreement between two boxes is not.
        expect(digits.drawn).toBeCloseTo(digits.expected, 0);
        expect(letters.drawn).toBeCloseTo(letters.expected, 0);
    });

    test("still draws emoji from the vendored family", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(
            page.locator('[data-compose--compose-toolbar-target="editor"]'),
        ).toBeVisible();

        // The other direction, and the reason the fix is a trimmed range rather
        // than a reordered stack: taking the digits out must not take the emoji
        // with them. Proven by what the page FETCHES — the family is served as
        // ten unicode-range slices, so a smiley in the body loading the slice
        // that covers U+1F600 means the browser asked this family to draw it.
        const loaded = await page.evaluate(async () => {
            const editor = document.querySelector<HTMLElement>(
                '[data-compose--compose-toolbar-target="editor"]',
            )!;
            editor.innerHTML = "<div>\u{1F600}</div>";

            await document.fonts.ready;

            const covers = [...document.fonts].some(
                (face) =>
                    face.status === "loaded" &&
                    face.family.includes("Noto Color Emoji") &&
                    /1f600/i.test(face.unicodeRange),
            );

            editor.innerHTML = "";
            return covers;
        });

        expect(loaded).toBe(true);
    });
});
