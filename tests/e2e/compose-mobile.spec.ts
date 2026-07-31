import { test, expect, devices, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The compose window on a phone.
 *
 * Everything here is about the three things the desktop dock got wrong below
 * `md`: the window has to be the whole screen, the bottom row has to sit above
 * the virtual keyboard rather than under it, and no control may be off screen
 * or clipped.
 *
 * The keyboard itself cannot be raised from Playwright — Chromium's headless
 * visual viewport never shrinks. What *is* checkable, and what actually makes
 * the keyboard behave, is that the window takes its height from
 * `window.visualViewport` instead of the layout viewport: once that holds, a
 * shrinking visual viewport shrinks the window by construction.
 *
 * These specs never type five characters into the body, so the autosave never
 * mints a draft and the suite leaves nothing behind to clean up.
 */
test.use({ ...devices["Pixel 5"] });

const dock = "#compose_dock";
const composeWindow = `${dock} .compose-window`;
/** Either window — the dock's or an inline reply's — since both go fullscreen. */
const anyComposeWindow = ".compose-window";

test.beforeAll(() => {
    seed("seed-mail");
});

/** Open the dock compose window on a phone-sized viewport. */
async function openCompose(page: Page) {
    await page.goto("/mail/inbox");
    // The sidebar is behind the burger at this width.
    await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
    await page.getByRole("link", { name: "Compose" }).click();
    await expect(page.locator(composeWindow)).toBeVisible();
}

/**
 * Open a reply. Above md this renders inline at the foot of the thread; below
 * md `compose--frame-target` sends it to the dock instead, so the fullscreen
 * window is a child of <body> rather than of the backdrop-filtered thread
 * pane, which would otherwise become its containing block.
 */
async function openReply(page: Page) {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(composeWindow)).toBeVisible();
}

test.describe("mobile compose window", () => {
    test("fills the screen", async ({ page }) => {
        await openCompose(page);

        const box = await page.locator(composeWindow).boundingBox();
        const viewport = page.viewportSize()!;

        expect(box).not.toBeNull();
        expect(box!.x).toBe(0);
        expect(box!.y).toBe(0);
        expect(box!.width).toBe(viewport.width);
    });

    // The whole point of the rework: a `100dvh` window measures against the
    // layout viewport, which the keyboard does not shrink, so its bottom row
    // ends up underneath the keyboard.
    test("takes its height from the visual viewport, not the layout one", async ({ page }) => {
        await openCompose(page);

        const measured = await page.evaluate(() => {
            const el = document.querySelector("#compose_dock .compose-window") as HTMLElement;

            return {
                height: el.getBoundingClientRect().height,
                visual: window.visualViewport!.height,
                inlineHeight: el.style.height,
            };
        });

        // Set as an inline style by the controller — proof it is tracking the
        // visual viewport rather than inheriting a CSS height.
        expect(measured.inlineHeight).not.toBe("");
        expect(Math.abs(measured.height - measured.visual)).toBeLessThan(2);
    });

    test("covers the visual viewport on both axes, with nothing showing past its edges", async ({ page }) => {
        await openCompose(page);

        // A window that tracked only the vertical axis left a strip of the page
        // visible down the right edge as well as above the keyboard, because
        // the browser can pan and scale the visual viewport horizontally too.
        const measured = await page.evaluate(() => {
            const el = document.querySelector("#compose_dock .compose-window") as HTMLElement;
            const box = el.getBoundingClientRect();

            return {
                width: box.width,
                height: box.height,
                right: box.right,
                bottom: box.bottom,
                visualWidth: window.visualViewport!.width,
                visualHeight: window.visualViewport!.height,
                inlineWidth: el.style.width,
            };
        });

        expect(measured.inlineWidth).not.toBe("");
        expect(measured.width).toBeGreaterThanOrEqual(measured.visualWidth);
        expect(measured.height).toBeGreaterThanOrEqual(measured.visualHeight);

        // And it reaches the far edges rather than stopping short of them.
        expect(measured.right).toBeGreaterThanOrEqual(measured.visualWidth - 0.5);
        expect(measured.bottom).toBeGreaterThanOrEqual(measured.visualHeight - 0.5);
    });

    test("keeps Send in the header, on screen", async ({ page }) => {
        await openCompose(page);

        const send = page.locator(composeWindow).getByRole("button", { name: "Send" });
        await expect(send).toBeVisible();

        const box = (await send.boundingBox())!;
        const viewport = page.viewportSize()!;

        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    });

    // Dock chrome. There is no dock to minimize into and nothing to expand to.
    test("hides the minimize and expand controls", async ({ page }) => {
        await openCompose(page);

        const window = page.locator(composeWindow);
        await expect(window.getByRole("button", { name: "Minimize" })).toBeHidden();
        await expect(window.getByRole("button", { name: "Expand" })).toBeHidden();
    });

    test("folds the formatting bar away until Aa asks for it", async ({ page }) => {
        await openCompose(page);

        const bar = page.locator('[data-compose--compose-target="formatBar"]');
        await expect(bar).toBeHidden();

        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).click();
        await expect(bar).toBeVisible();

        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).click();
        await expect(bar).toBeHidden();
    });

    // Wrapping the toolbar would eat three rows of a phone screen; clipping it
    // would put Redo out of reach. It scrolls sideways instead.
    test("reaches the far end of the formatting bar", async ({ page }) => {
        await openCompose(page);
        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).click();

        const redo = page.locator(composeWindow).getByRole("button", { name: "Redo" });
        await redo.scrollIntoViewIfNeeded();

        const box = (await redo.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width);
    });

    // Discard sits outside the scroller so it cannot be scrolled away — the
    // one destructive action you should never have to hunt for.
    test("pins Delete draft next to the scrolling icon row", async ({ page }) => {
        await openCompose(page);

        const discard = page.locator(composeWindow).getByRole("button", { name: "Delete draft" });
        await expect(discard).toBeInViewport();

        // Push the icon row to its far end; the trash can must not move with it.
        const before = (await discard.boundingBox())!;
        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).hover();
        await page.mouse.wheel(400, 0);
        const after = (await discard.boundingBox())!;

        expect(after.x).toBeCloseTo(before.x, 0);
        await expect(discard).toBeInViewport();
    });

    // Address rows and the editor share one scroll region, so the recipients
    // can be scrolled out of the way when the keyboard is up — while the
    // header and the action bar, which are outside it, stay put.
    test("scrolls the recipient rows with the body", async ({ page }) => {
        await openCompose(page);

        const scroller = page.locator('[data-compose--compose-target="scroller"]');
        const fields = page.locator('[data-compose--compose-target="fields"]');
        const send = page.locator(composeWindow).getByRole("button", { name: "Send" });

        const fieldsBefore = (await fields.boundingBox())!;
        const sendBefore = (await send.boundingBox())!;

        // Give it something to scroll past, then scroll.
        await page.locator('[data-compose--compose-toolbar-target="editor"]').fill(
            "line\n".repeat(60),
        );
        await scroller.evaluate((el) => { el.scrollTop = 400; });

        expect((await fields.boundingBox())!.y).toBeLessThan(fieldsBefore.y);
        expect((await send.boundingBox())!.y).toBeCloseTo(sendBefore.y, 0);

        // Written into the body, so a draft exists now — take it back out.
        await page.locator(composeWindow).getByRole("button", { name: "Delete draft" }).click();
        await expect(page.locator(composeWindow)).toBeHidden();
    });

    // Replying from a phone gets the same window as composing — there is no
    // room to wedge a compose card into the middle of a thread.
    test.describe("replying", () => {
        test("opens fullscreen instead of as a card in the thread", async ({ page }) => {
            await openReply(page);

            // Nothing rendered into the thread's own frame …
            await expect(page.locator(`#compose_inline ${anyComposeWindow}`)).toHaveCount(0);

            // … and what did render fills the screen.
            const box = (await page.locator(composeWindow).boundingBox())!;
            const viewport = page.viewportSize()!;

            expect(box.x).toBe(0);
            expect(box.y).toBe(0);
            expect(box.width).toBe(viewport.width);
            expect(Math.abs(box.height - viewport.height)).toBeLessThan(2);
        });

        test("keeps the recipient it is answering", async ({ page }) => {
            await openReply(page);

            await expect(
                page.locator(composeWindow).locator(".ts-control .item").first(),
            ).toContainText("@");
        });

        test("carries Send in the header", async ({ page }) => {
            await openReply(page);

            await expect(
                page.locator(composeWindow).getByRole("button", { name: "Send", exact: true }),
            ).toBeInViewport();
        });

        // Above md nothing changes: the reply is still a card at the foot of
        // the conversation.
        test("stays inline on a desktop viewport", async ({ page }) => {
            await page.setViewportSize({ width: 1280, height: 800 });
            await page.goto("/mail/inbox");
            await mailRow(page, INBOX_SUBJECTS.read).click();
            await page.getByRole("link", { name: "Reply", exact: true }).first().click();

            await expect(page.locator(`#compose_inline ${anyComposeWindow}`)).toBeVisible();
            await expect(page.locator(composeWindow)).toHaveCount(0);
        });
    });

    test("returns to the mailbox from the back arrow", async ({ page }) => {
        await openCompose(page);

        await page.locator(composeWindow)
            .getByRole("button", { name: "Save draft and close" })
            .click();

        await expect(page.locator(composeWindow)).toBeHidden();
    });
});
