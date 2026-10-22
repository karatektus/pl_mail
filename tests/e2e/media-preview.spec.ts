import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Pictures and videos open in a viewer, as PDFs do, and the chip's download
 * half still downloads (#295).
 *
 * ⚠ Run `php bin/console asset-map:compile` after changing anything under
 * assets/ — see pdf-preview.spec.
 *
 * The seeded video is not a real WebM (see SeedTestAttachmentCommand): the
 * Chromium Playwright ships plays no proprietary codecs anyway, so what a
 * browser can check here is the dialog's answer to a file it will not play.
 */
test.beforeEach(() => {
    seed("seed-mail", "seed-attachment");
});

async function openAttachmentThread(page: Page) {
    await page.goto("/mail/inbox");
    await page
        .locator("#message-list li")
        .filter({ hasText: "E2E Attachment" })
        .first()
        .click();
}

test.describe("the media viewer", () => {
    test("a picture opens in the dialog and is drawn", async ({ page }) => {
        await openAttachmentThread(page);

        await page.getByRole("button", { name: /e2e-photo\.png/ }).click();

        const image = page.locator("#modal img");
        await expect(image).toBeVisible();

        // Drawn, not merely present: a part served as a download would leave
        // an <img> with no natural size, which is what the viewer guards
        // against.
        await expect
            .poll(() => image.evaluate((node) => (node as HTMLImageElement).naturalWidth))
            .toBeGreaterThan(0);
    });

    test("a video opens in the dialog, and says so when it cannot play", async ({ page }) => {
        await openAttachmentThread(page);

        await page.getByRole("button", { name: /e2e-clip\.webm/ }).click();

        const modal = page.locator("#modal");
        await expect(modal).toContainText("This file could not be shown here.");
        await expect(modal.locator("video")).toHaveCount(0);
        await expect(modal.getByRole("link", { name: "Download" })).toBeVisible();
    });

    test("the chip's download half still downloads", async ({ page }) => {
        await openAttachmentThread(page);

        for (const name of ["e2e-photo.png", "e2e-clip.webm", "e2e-document.pdf"]) {
            const download = page.waitForEvent("download");
            await page.getByRole("link", { name: `Download ${name}` }).click();

            expect((await download).suggestedFilename()).toBe(name);
        }

        // And nothing opened on the way.
        await expect(page.locator("#modal img, #modal video")).toHaveCount(0);
    });
});
