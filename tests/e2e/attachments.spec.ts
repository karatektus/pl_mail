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
