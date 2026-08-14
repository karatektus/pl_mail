import { test, expect, type Page } from "../support/test";
import { seedUser } from "../support/config";

/**
 * The appearance preview is its own pane now, with a boundary you can move.
 *
 * It was a fixed 19rem column, which is a fine default and a poor answer for
 * anyone who wanted to look at a preview rather than glance at it. It is the
 * same ui--split the calendar pane uses, with the three-position machinery
 * switched off — there is nowhere for this boundary to go but wider or
 * narrower — so what is exercised here is the shared part: drag, persist,
 * restore, arrow keys, double-click reset, and staying out of the way when the
 * preview is not on screen at all.
 *
 * The width is persisted server-side in the settings bag
 * (User::SETTING_APPEARANCE_PREVIEW_WIDTH), so the first paint after a reload
 * is already the right size — a pane that appears at the default and jumps once
 * JavaScript connects is worse than one that does not remember.
 */

const DESKTOP = { width: 1600, height: 1000 };

/**
 * The width where a wide preview squeezes hardest — the narrowest window that
 * still puts the two cards side by side with a full-height row.
 */
const TIGHT = { width: 1280, height: 800 };

/**
 * Wide enough for the preview to actually REACH its maximum.
 *
 * 900 is a ceiling, not a promise: the boundary is clamped against what the two
 * cards have between them minus the controls card's floor, so it is reachable
 * on a window with 900 to spare and stops earlier on one without. At 1600 that
 * arithmetic stops the preview at 694, which is the clamp working rather than
 * the maximum failing — so the maximum needs its own viewport to be tested at.
 */
const WIDE = { width: 1920, height: 1080 };

/** What the controls card is never dragged below (`main-min` on the split row). */
const CONTROLS_FLOOR = 380;

/** The preview's own bounds — User::APPEARANCE_PREVIEW_{MIN,MAX}_WIDTH. */
const PREVIEW_MIN = 240;
const PREVIEW_MAX = 900;

/** A user of this spec's own, so the wizard can be entered without disturbing anyone. */
const WIZARD = { email: "e2e-wizard-host@plmail.test", password: "wizard-host-password" };

const pane = (page: Page) => page.locator('[data-ui--split-target="pane"]');
const handle = (page: Page) => page.locator('[data-ui--split-target="handle"]');

const width = async (page: Page) => Math.round((await pane(page).boundingBox())!.width);

/**
 * Drag the boundary by `dx`, negative being "wider" (the pane is on the right).
 *
 * Still grabbed near the TOP of the handle rather than at its centre, and that
 * is now belt and braces rather than necessity: the row is capped to the scroll
 * port, so the handle's centre is on screen. It used to be some 2700px tall,
 * which is what the grip's old `sticky` was working around.
 */
const drag = async (page: Page, dx: number) => {
    const box = (await handle(page).boundingBox())!;
    const x = box.x + box.width / 2;
    const y = Math.max(box.y + 40, 100);

    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + dx, y, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(500);
};

test.describe("appearance preview pane", () => {
    test.use({ viewport: DESKTOP });

    test.beforeEach(async ({ page }) => {
        const reset = await page.request.post("/settings/appearance/reset");
        expect(reset.ok()).toBe(true);
        await page.goto("/settings?section=appearance");

        // "Reset to defaults" does NOT put the pane back, deliberately: the
        // width lives in the settings bag rather than in the Appearance
        // embeddable, so it is not part of an exported theme and importing
        // somebody else's does not resize your panel. Which means a test that
        // moved the boundary has to put it back the way a person would.
        await handle(page).dblclick();
        await page.waitForTimeout(500);
    });

    test("drags wider and comes back that wide", async ({ page }) => {
        // 19rem, the width the column was fixed at before it could move.
        expect(await width(page)).toBe(304);

        await drag(page, -120);
        const dragged = await width(page);
        expect(dragged).toBeGreaterThan(304);

        await page.waitForTimeout(500);
        await page.reload();

        // Rendered into the first paint from the stored value, so this is the
        // server's answer rather than a controller catching up.
        expect(await width(page)).toBe(dragged);
    });

    test("is reachable without a mouse", async ({ page }) => {
        const separator = page.getByRole("separator", { name: /resize the preview/i });
        await expect(separator).toBeVisible();
        await expect(separator).toHaveAttribute("aria-orientation", "vertical");

        const before = await width(page);

        await separator.focus();
        await page.keyboard.press("ArrowLeft");
        await page.waitForTimeout(400);

        // Left is wider: the pane is on the right of the boundary.
        expect(await width(page)).toBeGreaterThan(before);

        await page.keyboard.press("ArrowRight");
        await page.waitForTimeout(400);
        expect(await width(page)).toBe(before);
    });

    test("double-click puts it back to the default", async ({ page }) => {
        await drag(page, -140);
        expect(await width(page)).toBeGreaterThan(304);

        await handle(page).dblclick();
        await page.waitForTimeout(500);

        expect(await width(page)).toBe(304);
    });

    /**
     * TWO ceilings, and at 1600 it is the second one that stops the drag.
     *
     * The preview's own maximum is 900. What the boundary is actually clamped
     * against is what the two cards have between them minus the controls
     * card's floor — 1074 - 380 here — so it stops at 694. That is the bound
     * that matters and the one worth pinning: a maximum that could always be
     * reached would be a maximum that could squeeze the controls off the page.
     */
    test("stays inside its bounds", async ({ page }) => {
        // Far past the maximum in one throw. It stops, rather than rubber-
        // banding: the band exists to reach the calendar's other two positions
        // and this boundary has none.
        await drag(page, -2000);

        const widest = await width(page);
        expect(widest).toBeGreaterThan(560); // wider than the old maximum
        expect(widest).toBeLessThanOrEqual(PREVIEW_MAX);

        // …and what stopped it was the controls card's floor, which is still
        // standing. Measured with a height too: a 0px-tall box would satisfy a
        // width assertion on its own.
        const controls = (await page.locator('[data-ui--split-target="main"]').boundingBox())!;
        expect(Math.round(controls.width)).toBeGreaterThanOrEqual(CONTROLS_FLOOR);
        expect(controls.height).toBeGreaterThan(400);

        await drag(page, 2000);
        expect(await width(page)).toBe(PREVIEW_MIN);
    });

    /**
     * The new maximum, on a window that can afford it.
     *
     * 900 is the calendar pane's maximum too, deliberately: both are "a pane
     * beside the thing the page is for". The old 560 was a sidebar's limit that
     * outlived the sidebar.
     */
    test("reaches the new maximum on a window with room for it", async ({ page }) => {
        await page.setViewportSize(WIDE);
        await page.waitForTimeout(400);

        await drag(page, -2000);
        expect(await width(page)).toBe(PREVIEW_MAX);

        // Wide as it goes, the controls are still well clear of their floor
        // and nothing in them is clipped by the card's `overflow-hidden`.
        const clipped = await page.evaluate(() => {
            const card = document.querySelector('[data-ui--split-target="main"]') as HTMLElement;

            return { w: Math.round(card.getBoundingClientRect().width), overflowX: card.scrollWidth - card.clientWidth };
        });

        expect(clipped.w).toBeGreaterThanOrEqual(CONTROLS_FLOOR);
        expect(clipped.overflowX).toBe(0);

        // It survives the round trip at that width, which is the part a clamp
        // disagreeing with the server would break.
        await page.waitForTimeout(500);
        await page.reload();
        expect(await width(page)).toBe(PREVIEW_MAX);
    });

    /**
     * The squeeze, at the narrowest window that still splits.
     *
     * A preview dragged as far as it goes here must not take the controls with
     * it. The clamp is what holds, and what it holds is a card wide enough that
     * the segmented groups still read and the theme tiles are still legible —
     * the tiles being the ones that used to break, since their column count was
     * a query on the PANEL and the panel does not narrow when this card does.
     */
    test("keeps the controls usable at 1280 with the preview as wide as it goes", async ({ page }) => {
        await page.setViewportSize(TIGHT);
        await page.waitForTimeout(400);

        await drag(page, -2000);

        const measured = await page.evaluate(() => {
            const card = document.querySelector('[data-ui--split-target="main"]') as HTMLElement;
            const tiles = [...card.querySelectorAll("[data-theme-name]")] as HTMLElement[];
            const segments = [...card.querySelectorAll('[role="radiogroup"] span')] as HTMLElement[];
            const worst = (els: HTMLElement[]) =>
                Math.max(0, ...els.map((el) => el.scrollWidth - el.clientWidth));

            return {
                cardW: Math.round(card.getBoundingClientRect().width),
                cardH: Math.round(card.getBoundingClientRect().height),
                cardClipX: card.scrollWidth - card.clientWidth,
                tileClip: worst(tiles),
                segmentClip: worst(segments),
                docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            };
        });

        expect(measured.cardW).toBeGreaterThanOrEqual(CONTROLS_FLOOR);
        expect(measured.cardH).toBeGreaterThan(300);
        expect(measured.cardClipX).toBe(0);
        expect(measured.tileClip).toBe(0);
        expect(measured.segmentClip).toBe(0);
        expect(measured.docOverflow).toBe(0);
    });

    /**
     * A width stored while a wider window was in front of the user.
     *
     * The server renders the remembered number into the first paint, and 900 of
     * it does not fit beside a usable controls card at 1280. ui--split
     * re-clamps on connect and does NOT write the smaller number back — coming
     * back to the big screen has to give back the pane that was chosen there.
     */
    test("a stored width the window cannot afford is brought back into range", async ({ page }) => {
        await page.setViewportSize(WIDE);
        await page.waitForTimeout(300);
        await drag(page, -2000);
        expect(await width(page)).toBe(PREVIEW_MAX);
        await page.waitForTimeout(500);

        await page.setViewportSize(TIGHT);
        await page.goto("/settings?section=appearance");
        await page.waitForTimeout(600);

        const here = await width(page);
        expect(here).toBeLessThan(PREVIEW_MAX);
        expect(here).toBeGreaterThanOrEqual(PREVIEW_MIN);

        const controls = (await page.locator('[data-ui--split-target="main"]').boundingBox())!;
        expect(Math.round(controls.width)).toBeGreaterThanOrEqual(CONTROLS_FLOOR);

        // Not persisted: the big screen's choice is still the stored one.
        await page.setViewportSize(WIDE);
        await page.goto("/settings?section=appearance");
        await page.waitForTimeout(600);
        expect(await width(page)).toBe(PREVIEW_MAX);
    });

    test("the splitter goes when the preview goes", async ({ page }) => {
        await expect(handle(page)).toBeVisible();
        await expect(pane(page)).toBeVisible();

        // Below the container query, the preview stacks and starts collapsed,
        // and the handle carries its own `hidden @3xl:flex` so it cannot be
        // left behind as a grab target for a boundary with no second side.
        await page.setViewportSize({ width: 900, height: 1000 });
        await page.waitForTimeout(400);

        await expect(handle(page)).toBeHidden();
        await expect(pane(page)).toBeHidden();
    });

    /**
     * TWO CARDS, not one card with a sidebar in it.
     *
     * The distinction is structural and worth asserting as structure: the two
     * are SIBLINGS under the split row, each a bordered card of its own, with
     * the handle in the gap between them rather than inside either.
     *
     * Every box is checked for a real width AND a real height. A containment
     * assertion alone passes for a 0px-tall clipped element, which is exactly
     * the failure a stacking bug produces.
     */
    test("renders as two peer cards with the splitter between them", async ({ page }) => {
        const measured = await page.evaluate(() => {
            const q = (s: string) => document.querySelector(s) as HTMLElement;
            const main = q('[data-ui--split-target="main"]');
            const region = q("#appearance-preview-region");
            const handleEl = q('[data-ui--split-target="handle"]');
            const previewCard = q('[data-ui--split-target="pane"] > section');

            const box = (el: HTMLElement) => {
                const { x, width, height } = el.getBoundingClientRect();

                return { x: Math.round(x), w: Math.round(width), h: Math.round(height) };
            };

            const bordered = (el: HTMLElement) => {
                const style = getComputedStyle(el);

                return parseFloat(style.borderTopWidth) > 0 && parseFloat(style.borderRadius) > 0;
            };

            return {
                siblings: main.parentElement === region.parentElement,
                controlsCard: box(main),
                previewCard: box(previewCard),
                handle: box(handleEl),
                controlsBordered: bordered(main),
                previewBordered: bordered(previewCard),
                // The handle is in the gap, inside neither card.
                handleInsideAnyCard: main.contains(handleEl) || previewCard.contains(handleEl),
            };
        });

        expect(measured.siblings).toBe(true);
        expect(measured.controlsBordered).toBe(true);
        expect(measured.previewBordered).toBe(true);
        expect(measured.handleInsideAnyCard).toBe(false);

        // Real boxes, both of them.
        expect(measured.controlsCard.w).toBeGreaterThan(360);
        expect(measured.controlsCard.h).toBeGreaterThan(400);
        expect(measured.previewCard.w).toBe(304);
        expect(measured.previewCard.h).toBeGreaterThan(200);

        // …and the boundary strictly between them, touching neither.
        const controlsRight = measured.controlsCard.x + measured.controlsCard.w;
        expect(measured.handle.x).toBeGreaterThanOrEqual(controlsRight);
        expect(measured.handle.x + measured.handle.w).toBeLessThanOrEqual(measured.previewCard.x);
        expect(measured.handle.h).toBeGreaterThan(400);
    });

    /**
     * THE SAME HEIGHT, which is the thing that was asked for — and the reason
     * it is not simply "stretch the preview" is asserted here too.
     *
     * Both cards are exactly as tall as the row, the row is exactly as tall as
     * the settings pane's scroll port, and the scroll port does not scroll:
     * whatever the controls card cannot show, IT scrolls, the way .main-pane
     * does for the message list. Stretching the preview alone would have passed
     * the first assertion and produced a 2500px card holding 500px of
     * miniature, so the cap and the absence of a second scrollbar are the
     * assertions that say which of the two happened.
     */
    for (const viewport of [DESKTOP, TIGHT]) {
        test(`the preview is the same height as the controls at ${viewport.width}x${viewport.height}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto("/settings?section=appearance");
            await page.waitForTimeout(500);

            const measured = await page.evaluate(() => {
                const q = (s: string) => document.querySelector(s) as HTMLElement;
                const controls = q('[data-ui--split-target="main"]');
                const preview = q('[data-ui--split-target="pane"] > section');
                const port = controls.closest("[class*=overflow-y-auto]") as HTMLElement;
                const controlsBody = q('[data-ui--split-target="main"] .pane-fill-scroll');
                const miniature = q('[data-ui--split-target="pane"] .pane-fill-grow');
                const h = (el: HTMLElement) => Math.round(el.getBoundingClientRect().height);
                const w = (el: HTMLElement) => Math.round(el.getBoundingClientRect().width);

                return {
                    controlsH: h(controls), previewH: h(preview),
                    controlsW: w(controls), previewW: w(preview),
                    portH: h(port),
                    portScrolls: port.scrollHeight > port.clientHeight + 1,
                    bodyScrolls: controlsBody.scrollHeight > controlsBody.clientHeight + 1,
                    // The card is tall; the miniature inside it grows to match,
                    // rather than the card being mostly empty ground.
                    miniatureH: h(miniature),
                    previewPosition: getComputedStyle(preview).position,
                    pageScrollsY:
                        document.documentElement.scrollHeight - document.documentElement.clientHeight,
                };
            });

            // Same height, and both of them real boxes.
            expect(measured.previewH).toBe(measured.controlsH);
            expect(measured.controlsH).toBeGreaterThan(300);
            expect(measured.controlsW).toBeGreaterThan(CONTROLS_FLOOR);
            expect(measured.previewW).toBeGreaterThan(200);

            // Capped by the scroll port rather than by its own content: this is
            // what stops "same height" meaning "both 2500px tall".
            expect(measured.controlsH).toBeLessThanOrEqual(measured.portH);

            // Exactly one scrollbar, and it is inside the controls card.
            expect(measured.bodyScrolls).toBe(true);
            expect(measured.portScrolls).toBe(false);
            expect(measured.pageScrollsY).toBe(0);

            // No sticky left over: a card that fills the row has nowhere to go.
            expect(measured.previewPosition).toBe("static");

            // And the preview is a preview, not a 500px miniature adrift in a
            // 900px card: it fills most of what the card gives it.
            expect(measured.miniatureH).toBeGreaterThan(measured.previewH * 0.7);
        });
    }

    /**
     * THE GAP, measured against the calendar's rather than described.
     *
     * Both boundaries are built from the same gutter token: the row's gap is
     * one gutter, the handle is two gutters wide and pulled left by one, so the
     * visible channel between the two panes is two gutters with the grip down
     * the middle of it. The numbers below are read off BOTH pages in the same
     * run, so this stays true if the token ever moves — a hardcoded 24 would
     * only prove what the stylesheet was compiled with today.
     */
    test("the gap and the grip are the calendar's, to the pixel", async ({ page }) => {
        const geometry = async () => page.evaluate(() => {
            const handleEl = document.querySelector(
                '[data-ui--split-target="handle"]',
            ) as HTMLElement;
            const grip = handleEl.querySelector(".split-grip") as HTMLElement;
            const style = getComputedStyle(handleEl);
            const gripStyle = getComputedStyle(grip);
            const hb = handleEl.getBoundingClientRect();
            const gb = grip.getBoundingClientRect();

            return {
                handleW: Math.round(hb.width),
                marginLeft: style.marginLeft,
                cursor: style.cursor,
                role: handleEl.getAttribute("role"),
                orientation: handleEl.getAttribute("aria-orientation"),
                tabindex: handleEl.getAttribute("tabindex"),
                gripW: Math.round(gb.width),
                gripH: Math.round(gb.height),
                gripPosition: gripStyle.position,
                // Where the grip sits inside the handle, as a fraction: 0.5 is
                // centred, which is the whole point of the negative margin.
                gripCentre: (gb.top + gb.height / 2 - hb.top) / hb.height,
            };
        });

        await page.setViewportSize(DESKTOP);
        await page.goto("/settings?section=appearance");
        await page.waitForTimeout(400);
        const preview = await geometry();

        // The reference, on the page it was written for.
        //
        // The calendar's position is PERSISTED per user, and this worker's user
        // is shared with every other spec in its slot — an earlier version of
        // this test opened the pane through the topbar switch and left it open,
        // which handed mail.spec a message list two thirds as wide and made it
        // fail, in a different place each run, several files away. So it is put
        // back at the end, and put back through the same endpoint the switch
        // writes rather than by pressing the switch again: a cycle depends on
        // which position it started in, and a failed assertion above would skip
        // it entirely.
        await page.goto("/mail/inbox");
        await page.waitForTimeout(400);

        const restore = async () => {
            const token = await page.locator("[data-ui--split-token-value]")
                .first()
                .getAttribute("data-ui--split-token-value");

            await page.request.post("/calendar/pane-state", {
                form: { mode: "mail", _token: token ?? "" },
            });
        };

        const handleVisible = await page.locator('[data-ui--split-target="handle"]')
            .first()
            .isVisible()
            .catch(() => false);

        if (false === handleVisible) {
            // Closed for this user; open it the way a person would.
            await page.locator("[data-calendar-toggle]").first().click();
            await page.waitForTimeout(800);
        }

        await expect(page.locator('[data-ui--split-target="handle"]')).toBeVisible();

        let calendar: Awaited<ReturnType<typeof geometry>>;

        try {
            calendar = await geometry();
        } finally {
            await restore();
        }

        expect(preview.handleW).toBe(calendar.handleW);
        expect(preview.marginLeft).toBe(calendar.marginLeft);
        expect(preview.cursor).toBe(calendar.cursor);
        expect(preview.gripW).toBe(calendar.gripW);
        expect(preview.gripH).toBe(calendar.gripH);
        expect(preview.gripPosition).toBe(calendar.gripPosition);
        expect(preview.gripPosition).toBe("static");

        // Same affordances, not only the same shape.
        expect(preview.role).toBe(calendar.role);
        expect(preview.orientation).toBe(calendar.orientation);
        expect(preview.tabindex).toBe(calendar.tabindex);

        // Centred in the handle on both, which is what the negative margin buys
        // and what the appearance grip's old `sticky top-24` gave up.
        expect(preview.gripCentre).toBeCloseTo(0.5, 1);
        expect(calendar.gripCentre).toBeCloseTo(0.5, 1);
    });

    /**
     * The strain state, which the appearance handle did not used to get.
     *
     * On the calendar the flare goes with the rubber band, and the band is
     * there to reach the two other positions. This boundary has no other
     * positions and no band — it stops dead — which is exactly why the flare
     * earns its place here: it is the only thing on a two-pixel control that
     * says "that is the end" rather than "this drag has broken".
     */
    test("the grip says when a drag is pushing past the end", async ({ page }) => {
        const box = (await handle(page).boundingBox())!;
        const x = box.x + box.width / 2;
        const y = Math.max(box.y + 40, 100);

        await page.mouse.move(x, y);
        await page.mouse.down();
        // Well past the limit — RUBBER_THRESHOLD is 140.
        await page.mouse.move(x - 1400, y, { steps: 14 });
        await page.waitForTimeout(150);

        await expect(handle(page)).toHaveClass(/is-straining/);
        // Flared, not merely classed.
        const flared = await page.locator(".split-grip").first().evaluate(
            (el) => getComputedStyle(el).transform,
        );
        expect(flared).not.toBe("none");

        await page.mouse.up();
        await page.waitForTimeout(300);

        // The pointer has gone, so the message goes with it.
        await expect(handle(page)).not.toHaveClass(/is-straining/);
    });

    /**
     * The panel's other host: the setup wizard's appearance step, which is a
     * modal with no `-mx-6` and no container ancestor of its own. A splitter
     * that overflowed it, or a negative margin that escaped it, would be
     * invisible from the settings page where it was built.
     */
    test("does not break the setup wizard's host", async ({ browser }) => {
        // Its own user, in its own context. The step is only reachable while
        // onboarding is unfinished (OnboardingController::assertApplicable), and
        // putting the worker's shared user back into the wizard would rehash
        // its password and log every later test in this worker out.
        seedUser({ email: WIZARD.email, password: WIZARD.password, pendingOnboarding: true });

        const context = await browser.newContext({
            baseURL: process.env.E2E_BASE_URL,
            storageState: { cookies: [], origins: [] },
            viewport: DESKTOP,
        });
        const page = await context.newPage();

        await page.goto("/login");
        await page.locator("#inputEmail").fill(WIZARD.email);
        await page.locator("#password").fill(WIZARD.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        // The wizard opens by itself over whatever the login lands on. Skipping
        // forward is how a person reaches the appearance step without
        // connecting a real mailbox on the way.
        await expect(page.locator("#onboarding-wizard")).toBeVisible({ timeout: 15000 });

        const panel = page.locator('[data-controller~="settings--appearance"]');

        for (let step = 0; step < 6 && (await panel.count()) === 0; step += 1) {
            await page.getByRole("button", { name: /skip this step/i }).click();
            await page.waitForTimeout(1200);
        }

        await expect(panel).toBeVisible({ timeout: 10000 });

        const measured = await panel.evaluate((el) => ({
            paneWidth: Math.round(
                (el.querySelector('[data-ui--split-target="pane"]') as HTMLElement)
                    .getBoundingClientRect().width,
            ),
            overflow: Math.round(el.scrollWidth - el.clientWidth),
            documentOverflow:
                document.documentElement.scrollWidth - document.documentElement.clientWidth,
            // Both boundaries on one page — the shell's calendar splitter and
            // this one. They coexist because each writes the property it is
            // handed, and neither knows a pane by name.
            splitters: document.querySelectorAll('[data-controller~="ui--split"]').length,
            // Both halves are cards in the modal too. The panel has no
            // container ancestor here, so its own `@container` is what decides
            // — which is the whole reason it carries one.
            cards: [
                el.querySelector('[data-ui--split-target="main"]'),
                el.querySelector('[data-ui--split-target="pane"] > section'),
            ].filter((card) => {
                if (!(card instanceof HTMLElement)) {
                    return false;
                }

                const style = getComputedStyle(card);

                return parseFloat(style.borderTopWidth) > 0
                    && card.getBoundingClientRect().height > 0;
            }).length,
            previewCardHeight: Math.round(
                (el.querySelector('[data-ui--split-target="pane"] > section') as HTMLElement)
                    .getBoundingClientRect().height,
            ),
            controlsCardHeight: Math.round(
                (el.querySelector('[data-ui--split-target="main"]') as HTMLElement)
                    .getBoundingClientRect().height,
            ),
            // The modal's own scrolling body. The panel has to FIT it, not
            // lengthen it — this is the host where that is easy to get wrong,
            // because the modal is `max-h-[92vh]` with an auto height and so
            // has no definite height for a percentage to resolve against. A
            // first version of the fill capped the settings page and left this
            // one at 2400px: two cards the same height, and both of them the
            // empty box the whole arrangement exists to avoid.
            modalBodyHeight: Math.round(
                (el.closest("[class*=overflow-y-auto]") as HTMLElement)
                    .getBoundingClientRect().height,
            ),
            modalBodyScrolls: (() => {
                const body = el.closest("[class*=overflow-y-auto]") as HTMLElement;

                return body.scrollHeight > body.clientHeight + 1;
            })(),
        }));

        expect(measured.paneWidth).toBe(304);
        expect(measured.overflow).toBe(0);
        expect(measured.documentOverflow).toBe(0);
        expect(measured.splitters).toBe(2);
        // Two cards in the modal too, not one card and a stray column.
        expect(measured.cards).toBe(2);
        expect(measured.previewCardHeight).toBeGreaterThan(200);

        // Same height here as well, and capped by the modal's body rather than
        // by the controls card's content.
        expect(measured.previewCardHeight).toBe(measured.controlsCardHeight);
        expect(measured.controlsCardHeight).toBeLessThanOrEqual(measured.modalBodyHeight);
        expect(measured.modalBodyScrolls).toBe(false);

        await context.close();
    });
});

/**
 * The phone, where the preview used to simply not exist.
 *
 * Below @3xl the two cards stack and the preview starts collapsed behind a
 * control in the settings card's header — the user's own suggestion. What is
 * asserted here is the part that makes it worth having: it is reachable, it is
 * a real box when it arrives, and the controls it explains stay usable while it
 * is on screen. A preview that covered the page would be worse than none.
 */
for (const phone of [
    { name: "414x851", width: 414, height: 851 },
    // The narrowest and SHORTEST screen the app claims to support. Height is
    // the constraint that actually bites: a pinned preview and a scroll port of
    // 431px have to share.
    { name: "320x568", width: 320, height: 568 },
]) {
    test.describe(`appearance preview on a phone (${phone.name})`, () => {
        test.use({ viewport: { width: phone.width, height: phone.height } });

        const toggle = (page: Page) =>
            page.locator("header [data-appearance-preview-toggle]");

        test.beforeEach(async ({ page }) => {
            await page.goto("/settings?section=appearance");
            await expect(page.locator('[data-controller="settings--appearance"]')).toBeVisible();
        });

        test("the header control reveals it, and the controls stay usable", async ({ page }) => {
            // Collapsed on arrival — the disclosure is deliberately NOT
            // persisted, so this is the state on every load.
            await expect(pane(page)).toBeHidden();
            await expect(handle(page)).toBeHidden();

            const control = toggle(page);
            await expect(control).toBeVisible();
            await expect(control).toHaveAttribute("aria-expanded", "false");
            await expect(control).toHaveAttribute("aria-controls", "appearance-preview-region");
            await expect(control).toHaveAccessibleName(/show preview/i);

            // The coarse-pointer floor the sidebar work established: a target no
            // smaller than a Comfortable sidebar row.
            const controlBox = (await control.boundingBox())!;
            expect(controlBox.height).toBeGreaterThanOrEqual(36);
            expect(controlBox.width).toBeGreaterThan(80);

            await control.click();
            await page.waitForTimeout(700);

            await expect(control).toHaveAttribute("aria-expanded", "true");
            await expect(control).toHaveAccessibleName(/hide preview/i);
            await expect(pane(page)).toBeVisible();

            // The splitter does NOT come back with it: stacked, there is no
            // boundary for it to move.
            await expect(handle(page)).toBeHidden();

            const measured = await page.evaluate(() => {
                const q = (s: string) => document.querySelector(s) as HTMLElement;
                const region = q("#appearance-preview-region");
                const main = q('[data-ui--split-target="main"]');
                const r = region.getBoundingClientRect();
                const m = main.getBoundingClientRect();

                return {
                    regionW: Math.round(r.width),
                    regionH: Math.round(r.height),
                    regionTop: Math.round(r.top),
                    regionBottom: Math.round(r.bottom),
                    // Where the controls card sits relative to the preview: the
                    // preview is order-first, so the controls start below it.
                    mainTop: Math.round(m.top),
                    viewportH: window.innerHeight,
                    docOverflow:
                        document.documentElement.scrollWidth
                        - document.documentElement.clientWidth,
                };
            });

            // A real box, not a 0px-tall clipped one.
            expect(measured.regionW).toBeGreaterThan(240);
            expect(measured.regionH).toBeGreaterThan(150);
            expect(measured.docOverflow).toBe(0);

            // …and it leaves the screen room for the controls. This is the
            // assertion that would fail if the preview were allowed its natural
            // height on a 568px screen.
            expect(measured.viewportH - measured.regionBottom).toBeGreaterThan(150);
            expect(measured.mainTop).toBeGreaterThanOrEqual(measured.regionBottom - 1);

            // Usable, not merely present: a control below the preview still
            // takes a click and still moves the setting.
            const compact = page.locator('input[name="density"][value="compact"]');
            await compact.click({ force: true });
            await page.waitForTimeout(600);
            await expect(compact).toBeChecked();
        });

        test("stays dismissible once the header has scrolled away", async ({ page }) => {
            await toggle(page).click();
            await page.waitForTimeout(700);

            await page.evaluate(() => window.scrollBy(0, 900));
            await page.waitForTimeout(400);

            // Pinned: still on screen after the page moved under it.
            const region = page.locator("#appearance-preview-region");
            const box = (await region.boundingBox())!;
            expect(box.height).toBeGreaterThan(150);
            expect(box.y).toBeLessThan(phone.height / 2);

            // The header's control has gone under it, which is exactly why the
            // card carries its own way out. Scoped to the region: BOTH controls
            // are named "Hide preview" while it is open, which is correct —
            // they are two doors on one room — and ambiguous to a bare lookup.
            const close = region.getByRole("button", { name: /hide preview/i });
            await close.click();
            await page.waitForTimeout(500);

            await expect(pane(page)).toBeHidden();
            await expect(toggle(page)).toHaveAttribute("aria-expanded", "false");
        });
    });
}
