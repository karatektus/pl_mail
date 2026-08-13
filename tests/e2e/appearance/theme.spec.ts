import { test, expect, type Page } from "../support/test";

/**
 * Picking a theme, and the colours that come with it.
 *
 * The whole panel is one live preview: choosing a theme rewrites CSS custom
 * properties on the document immediately, and the same values are what get
 * saved. So the failure worth catching is the two disagreeing — a preview that
 * looks right and a reload that does not, which is exactly what a preview
 * built from separate defaults would give.
 *
 * Reset is here for the same reason: it is the only way back from a colour
 * somebody cannot read, so it has to work from a state where they cannot read
 * anything.
 */

const PANEL = '[data-controller="settings--appearance"]';

/**
 * The value the page is actually painted with, not the one in the form.
 *
 * Tolerant of a navigation in flight: reset reloads the page itself, and an
 * evaluate that lands mid-navigation throws rather than returning a stale
 * value. Polling callers want to try again, not fail.
 */
async function cssVar(page: Page, name: string): Promise<string> {
    try {
        return await page.evaluate(
            (variable) => getComputedStyle(document.documentElement).getPropertyValue(variable).trim(),
            name,
        );
    } catch {
        return "";
    }
}

async function open(page: Page): Promise<void> {
    await page.goto("/settings?section=appearance");
    await expect(page.locator(PANEL)).toBeVisible();
}

/** Themes are radio-ish buttons carrying their own defaults as data. */
function themeButtons(page: Page) {
    return page.locator(`${PANEL} [data-theme-name]`);
}

test.describe("appearance — theme", () => {
    test.afterEach(async ({ page }) => {
        // Every test in here changes how the app looks for this user, and the
        // rest of the suite reads the same account.
        await open(page);
        await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();

        // The reset navigates; waiting on the response is enough, and a failure
        // to tidy up must not fail the test that already passed.
        await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
    });

    test("choosing a theme repaints the page immediately", async ({ page }) => {
        await open(page);

        const before = await cssVar(page, "--rgb-accent");

        // A theme other than the current one, whichever that is.
        const buttons = themeButtons(page);
        const count = await buttons.count();

        test.skip(count < 2, "only one theme in this build");

        for (let i = 0; i < count; i++) {
            await buttons.nth(i).click();

            if ((await cssVar(page, "--rgb-accent")) !== before) {
                return;
            }
        }

        throw new Error("no theme changed the accent colour");
    });

    /**
     * Rendered server-side on the next load, not restored by JavaScript: the
     * alternative is every page flashing the old theme before correcting
     * itself.
     */
    test("the choice survives a reload", async ({ page }) => {
        await open(page);

        const buttons = themeButtons(page);
        const before = await cssVar(page, "--rgb-accent");

        for (let i = 0, count = await buttons.count(); i < count; i++) {
            await buttons.nth(i).click();

            const now = await cssVar(page, "--rgb-accent");

            if (now === before) {
                continue;
            }

            await page.waitForResponse((r) => r.url().includes("/appearance"));
            await page.reload();

            expect(await cssVar(page, "--rgb-accent")).toBe(now);

            return;
        }

        test.skip(true, "no theme changed the accent colour");
    });

    /** The way back from an unreadable choice. */
    test("reset puts the defaults back", async ({ page }) => {
        await open(page);

        const buttons = themeButtons(page);
        const original = await cssVar(page, "--rgb-accent");

        for (let i = 0, count = await buttons.count(); i < count; i++) {
            await buttons.nth(i).click();

            if ((await cssVar(page, "--rgb-accent")) !== original) {
                break;
            }
        }

        await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();

        // Polled rather than reloaded: the reset navigates by itself, and a
        // reload racing that navigation aborts. What matters is that the page
        // ends up painted with the defaults, not how it got there.
        await expect.poll(() => cssVar(page, "--rgb-accent")).toBe(original);
    });
});
