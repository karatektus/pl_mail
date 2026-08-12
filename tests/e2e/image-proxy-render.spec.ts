import { test, expect } from "./support/test";
import { mailRow, seed } from "./support/config";

/**
 * The spec the original rendering-security suite was missing.
 *
 * Its siblings prove a proxied image is REQUESTED through our own origin with a
 * signature, but they point at a `.invalid` host on purpose, so the proxy always
 * answers with the 1×1 placeholder — they never prove a real image reaches the
 * eye. And "reaches the eye" is the whole feature: the mail body renders in a
 * sandbox with no `allow-same-origin`, an opaque origin whose `<img>` loads
 * carry NO session cookie. While the proxy route sat behind ROLE_USER those
 * cookieless requests were answered with the login page and every remote image
 * rendered broken (BUG 1); and even with a session the fetcher threw on a
 * forbidden curl option and served the placeholder for everything (BUG 2).
 *
 * So this asserts the one thing markup and top-frame fetches cannot: an opted-in
 * remote image, fetched by the proxy for a browser that sent no cookie, paints
 * real pixels INSIDE the opaque frame — naturalWidth > 0.
 *
 * Kept in sync with App\Command\Test\SeedRenderingCommand::SUBJECT_FETCHABLE.
 */
const SUBJECT = "E2E Fetchable Image";
const PROBE_ALT = "E2E Fetchable Render Probe";

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering");
});

test.describe("image proxy · renders inside the opaque frame", () => {
    test("an opted-in remote image paints real pixels in the sandboxed frame", async ({ page }) => {
        // Watch the browser-visible proxy requests: the frame's origin is opaque,
        // so if these ever carried a cookie the isolation would be broken.
        const proxyRequests: string[] = [];
        page.on("request", (request) => {
            if (request.url().includes("/mail/image-proxy")) proxyRequests.push(request.url());
        });

        await page.goto("/mail/inbox");
        await mailRow(page, SUBJECT).click();

        const body = page.locator(".mail-message-body").first();
        await expect(body).toBeVisible();

        // The frame really is opaque — no allow-same-origin. This is the
        // condition that made the cookieless load necessary in the first place.
        const sandbox = await body.locator("iframe").getAttribute("sandbox");
        expect(sandbox).not.toContain("allow-same-origin");

        // Blocked by default: opt in.
        await expect(body.getByTestId("images-bar")).toBeVisible();
        await body.getByTestId("show-images").click();

        // The proxy was asked, over our own origin, with the minted signature.
        await expect.poll(() => proxyRequests.length, { timeout: 10_000 }).toBeGreaterThan(0);
        for (const url of proxyRequests) {
            expect(url).toMatch(/[?&]s=[0-9a-f]{32}/);
        }

        // THE assertion. Read straight off the element the browser built inside
        // the sandbox — a broken image (login HTML, or the placeholder) decodes
        // to naturalWidth 0; the real 550×368 JPEG does not.
        const probe = page
            .frameLocator(".mail-message-body iframe")
            .first()
            .locator(`img[alt="${PROBE_ALT}"]`);

        await expect
            .poll(async () => probe.evaluate((img: HTMLImageElement) => img.naturalWidth), {
                timeout: 15_000,
                message: "the proxied image must decode to real pixels inside the opaque frame",
            })
            .toBeGreaterThan(0);
    });

    /**
     * The cookieless leg, stated on its own. A context with no storage state
     * makes the same request the opaque frame does — no session — and the route
     * must answer it as an image, never bounce it to the login form. Behind
     * ROLE_USER this came back as a 302 (or the login HTML at 200): BUG 1.
     */
    test("a request with no session gets an image answer, not a login redirect", async ({ browser }, testInfo) => {
        const context = await browser.newContext({
            baseURL: testInfo.project.use.baseURL,
        });
        try {
            const anon = await context.newPage();

            const signed = await anon.request.get(
                "/mail/image-proxy?u=" +
                    encodeURIComponent("https://www.gstatic.com/webp/gallery/1.jpg") +
                    "&s=deadbeef",
                { maxRedirects: 0 },
            );

            // A forged signature is refused with the placeholder — but crucially
            // as an IMAGE, at 200, with no redirect to /login. That is the whole
            // of BUG 1: the anonymous frame is served, not challenged.
            expect(signed.status()).toBe(200);
            expect(signed.headers()["content-type"]).toBe("image/gif");
        } finally {
            await context.close();
        }
    });
});
