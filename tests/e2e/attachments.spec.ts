import { readFileSync } from "node:fs";
import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * The attachment chip's download half, end to end: a click produces a file.
 *
 * Worth a spec of its own because the failure it guards against is invisible
 * from the server's side. Turbo Drive intercepts a same-origin link, fetches
 * it itself, and discards a response that is not HTML — so the request went
 * out, the app answered 200 with the bytes, the access log looked perfect and
 * the browser saved nothing. Nothing was logged and nothing was thrown; the
 * click simply did nothing. Asserting on the response would have passed
 * throughout. Only the download event proves a file arrived.
 */

const FILENAME = "e2e-attachment.txt";
const CONTENTS = "Seeded attachment body.\n";

test.beforeAll(() => {
    seed("seed-attachment");
});

/**
 * An image in the body is not an attachment, and the compose window must not
 * say it is.
 *
 * The paperclip and the attachment strip are both a promise about something
 * the reader has to go and open. A picture they can already see in the message
 * is not one — a signature logo would otherwise put a paperclip on every mail
 * the account ever sends.
 */
test("an inline image is not counted as an attachment", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const editor = dock.locator('[data-compose--compose-toolbar-target="editor"]');

    await expect(editor).toBeVisible();
    await editor.click();
    await editor.fill("a picture, in the body");

    await dock.locator('input[data-compose--compose-target="imageInput"]').setInputFiles({
        name: "e2e-inline.png",
        mimeType: "image/png",
        buffer: Buffer.from(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
            "base64",
        ),
    });

    // It is in the body…
    await expect(editor.locator("img[data-cid]")).toBeVisible();

    // …and nowhere in the strip beside it.
    await expect(dock.getByRole("link", { name: "e2e-inline.png" })).toHaveCount(0);
    await expect(
        dock.locator('[data-compose--compose-target="attachments"]'),
    ).not.toContainText("e2e-inline.png");

    // The upload forced a save, so this spec owns a draft now. Take it away
    // again rather than leaving it for the next run to find.
    await dock.getByRole("button", { name: "Delete draft" }).click();
});

test("downloading an attachment produces the file", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page
        .locator("#message-list li")
        .filter({ hasText: "E2E Attachment" })
        .first()
        .click();

    const chip = page.getByRole("link", { name: FILENAME });
    await expect(chip).toBeVisible();

    const downloadPromise = page.waitForEvent("download");
    await chip.click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe(FILENAME);

    const path = await download.path();
    expect(readFileSync(path, "utf8")).toBe(CONTENTS);
});
