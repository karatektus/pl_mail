import { test, expect } from "./support/test";

/**
 * A request that fails says so in a toast, and the page stays as it was.
 *
 * Reported from production: a profile save that failed left plMail's 500 page
 * hanging off the bottom of the settings screen. request_errors.js now stops
 * Turbo from rendering any failed response (422, 401 and 403 aside) and raises
 * a toast carrying the request's reference instead.
 *
 * The failure is faked at the network rather than provoked in the app, so the
 * spec does not depend on something in plMail being broken: Playwright answers
 * the form's POST with a 500 that looks like the real one — an error page and
 * an X-Request-Id.
 */
test("a failed form submission raises a toast and leaves the page alone", async ({ page }) => {
    await page.goto("/settings?section=profile");

    const form = page.locator('form[action$="/settings/profile/signature"]');
    await expect(form).toHaveCount(1);

    await page.route("**/settings/profile/signature", (route) =>
        route.fulfill({
            status: 500,
            contentType: "text/html",
            headers: { "X-Request-Id": "abcd1234" },
            body: "<!DOCTYPE html><html><head><title>500</title></head><body><h1>Something went wrong</h1></body></html>",
        }),
    );

    // Past the pad's own "draw something first" check, which is not what is
    // being tested: the submit goes out, and the server fails it.
    await form.evaluate((f: HTMLFormElement) => {
        f.querySelectorAll("[required]").forEach((el) => el.removeAttribute("required"));
        f.noValidate = true;
        f.requestSubmit();
    });

    const toast = page.locator("#toast-region [role=alert]");
    await expect(toast).toBeVisible();
    await expect(toast).toContainText("Ref. abcd1234");

    // The reference copies — the id alone, which is what the log browser's
    // Reference field takes.
    await page.context().grantPermissions(["clipboard-read", "clipboard-write"]);
    await toast.getByRole("button", { name: "Copy the reference" }).click();
    await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe("abcd1234");

    // Nothing of the error page was drawn, and the settings page is intact.
    await expect(page.getByRole("heading", { name: "Something went wrong" })).toHaveCount(0);
    await expect(page.locator("#sidebar")).toBeVisible();
    await expect(page).toHaveURL(/section=profile/);
});
