import { test, expect, type Page } from "./support/test";

/**
 * The account corner in a REAL multi-account list, on and off.
 *
 * Split out from appearance-shots because it needs a second mail account, and
 * the e2e user has exactly one — which is why the corner never appears in the
 * ordinary suite's list at all (it is drawn only in a unified list on an
 * install with more than one account, and that predates this change). Run it
 * against a stack where a second account exists; it skips otherwise rather
 * than passing vacuously, because a corner that is absent for the wrong reason
 * is exactly the result this must not accept.
 */

const PANEL = '[data-controller="settings--appearance"]';
const OUT = "var/shots";

const setCorner = async (page: Page, on: boolean) => {
    await page.goto("/settings?section=appearance");
    await expect(page.locator(PANEL)).toBeVisible();

    const segment = page.locator(
        `${PANEL} input[type="radio"][data-toggles="accountCorner"][value="${on ? "1" : "0"}"]`,
    );

    if (false === (await segment.isChecked())) {
        await segment.check({ force: true });
        await page.waitForResponse(
            (r) => r.url().includes("/settings/appearance") && r.request().method() === "POST",
        );
    }
};

test.describe("appearance — the account corner in a real list", () => {
    test.skip(process.env.E2E_SHOTS !== "1", "set E2E_SHOTS=1");

    test.afterEach(async ({ page }) => {
        await setCorner(page, true);
    });

    test("is drawn with real size, and gone when switched off", async ({ page }) => {
        await setCorner(page, true);
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();

        const corners = page.locator("#message-list li [data-account-corner]");
        const count = await corners.count();

        test.skip(count === 0, "this stack has one account; the corner is never drawn");

        // Width AND height, not containment: a 0px-tall box sits inside its
        // row and inside the viewport and would satisfy a containment check
        // while being invisible.
        const box = await corners.first().boundingBox();
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThanOrEqual(9);
        expect(box!.height).toBeGreaterThanOrEqual(9);

        await page.screenshot({ path: `${OUT}/multi-account-corner-on.png` });

        await setCorner(page, false);
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();

        await expect(corners.first()).toBeHidden();
        expect(await corners.first().boundingBox()).toBeNull();

        await page.screenshot({ path: `${OUT}/multi-account-corner-off.png` });

        // And back, in the same session: a setting that only goes one way is
        // half a setting.
        await setCorner(page, true);
        await page.goto("/mail/inbox");
        await expect(corners.first()).toBeVisible();
    });
});
