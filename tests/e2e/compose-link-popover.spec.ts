import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The insert-link popover, and links being reachable inside the editor.
 *
 * Both replace things the browser used to do for us and did badly. Inserting a
 * link was `prompt('Enter URL:')` — one unthemed field, no way to say what the
 * link should READ as, and a bare "example.com" went in as a relative link to
 * a path inside the app. And a link once written was unreachable: a click in a
 * contenteditable places a caret and nothing else, so there was no way to
 * check where a link went, change it, or take it off again short of sending
 * the message to yourself.
 *
 * What is asserted here is behaviour a server-side test cannot see: where the
 * selection is when the popover opens, what markup comes out, and what the
 * editor does with a `javascript:` URL. Whether the result survives the save
 * is MailBodySanitizer's own tests, next door.
 */

const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const HIDDEN = '[data-compose--compose-toolbar-target="hiddenInput"]';
const POPOVER = '[data-compose--compose-toolbar-target="linkPopover"]';
const URL_FIELD = '[data-compose--compose-toolbar-target="linkUrl"]';
const TEXT_FIELD = '[data-compose--compose-toolbar-target="linkText"]';
const VIEW = '[data-compose--compose-toolbar-target="linkView"]';
const EDIT = '[data-compose--compose-toolbar-target="linkEdit"]';
const ERROR = '[data-compose--compose-toolbar-target="linkError"]';

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
}

/**
 * Type `text` into the body and leave its last `length` characters selected.
 *
 * Backwards from the caret rather than forwards from Home, because the body
 * this opens with is not necessarily empty: the account may carry a signature,
 * in which case Home is the start of whichever line the signature put the
 * caret on and the selection would be six characters of "Kind regards". The
 * caret after typing is the one position this can be sure of.
 */
async function typeAndSelect(page: Page, text: string, length: number): Promise<void> {
    const editor = page.locator(`${DOCK} ${EDITOR}`);

    await editor.click();
    await page.keyboard.type(text);

    for (let i = 0; i < length; i++) {
        await page.keyboard.press("Shift+ArrowLeft");
    }
}

const linkButton = (page: Page) =>
    page.locator(DOCK).getByRole("button", { name: "Insert link" });

test.describe("insert link", () => {
    // The headline of the report: not a browser dialog.
    test("opens an in-app popover rather than a native prompt", async ({ page }) => {
        await openCompose(page);

        // A native prompt would hang the click until something answered it,
        // and this listener is what would have to answer. It must never fire.
        let dialogs = 0;
        page.on("dialog", (dialog) => {
            dialogs += 1;
            void dialog.dismiss();
        });

        await page.locator(`${DOCK} ${EDITOR}`).click();
        await linkButton(page).click();

        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${URL_FIELD}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${TEXT_FIELD}`)).toBeVisible();
        expect(dialogs).toBe(0);
    });

    /**
     * The selection survives the popover opening. It is the reason the toolbar
     * keeps a saved range at all, and the reason the buttons cancel their own
     * mousedown: focusing the URL field blurs the editor, and a blur that was
     * allowed to re-read the selection would record the collapsed one.
     */
    test("prefills the text field from the selection", async ({ page }) => {
        await openCompose(page);
        await typeAndSelect(page, "a link to plMail", 6);

        await linkButton(page).click();

        await expect(page.locator(`${DOCK} ${TEXT_FIELD}`)).toHaveValue("plMail");
    });

    test("wraps the selection, and normalises a bare host to https", async ({ page }) => {
        await openCompose(page);
        await typeAndSelect(page, "a link to plMail", 6);

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("example.com");
        await page.locator(`${DOCK}`).getByRole("button", { name: "Apply" }).click();

        const anchor = page.locator(`${DOCK} ${EDITOR} a`);

        await expect(anchor).toHaveCount(1);
        // https://, not a relative link to /mail/example.com — which is what
        // the old prompt produced and what the sanitiser would have kept.
        await expect(anchor).toHaveAttribute("href", "https://example.com");
        await expect(anchor).toHaveText("plMail");
        // A new tab that cannot reach back into the app that opened it.
        await expect(anchor).toHaveAttribute("rel", "noopener noreferrer");

        // And it is in the hidden input, which is what actually gets posted.
        expect(await page.locator(`${DOCK} ${HIDDEN}`).inputValue()).toContain(
            'href="https://example.com"',
        );
    });

    test("Enter applies without submitting the message", async ({ page }) => {
        await openCompose(page);
        await page.locator(`${DOCK} ${EDITOR}`).click();

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("https://example.org/a");
        await page.locator(`${DOCK} ${TEXT_FIELD}`).fill("a link");
        await page.locator(`${DOCK} ${URL_FIELD}`).press("Enter");

        // The popover lives inside the compose <form>: an un-cancelled Enter
        // in a field would send the half-written message.
        await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveText("a link");
    });

    test("Escape closes it and gives the selection back", async ({ page }) => {
        await openCompose(page);
        await typeAndSelect(page, "a link to plMail", 6);

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).press("Escape");

        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeHidden();
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(0);

        // The six characters are still selected, so the very next formatting
        // action applies to them — which is what "gives it back" has to mean.
        expect(await page.evaluate(() => window.getSelection()?.toString())).toBe("plMail");
    });

    /**
     * MailBodySanitizer refuses anything outside http/https/mailto/tel on the
     * way out, and would drop this silently. A refusal the writer can see is
     * the difference between a rule and a disappearance.
     */
    test("refuses a javascript: URL, in the editor, with a reason", async ({ page }) => {
        await openCompose(page);
        await page.locator(`${DOCK} ${EDITOR}`).click();

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("javascript:alert(1)");
        await page.locator(DOCK).getByRole("button", { name: "Apply" }).click();

        await expect(page.locator(`${DOCK} ${ERROR}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(0);
    });

    /**
     * The allow-list is a list, not a `javascript:` special case — it is
     * MailBodySanitizer::allowLinkSchemes() spelled again, so `data:` is
     * refused for the same reason and by the same rule.
     *
     * (A `javascript:` URL broken up by a newline, the classic way past a
     * naive scheme test, cannot be typed into this field at all: a single-line
     * <input> strips CR and LF from its own value before anything reads it.
     * The controller strips control characters anyway, for the hrefs it reads
     * back off anchors that came from elsewhere.)
     */
    test("refuses a data: URL by the same rule", async ({ page }) => {
        await openCompose(page);
        await page.locator(`${DOCK} ${EDITOR}`).click();

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("data:text/html,<h1>hi</h1>");
        await page.locator(DOCK).getByRole("button", { name: "Apply" }).click();

        await expect(page.locator(`${DOCK} ${ERROR}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(0);
    });

    test("turns a bare address into a mailto:", async ({ page }) => {
        await openCompose(page);
        await page.locator(`${DOCK} ${EDITOR}`).click();

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("someone@example.com");
        await page.locator(DOCK).getByRole("button", { name: "Apply" }).click();

        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveAttribute(
            "href",
            "mailto:someone@example.com",
        );
    });
});

/**
 * The signature editor runs the SAME toolbar controller, and had the same link
 * button raising the same `prompt()`. It is the one other host of the shared
 * partial, so it is the one place a change to the popover can break unseen.
 */
test.describe("the signature editor's link popover", () => {
    test("is the same popover, and still not a browser prompt", async ({ page }) => {
        let dialogs = 0;
        page.on("dialog", (dialog) => {
            dialogs += 1;
            void dialog.dismiss();
        });

        await page.goto("/settings?section=aliases");

        const form = page.locator('form[action$="/compose-defaults/signature"]').first();
        const editor = form.locator('[data-compose--compose-toolbar-target="editor"]');

        await editor.click();
        await page.keyboard.type("regards, me");

        for (let i = 0; i < 2; i++) {
            await page.keyboard.press("Shift+ArrowLeft");
        }

        await form.getByRole("button", { name: "Insert link" }).click();

        await expect(form.locator(POPOVER)).toBeVisible();
        await expect(form.locator(TEXT_FIELD)).toHaveValue("me");
        expect(dialogs).toBe(0);

        await form.locator(URL_FIELD).fill("example.net");
        await form.getByRole("button", { name: "Apply" }).click();

        await expect(editor.locator("a")).toHaveAttribute("href", "https://example.net");
    });
});

test.describe("a link in the editor", () => {
    /** Write one link and leave the caret inside it. */
    async function withLink(page: Page): Promise<void> {
        await openCompose(page);
        await typeAndSelect(page, "a link to plMail", 6);

        await linkButton(page).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("https://example.com/one");
        await page.locator(DOCK).getByRole("button", { name: "Apply" }).click();
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(1);
    }

    test("clicking it shows where it goes", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();

        await expect(page.locator(`${DOCK} ${VIEW}`)).toBeVisible();
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="linkHref"]`),
        ).toHaveText("https://example.com/one");
    });

    test("Change reopens the editor prefilled from it", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await page.locator(DOCK).getByRole("button", { name: "Change" }).click();

        await expect(page.locator(`${DOCK} ${EDIT}`)).toBeVisible();
        await expect(page.locator(`${DOCK} ${URL_FIELD}`)).toHaveValue(
            "https://example.com/one",
        );
        await expect(page.locator(`${DOCK} ${TEXT_FIELD}`)).toHaveValue("plMail");
    });

    // In place, not nested. execCommand('createLink') over a selection already
    // inside an <a> produces an <a> inside an <a>, which no client renders the
    // way the writer meant.
    test("editing changes the link rather than nesting a second one", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await page.locator(DOCK).getByRole("button", { name: "Change" }).click();
        await page.locator(`${DOCK} ${URL_FIELD}`).fill("https://example.com/two");
        await page.locator(DOCK).getByRole("button", { name: "Apply" }).click();

        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(1);
        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveAttribute(
            "href",
            "https://example.com/two",
        );
        await expect(page.locator(`${DOCK} ${EDITOR} a a`)).toHaveCount(0);
    });

    test("Remove unwraps it and keeps the words", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await page.locator(DOCK).getByRole("button", { name: "Remove link" }).click();

        await expect(page.locator(`${DOCK} ${EDITOR} a`)).toHaveCount(0);
        await expect(page.locator(`${DOCK} ${EDITOR}`)).toContainText("a link to plMail");
        expect(await page.locator(`${DOCK} ${HIDDEN}`).inputValue()).not.toContain("<a");
    });

    // The popover must not fight the caret: it is an addition to the click,
    // not a replacement for it, and it goes away when the caret does.
    test("dismisses itself once the caret leaves the link", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await expect(page.locator(`${DOCK} ${VIEW}`)).toBeVisible();

        // Select the whole body: the range now starts outside the anchor,
        // which is the "the caret is not in this link any more" the controller
        // watches selectionchange for.
        await page.keyboard.press("ControlOrMeta+a");

        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeHidden();
    });

    test("Escape dismisses it", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await expect(page.locator(`${DOCK} ${VIEW}`)).toBeVisible();

        await page.keyboard.press("Escape");

        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeHidden();
    });

    // Typing still works, which is the thing a popover over an editor is most
    // likely to break.
    test("leaves ordinary typing alone", async ({ page }) => {
        await withLink(page);

        await page.locator(`${DOCK} ${EDITOR} a`).click();
        await page.keyboard.press("End");
        await page.keyboard.type(" and more");

        await expect(page.locator(`${DOCK} ${EDITOR}`)).toContainText("and more");
        await expect(page.locator(`${DOCK} ${POPOVER}`)).toBeHidden();
    });
});
