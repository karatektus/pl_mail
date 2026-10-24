import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * A menu for one conversation ticks the labels it is GIVEN, not only the ones the
 * server rendered into it.
 *
 * The reading pane's label menu is single-target and is ticked by the server
 * (activeLabels). The action sheet opens one copy of the same menu for each row
 * in turn, so the row's own labels are handed to it as `labelIds` at open time,
 * and that is the source its ticks must follow. This spec sets that value on the
 * pane's menu directly, so the behaviour is proven without the sheet (which is
 * tested by its own spec).
 *
 * Presence companion: a label that is NOT in the given ids stays unticked, so
 * "every label ticked" cannot pass for "the given ones ticked".
 */
test.beforeEach(() => {
    seed("seed-mail", "seed-label");
});

test.describe("label menu for one conversation", () => {
    test("ticks the labels it is given, and only those", async ({ page }) => {
        await page.goto("/mail/inbox");

        await mailRow(page, INBOX_SUBJECTS.read).click();

        const menu = page
            .locator('[data-controller="mail--label-menu"][data-mail--label-menu-target-id-value]')
            .first();
        const trigger = menu.locator('button[data-action="click->mail--label-menu#toggle"]').first();
        const panel = menu.locator('[data-mail--label-menu-target="panel"]');

        await trigger.click();
        await expect(panel).toBeVisible();

        const given = panel.locator('[data-label-id][data-attached="false"]').first();
        const givenId = await given.getAttribute("data-label-id");

        if (givenId === null) {
            throw new Error("the label the menu is given has no data-label-id");
        }

        // The label the server did not tick: presence of the starting state.
        await expect(given).toHaveAttribute("data-attached", "false");
        expect(givenId).not.toBeNull();

        await trigger.click();
        await expect(panel).toBeHidden();

        await menu.evaluate((element, ids) => {
            element.setAttribute("data-mail--label-menu-label-ids-value", ids);
        }, givenId);

        await trigger.click();
        await expect(panel).toBeVisible();

        await expect(panel.locator(`[data-label-id="${givenId}"]`)).toHaveAttribute("data-attached", "true");
        await expect(panel.locator(`[data-label-id="${givenId}"] [data-mail--label-menu-target="check"]`)).not.toHaveClass(/invisible/);

        // Presence companion: exactly the given label is ticked. Counted, not sampled, so a
        // menu that ticks everything, or keeps the server's ticks beside the given ones, fails.
        await expect(panel.locator('[data-label-id][data-attached="true"]')).toHaveCount(1);
    });
});
