import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Expanding the compose window keeps the whole message on screen.
 *
 * Expanding gave the editor `flex: 1; height: 0` while the block around it is
 * not a flex container, so the flex did nothing and the editor fell back to its
 * 170px min-height. Its `[contain:paint]` then clipped everything below that,
 * and a longer message lost its last lines the moment the window was enlarged.
 */

const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

test.beforeAll(() => {
    seed("seed-mail");
});

test.describe("expanded compose window", () => {
    test("the editor grows with a long message instead of clipping it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();
        const editor = page.locator(EDITOR);
        await expect(editor).toBeVisible();

        await editor.evaluate((el) => {
            el.innerHTML = Array.from({ length: 30 }, (_, i) => `<div>line ${i + 1}</div>`).join("");
        });

        await page.getByRole("button", { name: "Expand" }).click();

        const box = await editor.evaluate((el) => ({
            client: el.clientHeight,
            scroll: el.scrollHeight,
        }));

        // Nothing is hidden below the editor's own box.
        expect(box.client).toBeGreaterThanOrEqual(box.scroll);
    });
});
