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

        // Below the container query that drops the preview outright. The handle
        // lives INSIDE the dropped element, so it cannot be left behind as a
        // grab target for a pane that is not there.
        await page.setViewportSize({ width: 900, height: 1000 });
        await page.waitForTimeout(400);

        await expect(handle(page)).toBeHidden();
        await expect(pane(page)).toBeHidden();
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
        }));

        expect(measured.paneWidth).toBe(304);
        expect(measured.overflow).toBe(0);
        expect(measured.documentOverflow).toBe(0);
        expect(measured.splitters).toBe(2);

        await context.close();
    });
});
