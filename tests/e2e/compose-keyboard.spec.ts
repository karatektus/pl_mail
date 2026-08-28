import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { lowerKeyboard, pinchZoom, publishedInset, raiseKeyboard } from "./support/keyboard";

/**
 * The dock compose window and the on-screen keyboard, on a tablet.
 *
 * A `fixed` element is laid out against the LAYOUT viewport, and iOS does not
 * shrink that one when the keyboard comes up — only the visual viewport. So the
 * dock went on sitting `bottom: 1rem` from a screen edge that was now behind a
 * keyboard, and the window's bottom rows — the action bar, Send — were under
 * it. Reported from an iPad; Android never had it, because
 * `interactive-widget=resizes-content` shrinks its layout viewport too, which
 * is why the two viewports have to be compared rather than assumed equal.
 *
 * The fix is in two halves and each is tested here: the dock moves up by the
 * height of the keyboard, and the card caps its height against what is left, so
 * that moving up does not carry its title bar off the top instead.
 *
 * The phone half of the same fault — a fullscreen window that fits the keyboard
 * but still reserves the home indicator behind it — is in
 * compose-mobile.spec.ts, with the rest of the phone window.
 *
 * On staging a keyboard Chromium does not have, see support/keyboard.ts.
 */

const dock = "#compose_dock";
const composeWindow = `${dock} .compose-window`;

/** How much screen the staged keyboard takes. Deep enough to be unambiguous. */
const KEYBOARD = 400;

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).click();
    await expect(page.locator(composeWindow)).toBeVisible();
}

test.describe("compose on a tablet", () => {
    // An iPad's portrait width — above md, so this is the dock card and not the
    // fullscreen phone window. The whole point: wide enough that nothing in the
    // mobile path runs, and the width the report came from.
    test.use({ viewport: { width: 834, height: 1194 } });

    test("publishes the keyboard's height, and nothing while it is down", async ({ page }) => {
        await openCompose(page);

        // Present and zero rather than absent: the property is written on every
        // viewport event, so a window that never wrote it is a window whose
        // tracking never started.
        expect(await publishedInset(page)).toBe("0px");

        await raiseKeyboard(page, KEYBOARD);
        expect(await publishedInset(page)).toBe(`${KEYBOARD}px`);

        await lowerKeyboard(page);
        expect(await publishedInset(page)).toBe("0px");
    });

    /**
     * KEYBOARD_INSET_MIN, pinned from both sides. Without the lower case the
     * floor can be deleted and nothing notices; without the upper one it can be
     * RAISED — say to 80, to quell a browser toolbar somebody saw twitching —
     * and that silently drops iOS's bare accessory bar, the ~55px an external
     * keyboard on an iPad leaves behind, which the constant's own comment says
     * has to count. Both directions stayed green across 606 tests before this.
     */
    test("ignores a movement too small to be a keyboard, and answers one barely big enough", async ({ page }) => {
        await openCompose(page);

        await raiseKeyboard(page, 12);
        expect(await publishedInset(page)).toBe("0px");

        await raiseKeyboard(page, 55);
        expect(await publishedInset(page)).toBe("55px");
    });

    /**
     * Pinch zoom is not a keyboard, and the arithmetic cannot tell them apart:
     * `innerHeight` ignores the scale, `visualViewport.height` does not, so
     * layout-minus-visual reports half the screen as keyboard with none on it.
     * Panning then varies the phantom, and both `resize` and `scroll` are
     * subscribed — so the card would have resized continuously under the
     * user's finger. Not correcting is the answer; the viewport is theirs while
     * they are holding it.
     */
    test("does not mistake pinch zoom for a keyboard", async ({ page }) => {
        await openCompose(page);

        const before = (await page.locator(composeWindow).boundingBox())!;

        await pinchZoom(page, { scale: 2 });
        expect(await publishedInset(page)).toBe("0px");

        // And panning the zoomed viewport does not start it moving either.
        await pinchZoom(page, { scale: 2, offsetTop: 300 });
        expect(await publishedInset(page)).toBe("0px");

        const after = (await page.locator(composeWindow).boundingBox())!;
        expect(after.y).toBeCloseTo(before.y, 0);
        expect(after.height).toBeCloseTo(before.height, 0);
    });

    /**
     * `--keyboard-inset` is one property on <html> written by every compose
     * window, and above md two are open at once by design — a dock window and
     * an inline reply. The inline frame can be emptied with no gesture at all:
     * _settleSend() does it from a timer 8 seconds after a send, keyboard
     * untouched. A window that cleared the property on its way out would drop
     * the dock back under the keyboard mid-sentence, with nothing to put it
     * back — _trackViewport runs on viewport events, and the keyboard is still
     * up, so no resize and no scroll is coming.
     */
    test("keeps the lift when a second compose window closes under it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await expect(page.locator(`#compose_inline .compose-window`)).toBeVisible();

        await page.getByRole("link", { name: "Compose" }).click();
        await expect(page.locator(composeWindow)).toBeVisible();

        await raiseKeyboard(page, KEYBOARD);
        expect(await publishedInset(page)).toBe(`${KEYBOARD}px`);

        const lifted = (await page.locator(composeWindow).boundingBox())!;

        // Emptied rather than dismissed, because that is what the timer does:
        // a turbo-stream `update` with an empty template, no blur, no keyboard
        // movement, nothing that would fire a viewport event afterwards.
        await page.evaluate(() => {
            document.querySelector("#compose_inline")!.innerHTML = "";
        });
        await expect(page.locator(`#compose_inline .compose-window`)).toHaveCount(0);

        expect(await publishedInset(page)).toBe(`${KEYBOARD}px`);

        const after = (await page.locator(composeWindow).boundingBox())!;
        expect(after.y).toBeCloseTo(lifted.y, 0);
    });

    test("lifts the window clear of the keyboard", async ({ page }) => {
        await openCompose(page);

        const before = (await page.locator(composeWindow).boundingBox())!;

        await raiseKeyboard(page, KEYBOARD);

        const after = (await page.locator(composeWindow).boundingBox())!;
        const layout = await page.evaluate(() => window.innerHeight);

        // The bottom row — Send, the action bar — is above the keyboard, which
        // is the entire report. It sat at `layout - 32` before.
        expect(after.y + after.height).toBeLessThanOrEqual(layout - KEYBOARD);
        expect(before.y + before.height).toBeGreaterThan(layout - KEYBOARD);
    });

    test("puts the window back when the keyboard goes away", async ({ page }) => {
        await openCompose(page);

        const before = (await page.locator(composeWindow).boundingBox())!;

        await raiseKeyboard(page, KEYBOARD);
        await lowerKeyboard(page);

        const after = (await page.locator(composeWindow).boundingBox())!;

        expect(after.y).toBeCloseTo(before.y, 0);
        expect(after.height).toBeCloseTo(before.height, 0);
    });

    test("drops the correction when the window closes", async ({ page }) => {
        await openCompose(page);
        await raiseKeyboard(page, KEYBOARD);

        expect(await publishedInset(page)).toBe(`${KEYBOARD}px`);

        // Closed, the dock is empty — a lift left behind would push the next
        // window that opens, and the failure card the dock controller draws,
        // up over a keyboard that is not there. Nothing was typed, so "save and
        // close" leaves no draft behind either.
        await page.locator(dock).getByRole("button", { name: "Save draft and close" }).click();
        await expect(page.locator(composeWindow)).toBeHidden();

        expect(await publishedInset(page)).toBe("");
    });
});

test.describe("compose on a tablet held sideways", () => {
    // The same iPad turned over. Landscape is where the second half of the fix
    // earns its keep: 90vh of 834px is 750, so a 350px keyboard leaves less room
    // than the card's own 600px and the lift alone would carry its title bar off
    // the top of the screen. Portrait never gets there — 90vh of 1194 less an
    // even deeper keyboard is still over 600 — which is why the cap needs a test
    // of its own rather than a line in the one above.
    test.use({ viewport: { width: 1194, height: 834 } });

    test("shrinks rather than climbing off the top of the screen", async ({ page }) => {
        await openCompose(page);

        const before = (await page.locator(composeWindow).boundingBox())!;

        await raiseKeyboard(page, 350);

        const after = (await page.locator(composeWindow).boundingBox())!;

        expect(after.height).toBeLessThan(before.height);
        // Uncapped this is about -150: the top of the window, and every control
        // in its header, off the screen.
        expect(after.y).toBeGreaterThanOrEqual(0);
    });
});

test.describe("compose on a phone held sideways", () => {
    /**
     * 844x390 is an iPhone 15 in landscape — and it is WIDER than md, so it
     * gets the dock card rather than the fullscreen window. A Pro Max is
     * 926x428 and a mini 780x360; the whole class lands here.
     *
     * There is no arrangement that fits a compose window and a 200px keyboard
     * into 390px of screen, and this does not pretend otherwise. What it pins
     * is that the fix does not make that case WORSE: capping the card at
     * `90vh - keyboard` with no floor computed 151px against ~210px of header
     * and action bar, and a box shorter than its `shrink-0` children does not
     * shrink them — it spills them out of its bottom edge, past the painted
     * card, while the scroller between them (From, To, Subject and the message
     * share one scroll region) collapsed to 0px. Everything you would type
     * into, gone.
     */
    test.use({ viewport: { width: 844, height: 390 } });

    test("keeps the whole card on screen with something left to write in", async ({ page }) => {
        await openCompose(page);

        await raiseKeyboard(page, 200);

        const card = (await page.locator(composeWindow).boundingBox())!;
        const scroller = (await page
            .locator(`${dock} [data-compose--compose-target="scroller"]`)
            .boundingBox())!;
        const layout = await page.evaluate(() => window.innerHeight);

        // Top and bottom both on screen: the clamp on the lift is what buys
        // the top edge, the floor under the cap the bottom one.
        expect(card.y).toBeGreaterThanOrEqual(0);
        expect(card.y + card.height).toBeLessThanOrEqual(layout);

        // And the card is still a compose window rather than a stack of
        // furniture: the region holding the recipients and the message has
        // real height.
        expect(scroller.height).toBeGreaterThan(60);
    });
});
