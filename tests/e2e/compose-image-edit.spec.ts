import { test, expect, type Locator, type Page } from "./support/test";
import { deleteDraft } from "./support/compose";

/**
 * Resizing, cropping and turning a picture in the body (#515), end to end.
 *
 * Like the Image size row (#502, image-size.spec.ts) the work happens in the
 * browser: the bar writes a recipe onto the <img>, and the compose window
 * makes the part's bytes from the original. So the checks are on both ends —
 * the attributes in the body, and the picture the server now serves.
 */

/** A noisy JPEG, so a smaller copy really is fewer bytes. */
async function photo(page: Page, width: number, height: number): Promise<Buffer> {
    const base64 = await page.evaluate(async ([w, h]) => {
        const canvas = new OffscreenCanvas(w, h);
        const context = canvas.getContext("2d")!;
        const pixels = context.createImageData(w, h);

        for (let i = 0; i < pixels.data.length; i += 4) {
            pixels.data[i] = (i * 7) % 251;
            pixels.data[i + 1] = (i * 13) % 241;
            pixels.data[i + 2] = (i * 31) % 239;
            pixels.data[i + 3] = 255;
        }

        context.putImageData(pixels, 0, 0);

        const blob = await canvas.convertToBlob({ type: "image/jpeg", quality: 0.92 });
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = "";

        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
        }

        return btoa(binary);
    }, [width, height]);

    return Buffer.from(base64, "base64");
}

/**
 * How long a picture may take to come back from the server at its new size:
 * every change is fetch the original, encode, upload, save, one after the
 * other, and with four workers on one machine that is more than the default
 * five seconds.
 */
const SLOW = { timeout: 15_000 };

/** The width and height of what the server now serves for a part. */
async function servedSize(page: Page, partId: string): Promise<[number, number]> {
    return page.evaluate(async (id) => {
        const response = await fetch(`/mail/attachment/${id}`, { cache: "no-store" });
        const bitmap = await createImageBitmap(await response.blob());

        return [bitmap.width, bitmap.height] as [number, number];
    }, partId);
}

async function openWithPicture(page: Page, width: number, height: number) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const editor = dock.locator('[data-compose--compose-toolbar-target="editor"]');

    await expect(editor).toBeVisible();
    await editor.click();
    await editor.fill("a picture to edit");

    await dock.locator('input[data-compose--compose-target="imageInput"]').setInputFiles({
        name: "e2e-edit.jpg",
        mimeType: "image/jpeg",
        buffer: await photo(page, width, height),
    });

    const image = editor.locator("img[data-cid]");

    // Generous: the first upload into a freshly started app is slow.
    await expect(image).toHaveCount(1, { timeout: 15_000 });
    await expect.poll(() => image.evaluate((img: HTMLImageElement) => (img.complete ? img.naturalWidth : 0)), { timeout: 15_000 }).toBeGreaterThan(0);

    const partId = (await image.getAttribute("src"))!.match(/\/mail\/attachment\/(\d+)/)![1];

    return { dock, editor, image, partId, bar: dock.locator("[data-image-bar]") };
}

async function waitForSave(dock: Locator) {
    await expect(dock.locator('[data-compose--compose-target="saveStatus"]')).not.toContainText("Editing picture");
}

test("the bar's sizes set the picture's file size and leave its width alone, and Full size brings it back", async ({ page }) => {
    const { dock, editor, image, partId, bar } = await openWithPicture(page, 2400, 1600);

    await expect(bar).toBeHidden();

    // Selecting scrolls nothing, and puts the bar where it can be seen.
    // Brought into view first: Playwright's click scrolls its target into
    // view by itself, and that scroll is not the one under test.
    const scroller = dock.locator('[data-compose--compose-target="scroller"]');

    await image.scrollIntoViewIfNeeded();

    const scrolled = await scroller.evaluate((el) => el.scrollTop);

    await image.click();
    await expect(bar).toBeVisible();
    await expect(dock.locator("[data-image-frame]")).toBeVisible();
    expect(await scroller.evaluate((el) => el.scrollTop)).toBe(scrolled);

    const view = (await scroller.boundingBox())!;
    const barBox = (await bar.boundingBox())!;

    expect(barBox.y).toBeGreaterThanOrEqual(view.y - 0.5);
    expect(barBox.y + barBox.height).toBeLessThanOrEqual(view.y + view.height + 0.5);

    // Full size is pressed to begin with: the draft's Image size is Original.
    await expect(bar.getByRole("button", { name: "Full size" })).toHaveAttribute("aria-pressed", "true");

    await bar.getByRole("button", { name: "Small" }).click();

    // Small is how many pixels go out — the Image size row's Small, 800 on
    // the longest side, for this picture alone — and nothing about how big
    // it is shown, which is the corner handles' (the next test).
    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-size", "small");
    await expect(editor.locator("img[data-cid]")).not.toHaveAttribute("width", /.*/);
    await expect(editor.locator("img[data-cid]")).toHaveAttribute("style", /max-width:\s*100%/);
    await expect(bar.getByRole("button", { name: "Small" })).toHaveAttribute("aria-pressed", "true");
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([800, 533]);
    await waitForSave(dock);

    // Full size: the upload itself comes back.
    await editor.locator("img[data-cid]").click();
    await bar.getByRole("button", { name: "Full size" }).click();

    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-size", "original");
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([2400, 1600]);
    await waitForSave(dock);

    // A size picked in the Image size row is for every picture: this one's
    // own size goes, and it follows the row again.
    await page.keyboard.press("Escape");
    await dock.locator('[data-compose--compose-target="imageSize"]').getByRole("radio", { name: "Large" }).click();

    await expect(editor.locator("img[data-cid]")).not.toHaveAttribute("data-pl-size", /.*/);
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([2048, 1365]);
    await editor.locator("img[data-cid]").click();
    await expect(bar.getByRole("button", { name: "Large" })).toHaveAttribute("aria-pressed", "true");

    await page.keyboard.press("Escape");
    await expect(bar).toBeHidden();

    await deleteDraft(dock);
});

test("dragging a corner handle sets the width it is shown at, and only that", async ({ page }) => {
    const { dock, editor, image, partId } = await openWithPicture(page, 1200, 800);

    await image.click();

    // The top-left handle: the picture fills the body, and selecting it does
    // not scroll, so its foot (and the lower handles) can be under the fold.
    const handle = dock.locator('[data-image-handle="nw"]');
    const box = (await handle.boundingBox())!;
    const before = (await image.boundingBox())!.width;

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 100, box.y + 10, { steps: 5 });
    await page.mouse.up();

    const width = Number(await editor.locator("img[data-cid]").getAttribute("width"));

    expect(width).toBeGreaterThan(before - 110);
    expect(width).toBeLessThan(before - 90);
    await waitForSave(dock);
    expect(await servedSize(page, partId)).toEqual([1200, 800]);

    await deleteDraft(dock);
});

test("a turn and a crop change the picture itself, and undo takes the turn back", async ({ page }) => {
    const { dock, editor, image, partId, bar } = await openWithPicture(page, 1200, 800);

    await image.click();
    await bar.getByRole("button", { name: "Rotate right" }).click();

    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-rotate", "90");
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([800, 1200]);
    await waitForSave(dock);

    // Crop: the whole turned picture in a dialog, the frame pulled in from
    // the bottom-right corner to half each way.
    await editor.locator("img[data-cid]").click();
    await bar.getByRole("button", { name: "Crop" }).click();

    const dialog = page.locator("[data-image-crop-dialog]");
    await expect(dialog).toBeVisible();
    await expect.poll(() => dialog.locator("[data-crop-image]").evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

    const stage = (await dialog.locator("[data-crop-stage]").boundingBox())!;
    const corner = (await dialog.locator('[data-crop-handle="se"]').boundingBox())!;

    await page.mouse.move(corner.x + corner.width / 2, corner.y + corner.height / 2);
    await page.mouse.down();
    // The handle sits inside the frame's corner: aim the corner, not the handle's middle.
    await page.mouse.move(stage.x + stage.width / 2 - corner.width / 2, stage.y + stage.height / 2 - corner.height / 2, { steps: 5 });
    await page.mouse.up();

    // Let go is let go: the frame stays where it was dropped while the
    // mouse moves over it with no button down.
    const frame = dialog.locator("[data-crop-frame]");
    const dropped = await frame.boundingBox();

    await page.mouse.move(stage.x + 40, stage.y + 40, { steps: 5 });
    await page.mouse.move(stage.x + stage.width / 3, stage.y + stage.height / 3, { steps: 5 });
    expect(await frame.boundingBox()).toEqual(dropped);

    await dialog.getByRole("button", { name: "Crop" }).click();
    await expect(dialog).toBeHidden();

    const crop = (await editor.locator("img[data-cid]").getAttribute("data-pl-crop"))!.split(",").map(Number);

    expect(crop[0]).toBe(0);
    expect(crop[1]).toBe(0);
    expect(Math.abs(crop[2] - 0.5)).toBeLessThan(0.03);
    expect(Math.abs(crop[3] - 0.5)).toBeLessThan(0.03);
    await expect.poll(async () => {
        const [w, h] = await servedSize(page, partId);

        return Math.abs(w - 400) < 25 && Math.abs(h - 600) < 25;
    }, SLOW).toBe(true);
    await waitForSave(dock);

    // Undo: the crop goes, and the stored picture follows.
    await editor.focus();
    await page.keyboard.press("ControlOrMeta+z");

    await expect(editor.locator("img[data-cid]")).not.toHaveAttribute("data-pl-crop", /.*/);
    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-rotate", "90");
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([800, 1200]);
    await waitForSave(dock);

    await deleteDraft(dock);
});

test("the recipe survives reopening the draft", async ({ page }) => {
    const subject = `e2e picture edit ${Date.now()}`;
    const { dock, editor, image, partId, bar } = await openWithPicture(page, 1200, 800);

    await dock.getByPlaceholder("Subject").fill(subject);
    await image.click();
    await bar.getByRole("button", { name: "Small" }).click();
    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-size", "small");
    await editor.locator("img[data-cid]").click();
    await bar.getByRole("button", { name: "Rotate left" }).click();
    await expect(editor.locator("img[data-cid]")).toHaveAttribute("data-pl-rotate", "270");
    // Turned, and brought to Small's 800 on its longest side.
    await expect.poll(() => servedSize(page, partId), SLOW).toEqual([533, 800]);
    await waitForSave(dock);

    await page.goto("/mail/drafts");
    await page.locator("#message-list li").filter({ hasText: subject }).first().click();

    const reopened = page.locator("#compose_dock").locator('[data-compose--compose-toolbar-target="editor"] img[data-cid]');

    await expect(reopened).toHaveAttribute("data-pl-rotate", "270");
    await expect(reopened).toHaveAttribute("data-pl-size", "small");

    await deleteDraft(page.locator("#compose_dock"));
});
