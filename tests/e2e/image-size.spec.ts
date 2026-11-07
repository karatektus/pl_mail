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

/**
 * The Adjust panel (B+): longest side and quality set apart, a Custom
 * segment for what is neither a preset nor Original, and the row's
 * before → after once anything is smaller.
 */
test("the Adjust panel sets size and quality apart", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const editor = dock.locator('[data-compose--compose-toolbar-target="editor"]');
    const row = () => dock.locator('[data-compose--compose-target="imageSize"]');

    await expect(editor).toBeVisible();
    await editor.click();
    await editor.fill("photos, tuned by hand");

    await dock.locator('input[data-compose--compose-target="fileInput"]').setInputFiles({
        name: "e2e-tuned.jpg",
        mimeType: "image/jpeg",
        buffer: await photo(page, 2400, 1600),
    });

    const chip = dock.locator("[data-compose-part]").filter({ hasText: "e2e-tuned.jpg" });
    await expect(chip).toBeVisible();

    const partId = (await chip.getAttribute("data-compose-part"))!;
    const fullSize = Number(await chip.getAttribute("data-part-size"));

    // Nothing smaller yet: a plain total, no arrow.
    await expect(row().locator("[data-image-size-total]")).toContainText("in all");

    // Adjust opens on Original: own size, full quality.
    await row().getByRole("button", { name: "Adjust size and quality" }).click();

    const panel = row().locator("[data-image-size-panel]");
    await expect(panel).toBeVisible();
    await expect(panel.getByLabel("Keep each photo’s own size, only compress it")).toBeChecked();

    await panel.getByLabel("Keep each photo’s own size, only compress it").uncheck();
    await panel.getByLabel("Longest side").fill("1600");
    await panel.getByLabel("Quality").fill("60");

    await expect(panel.locator("[data-image-size-edge-label]")).toHaveText("1600 px");
    await expect(panel.locator("[data-image-size-quality-label]")).toHaveText("60 %");
    await expect(panel.locator("[data-image-size-estimate]")).toContainText("→ ≈");

    await panel.getByRole("button", { name: "Apply to all images" }).click();

    // A Custom segment, chosen, with its numbers; the photo is 1600 wide.
    await expect(row().getByRole("radio", { name: "Custom" })).toHaveAttribute("aria-checked", "true");
    await expect(row().locator("[data-image-size-custom]")).toHaveText("1600 px · 60 %");
    await expect.poll(() => servedSize(page, partId)).toEqual([1600, 1067]);
    await expect(row().locator("[data-image-size-total]")).toContainText("→");
    const customSize = Number(await chip.getAttribute("data-part-size"));
    expect(customSize).toBeLessThan(fullSize);

    // A preset's numbers select the preset.
    await row().getByRole("button", { name: "Adjust size and quality" }).click();
    await row().locator("[data-image-size-panel]").getByRole("button", { name: "Back to Medium" }).click();
    await row().locator("[data-image-size-panel]").getByRole("button", { name: "Apply to all images" }).click();

    await expect(row().getByRole("radio", { name: "Medium" })).toHaveAttribute("aria-checked", "true");
    await expect(row().getByRole("radio", { name: /^Custom/ })).toHaveCount(0);
    await expect.poll(() => servedSize(page, partId)).toEqual([1280, 853]);

    // Own size, only compressed: same pixels, fewer bytes.
    await row().getByRole("button", { name: "Adjust size and quality" }).click();
    await row().locator("[data-image-size-panel]").getByLabel("Keep each photo’s own size, only compress it").check();
    await row().locator("[data-image-size-panel]").getByLabel("Quality").fill("50");
    await row().locator("[data-image-size-panel]").getByRole("button", { name: "Apply to all images" }).click();

    await expect(row().getByRole("radio", { name: "Custom" })).toHaveAttribute("aria-checked", "true");
    await expect(row().locator("[data-image-size-custom]")).toHaveText("Own size · 50 %");
    await expect.poll(() => servedSize(page, partId)).toEqual([2400, 1600]);
    await expect.poll(async () => Number(await chip.getAttribute("data-part-size"))).toBeLessThan(fullSize);

    await dock.getByRole("button", { name: "Delete draft" }).click();
});
