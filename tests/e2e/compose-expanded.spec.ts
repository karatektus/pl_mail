import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * The expanded compose window shows all of a long message (#262).
 *
 * Expanding used to size the editor with
 *
 *     this.bodyTarget.closest('div').style.flex = '1';
 *     this.bodyTarget.style.height = '0';
 *
 * and `closest()` starts at the element itself. The editor is a <div>, so the
 * first line set flex on the editor rather than on its wrapper, the wrapper
 * never became a flex container, and the editor was left at `height: 0` —
 * which its `min-h-[170px]` overrode. A 170px box over a long message, and
 * `[contain:paint]` (which stays, see the template) clipped the rest instead
 * of letting the window's scroller reach it.
 *
 * Asked the way the reader sees it: is the last line of a long message
 * reachable by scrolling, and does a short one still fill the window so a
 * click under the text lands in the editor.
 */

test.beforeAll(() => {
    seed("seed-mail");
});

test.describe("expanded compose window", () => {
    test.use({ viewport: { width: 1280, height: 800 } });

    test("a long message grows and scrolls instead of being cut off", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const editor = page.locator('[data-compose--compose-toolbar-target="editor"]');
        await expect(editor).toBeVisible();

        // Written straight into the body rather than typed, as the typography
        // spec does: typing arms the autosave and mints a draft.
        await editor.evaluate((el) => {
            el.innerHTML = Array.from({ length: 80 }, (_, i) => `<div>Line ${i + 1}</div>`).join("")
                + '<div id="last-line">The last line</div>';
        });

        await page.getByRole("button", { name: "Expand" }).click();

        const sizes = await editor.evaluate((el) => ({
            box: el.getBoundingClientRect().height,
            content: el.scrollHeight,
        }));

        // The box holds its content: nothing past it for contain:paint to clip.
        expect(sizes.box).toBeGreaterThanOrEqual(sizes.content - 1);

        const last = page.locator("#last-line");
        await last.scrollIntoViewIfNeeded();
        await expect(last).toBeInViewport();
    });

    test("a short message still fills the window", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        const editor = page.locator('[data-compose--compose-toolbar-target="editor"]');
        await expect(editor).toBeVisible();

        await page.getByRole("button", { name: "Expand" }).click();

        // Most of the 800px screen, not the 170px floor: the empty space under
        // the text is the editor's, so clicking it puts the caret there.
        const height = await editor.evaluate((el) => el.getBoundingClientRect().height);
        expect(height).toBeGreaterThan(300);

        // Restored, the inline sizing is gone again.
        await page.getByRole("button", { name: "Expand" }).click();
        const restored = await editor.evaluate((el) => ({
            flex: el.style.flex,
            wrap: el.parentElement!.style.display,
        }));
        expect(restored).toEqual({ flex: "", wrap: "" });
    });
});
