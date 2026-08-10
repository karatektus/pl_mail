import { test, expect, type Page } from "./support/test";
import { WORKER_SLOT, login, seed, seedUser } from "./support/config";

/**
 * The three shared widgets, each pinned by the bug that made it worth pinning:
 * the modal's backdrop dismissal, the Tom Select match mark, and the onboarding
 * provider tab list.
 *
 * Kept in one file rather than folded into label.spec.ts / onboarding.spec.ts
 * because none of these are about labels or about setup — they are about the
 * dialog shell, the dropdown skin and a tab row that three steps share. A
 * failure here should read as "the widget broke", not as "labels broke".
 */

const modal = (page: Page) => page.locator("#modal-backdrop");

test.describe("modal backdrop dismissal", () => {
    test.beforeEach(() => {
        seed("seed-mail", "seed-label");
    });

    /** Opens the label form — a dialog with a real text field in it. */
    async function openLabelForm(page: Page): Promise<void> {
        await page.goto("/mail/inbox");
        await page.locator("#sidebar").getByRole("button", { name: "Create label" }).click();

        await expect(modal(page)).toBeVisible();

        // Editable, not merely visible. The frame is fetched, and the modal
        // controller moves focus into it on turbo:frame-load — so under a busy
        // suite the panel can be on screen while the form behind it is still
        // arriving, and a drag measured then is measured against a box that is
        // about to move.
        await expect(modal(page).getByLabel("Name")).toBeEditable();
    }

    /**
     * A point on the backdrop, clear of the panel.
     *
     * Derived from the backdrop's own box rather than hard-coded near the
     * window corner: the corner is where Turbo's progress bar and any toast
     * live, and a gesture that ends on one of those is not the gesture this
     * test means to make.
     */
    async function pointOutsideThePanel(page: Page): Promise<{ x: number; y: number }> {
        const backdrop = await modal(page).boundingBox();
        const panel = await modal(page).locator("[data-ui--modal-panel]").boundingBox();

        expect(backdrop).not.toBeNull();
        expect(panel).not.toBeNull();

        return {
            x: backdrop!.x + Math.max(12, (panel!.x - backdrop!.x) / 2),
            y: backdrop!.y + backdrop!.height / 2,
        };
    }

    /**
     * The bug this file exists for.
     *
     * Select text in a field and let go of the button past the edge of the
     * panel: the `click` that follows is reported on the nearest common
     * ancestor of the press and the release, which for a dialog over a
     * full-screen backdrop is the backdrop itself. A close handler reading only
     * the click could not tell that from a click on the backdrop, so the dialog
     * closed and took what had been typed with it.
     */
    test("survives a text selection that is released outside the panel", async ({ page }) => {
        await openLabelForm(page);

        const field = modal(page).getByLabel("Name");
        const typed = "Still here";
        await field.fill(typed);

        const box = await field.boundingBox();
        expect(box).not.toBeNull();

        const outside = await pointOutsideThePanel(page);

        // Press inside the field, drag out over the backdrop, release there.
        await page.mouse.move(box!.x + box!.width - 6, box!.y + box!.height / 2);
        await page.mouse.down();
        await page.mouse.move(box!.x + 6, box!.y + box!.height / 2, { steps: 8 });
        await page.mouse.move(outside.x, outside.y, { steps: 12 });
        await page.mouse.up();

        await expect(modal(page)).toBeVisible();

        // And the point of it staying open: the input is untouched.
        await expect(field).toHaveValue(typed);
    });

    /** The other direction — a press that begins on the backdrop and ends on
     *  the panel is a drag INTO the dialog, and must not dismiss it either. */
    test("survives a drag that starts on the backdrop and ends on the panel", async ({ page }) => {
        await openLabelForm(page);

        const panel = modal(page).locator("[data-ui--modal-panel]");
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();

        const outside = await pointOutsideThePanel(page);

        await page.mouse.move(outside.x, outside.y);
        await page.mouse.down();
        await page.mouse.move(box!.x + box!.width / 2, box!.y + 12, { steps: 12 });
        await page.mouse.up();

        await expect(modal(page)).toBeVisible();
    });

    /** Still dismissable the way it is supposed to be. */
    test("closes on a genuine click on the backdrop", async ({ page }) => {
        await openLabelForm(page);

        const outside = await pointOutsideThePanel(page);

        await page.mouse.move(outside.x, outside.y);
        await page.mouse.down();
        await page.mouse.up();

        await expect(modal(page)).toBeHidden();
    });

    test("closes on Escape", async ({ page }) => {
        await openLabelForm(page);

        await page.keyboard.press("Escape");

        await expect(modal(page)).toBeHidden();
    });

    test("closes on the header's close button", async ({ page }) => {
        await openLabelForm(page);

        await modal(page).getByRole("button", { name: "Close" }).click();

        await expect(modal(page)).toBeHidden();
    });
});

test.describe("tom select match mark", () => {
    /**
     * The timezone picker: several hundred options, so the widget renders its
     * filter box, which is the only place a match mark can appear.
     */
    const wrapper = (page: Page) => page.locator('form[action="/settings/timezone"] .ts-wrapper');
    const dropdown = (page: Page) => page.locator("#settings-timezone-ts-dropdown");

    async function search(page: Page, query: string): Promise<void> {
        await wrapper(page).locator(".ts-control").click();
        await wrapper(page).locator("input").first().fill(query);
        await expect(dropdown(page).locator(".highlight").first()).toBeVisible();
    }

    test("marks exactly what was typed, with no trailing space", async ({ page }) => {
        await page.goto("/settings?section=general");
        await search(page, "africa/a");

        const marks = await dropdown(page)
            .locator(".option .highlight")
            .evaluateAll((els) => els.map((el) => el.textContent ?? ""));

        expect(marks.length).toBeGreaterThan(0);

        for (const mark of marks) {
            // The whole point: the marked run is the query and nothing else —
            // no boundary whitespace swept in, and no gap standing beside it.
            expect(mark).toBe("Africa/A");
            expect(mark).not.toMatch(/\s$/);
        }
    });

    test("renders the mark in bold", async ({ page }) => {
        await page.goto("/settings?section=general");
        await search(page, "africa/a");

        const weight = await dropdown(page)
            .locator(".option .highlight")
            .first()
            .evaluate((el) => getComputedStyle(el).fontWeight);

        expect(Number(weight)).toBeGreaterThanOrEqual(700);

        await dropdown(page).screenshot({
            path: "test-results/screenshots/tom-select-bold-match.png",
        });
    });

    /**
     * The mechanism, pinned directly.
     *
     * `.option` used to be `display: flex; gap: 10px`. Highlighting splits the
     * option's text node in three, so under flex each piece became a flex item
     * and the gap was painted between them — a hole beside the mark that read
     * as a trailing space — while real spaces vanished, because an anonymous
     * flex item of pure white space is not rendered at all.
     */
    test("lays options out as text, so a mark introduces no gap", async ({ page }) => {
        await page.goto("/settings?section=general");
        await search(page, "america argentina");

        const option = dropdown(page).locator(".option").first();

        expect(await option.evaluate((el) => getComputedStyle(el).display)).toBe("block");

        // Both words marked, and the separator between them still a real "/".
        const text = (await option.textContent())?.trim();
        expect(text).toMatch(/^America\/Argentina\//);

        const marks = await option
            .locator(".highlight")
            .evaluateAll((els) => els.map((el) => el.textContent ?? ""));
        expect(marks).toEqual(["America", "Argentina"]);
    });

    test("matches case-insensitively and through umlauts without widening the mark", async ({
        page,
    }) => {
        await page.goto("/settings?section=general");

        await search(page, "EUROPE/z");
        expect(
            await dropdown(page).locator(".option .highlight").first().textContent(),
        ).toBe("Europe/Z");

        // Filtering is debounced, so poll rather than read once: the previous
        // query's marks are still on screen for a frame or two.
        await wrapper(page).locator("input").first().fill("zürich");
        await expect
            .poll(async () =>
                dropdown(page).locator(".option .highlight").first().textContent(),
            )
            .toBe("Zurich");
    });

    /**
     * The accessibility contract, asserted against whatever element the <label>
     * actually points at rather than against a class name.
     *
     * That indirection is the point. Restoring the filter box moves the
     * combobox role from the control div onto the input Tom Select puts inside
     * it — which is the ARIA 1.2 pattern and is still "role=combobox on the
     * labelled control", because the label's `for` moves with it. A test
     * hard-coded to `.ts-control` would call that a regression when nothing
     * about the contract changed.
     */
    test("keeps the combobox contract on the labelled control", async ({ page }) => {
        await page.goto("/settings?section=general");

        const form = page.locator('form[action="/settings/timezone"]');
        const labelFor = await form.locator("label").first().getAttribute("for");
        expect(labelFor).toBeTruthy();

        const combo = page.locator(`#${labelFor}`);
        await expect(combo).toHaveAttribute("role", "combobox");
        await expect(combo).toHaveAttribute("aria-expanded", "false");
        await expect(combo).toHaveAttribute("aria-controls", "settings-timezone-ts-dropdown");
        await expect(combo).toHaveAttribute("aria-labelledby", /.+/);

        await wrapper(page).locator(".ts-control").click();
        await expect(combo).toHaveAttribute("aria-expanded", "true");

        // The native <select> is still the source of truth, and still the thing
        // a keyboard choice writes back to.
        await page.keyboard.press("Enter");
        await expect(page.locator("#settings-timezone")).toHaveCount(1);
    });
});

test.describe("onboarding provider tab list", () => {
    // Its own admin user with setup still pending: the provider chooser only
    // renders on the two administrator steps.
    const ADMIN = {
        email: `e2e-tabs-admin-w${WORKER_SLOT}@plmail.test`,
        password: "e2e-tabs-admin-password",
    };

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeEach(() => {
        seedUser({ ...ADMIN, admin: true, pendingOnboarding: true });
    });

    /**
     * Skips forward to the INTEGRATION credentials step specifically.
     *
     * Not merely "the first step with a tab list": the mail step shares the
     * same partial but offers two providers, which fits on one line at any
     * width and would prove nothing. Integrations is the one with eight.
     */
    async function reachIntegrationTabs(page: Page): Promise<void> {
        const step = page.locator("#onboarding-step-admin-integrations");

        for (let i = 0; i < 8; i++) {
            if (await step.isVisible()) {
                return;
            }

            await expect(page.locator("#onboarding-skip")).toBeEnabled();
            await page.locator("#onboarding-skip").click();
            await page.waitForTimeout(250);
        }

        await expect(step).toBeVisible();
    }

    for (const viewport of [
        { name: "desktop", width: 1280, height: 900 },
        { name: "narrow", width: 390, height: 780 },
    ]) {
        test(`wraps instead of scrolling sideways — ${viewport.name}`, async ({ page }) => {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await login(page, ADMIN.email, ADMIN.password);
            await expect(page.locator("#onboarding-wizard")).toBeVisible();

            await reachIntegrationTabs(page);

            const tabs = page.locator('#onboarding-wizard [role="tablist"]');

            // Nothing on the dialog may scroll horizontally — not the row, not
            // the scrolling body it sits in, not the panel, not the document.
            const overflow = await page.evaluate(() => {
                const names = [
                    "#modal-backdrop [data-ui--modal-panel]",
                    "#onboarding-wizard",
                    '#onboarding-wizard [role="tablist"]',
                ];
                const out: Record<string, number> = {
                    document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                };
                for (const sel of names) {
                    const el = document.querySelector(sel);
                    if (el) out[sel] = el.scrollWidth - el.clientWidth;
                }
                // The wizard's scrolling body, whichever element carries it.
                const body = document.querySelector("#onboarding-wizard .overflow-y-auto");
                if (body) out.body = body.scrollWidth - body.clientWidth;
                return out;
            });

            for (const [where, slack] of Object.entries(overflow)) {
                expect(slack, `${where} scrolls horizontally`).toBeLessThanOrEqual(1);
            }

            // And the row genuinely wraps rather than squeezing: no button is
            // allowed to break its own name across two lines.
            const lines = await tabs.locator("button").evaluateAll((els) =>
                els.map((el) => {
                    const range = document.createRange();
                    range.selectNodeContents(el);
                    return {
                        text: (el.textContent ?? "").trim(),
                        rects: range.getClientRects().length,
                        height: el.getBoundingClientRect().height,
                    };
                }),
            );

            expect(lines.length).toBeGreaterThan(1);
            for (const line of lines) {
                // h-8 is 32px; a label broken onto two lines makes the button
                // taller than the row it was designed for.
                expect(line.height, `"${line.text}" is taller than one row`).toBeLessThan(40);
            }

            await page.screenshot({
                path: `test-results/screenshots/onboarding-provider-tabs-${viewport.name}.png`,
                fullPage: false,
            });
        });
    }
});
