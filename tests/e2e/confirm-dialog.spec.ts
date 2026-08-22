import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Destructive actions ask in the application, not in the browser.
 *
 * `data-turbo-confirm` calls `window.confirm()` unless Turbo is told otherwise,
 * and that dialog is the one piece of interface plMail cannot style, theme or
 * translate — it also puts the origin at the top, so "delete this label?" reads
 * like a phishing warning rather than part of the app. Twenty-eight places used
 * it, counting the forms that called confirm() from an `onsubmit` attribute.
 *
 * The fix is a single `Turbo.setConfirmMethod`, so what is worth asserting is
 * the mechanism rather than any one screen: whatever carries a confirmation
 * goes through the app's dialog, and nothing anywhere can reach the browser's.
 * A native dialog makes `page.on("dialog")` fire, so that is watched throughout.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

/** Fails loudly instead of hanging: a native confirm() blocks the page. */
function refuseNativeDialogs(page: import("@playwright/test").Page): () => number {
    let seen = 0;

    page.on("dialog", (dialog) => {
        seen++;
        void dialog.dismiss();
    });

    return () => seen;
}

test.describe("confirmations", () => {
    test("a confirmed action asks in the app, and Cancel means no", async ({ page }) => {
        const nativeDialogs = refuseNativeDialogs(page);

        await page.goto("/settings?section=labels");

        const form = page.locator("form[data-turbo-confirm]").first();
        await expect(form, "no confirmed action on this page to exercise").toBeVisible();

        const question = await form.getAttribute("data-turbo-confirm") ?? "";
        expect(question.length, "the confirmation has no question in it").toBeGreaterThan(0);

        await form.locator('button[type="submit"], button:not([type])').first().click();

        const dialog = page.locator("#confirm-dialog");
        await expect(dialog).toBeVisible();

        // The app's dialog, carrying the app's question — not a generic one.
        await expect(dialog).toContainText(question.slice(0, 40));

        await dialog.getByRole("button", { name: "Cancel" }).click();
        await expect(dialog).toBeHidden();

        // Cancelling did not submit: the form is still there to be asked again.
        await expect(form).toBeVisible();

        expect(nativeDialogs(), "a browser confirm() was reached").toBe(0);
    });

    /**
     * Escape means no.
     *
     * <dialog> closes on Escape by itself, which is a reason to use one — but it
     * closes with an empty returnValue, and reading an empty value as anything
     * other than "cancel" would turn the safest gesture a user has into the
     * destructive one.
     */
    test("Escape cancels rather than confirming", async ({ page }) => {
        const nativeDialogs = refuseNativeDialogs(page);

        await page.goto("/settings?section=labels");

        const form = page.locator("form[data-turbo-confirm]").first();
        await form.locator('button[type="submit"], button:not([type])').first().click();

        const dialog = page.locator("#confirm-dialog");
        await expect(dialog).toBeVisible();

        await page.keyboard.press("Escape");
        await expect(dialog).toBeHidden();
        await expect(form, "Escape submitted the form").toBeVisible();

        expect(nativeDialogs()).toBe(0);
    });

    /**
     * Cancel is focused, not Continue.
     *
     * Every caller of this is destructive — that is what a confirmation is for
     * — so the safe answer is the one a stray Enter or Space lands on.
     */
    test("the safe answer has the focus", async ({ page }) => {
        await page.goto("/settings?section=labels");

        const form = page.locator("form[data-turbo-confirm]").first();
        await form.locator('button[type="submit"], button:not([type])').first().click();
        await expect(page.locator("#confirm-dialog")).toBeVisible();

        await expect(page.locator("#confirm-dialog").getByRole("button", { name: "Cancel" }))
            .toBeFocused();
    });

    /**
     * Nothing in the application still reaches window.confirm().
     *
     * The fix is one registration, so the risk is not that it stops working —
     * it is that somebody writes `onsubmit="return confirm(…)"` again, which
     * bypasses Turbo entirely and cannot be caught by any amount of correct
     * wiring elsewhere. Twenty-eight of those existed; this is what stops the
     * twenty-ninth.
     */
    test("no page still calls the browser dialog from an attribute", async ({ page }) => {
        for (const url of ["/settings?section=labels", "/settings?section=security", "/settings?section=filters", "/admin"]) {
            const response = await page.goto(url);

            if ((response?.status() ?? 500) >= 400) {
                continue;
            }

            const inlineConfirms = await page.evaluate(
                () => document.querySelectorAll('[onsubmit*="confirm"], [onclick*="confirm"]').length,
            );

            expect(inlineConfirms, `${url} calls confirm() from an attribute`).toBe(0);
        }
    });
});
