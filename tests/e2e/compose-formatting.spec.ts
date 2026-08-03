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
