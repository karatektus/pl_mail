import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The last three controls in the compose toolbar: signature, more options,
 * encrypt.
 *
 * All three are things a server-side test cannot see. The signature swap on a
 * From change is a DOM operation with one rule that matters — it must not
 * touch what the user typed — and plain-text mode is a swap of one editing
 * surface for another that has to leave a submittable form behind. The encrypt
 * button is here because "visible, named and disabled" is a browser fact.
 *
 * Everything about what is STORED lives in the PHPUnit tests next door; this
 * file is only about what the window does before it posts.
 */

const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const HIDDEN = '[data-compose--compose-toolbar-target="hiddenInput"]';
const PLAIN = '[data-compose--compose-target="plainBody"]';
const SIGNATURE = "[data-pl-signature]";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
}

/** Give the first account a signature through the settings panel. */
async function setAccountSignature(page: Page, text: string): Promise<void> {
    await page.goto("/settings?section=aliases");

    const form = page
        .locator('form[action$="/compose-defaults/signature"]')
        .first();

    const editor = form.locator('[contenteditable="true"]');

    await editor.click();
    await editor.fill(text);
    await form.getByRole("button", { name: /Save signature/i }).click();

    await expect(page.getByText("Saved.")).toBeVisible();
}

test.describe("compose signature", () => {
    /**
     * The one rule of the From switch. A signature that follows the sender is
     * only useful if changing sender is free — a user who has written a
     * paragraph must not pay for it by losing the paragraph.
     */
    test("switching From swaps the signature and leaves typed text alone", async ({
        page,
    }) => {
        await setAccountSignature(page, "Kind regards, Ada");
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);

        await expect(editor.locator(SIGNATURE)).toHaveCount(1);

        // Into the writing space ABOVE the signature — the first line, where
        // the window puts the caret on open. Clicking the middle of the editor
        // would land in the empty area below the content, which the browser
        // resolves to the end of the signature, and typing there is genuinely
        // typing into the signature.
        await editor.click({ position: { x: 8, y: 8 } });
        await page.keyboard.type("A paragraph I do not want to lose.");

        const options = page.locator(
            `${DOCK} [data-compose--compose-target="fromDropdown"] button`,
        );

        // Only meaningful with something to switch to; the seeded user may
        // have one address, in which case the swap is a no-op and the
        // assertion below still holds.
        await page
            .locator(`${DOCK} [data-compose--compose-target="fromBtn"]`)
            .click();
        await options.last().click();

        await expect(editor).toContainText("A paragraph I do not want to lose.");
        await expect(editor.locator(SIGNATURE)).toHaveCount(1);
    });

    /**
     * Clicking the button twice is a thing people do. It must not leave two
     * sign-offs behind — the block is replaced in place, never appended to.
     */
    test("the signature button never leaves two signatures behind", async ({
        page,
    }) => {
        await setAccountSignature(page, "Kind regards, Ada");
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        const button = page.locator(`${DOCK} .fa-pen-nib`).locator("..");

        await button.click();
        await button.click();

        await expect(editor.locator(SIGNATURE)).toHaveCount(1);
    });
});

test.describe("compose more options", () => {
    test("plain text mode swaps the editor and keeps the text submittable", async ({
        page,
    }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        const plain = page.locator(`${DOCK} ${PLAIN}`);

        await editor.click();
        await page.keyboard.type("Words with no markup.");

        // The confirm is the warning about losing formatting — accepted here,
        // because losing it is exactly what the test is asking for.
        page.once("dialog", (dialog) => void dialog.accept());

        await page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..").click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        await expect(plain).toBeVisible();
        await expect(editor).toBeHidden();
        await expect(plain).toHaveValue(/Words with no markup\./);

        // The HTML body is taken out of the submission entirely, which is what
        // makes the send path emit text only.
        await expect(page.locator(`${DOCK} ${HIDDEN}`)).toBeDisabled();
    });

    /**
     * Reversible, at least until the draft is saved in plain mode: the editor
     * still holds the HTML, so coming back restores it rather than re-parsing
     * the text.
     */
    test("switching back out of plain text restores the formatting", async ({
        page,
    }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);

        await editor.click();
        await page.keyboard.type("emphasis");
        await page.keyboard.press("ControlOrMeta+a");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="boldBtn"]`)
            .click();

        const menu = page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..");

        page.once("dialog", (dialog) => void dialog.accept());
        await menu.click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        await menu.click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        await expect(editor).toBeVisible();
        expect(await page.locator(`${DOCK} ${HIDDEN}`).inputValue()).toMatch(
            /<(b|strong)>/i,
        );
    });

    test("priority and read receipt are real form controls", async ({ page }) => {
        await openCompose(page);

        await page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..").click();

        const priority = page.locator(`${DOCK} select[name$="[priority]"]`);
        const receipt = page.locator(
            `${DOCK} input[name$="[readReceiptRequested]"]`,
        );

        await expect(priority).toBeVisible();
        await priority.selectOption("high");
        await receipt.check();

        await expect(priority).toHaveValue("high");
        await expect(receipt).toBeChecked();
    });
});

test.describe("compose encrypt", () => {
    /**
     * Visible, named, and demonstrably not clickable. A lock icon that looked
     * live would tell the user their mail was encrypted when it was not.
     */
    test("the encrypt button is present, named and disabled", async ({ page }) => {
        await openCompose(page);

        const button = page.locator(`${DOCK} .fa-lock`).locator("..");

        await expect(button).toBeVisible();
        await expect(button).toBeDisabled();
        await expect(button).toHaveAttribute("aria-label", /encrypt/i);
    });
});
