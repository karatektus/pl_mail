import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The formatting toolbar, and what it puts in the draft.
 *
 * The editor is a contenteditable and the toolbar drives it through
 * execCommand, so nothing here is provable by looking at the button: a control
 * that highlights itself while writing no markup would pass any check of its
 * own state. Every assertion is therefore about the HTML that would be sent,
 * read from the hidden input the form actually submits.
 *
 * The selection is the other half. execCommand acts on whatever is selected,
 * so a toolbar that loses the selection when a button takes focus formats
 * nothing — which is the bug this shape of test exists to catch.
 */

const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const HIDDEN = '[data-compose--compose-toolbar-target="hiddenInput"]';

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
}

/** Type into the editor and select all of it, which is what the toolbar acts on. */
async function write(page: Page, text: string): Promise<void> {
    const editor = page.locator(`${DOCK} ${EDITOR}`);

    await editor.click();
    await editor.fill(text);
    await page.keyboard.press("ControlOrMeta+a");
}

/** The markup the form would submit, which is the only thing that matters. */
async function html(page: Page): Promise<string> {
    return page.locator(`${DOCK} ${HIDDEN}`).inputValue();
}

test.describe("compose formatting", () => {
    test("bold, italic and underline reach the submitted markup", async ({ page }) => {
        await openCompose(page);
        await write(page, "emphasis");

        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="boldBtn"]`).click();
        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="italicBtn"]`).click();
        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="underlineBtn"]`).click();

        const markup = await html(page);

        expect(markup).toMatch(/<(b|strong)>/i);
        expect(markup).toMatch(/<(i|em)>/i);
        expect(markup).toMatch(/<u>/i);
    });

    /**
     * A button pressed while text is selected must not steal the selection on
     * its way to acting — mousedown default is what would take it, and the
     * command would then apply to a collapsed cursor and do nothing.
     */
    test("the selection survives pressing a toolbar button", async ({ page }) => {
        await openCompose(page);
        await write(page, "keep me selected");

        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="boldBtn"]`).click();

        // Still selected, so a second command applies to the same text rather
        // than to nothing.
        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="italicBtn"]`).click();

        const markup = await html(page);

        expect(markup).toMatch(/<(b|strong)>/i);
        expect(markup).toMatch(/<(i|em)>/i);
    });

    test("lists are written as lists", async ({ page }) => {
        await openCompose(page);
        await write(page, "one item");

        await page.locator(`${DOCK} button[title="Bullet list"]`).click();
        expect(await html(page)).toMatch(/<ul>/i);

        await write(page, "another");
        await page.locator(`${DOCK} button[title="Numbered list"]`).click();
        expect(await html(page)).toMatch(/<ol>/i);
    });

    test("alignment is written as a style rather than as nothing", async ({ page }) => {
        await openCompose(page);
        await write(page, "centre me");

        await page.locator(`${DOCK} button[title="Align center"]`).click();

        expect(await html(page)).toMatch(/text-align:\s*center/i);
    });

    /**
     * Font choices are the ones people notice going missing, because they are
     * chosen deliberately and then survive nothing.
     */
    test("a font family and size reach the markup", async ({ page }) => {
        await openCompose(page);
        await write(page, "styled");

        const family = page.locator(`${DOCK} [data-compose--compose-toolbar-target="fontFamily"]`);
        const options = await family.locator("option").count();

        test.skip(options < 2, "no alternative font offered in this build");

        await family.selectOption({ index: 1 });

        const markup = await html(page);

        expect(markup).toMatch(/font-family|<font/i);
    });

    /** Typed text with no formatting still has to arrive. */
    test("plain text reaches the draft unchanged", async ({ page }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        await editor.click();
        await editor.fill("nothing fancy here");

        // The hidden input is synced on input, not only on submit — a draft
        // autosaved before the first keystroke propagates would be empty.
        await expect(page.locator(`${DOCK} ${HIDDEN}`)).toHaveValue(/nothing fancy here/);
    });
});

/**
 * The emoji picker, and the thing it is deliberately not.
 *
 * The picker is `emoji-picker-element`, a web component whose grid lives in a
 * shadow root — reachable from Playwright, which pierces shadow DOM, but not
 * from a CSS selector in the app. What is asserted here is the two ends of it:
 * a click produces a character in the body at the cursor, and nothing else in
 * the window ever produces one.
 */
test.describe("emoji", () => {
    const PICKER = "emoji-picker";

    async function openPicker(page: Page) {
        await page.locator(`${DOCK} button[title="Insert emoji"]`).click();
        await expect(page.locator(`${DOCK} ${PICKER}`)).toBeVisible();
    }

    test("a chosen emoji lands in the body at the cursor", async ({ page }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        await editor.click();
        await editor.fill("before after");

        // Cursor between the two words, which is the whole point: the picker
        // steals focus, and an implementation that forgets the selection
        // appends to the end instead.
        await editor.evaluate((node) => {
            // The text node holding what was just filled in, FOUND rather than
            // assumed. `node.firstChild` is that text node only while the body
            // is otherwise empty; an account with a signature makes the first
            // child an element, and `setStart(element, 6)` then counts child
            // nodes and throws "IndexSizeError: there is no child at offset 6".
            // That is a fact about the account, not about the emoji picker, so
            // this test should not be reading it.
            const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            let text: Text | null = null;

            while (walker.nextNode()) {
                const candidate = walker.currentNode as Text;

                if (candidate.data.includes("before")) {
                    text = candidate;
                    break;
                }
            }

            if (null === text) {
                throw new Error("the filled-in text is not in the editor");
            }

            const range = document.createRange();
            range.setStart(text, text.data.indexOf("before") + "before".length);
            range.collapse(true);
            const sel = window.getSelection()!;
            sel.removeAllRanges();
            sel.addRange(range);
            node.dispatchEvent(new Event("blur"));
        });

        await openPicker(page);

        // The first emoji in the grid, whatever the data file says it is.
        const first = page.locator(`${DOCK} ${PICKER} .emoji-menu [role="menuitem"]`).first();
        await expect(first).toBeVisible();
        const glyph = (await first.textContent())!.trim();
        await first.click();

        // The component looks the emoji up in its database before announcing
        // the click, so `emoji-click` lands a turn or two after the press —
        // reading the input straight away reads it as it was.
        await expect(page.locator(`${DOCK} ${HIDDEN}`)).toHaveValue(
            new RegExp(`before${glyph}\\s*after`),
        );
    });

    /**
     * The rule the whole feature is built around: emoji enter the body when
     * somebody picks one, and at no other time. No `:)` → 🙂, no `:smile:`
     * expansion, no autocomplete on a colon. Substitution rewrites what a
     * person wrote, and a mail client is the wrong place to guess.
     *
     * Byte-identical, not "looks the same": the failure this guards against
     * replaces two characters with one, which every loose assertion passes.
     */
    test("typed emoticons and shortcodes are left exactly as typed", async ({ page }) => {
        await openCompose(page);

        const typed = ":) :-) :smile: :D <3 :thumbsup:";
        const editor = page.locator(`${DOCK} ${EDITOR}`);

        await editor.click();

        // What the body held before a key was pressed. Clicking the editor
        // puts the caret at the end of whatever is already there, so typing
        // appends — and the assertion below can therefore be exact about the
        // WHOLE body without needing it to have started empty.
        //
        // It used to just say `toBe(typed)`, which quietly also asserted that
        // the body was empty. That is a claim about the account rather than
        // about emoji, and a false one whenever an account carries a signature
        // — the four lines of sign-off failed this test with nothing wrong.
        const before = await editor.innerText();

        // Typed key by key rather than filled, because an autocomplete would
        // trigger on the keystrokes and not on the value.
        await page.keyboard.type(typed);

        // Give any hypothetical debounce a chance to fire before asserting.
        await page.waitForTimeout(500);

        // Byte for byte, which is the actual requirement: the failure this
        // guards against swaps two characters for one, and every looser
        // assertion passes through it. Nothing may be added either — the body
        // is the body as it was, plus exactly what was typed.
        expect(await editor.innerText()).toBe(before + typed);

        // The markup that would be posted, too — `<` arrives escaped, as any
        // text typed into HTML must, and nothing else has been touched.
        expect(await html(page)).toContain(":) :-) :smile: :D &lt;3 :thumbsup:");

        // And no picker opened itself along the way.
        await expect(page.locator(`${DOCK} ${PICKER}`)).toHaveCount(0);
    });

    /**
     * Nothing may be fetched from a CDN at runtime. The picker's default data
     * source is a jsdelivr URL, so this is the one assertion standing between
     * a working offline install and a broken one.
     */
    test("the picker loads its data from this app and not from a CDN", async ({ page }, testInfo) => {
        const ours = new URL(testInfo.project.use.baseURL!).host;
        const external: string[] = [];

        page.on("request", (request) => {
            if (new URL(request.url()).host !== ours) {
                external.push(request.url());
            }
        });

        await openCompose(page);
        await openPicker(page);

        await expect(page.locator(`${DOCK} ${PICKER} .emoji-menu [role="menuitem"]`).first()).toBeVisible();

        expect(external.filter((url) => !url.startsWith("data:"))).toEqual([]);
    });
});

/**
 * An image placed in the body rather than beside it.
 *
 * The proof is the `data-cid`: it is what the server turns back into a `cid:`
 * reference on save, and an <img> without one is a link into plMail that
 * renders as a broken image for the recipient.
 */
test.describe("inline images", () => {
    const PNG = Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
        "base64",
    );

    test("an image is inserted into the body and survives the autosave", async ({ page }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        await editor.click();
        await editor.fill("here it is");

        await page
            .locator(`${DOCK} input[data-compose--compose-target="imageInput"]`)
            .setInputFiles({ name: "e2e-inline.png", mimeType: "image/png", buffer: PNG });

        const image = editor.locator("img[data-cid]");
        await expect(image).toBeVisible();
        await expect(image).toHaveAttribute("src", /\/mail\/attachment\/\d+/);

        // The hidden input is what the form posts, so the image has to be in
        // it — not only on screen.
        expect(await html(page)).toMatch(/<img[^>]+data-cid=/i);

        // Reopened from the server. This is the assertion that matters: the
        // draft is stored with `cid:` references, so a window rendered from
        // the database that still shows the picture proves the rewrite has a
        // working inverse. Without it the image is there until the first
        // reload and a broken icon afterwards.
        await expect(page.locator(`${DOCK} [data-compose--compose-target="saveStatus"]`))
            .toHaveText(/saved/i, { timeout: 10_000 });

        const draftUrl = await page
            .locator(`${DOCK} [data-compose--compose-draft-url-value]`)
            .getAttribute("data-compose--compose-draft-url-value");

        const id = draftUrl!.match(/\/(\d+)(?:\?|$)/)![1];

        await page.goto(`/compose/edit/${id}`);

        const reopened = page.locator(`${EDITOR} img[data-cid]`);
        await expect(reopened).toHaveCount(1);
        await expect(reopened).toHaveAttribute("src", /\/mail\/attachment\/\d+/);
    });

    /**
     * Pasting is the path most people actually take, and the one that is
     * expensive to get wrong: left to the browser, a pasted screenshot becomes
     * a data: URI inside the contenteditable, and every autosave from then on
     * posts the whole picture back as base64 text.
     */
    for (const gesture of ["paste", "drop"] as const) {
        test(`an image ${gesture === "paste" ? "pasted" : "dropped"} into the body is uploaded, not inlined as base64`, async ({ page }) => {
            await openCompose(page);

            const editor = page.locator(`${DOCK} ${EDITOR}`);
            await editor.click();
            await editor.fill("look at this");

            await editor.evaluate((node, kind) => {
                const bytes = Uint8Array.from(
                    atob(
                        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
                    ),
                    (c) => c.charCodeAt(0),
                );

                const transfer = new DataTransfer();
                transfer.items.add(new File([bytes], "e2e-gesture.png", { type: "image/png" }));

                node.dispatchEvent(
                    "paste" === kind
                        ? new ClipboardEvent("paste", {
                              clipboardData: transfer,
                              bubbles: true,
                              cancelable: true,
                          })
                        : new DragEvent("drop", {
                              dataTransfer: transfer,
                              bubbles: true,
                              cancelable: true,
                          }),
                );
            }, gesture);

            const image = editor.locator("img");
            await expect(image).toBeVisible();
            await expect(image).toHaveAttribute("src", /\/mail\/attachment\/\d+/);
            await expect(image).toHaveAttribute("data-cid", /@plmail$/);

            // The thing that must not have happened.
            expect(await html(page)).not.toContain("data:image");
        });
    }
});
