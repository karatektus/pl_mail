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
const WARNING = '[data-compose--compose-target="plainWarning"]';
const SIGNATURE = "[data-pl-signature]";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
}

/**
 * Give the first account a signature through the settings panel.
 *
 * ALWAYS pair with clearAccountSignature() in a `finally`. The signature is
 * stored on the account, so it outlives the test, the file and the browser
 * context — and the next spec on this worker opens compose to find a body that
 * is not empty. That is not hypothetical: it is why the two emoji tests in
 * compose-formatting.spec.ts failed on full runs and passed on their own. One
 * placed a caret at offset 6 of `editor.firstChild` and got
 * `IndexSizeError: there is no child at offset 6`, because a signature makes
 * the first child an element rather than the text node it had just filled in;
 * the other compared the whole editor's innerText to the string it typed and
 * found four extra lines of sign-off.
 */
async function setAccountSignature(page: Page, text: string): Promise<void> {
    await page.goto("/settings?section=signature");

    // The account editor, which is the only one on the page until an address
    // is given a signature of its own.
    const form = page
        .locator('form[action$="/signature"]')
        .first();

    const editor = form.locator('[contenteditable="true"]');

    await editor.click();
    await editor.fill(text);
    await form.getByRole("button", { name: /Save signature/i }).click();

    await expect(page.getByText("Saved.")).toBeVisible();
}

/**
 * Put the account back to signing with nothing.
 *
 * An empty signature is genuinely "no signature": SignatureProvider::block()
 * returns the empty string for anything that trims to nothing, so no
 * `[data-pl-signature]` block is written into a body — which is the state a
 * freshly seeded account is in.
 */
async function clearAccountSignature(page: Page): Promise<void> {
    await page.goto("/settings?section=signature");

    const form = page.locator('form[action$="/signature"]').first();
    const editor = form.locator('[contenteditable="true"]');

    await editor.click();

    // Emptied through the DOM, not with `fill("")`. A contenteditable that is
    // cleared by typing keeps the `<br>` the browser puts in an empty block, so
    // `fill("")` posts "<br>" — and "<br>" does not trim to nothing, so
    // SignatureProvider::block() wraps it and every later compose body opens
    // with an empty-looking but very real signature block. The account is then
    // not back where it started, which is the whole point of this helper.
    //
    // The `input` event is what the toolbar controller listens for to mirror
    // the editor into the hidden field the form actually submits, so this still
    // goes through the real save path rather than around it.
    await editor.evaluate((node) => {
        node.innerHTML = "";
        node.dispatchEvent(new Event("input", { bubbles: true }));
    });

    await form.getByRole("button", { name: /Save signature/i }).click();

    await expect(page.getByText("Saved.")).toBeVisible();
}

test.describe("writing above the signature", () => {
    /**
     * The reported bug, and it is a mouse bug: clicking the empty white area
     * BELOW the signature put the caret at the end of the signature LINE, so
     * the first thing typed in a new message came out welded onto the sign-off
     * — "-- SIG-OUTLOOK Paul" followed immediately by the sentence, no
     * separator — and there was no way with a mouse to start a paragraph above
     * it at all.
     *
     * A contenteditable resolves a click on its own padding to the nearest
     * position in the nearest child, and a new body ends with the signature, so
     * every click in the empty space below it landed there. The window seeds a
     * `<p><br></p>` writing space ABOVE the signature; the fix sends those
     * clicks to it.
     */
    test("clicking below the signature types above it, not onto it", async ({ page }) => {
        const SIG = "SIG-OUTLOOK Paul";

        await setAccountSignature(page, SIG);

        try {
            await openCompose(page);

            const editor = page.locator(`${DOCK} ${EDITOR}`);
            await expect(editor.locator(SIGNATURE)).toBeVisible();

            // The empty area below the signature — a real click at a real
            // point, low in the editor's box, which is what a person does.
            const box = (await editor.boundingBox())!;
            await page.mouse.click(box.x + box.width / 2, box.y + box.height - 8);

            await page.keyboard.type("Diese Mail geht an beide Konten.");

            const signature = editor.locator(SIGNATURE);

            // The signature still says only what the signature said. This is
            // the assertion the bug fails: it used to read
            // "SIG-OUTLOOK PaulDiese Mail geht an beide Konten."
            await expect(signature).toHaveText(new RegExp(`^\\s*${SIG}\\s*$`));

            // And the typing is in the body, above it.
            await expect(editor).toContainText("Diese Mail geht an beide Konten.");

            const typedIsAbove = await editor.evaluate((node) => {
                const sig = node.querySelector("[data-pl-signature]")!;
                const text = [...node.childNodes].find(
                    (child) => child.textContent?.includes("Diese Mail geht an beide Konten."),
                )!;

                return sig.compareDocumentPosition(text) === Node.DOCUMENT_POSITION_PRECEDING;
            });

            expect(typedIsAbove, "the paragraph is above the sign-off").toBe(true);

            // …and Send does not then claim the message is empty. The emptiness
            // check subtracted the whole signature block, so text that had
            // landed inside it was subtracted with it and a visibly non-empty
            // message was reported as having no text.
            await page.locator(`${DOCK} .ts-control input`).first().fill("sig-caret@example.test");
            await page.locator(`${DOCK} .ts-control input`).first().press("Enter");
            await page
                .locator(`${DOCK} [data-compose--compose-target="subject"]`)
                .fill("Above the signature");

            await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

            await expect(
                page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`),
            ).toBeHidden();
        } finally {
            await clearAccountSignature(page);
        }
    });

    /** Clicking ON the signature still edits the signature. */
    test("clicking the signature itself still puts the caret in it", async ({ page }) => {
        await setAccountSignature(page, "SIG-OUTLOOK Paul");

        try {
            await openCompose(page);

            const editor = page.locator(`${DOCK} ${EDITOR}`);
            const signature = editor.locator(SIGNATURE);

            await expect(signature).toBeVisible();
            await signature.click();
            await page.keyboard.type("!");

            await expect(signature).toContainText("!");
        } finally {
            await clearAccountSignature(page);
        }
    });
});

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

        try {
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

            await expect(editor).toContainText(
                "A paragraph I do not want to lose.",
            );
            await expect(editor.locator(SIGNATURE)).toHaveCount(1);
        } finally {
            await clearAccountSignature(page);
        }
    });

    /**
     * Clicking the button twice is a thing people do. It must not leave two
     * sign-offs behind — the block is replaced in place, never appended to.
     */
    test("the signature button never leaves two signatures behind", async ({
        page,
    }) => {
        await setAccountSignature(page, "Kind regards, Ada");

        try {
            await openCompose(page);

            const editor = page.locator(`${DOCK} ${EDITOR}`);
            const button = page.locator(`${DOCK} .fa-pen-nib`).locator("..");

            await button.click();
            await button.click();

            await expect(editor.locator(SIGNATURE)).toHaveCount(1);
        } finally {
            await clearAccountSignature(page);
        }
    });
});

/**
 * Flip plain-text mode from the more-options menu, accepting the
 * lose-your-formatting warning if it stands in the way.
 *
 * Conditional because whether it appears is not this window's decision: the
 * seeded account may carry a signature, which is markup in the body, which is
 * formatting flattening would destroy. A spec that assumed an empty body would
 * pass or fail on whether another file had been there first.
 */
async function togglePlainText(page: Page): Promise<void> {
    await page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..").click();
    await page.getByRole("button", { name: /Plain text mode/i }).click();

    const warning = page.locator(`${DOCK} ${WARNING}`);

    if (await warning.isVisible()) {
        await page.locator(DOCK).getByRole("button", { name: "Continue" }).click();
    }
}

test.describe("compose more options", () => {
    test("plain text mode swaps the editor and keeps the text submittable", async ({
        page,
    }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);
        const plain = page.locator(`${DOCK} ${PLAIN}`);

        await editor.click();
        await page.keyboard.type("Words with no markup.");

        // Through the tolerant helper, which accepts the lose-your-formatting
        // warning only if it appears.
        //
        // This used to insist the warning WAS there, and passed only because
        // the two signature tests above it in this file left "Kind regards,
        // Ada" on the account — markup in the body, hence something to lose.
        // With those tests cleaning up after themselves the body is what this
        // test says it is, "words with no markup", there is correctly nothing
        // to warn about, and the demand for a warning failed. It was an
        // assertion about a leak, not about plain-text mode.
        //
        // Nothing is lost by dropping it here: the warning has three tests of
        // its own below, each of which bolds something first so that there is
        // genuinely formatting at stake.
        await togglePlainText(page);

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

        await menu.click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        // There IS formatting to lose here, so the warning stands in the way —
        // and accepting it is what the rest of this test needs.
        await expect(page.locator(`${DOCK} ${WARNING}`)).toBeVisible();
        await page.locator(DOCK).getByRole("button", { name: "Continue" }).click();

        await menu.click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        await expect(editor).toBeVisible();
        expect(await page.locator(`${DOCK} ${HIDDEN}`).inputValue()).toMatch(
            /<(b|strong)>/i,
        );
    });

    /**
     * The toolbar after a ROUND TRIP through plain text.
     *
     * Enabling plain text folds the format bar away, hides "Aa", and greys the
     * four buttons that can only write into the rich editor. Disabling it used
     * to undo three of those four and leave the format bar at
     * `display: none` for good — a rich-mode window with no formatting bar and
     * no visible reason why. It shipped because the spec that covered this
     * switch only ever went one way.
     *
     * So this one goes both, and asserts the state on the way BACK rather than
     * only on the way in.
     */
    test("leaving plain text brings the whole toolbar back", async ({ page }) => {
        await openCompose(page);

        const bar = page.locator(`${DOCK} [data-compose--compose-target="formatBar"]`);
        const aa = page.locator(DOCK).getByRole("button", { name: "Aa" });
        const rich = page.locator(`${DOCK} [data-compose--compose-target="richOnly"]`);

        // Before: the bar is up (it is shown by default at every width now),
        // "Aa" is there, and every rich-only affordance is live.
        await expect(bar).toBeVisible();
        await expect(aa).toBeVisible();

        const count = await rich.count();
        expect(count).toBeGreaterThanOrEqual(4);

        for (let i = 0; i < count; i++) {
            await expect(rich.nth(i)).toBeEnabled();
        }

        // In.
        await togglePlainText(page);

        await expect(page.locator(`${DOCK} ${PLAIN}`)).toBeVisible();
        await expect(bar).toBeHidden();
        await expect(aa).toBeHidden();

        for (let i = 0; i < count; i++) {
            await expect(rich.nth(i)).toBeDisabled();
        }

        // And out again — the half that was missing.
        await togglePlainText(page);

        await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
        await expect(bar).toBeVisible();
        await expect(aa).toBeVisible();
        await expect(page.locator(`${DOCK} ${HIDDEN}`)).toBeEnabled();

        for (let i = 0; i < count; i++) {
            await expect(rich.nth(i)).toBeEnabled();
        }

        // Visible is not enough — a bar restored to zero height is still a bar
        // nobody can press.
        const box = (await bar.boundingBox())!;
        expect(box.height).toBeGreaterThan(20);
        expect(box.width).toBeGreaterThan(200);
    });

    /**
     * And "Aa" survives the round trip as the user left it: a bar folded away
     * on purpose must not be reopened by a detour through plain text, which is
     * the failure mode of "just show it again on the way out".
     */
    test("a folded formatting bar stays folded across plain-text mode", async ({ page }) => {
        await openCompose(page);

        const bar = page.locator(`${DOCK} [data-compose--compose-target="formatBar"]`);
        const aa = page.locator(DOCK).getByRole("button", { name: "Aa" });

        await aa.click();
        await expect(bar).toBeHidden();

        await togglePlainText(page);
        await expect(page.locator(`${DOCK} ${PLAIN}`)).toBeVisible();

        await togglePlainText(page);
        await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();

        await expect(bar).toBeHidden();

        // Still operable, not merely still hidden.
        await aa.click();
        await expect(bar).toBeVisible();
    });

    /**
     * The warning is an in-app popover now, not `window.confirm()`. Which
     * means it has to be refusable — the browser's dialog was, and a warning
     * that only has a Continue is not a warning.
     */
    test("declining the plain-text warning leaves the formatting alone", async ({
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

        await page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..").click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();

        const warning = page.locator(`${DOCK} ${WARNING}`);
        await expect(warning).toBeVisible();

        // No browser dialog was ever raised: nothing here would have answered
        // one, so a lingering confirm() would hang this test rather than pass it.
        await page.locator(DOCK).getByRole("button", { name: "Keep formatting" }).click();

        await expect(warning).toBeHidden();
        await expect(editor).toBeVisible();
        await expect(page.locator(`${DOCK} ${PLAIN}`)).toBeHidden();
        expect(await page.locator(`${DOCK} ${HIDDEN}`).inputValue()).toMatch(
            /<(b|strong)>/i,
        );
    });

    test("Escape declines the plain-text warning too", async ({ page }) => {
        await openCompose(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);

        await editor.click();
        await page.keyboard.type("emphasis");
        await page.keyboard.press("ControlOrMeta+a");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="boldBtn"]`)
            .click();

        await page.locator(`${DOCK} .fa-ellipsis-vertical`).locator("..").click();
        await page.getByRole("button", { name: /Plain text mode/i }).click();
        await expect(page.locator(`${DOCK} ${WARNING}`)).toBeVisible();

        await page.keyboard.press("Escape");

        await expect(page.locator(`${DOCK} ${WARNING}`)).toBeHidden();
        await expect(page.locator(`${DOCK} ${PLAIN}`)).toBeHidden();
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
