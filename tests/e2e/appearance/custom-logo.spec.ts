import { test, expect, type Page } from "../support/test";
import { ajaxPost } from "../support/config";

/**
 * A logo of the user's own, uploaded as an SVG (#48).
 *
 * Only a browser shows the whole path: the file goes up through the pane's
 * own button, the pane is rendered again wearing it, and the page draws the
 * installed app's PNGs from it on a canvas (assets/app_icons.js) exactly as it
 * does for a motif. CustomLogoTest covers what the server does with each
 * request; this covers that the page makes them.
 */

const PANEL = '[data-controller="settings--appearance"]';
const INPUT = '[data-settings--appearance-target="logoUploadInput"]';

const LOGO = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">
    <circle cx="5" cy="5" r="4" fill="#c026d3"/>
</svg>`;

/** The browser-drawn PNGs for the uploaded logo, whatever its hash. */
function customPngUpload(page: Page) {
    return page.waitForResponse(async (r) => {
        if (!r.url().endsWith("/settings/appearance/app-icons") || r.request().method() !== "POST") {
            return false;
        }

        try {
            return String((await r.json()).choice).startsWith("custom.");
        } catch {
            return false;
        }
    });
}

/** No uploaded logo, and the default motif: where every test starts and ends. */
async function reset(page: Page): Promise<void> {
    await page.goto("/settings?section=appearance");
    await ajaxPost(page, "/settings/appearance/logo/remove", {});
    await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();
    await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
}

test.describe("appearance — a logo of your own", () => {
    test.beforeEach(async ({ page }) => reset(page));
    test.afterEach(async ({ page }) => reset(page));

    test("an uploaded SVG is worn everywhere, and the app icons are drawn from it", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await expect(page.locator(PANEL)).toBeVisible();

        const pngs = customPngUpload(page);

        await page.locator(INPUT).setInputFiles({ name: "logo.svg", mimeType: "image/svg+xml", buffer: Buffer.from(LOGO) });

        // The pane comes back wearing it: its tile selected, the paints hidden.
        const tile = page.locator(`${PANEL} [data-logo-motif="custom"]`);
        await expect(tile).toHaveAttribute("aria-pressed", "true");
        await expect(page.locator('[data-settings--appearance-target="logoPaintStep"]')).toBeHidden();

        await expect(page.locator('link[rel="icon"]')).toHaveAttribute("href", /\/branding\/favicon\/custom\/[0-9a-f]{16}\.svg/);
        await expect(page.locator('[data-logo-live-icon="light"]')).toHaveAttribute("src", /\/branding\/logo\/custom\//);

        expect((await pngs).status(), "the server kept the four PNGs drawn from it").toBe(200);

        const appleTouch = (await page.locator('link[rel="apple-touch-icon"]').getAttribute("href")) as string;
        const response = await page.request.get(appleTouch);

        expect(response.status()).toBe(200);
        expect(response.headers()["cache-control"], "the user's own PNG, not the wax seal").toContain("immutable");
    });

    test("going back to a motif keeps the upload to return to", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await page.locator(INPUT).setInputFiles({ name: "logo.svg", mimeType: "image/svg+xml", buffer: Buffer.from(LOGO) });
        await expect(page.locator(`${PANEL} [data-logo-motif="custom"]`)).toHaveAttribute("aria-pressed", "true");

        const saved = page.waitForResponse((r) => r.url().endsWith("/settings/appearance") && r.request().method() === "POST");
        await page.locator(`${PANEL} [data-logo-motif="blue-horn"]`).click();
        await saved;

        await expect(page.locator('[data-settings--appearance-target="logoPaintStep"]')).toBeVisible();
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute("href", /\/branding\/favicon\/blue-horn\//);

        await page.reload();
        await expect(page.locator(`${PANEL} [data-logo-motif="blue-horn"]`)).toHaveAttribute("aria-pressed", "true");
        await expect(page.locator(`${PANEL} [data-logo-motif="custom"]`)).toHaveAttribute("aria-pressed", "false");
    });

    test("a file that is not an SVG logo is refused with a reason", async ({ page }) => {
        await page.goto("/settings?section=appearance");

        await page.locator(INPUT).setInputFiles({ name: "logo.svg", mimeType: "image/svg+xml", buffer: Buffer.from("<html>no</html>") });

        await expect(page.locator("#toast-region")).toContainText("SVG");
        await expect(page.locator(`${PANEL} [data-logo-motif="custom"]`)).toHaveCount(0);
    });
});
