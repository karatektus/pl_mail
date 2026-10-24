import { test, expect, type Page } from "../support/test";
import { ajaxPost } from "../support/config";

/**
 * The installed app's icons follow the logo (#141).
 *
 * The server never draws these PNGs: the browser draws the chosen icon on a
 * canvas and uploads it (assets/app_icons.js), so only a real browser can show
 * the whole path works: a pick draws four PNGs of the right sizes, the server
 * keeps them, and the manifest and iOS icon the page links serve them instead
 * of the wax seal.
 *
 * "Serves them" is told apart from the wax seal by the cache header: the
 * user's own PNG is immutable at the current drawing, the stand-in is
 * `no-cache` (BrandingController::appIcon()).
 */

const PANEL = '[data-controller="settings--appearance"]';
const UPLOAD = "/settings/appearance/app-icons";

/**
 * The upload of one choice, as the server names it (`<motif>.<paint>`). Not
 * just any upload: opening a page can draw the user's current choice too, when
 * it is missing, and that one must not be mistaken for the pick's. Told apart
 * by the answer, because Chromium does not expose a multipart body with files
 * to the test.
 */
function uploadOf(page: Page, choice: string) {
    return page.waitForResponse(async (r) => {
        if (!r.url().endsWith(UPLOAD) || r.request().method() !== "POST") {
            return false;
        }

        try {
            return (await r.json()).choice === choice;
        } catch {
            return false;
        }
    });
}

/** Width and height from a PNG's IHDR chunk, which always starts at byte 16. */
function pngSize(bytes: Buffer): [number, number] {
    expect(bytes.subarray(1, 4).toString("latin1"), "a PNG").toBe("PNG");

    return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)];
}

async function linked(page: Page, rel: string): Promise<string> {
    const href = await page.locator(`link[rel="${rel}"]`).getAttribute("href");

    expect(href, `the page links a ${rel}`).toBeTruthy();

    return href as string;
}

/** The user's own PNG at that URL, of that size. */
async function expectOwnIcon(page: Page, url: string, size: number): Promise<void> {
    const response = await page.request.get(url);

    expect(response.status(), url).toBe(200);
    expect(response.headers()["content-type"]).toBe("image/png");
    expect(response.headers()["cache-control"], `${url} is the user's own, not the wax seal`).toContain("immutable");
    expect(pngSize(await response.body())).toEqual([size, size]);
}

/** Back to the default logo, which is where both tests start and must leave the user. */
async function reset(page: Page): Promise<void> {
    await page.goto("/settings?section=appearance");
    await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();
    await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
}

test.describe("appearance — the installed app's icons", () => {
    // Before as well as after: picking the logo that is already chosen is not
    // a change and draws nothing, so the test must not inherit a blue horn.
    test.beforeEach(async ({ page }) => reset(page));

    // The logo is this worker's user's, and the rest of the suite reads it.
    test.afterEach(async ({ page }) => reset(page));

    test("picking a logo draws its PNGs, and the manifest and iOS icon serve them", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await expect(page.locator(PANEL)).toBeVisible();

        const uploaded = uploadOf(page, "blue-horn.original");
        await page.locator(`${PANEL} [data-logo-motif="blue-horn"]`).click();
        expect((await uploaded).status(), "the server kept all four").toBe(200);

        // Re-pointed on the pick, before any reload.
        const manifestUrl = await linked(page, "manifest");
        expect(manifestUrl).toContain("/branding/manifest/blue-horn/original.webmanifest?u=");

        const manifest = await (await page.request.get(manifestUrl)).json();
        const icons = manifest.icons as { src: string; sizes: string; purpose: string }[];

        expect(icons.map((icon) => [icon.sizes, icon.purpose])).toEqual([
            ["192x192", "any"],
            ["512x512", "any"],
            ["512x512", "maskable"],
        ]);

        for (const icon of icons) {
            await expectOwnIcon(page, icon.src, Number(icon.sizes.split("x")[0]));
        }

        const appleTouch = await linked(page, "apple-touch-icon");
        expect(appleTouch).toContain("/bleed-180/blue-horn/original.png");
        await expectOwnIcon(page, appleTouch, 180);
    });

    test("a page whose PNGs are missing draws them once", async ({ page }) => {
        // Saved without the pane, so nothing has drawn this choice: only one
        // choice is kept per user, and the last one drawn was not this.
        await page.goto("/settings?section=appearance");
        const saved = await ajaxPost(page, "/settings/appearance", { logoMotif: "pl", logoOriginal: "0", logoLinked: "0", logoStyle: "postal" });
        expect(saved.ok()).toBe(true);

        const uploaded = uploadOf(page, "pl.postal");
        await page.goto("/mail/inbox");
        expect((await uploaded).status()).toBe(200);

        await expectOwnIcon(page, await linked(page, "apple-touch-icon"), 180);

        // Drawn now, so the next page does not ask again.
        await page.goto("/mail/inbox");
        await expect(page.locator('[data-controller="core--app-icons"]')).toHaveCount(0);
    });
});
