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

/** A user of this spec's own, so the wizard can be entered without disturbing anyone. */
const WIZARD = { email: "e2e-wizard-host@plmail.test", password: "wizard-host-password" };

const pane = (page: Page) => page.locator('[data-ui--split-target="pane"]');
const handle = (page: Page) => page.locator('[data-ui--split-target="handle"]');

const width = async (page: Page) => Math.round((await pane(page).boundingBox())!.width);

/**
 * Drag the boundary by `dx`, negative being "wider" (the pane is on the right).
 *
 * Grabbed near the TOP of the handle rather than at its centre: the handle
 * stretches the whole grid row, which on this panel is some 2700px tall, so its
 * centre is a long way below the viewport and clicking it would hit nothing.
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

    test("stays inside its bounds", async ({ page }) => {
        // Far past the maximum in one throw. It stops, rather than rubber-
        // banding: the band exists to reach the calendar's other two positions
        // and this boundary has none.
        await drag(page, -2000);
        expect(await width(page)).toBe(560);

        await drag(page, 2000);
        expect(await width(page)).toBe(240);
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
        }));

        expect(measured.paneWidth).toBe(304);
        expect(measured.overflow).toBe(0);
        expect(measured.documentOverflow).toBe(0);
        expect(measured.splitters).toBe(2);
        // Two cards in the modal too, not one card and a stray column.
        expect(measured.cards).toBe(2);
        expect(measured.previewCardHeight).toBeGreaterThan(200);

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
