import { test, expect, type Page } from "./support/test";

/**
 * Sending a draft's images smaller (#502), end to end.
 *
 * The scaling happens in the browser, so only a browser can show it works:
 * the row appears when an image is attached, a size makes the stored file
 * smaller, and Original brings back the very file that was uploaded. The
 * server's half — what it accepts, and that it keeps the original — is in
 * DraftAttachmentServiceTest.
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

/** The width and height of what the server now serves for a part. */
async function servedSize(page: Page, partId: string): Promise<[number, number]> {
    return page.evaluate(async (id) => {
        const response = await fetch(`/mail/attachment/${id}`, { cache: "no-store" });
        const bitmap = await createImageBitmap(await response.blob());

        return [bitmap.width, bitmap.height] as [number, number];
    }, partId);
}

test("an attached photo can be sent smaller and back at full size", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const editor = dock.locator('[data-compose--compose-toolbar-target="editor"]');
    const row = dock.locator('[data-compose--compose-target="imageSize"]');

    await expect(editor).toBeVisible();
    await editor.click();
    await editor.fill("photos from the lake");

    // Nothing to make smaller yet.
    await expect(row).toBeHidden();

    await dock.locator('input[data-compose--compose-target="fileInput"]').setInputFiles({
        name: "e2e-lake.jpg",
        mimeType: "image/jpeg",
        buffer: await photo(page, 2400, 1600),
    });

    const chip = dock.locator("[data-compose-part]").filter({ hasText: "e2e-lake.jpg" });

    await expect(chip).toBeVisible();
    await expect(row).toBeVisible();
    await expect(row.getByRole("radio", { name: "Original" })).toHaveAttribute("aria-checked", "true");

    const partId = (await chip.getAttribute("data-compose-part"))!;
    const fullSize = Number(await chip.getAttribute("data-part-size"));

    expect(await servedSize(page, partId)).toEqual([2400, 1600]);

    // Medium: 1280 on the longest side, fewer bytes, and the choice is kept.
    await row.getByRole("radio", { name: "Medium" }).click();

    await expect(dock.locator('[data-compose--compose-target="imageSize"]').getByRole("radio", { name: "Medium" }))
        .toHaveAttribute("aria-checked", "true");
    await expect.poll(async () => Number(await chip.getAttribute("data-part-size"))).toBeLessThan(fullSize);
    expect(await servedSize(page, partId)).toEqual([1280, 853]);

    // Original: the upload itself, byte for byte the size it was.
    await dock.locator('[data-compose--compose-target="imageSize"]').getByRole("radio", { name: "Original" }).click();

    await expect.poll(async () => Number(await chip.getAttribute("data-part-size"))).toBe(fullSize);
    expect(await servedSize(page, partId)).toEqual([2400, 1600]);

    await dock.getByRole("button", { name: "Delete draft" }).click();
});

/** A picture placed in the body follows the size too, and so does one added after the size was picked. */
test("an image in the body is scaled to the size already picked", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const editor = dock.locator('[data-compose--compose-toolbar-target="editor"]');
    const row = dock.locator('[data-compose--compose-target="imageSize"]');

    await expect(editor).toBeVisible();
    await editor.click();
    await editor.fill("a picture, in the body");

    await dock.locator('input[data-compose--compose-target="imageInput"]').setInputFiles({
        name: "e2e-first.jpg",
        mimeType: "image/jpeg",
        buffer: await photo(page, 1800, 1200),
    });

    await expect(editor.locator("img[data-cid]")).toHaveCount(1);
    await expect(row).toBeVisible();

    await row.getByRole("radio", { name: "Small" }).click();
    await expect(dock.locator('[data-compose--compose-target="imageSize"]').getByRole("radio", { name: "Small" }))
        .toHaveAttribute("aria-checked", "true");

    await editor.click();
    await dock.locator('input[data-compose--compose-target="imageInput"]').setInputFiles({
        name: "e2e-second.jpg",
        mimeType: "image/jpeg",
        buffer: await photo(page, 1200, 1800),
    });

    await expect(editor.locator("img[data-cid]")).toHaveCount(2);

    const ids = await editor.locator("img[data-cid]").evaluateAll((images) =>
        images.map((image) => (image.getAttribute("src") ?? "").match(/\/mail\/attachment\/(\d+)/)?.[1] ?? ""),
    );

    await expect.poll(() => servedSize(page, ids[0])).toEqual([800, 533]);
    await expect.poll(() => servedSize(page, ids[1])).toEqual([533, 800]);

    await dock.getByRole("button", { name: "Delete draft" }).click();
});
