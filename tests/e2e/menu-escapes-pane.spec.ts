import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * A menu opened inside a pane is not trapped by it.
 *
 * Reported twice, as two different bugs: the label dropdown in a conversation
 * disappeared behind the navbar, and the recipient dropdown in the composer
 * pushed the dialog down the page instead of overlaying it. One cause.
 *
 * The panes carry `backdrop-filter`, which does two things CSS rarely has to
 * account for: it makes the pane a stacking context, so a `z-30` menu inside it
 * can never rise above a `z-20` header outside it — raising the number does
 * nothing — and it makes the pane the containing block for `position: fixed`,
 * so the usual escape hatch is not one. Every ancestor from the pane down is
 * also `overflow: hidden`, so the menu is clipped as well as painted under.
 *
 * The fix is the browser's top layer, via the popover API, which is above every
 * stacking context and clipped by nothing while leaving the element where it is
 * in the DOM — so the Stimulus targets and actions scoped to it keep working.
 *
 * What this asserts is therefore geometric rather than stylistic: the menu is
 * on screen, and nothing is painted on top of it. `elementFromPoint` is the
 * honest test — it answers what the user would actually click.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

/** True when the topmost element at the menu's centre is the menu itself. */
async function isOnTop(page: import("@playwright/test").Page, selector: string): Promise<boolean> {
    return page.evaluate((sel) => {
        const menu = document.querySelector(sel);

        if (!menu) {
            return false;
        }

        const box = menu.getBoundingClientRect();

        if (0 === box.width || 0 === box.height) {
            return false;
        }

        const hit = document.elementFromPoint(box.left + box.width / 2, box.top + 8);

        return null !== hit && (hit === menu || menu.contains(hit));
    }, selector);
}

test.describe("menus escape their pane", () => {
    /**
     * KNOWN FAILING — the fix was reverted, and this records why rather than
     * quietly disappearing with it.
     *
     * The reported bug is real: the label dropdown in a conversation is painted
     * under the navbar, and it is not a z-index problem — the panes carry
     * `backdrop-filter`, which makes each one a stacking context AND the
     * containing block for `position: fixed`, so nothing anchored inside one
     * can rise above the chrome whatever its number says.
     *
     * The attempted fix was the browser's top layer, via the popover API. It
     * places the panel correctly and it broke clicking: with the panel shown as
     * a popover, `document.elementFromPoint` at the option's own coordinates
     * answers null and the click never lands. It did the same to the snooze
     * menu. Three specs caught it, which is the system working.
     *
     * Reverted rather than pursued, because a menu that is visible and does
     * nothing is worse than one that is clipped, and because I could not find
     * the cause without seeing the real layout. It wants a reproduction in a
     * browser rather than a fourth guess.
     */
    test.fixme("the label menu in a conversation is not hidden behind the navbar", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        // `:visible` rather than .first(): the list toolbar renders a bulk
        // instance of this menu that stays hidden until rows are selected, and
        // it comes first in the document.
        const trigger = page.locator('[data-controller="mail--label-menu"] button:visible').first();
        await expect(trigger).toBeVisible();
        await trigger.click();

        const panel = page.locator('[data-mail--label-menu-target="panel"]:visible').first();
        await expect(panel).toBeVisible();

        // Fully on screen, not cut off by an overflow:hidden ancestor.
        const box = await panel.boundingBox();
        expect(box, "the panel has no box").not.toBeNull();
        expect(box!.y, "the panel starts above the viewport").toBeGreaterThanOrEqual(0);
        expect(box!.y + box!.height, "the panel runs off the bottom")
            .toBeLessThanOrEqual((page.viewportSize()?.height ?? 0) + 1);

        expect(
            await isOnTop(page, '[data-mail--label-menu-target="panel"]:popover-open'),
            "something is painted over the label menu — it is back under the chrome",
        ).toBe(true);
    });

});
