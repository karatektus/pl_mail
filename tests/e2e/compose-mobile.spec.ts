import { test, expect, devices, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { lowerKeyboard, raiseKeyboard } from "./support/keyboard";

/**
 * The compose window on a phone.
 *
 * Everything here is about the three things the desktop dock got wrong below
 * `md`: the window has to be the whole screen, the bottom row has to sit above
 * the virtual keyboard rather than under it, and no control may be off screen
 * or clipped.
 *
 * The keyboard itself cannot be raised by any gesture Playwright has —
 * Chromium's headless visual viewport never shrinks — but the shape one leaves
 * behind can be staged, and support/keyboard.ts does that: the real visual
 * viewport, reporting a height short of the layout viewport's, announcing it
 * with a `resize`. What the window does about it is then testable directly.
 * The dock card's answer on a tablet, which is a different one, is in
 * compose-keyboard.spec.ts.
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

/** How much screen the staged keyboard takes. Deep enough to be unambiguous. */
const KEYBOARD = 400;

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

    test("gives the keyboard the height it takes", async ({ page }) => {
        await openCompose(page);

        await raiseKeyboard(page, KEYBOARD);

        const measured = await page.evaluate(() => {
            const el = document.querySelector("#compose_dock .compose-window") as HTMLElement;

            return {
                height: el.getBoundingClientRect().height,
                visual: window.visualViewport!.height,
            };
        });

        // The test above proves the window is *wired* to the visual viewport.
        // This one shrinks that viewport and watches the window follow, which
        // is the behaviour the wiring exists for.
        expect(Math.abs(measured.height - measured.visual)).toBeLessThan(2);
    });

    /**
     * `env(safe-area-inset-bottom)` describes the LAYOUT viewport, which the
     * keyboard does not touch, so it goes on reporting the home indicator's
     * 34px. Against a window sized to the VISUAL viewport — whose bottom edge
     * IS the top of the keyboard — those 34px stop being clearance and become a
     * dead strip of window between the action bar and the keys. That was the
     * gap reported on an iPhone; Android reports no inset here, which is the
     * other half of why it looked right there.
     */
    test("stops reserving the home indicator while the keyboard covers it", async ({ page }) => {
        await openCompose(page);

        const paddingBottom = () =>
            page.evaluate(
                () =>
                    (document.querySelector("#compose_dock .compose-window") as HTMLElement)
                        .style.paddingBottom,
            );

        // The INLINE style, not the computed one. Chromium reports no safe area
        // at all, so the padding this drops computes to 0 here and the strip is
        // invisible in this browser — the override is the mechanism under test,
        // and on an iPhone it is 34px of window.
        expect(await paddingBottom()).toBe("");

        await raiseKeyboard(page, KEYBOARD);
        expect(await paddingBottom()).toBe("0px");

        // And handed back, or the action bar sits under the home indicator for
        // the rest of the session once the keyboard drops.
        await lowerKeyboard(page);
        expect(await paddingBottom()).toBe("");
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

    /**
     * ONE Send, it is the pill, and on a phone it is in the HEADER.
     *
     * Three shapes in three rounds. First: a plain Send icon in the header,
     * because the action bar was a sideways scroller Send could be scrolled out
     * of — so a phone could send but never schedule. Then: the icon deleted and
     * the pill rendered at every width, which fixed scheduling but moved Send
     * to the bottom of the screen. Now: the pill itself in the header, where
     * the icon was, with the action-bar copy `hidden md:flex` behind it.
     *
     * The invariant that survived all three is the one asserted first —
     * EXACTLY ONE Send on screen. `getByRole` matches the accessibility tree,
     * so the copy that CSS is hiding does not count; a count of two here means
     * a breakpoint class went missing and the phone grew two Sends again.
     */
    test("carries the send pill in the header, with nothing duplicating it", async ({ page }) => {
        await openCompose(page);

        const send = page.locator(composeWindow).getByRole("button", { name: "Send", exact: true });

        await expect(send).toHaveCount(1);
        await expect(send).toBeVisible();

        const box = (await send.boundingBox())!;
        const viewport = page.viewportSize()!;

        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);

        // A thumb target, not a mouse one.
        expect(box.height).toBeGreaterThanOrEqual(44);

        // In the header: above everything the body block holds. The pill the
        // action bar renders is the other one, and it is hidden here.
        const bodyBox = (await page
            .locator(`${composeWindow} [data-compose--compose-target="collapsible"]`)
            .boundingBox())!;

        expect(
            box.y + box.height,
            JSON.stringify({ send: box, body: bodyBox }),
        ).toBeLessThanOrEqual(bodyBox.y + 0.5);

        await expect(page.locator(`${composeWindow} [data-compose-send-pill="header"]`)).toBeVisible();
        await expect(page.locator(`${composeWindow} [data-compose-send-pill="bar"]`)).toBeHidden();

        // And the chevron beside it, which is what makes it a send PILL rather
        // than a send button.
        const chevron = page.locator(composeWindow).getByRole("button", { name: "Send options" });

        await expect(chevron).toHaveCount(1);
        await expect(chevron).toBeVisible();

        const chevronBox = (await chevron.boundingBox())!;

        expect(chevronBox.width).toBeGreaterThanOrEqual(44);
        expect(chevronBox.height).toBeGreaterThanOrEqual(44);
    });

    /**
     * The rest of the header has to survive the pill.
     *
     * It carries a back arrow, a title and a save status already, and 320px is
     * the narrow end. The rule is that the pill is the thing that does NOT
     * yield: the title truncates and the save status is capped, because a Send
     * pushed off the row is the bug, and a shortened title is not.
     */
    test("keeps the whole header on the row at 320px, pill included", async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 568 });
        await openCompose(page);

        // Something in the status, so it is competing for the row rather than
        // being an empty span.
        await page.locator('[data-compose--compose-target="subject"]').fill("A subject");

        const measured = await page.evaluate(() => {
            const win  = document.querySelector("#compose_dock .compose-window") as HTMLElement;
            const pill = win.querySelector<HTMLElement>("[data-compose-send-pill='header']")!;
            // `select-none` is the header row itself; the pill sits two levels
            // down inside it, in the group that also holds the save status.
            const header = pill.closest<HTMLElement>("div.select-none")!;
            const box = (el: Element) => {
                const r = el.getBoundingClientRect();

                return { x: r.x, right: r.right, width: r.width, height: r.height };
            };

            return {
                header: box(header),
                pill:   box(pill),
                back:   box(header.querySelector("button")!),
                scrollWidth: header.scrollWidth,
                clientWidth: header.clientWidth,
            };
        });

        const json = JSON.stringify(measured);

        // The pill is whole and on screen …
        expect(measured.pill.x, json).toBeGreaterThanOrEqual(0);
        expect(measured.pill.right, json).toBeLessThanOrEqual(320 + 0.5);
        expect(measured.pill.width, json).toBeGreaterThan(80);

        // … the back arrow, which is the first button on the row, is still
        // reachable at the other end …
        expect(measured.back.x, json).toBeGreaterThanOrEqual(0);
        expect(measured.back.width, json).toBeGreaterThan(8);

        // … and the row has not overflowed to fit them both. This is the check
        // that fails when the title stops truncating.
        expect(measured.scrollWidth, json).toBeLessThanOrEqual(measured.clientWidth + 1);

        // The header must not have eaten the screen to hold a 44px pill either.
        expect(measured.header.height, json).toBeLessThan(80);
    });

    // Dock chrome. There is no dock to minimize into and nothing to expand to.
    test("hides the minimize and expand controls", async ({ page }) => {
        await openCompose(page);

        const window = page.locator(composeWindow);
        await expect(window.getByRole("button", { name: "Minimize" })).toBeHidden();
        await expect(window.getByRole("button", { name: "Expand" })).toBeHidden();
    });

    // Shown by default now, on a phone as on a desktop. It used to start folded
    // here and had to be found behind "Aa" — a bar most people never met.
    test("shows the formatting bar by default, and Aa still folds it away", async ({ page }) => {
        await openCompose(page);

        const bar = page.locator('[data-compose--compose-target="formatBar"]');
        await expect(bar).toBeVisible();

        // Visible AND drawn — a zero-height bar is also "visible" to a spec
        // that only asks whether it is displayed.
        const box = (await bar.boundingBox())!;
        expect(box.height).toBeGreaterThan(20);
        expect(box.width).toBeGreaterThan(200);

        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).click();
        await expect(bar).toBeHidden();

        await page.locator(composeWindow).getByRole("button", { name: "Aa" }).click();
        await expect(bar).toBeVisible();
    });

    /**
     * Neither toolbar row may scroll sideways — the user's words, and the
     * reason two of last round's bugs existed. `overflow-x: auto` computes
     * `overflow-y: auto` with it, which is what clipped the more-options menu
     * to nothing; and a control parked past the right edge of a strip nobody
     * thinks to swipe is a control that is not there.
     *
     * Asserted as the computed `overflow-x` rather than as scrollWidth: it is
     * the PROPERTY that does the damage — `auto` on one axis computes `auto` on
     * the other, which is what clipped the more-options menu — and scrollWidth
     * also flags the sr-only spans and the collapsed native <select>s that the
     * enhanced selects hide behind, neither of which is a toolbar that scrolls.
     */
    test("never scrolls either toolbar row sideways", async ({ page }) => {
        await openCompose(page);

        const scrollers = await page.evaluate(() => {
            // The toolbar block: the format bar, the action bar and everything
            // in them. Checked wholesale rather than row by row, because a
            // scroller reintroduced anywhere inside it is the same bug.
            const block = document
                .querySelector("#compose_dock .compose-window")!
                .querySelector<HTMLElement>('[data-compose--compose-target="formatBar"]')!
                .parentElement!;

            return [block, ...block.querySelectorAll<HTMLElement>("*")]
                .filter((el) => {
                    const x = getComputedStyle(el).overflowX;

                    return "auto" === x || "scroll" === x;
                })
                .map((el) => String(el.className).slice(0, 70));
        });

        expect(scrollers, JSON.stringify(scrollers)).toEqual([]);
    });

    // Every icon reachable without a gesture, because the row wraps. Redo is
    // the far end of the format bar and Delete draft the far end of the icon
    // row; if either is off screen, something is scrolling that should wrap.
    test("keeps both ends of both toolbar rows on screen", async ({ page }) => {
        await openCompose(page);

        const viewport = page.viewportSize()!;

        for (const name of ["Redo", "Delete draft"]) {
            const button = page.locator(composeWindow).getByRole("button", { name });

            await expect(button).toBeVisible();

            const box = (await button.boundingBox())!;

            expect(box.x, name).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width, name).toBeLessThanOrEqual(viewport.width + 0.5);
            expect(box.y, name).toBeGreaterThanOrEqual(0);
            expect(box.y + box.height, name).toBeLessThanOrEqual(viewport.height + 0.5);
            expect(box.width, name).toBeGreaterThan(8);
            expect(box.height, name).toBeGreaterThan(8);
        }
    });

    /**
     * The whole point of Task 5 plus Task 3 together: a visible format bar, a
     * wrapped icon row and a send pill are a lot of furniture, and the body has
     * to survive it on the shortest phone anyone still carries.
     *
     * 568px is the iPhone SE. The number asserted is deliberately modest — this
     * is a floor, not a target — but it is a number, so the day the toolbar
     * grows a third row this fails instead of shipping a 20px writing slot.
     */
    test("leaves the body usable at 320x568, with all the toolbar furniture up", async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 568 });
        await openCompose(page);

        await expect(page.locator('[data-compose--compose-target="formatBar"]')).toBeVisible();

        const measured = await page.evaluate(() => {
            const win = document.querySelector("#compose_dock .compose-window") as HTMLElement;
            const scroller = win.querySelector(
                '[data-compose--compose-target="scroller"]',
            ) as HTMLElement;
            const bar = win.querySelector(
                '[data-compose--compose-target="formatBar"]',
            ) as HTMLElement;

            return {
                window: win.getBoundingClientRect().height,
                body: scroller.getBoundingClientRect().height,
                toolbar: bar.parentElement!.getBoundingClientRect().height,
                formatBar: bar.getBoundingClientRect().height,
            };
        });

        // Reported in the failure message, because the useful thing about this
        // test is the number and not the boolean.
        expect(measured.body, JSON.stringify(measured)).toBeGreaterThanOrEqual(120);

        // And the furniture has not eaten the window: the toolbar block is a
        // minority of the screen.
        expect(measured.toolbar, JSON.stringify(measured)).toBeLessThan(measured.window / 2);
    });

    // Address rows and the editor share one scroll region, so the recipients
    // can be scrolled out of the way when the keyboard is up — while the
    // header and the action bar, which are outside it, stay put.
    test("scrolls the recipient rows with the body", async ({ page }) => {
        await openCompose(page);

        const scroller = page.locator('[data-compose--compose-target="scroller"]');
        const fields = page.locator('[data-compose--compose-target="fields"]');
        const send = page.locator(composeWindow).getByRole("button", { name: "Send", exact: true });

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

        test("carries the send pill, schedule chevron and all, in its header", async ({ page }) => {
            await openReply(page);

            const send = page
                .locator(composeWindow)
                .getByRole("button", { name: "Send", exact: true });

            // One, as everywhere else, and it is the header copy. A reply
            // below md is a DOCK window (compose--frame-target rewrites the
            // frame), so this is the same header as composing — which is the
            // point: replying from a phone gets the same window.
            await expect(send).toHaveCount(1);
            await expect(send).toBeInViewport();
            await expect(
                page.locator(composeWindow).getByRole("button", { name: "Send options" }),
            ).toBeInViewport();

            await expect(
                page.locator(`${composeWindow} [data-compose-send-pill="header"]`),
            ).toBeVisible();

            const sendBox = (await send.boundingBox())!;
            const bodyBox = (await page
                .locator(`${composeWindow} [data-compose--compose-target="collapsible"]`)
                .boundingBox())!;

            expect(
                sendBox.y + sendBox.height,
                JSON.stringify({ send: sendBox, body: bodyBox }),
            ).toBeLessThanOrEqual(bodyBox.y + 0.5);
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

    /**
     * The emoji panel is centred on the compose WINDOW, not anchored to its
     * button — the button sits in a row that wraps, so its x depends on how
     * many icons fitted on the line above it, and a panel hung off it lands
     * wherever that happened to be.
     */
    test("centres the emoji picker on the window", async ({ page }) => {
        await openCompose(page);

        await page.locator(composeWindow).getByRole("button", { name: "Insert emoji" }).click();

        const panel = page.locator(`${composeWindow} .emoji-panel`);
        await expect(panel).toBeVisible();
        await expect(panel).toBeInViewport();

        const box = (await panel.boundingBox())!;
        const win = (await page.locator(composeWindow).boundingBox())!;

        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(win.x + win.width + 0.5);
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(win.y + win.height + 0.5);

        // Centred: the two margins match, within a pixel of rounding.
        const left = box.x - win.x;
        const right = win.x + win.width - (box.x + box.width);

        expect(Math.abs(left - right), JSON.stringify({ left, right })).toBeLessThanOrEqual(1);
    });

    /**
     * EVERY popover the toolbar can open, measured against the viewport at two
     * phone widths.
     *
     * The last round shipped an emoji panel that was off screen on a phone
     * while this file passed, because the spec asserted the panel EXISTED.
     * Existence is not usability: a panel whose left edge is at -140 is
     * visible to `toBeVisible()`, present to `toHaveCount(1)`, and useless to
     * the person holding the phone. So these assert geometry — the box is
     * inside the viewport on both axes — and they do it at 320px as well as
     * 393px, because the narrow end is where a fixed `w-64` stops fitting.
     *
     * The reason any of it works: none of these are anchored to their button.
     * Every one of them anchors to the toolbar BLOCK, which spans the window —
     * the buttons themselves sit in rows that wrap, so a menu hung off one
     * lands wherever that button happened to wrap to, which at 320px is
     * routinely past the right edge.
     *
     * The send-options menu is in this list for the first time. It was
     * `hidden md:flex` before, so the phone path had never once been exercised.
     */
    test.describe("popovers stay on screen", () => {
        const cases: { name: string; open: (page: Page) => Promise<void>; panel: string }[] = [
            {
                name: "the emoji picker",
                panel: `${composeWindow} .emoji-panel`,
                open: async (page) => {
                    await page.locator(composeWindow)
                        .getByRole("button", { name: "Insert emoji" }).click();
                },
            },
            {
                name: "the link popover",
                panel: '[data-compose--compose-toolbar-target="linkPopover"]',
                open: async (page) => {
                    await page.locator('[data-compose--compose-toolbar-target="editor"]').click();
                    await page.locator(composeWindow)
                        .getByRole("button", { name: "Insert link" }).click();
                },
            },
            {
                name: "the more-options menu",
                // By what is inside it, not by position: the window holds
                // several dropdown menus and the send-options one is hidden at
                // this width, so `.first()` would silently measure that.
                panel: '[data-ui--dropdown-target="menu"]:has([data-compose--compose-target="plainToggle"])',
                open: async (page) => {
                    await page.locator(composeWindow)
                        .getByRole("button", { name: "More options" }).click();
                },
            },
            {
                name: "the send-options menu",
                // Named by placement, because there are two schedule menus in
                // the DOM now — the header pill's and the action bar's — and at
                // these widths it is the header's that is on screen. It also
                // opens the other way: `top-full`, downward, since a header
                // pill with `bottom-full` opens off the top of the phone.
                panel: '[data-compose-send-pill="header"] [data-ui--dropdown-target="menu"]',
                open: async (page) => {
                    await page.locator(composeWindow)
                        .getByRole("button", { name: "Send options" }).click();
                },
            },
            {
                name: "the plain-text warning",
                panel: '[data-compose--compose-target="plainWarning"]',
                open: async (page) => {
                    const editor = page.locator('[data-compose--compose-toolbar-target="editor"]');

                    await editor.click();
                    await page.keyboard.type("emphasis");
                    await page.keyboard.press("ControlOrMeta+a");
                    // No "Aa" first: the format bar is up from the start now,
                    // and pressing Aa here would fold Bold away rather than
                    // reveal it.
                    await page.locator(composeWindow).getByRole("button", { name: "Bold" }).click();

                    await page.locator(composeWindow)
                        .getByRole("button", { name: "More options" }).click();
                    await page.getByRole("button", { name: /Plain text mode/i }).click();
                },
            },
        ];

        for (const width of [393, 320]) {
            for (const { name, open, panel } of cases) {
                test(`${name} at ${width}px`, async ({ page }) => {
                    await page.setViewportSize({ width, height: 851 });
                    await openCompose(page);

                    await open(page);

                    const target = page.locator(panel).first();
                    await expect(target).toBeVisible();

                    const box = (await target.boundingBox())!;

                    expect(box.x).toBeGreaterThanOrEqual(0);
                    expect(box.x + box.width).toBeLessThanOrEqual(width + 0.5);
                    expect(box.y).toBeGreaterThanOrEqual(0);
                    expect(box.y + box.height).toBeLessThanOrEqual(851 + 0.5);
                    // And wide enough to be worth opening — a 0px-tall clipped
                    // box also satisfies "inside the viewport".
                    expect(box.width).toBeGreaterThan(120);
                    expect(box.height).toBeGreaterThan(40);
                });
            }
        }
    });

    test("returns to the mailbox from the back arrow", async ({ page }) => {
        await openCompose(page);

        await page.locator(composeWindow)
            .getByRole("button", { name: "Save draft and close" })
            .click();

        await expect(page.locator(composeWindow)).toBeHidden();
    });
});
