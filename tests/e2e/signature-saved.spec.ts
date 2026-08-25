import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * A signature drawn once in Settings, and used on a PDF without drawing again.
 *
 * ⚠ JavaScript and CSS both. Run `php bin/console tailwind:build` and then
 * `asset-map:compile` after changing anything under assets/ — a stylesheet
 * change skipped by the first one is served as a valid file your rule is simply
 * not in, which looks like a specificity problem and is not.
 *
 * The property worth pinning is that a saved signature and a freshly drawn one
 * go through the SAME placement path. A stamp is an image from the moment it is
 * captured rather than a set of strokes, and that is what makes "use the saved
 * one" six lines instead of a second implementation to keep in step.
 *
 * It is a picture of a name. Nothing here asserts anything about authenticity,
 * because there is nothing to assert.
 */
test.beforeEach(async ({ page }) => {
    seed("seed-mail", "seed-attachment");

    // A saved signature outlives the seeds — it is a file on disk and a column
    // on the user, and no app:test:* command touches either. Left alone, every
    // test here would find the one the previous RUN saved, and "it saved
    // correctly" would pass without anything being saved at all. That is not
    // hypothetical: it is what these tests did while the save endpoint was
    // answering 500.
    await page.goto("/settings?section=profile");

    const remove = page.getByRole("button", { name: "Remove saved signature" });

    if (0 < (await remove.count())) {
        await remove.click();
        await expect(page.getByAltText("Your saved signature")).toHaveCount(0);
    }
});

const PAD = '[data-settings--signature-pad-target="ink"]';
const STAMP = '[data-mail--pdf-sign-target="stamp"]';
const PAGES = '[data-mail--pdf-viewer-target="stack"] canvas';

/** Draws on whichever pad is on screen, the way a pointer actually would. */
async function scribbleOn(page: Page, selector: string): Promise<void> {
    const pad = page.locator(selector);
    await expect(pad).toBeVisible();

    // Into view BEFORE the box is measured. page.mouse works in viewport
    // coordinates, and boundingBox() happily reports a box below the fold — so
    // without this the strokes land somewhere else entirely and the pad stays
    // blank, which reads as "drawing is broken" rather than "the test aimed
    // outside the window".
    await pad.scrollIntoViewIfNeeded();

    const box = (await pad.boundingBox())!;
    const y = box.y + box.height / 2;

    await page.mouse.move(box.x + 20, y);
    await page.mouse.down();

    for (let step = 1; step <= 8; step += 1) {
        await page.mouse.move(box.x + 20 + step * 12, y + (step % 2 === 0 ? -14 : 14));
    }

    await page.mouse.up();
}

/** Saves one, so the tests below have something to find. */
async function saveSignature(page: Page): Promise<void> {
    await page.goto("/settings?section=profile");
    await scribbleOn(page, PAD);

    await page.getByRole("button", { name: "Save signature" }).click();
    await expect(page.getByAltText("Your saved signature")).toBeVisible();
}

test.describe("a saved signature", () => {
    /**
     * Saving is an ordinary form post, so it survives a reload — which is the
     * difference between a saved signature and one held in a tab.
     */
    test("is drawn in Settings and stays there", async ({ page }) => {
        await saveSignature(page);

        await page.reload();
        await expect(page.getByAltText("Your saved signature")).toBeVisible();
    });

    /** An untouched pad has nothing to save, and must not pretend otherwise. */
    test("cannot be saved before anything is drawn", async ({ page }) => {
        await page.goto("/settings?section=profile");

        await expect(page.getByRole("button", { name: "Save signature" })).toBeDisabled();
    });

    test("is offered in the reader and places without drawing again", async ({ page }) => {
        await saveSignature(page);

        await page.goto("/mail/inbox");
        await page.locator("#message-list li").filter({ hasText: "E2E Attachment" }).first().click();
        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
        await expect(page.locator(PAGES).first()).toBeVisible({ timeout: 20000 });

        await page.getByRole("button", { name: "Use saved signature" }).click();

        const stamp = page.locator(STAMP);
        await expect(stamp).toBeVisible();

        // Carrying the saved image, not an empty outline — and the pad never
        // opened, which is the whole point of having saved one.
        await expect(stamp).toHaveAttribute("style", /background-image: url/);
        await expect(page.locator('[data-mail--pdf-sign-target="ink"]')).toBeHidden();
    });

    /**
     * Removing it removes the offer too. A button that places nothing is worse
     * than no button, and this is the assertion that fails if the toolbar ever
     * starts rendering it unconditionally.
     */
    test("can be removed, and stops being offered", async ({ page }) => {
        await saveSignature(page);

        await page.getByRole("button", { name: "Remove saved signature" }).click();
        await expect(page.getByAltText("Your saved signature")).toHaveCount(0);

        await page.goto("/mail/inbox");
        await page.locator("#message-list li").filter({ hasText: "E2E Attachment" }).first().click();
        await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
        await expect(page.locator(PAGES).first()).toBeVisible({ timeout: 20000 });

        await expect(page.getByRole("button", { name: "Use saved signature" })).toHaveCount(0);
    });
});
