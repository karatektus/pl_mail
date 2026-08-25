import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Reading a PDF attachment without downloading it.
 *
 * ⚠ These are almost entirely JavaScript. Run `php bin/console asset-map:compile`
 * after changing anything under assets/, or Playwright reads the previous
 * build and every result here is about code that is no longer on disk.
 *
 * The document is `SamplePdf::awkward()` — two pages, the second rotated and
 * with an offset MediaBox. Those matter far more for signing than for reading,
 * but a reader that cannot draw them is a reader that cannot be signed on.
 */
test.beforeEach(() => {
    seed("seed-mail", "seed-attachment");
});

/**
 * Opens the thread carrying the seeded attachments.
 *
 * Filtered on the row's text rather than through mailRow(), which matches on
 * the subject constants in support/config — "E2E Attachment" is seeded by
 * app:test:seed-attachment and is not one of them. attachments.spec.ts reaches
 * it the same way.
 */
async function openAttachmentThread(page: Page) {
    await page.goto("/mail/inbox");
    await page
        .locator("#message-list li")
        .filter({ hasText: "E2E Attachment" })
        .first()
        .click();
}

/**
 * The page canvases, and only those.
 *
 * Scoped to the stack rather than to the modal: the signing pad is a canvas in
 * this dialog too, and `#modal-backdrop canvas` counted it the moment signing
 * was added. See CODESTYLE 9.5 — an assertion must survive the screen growing.
 */
const PAGES = '[data-mail--pdf-viewer-target="stack"] canvas';

test.describe("the PDF reader", () => {
    test("opens from the chip and draws every page", async ({ page }) => {
        await openAttachmentThread(page);

        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();

        const canvases = page.locator(PAGES);

        // Two pages, because the fixture has two. Both drawn — a canvas with
        // width 0 is one pdf.js never rendered, which is what a failure inside
        // the worker looks like from out here.
        await expect(canvases).toHaveCount(2, { timeout: 20000 });

        const widths = await canvases.evaluateAll((nodes) =>
            nodes.map((node) => (node as HTMLCanvasElement).width),
        );

        expect(widths.every((width) => width > 0), `canvas widths were ${widths}`).toBe(true);
    });

    /**
     * The assertion that keeps the whole approach honest.
     *
     * pdf.js can run under this policy only because the worker is same-origin
     * and WASM is switched off. Both are one careless upgrade away from
     * regressing — a blob: worker or a WASM attempt would each raise a
     * violation here, and in production the policy is ENFORCED, so the reader
     * would simply not work.
     */
    test("renders without violating the content security policy", async ({ page }) => {
        await page.addInitScript(() => {
            (window as never as { __csp: string[] }).__csp = [];
            document.addEventListener("securitypolicyviolation", (event) => {
                (window as never as { __csp: string[] }).__csp.push(
                    `${event.effectiveDirective} <- ${event.blockedURI}`,
                );
            });
        });

        await openAttachmentThread(page);
        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
        await expect(page.locator(PAGES).first()).toBeVisible({ timeout: 20000 });

        // The worker starts and decodes after the first paint, so a violation
        // it causes arrives later than the canvas does.
        await page.waitForTimeout(2000);

        const violations = await page.evaluate(() => (window as never as { __csp: string[] }).__csp);

        expect(violations, "the reader violates the app's own policy").toEqual([]);
    });

    test("zooming changes how large the pages are drawn", async ({ page }) => {
        await openAttachmentThread(page);
        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();

        const first = page.locator(PAGES).first();
        await expect(first).toBeVisible({ timeout: 20000 });

        const before = (await first.boundingBox())!.width;

        await page.getByRole("button", { name: "Zoom in" }).click();

        // Compared against what it was, not against a number: the starting
        // scale is the viewer's business and may change. See CODESTYLE 9.5.
        await expect
            .poll(async () => (await page.locator(PAGES).first().boundingBox())!.width)
            .toBeGreaterThan(before);
    });

    /**
     * The modal empties its frame on close, so a viewer that does not tear
     * down leaves a worker behind on every open.
     */
    test("closing the reader takes its worker with it", async ({ page }) => {
        await openAttachmentThread(page);

        const before = page.workers().length;

        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
        await expect(page.locator(PAGES).first()).toBeVisible({ timeout: 20000 });

        expect(page.workers().length, "pdf.js should have started a worker").toBeGreaterThan(before);

        await page.keyboard.press("Escape");

        await expect.poll(() => page.workers().length, { timeout: 10000 }).toBe(before);
    });

    /** A non-PDF part keeps the download it always had. */
    test("a text attachment is still a download, not a reader", async ({ page }) => {
        await openAttachmentThread(page);

        const chip = page.getByRole("link", { name: /e2e-attachment\.txt/ });

        await expect(chip).toHaveAttribute("download", /e2e-attachment\.txt/);
    });
});
