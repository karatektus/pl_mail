import { test, expect, type Page } from "./support/test";
import { TEST_USER, consoleCommand, login } from "./support/config";
import { totp } from "./support/totp";
import { acceptConfirm } from "./support/confirm";

/**
 * Two-factor authentication, driven the way a person would drive it: enrol
 * through the settings UI, read the setup key off the page, then act as the
 * authenticator app from there.
 *
 * Nothing is stubbed. That is the point — the failure this catches is the one
 * unit tests structurally cannot, where the secret plMail puts in the QR and
 * the configuration it later validates against have drifted apart. It scans
 * cleanly and rejects every code, and only a real round trip notices.
 *
 * Runs unauthenticated: enabling 2FA invalidates the shared storage state, and
 * these tests need to see the code prompt anyway.
 */
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * Runs serially and puts the user back afterwards — including when a test
 * fails halfway, which is what the console escape hatch is for.
 *
 * The user is this worker's own, so no other file is at risk, but the cleanup
 * still matters within this one: leaving 2FA on means the next test's login
 * stops at /2fa instead of the inbox, and a re-run finds an account it cannot
 * enrol again.
 */
test.describe.configure({ mode: "serial" });

test.afterAll(() => {
    consoleCommand(`app:user:2fa-disable ${TEST_USER.email} --force`);
});

/**
 * Enrol through the UI and return the shared secret.
 *
 * The manual setup key is read from the page rather than the database: it is
 * the same value the QR encodes, and taking it from the browser means the test
 * only trusts what a user could actually have scanned.
 */
async function enrol(page: Page): Promise<string> {
    await page.goto("/settings?section=security&enrol=1");

    await page.getByText("Can't scan? Enter a key instead").click();

    const secret = (await page.locator("details code").innerText()).trim();
    expect(secret).toMatch(/^[A-Z2-7]+$/);

    await submitCode(page, secret, page.getByRole("button", { name: "Confirm" }));

    // exact, because the success toast now says almost the same thing. The
    // flash used to reach the page as the raw key "two_factor.flash.enabled" —
    // nothing translated it — so this locator matched the panel heading alone
    // and passed for the wrong reason. Translating the flash (which is what a
    // user should see) made it match both, and strict mode rightly refused.
    // The heading has no trailing full stop; the sentence in the toast does.
    await expect(page.getByText("Two-factor authentication is on", { exact: true })).toBeVisible();

    return secret;
}

/**
 * Type a fresh code and submit it.
 *
 * Deliberately does NOT wait for the TOTP window to roll over — an earlier
 * version did, and it was pure superstition costing ~16s a run.
 *
 * otphp's verify() does not widen the window when given a leeway; it compares
 * exactly three codes, `at(t - leeway)`, `at(t)` and `at(t + leeway)`. With
 * plMail's period of 30 and leeway of 15 (config/packages/scheb_2fa.yaml), a
 * code minted in window W is therefore accepted for any submission in
 * [30W - 15, 30W + 45) — from 15s before the window opens until 15s after it
 * closes. A code generated with a hundredth of a second left is still good for
 * another 15s, and the submit below takes about a quarter of one.
 */
async function submitCode(page: Page, secret: string, submit = page.getByRole("button", { name: "Continue" })) {
    await page.locator('input[name="code"], input[name="_auth_code"]').first().fill(totp(secret));
    await submit.click();
}

test.describe("two-factor authentication", () => {
    test("enrols, then demands a code on the next sign-in", async ({ page }) => {
        await login(page);
        const secret = await enrol(page);

        await page.goto("/logout");

        // Password alone now stops at the code form rather than the inbox.
        await page.goto("/login");
        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/2fa/);
        await expect(page.locator("#_auth_code")).toBeVisible();

        // And the half-authenticated session reaches nothing.
        await page.goto("/mail/inbox");
        await expect(page).toHaveURL(/\/2fa/);

        await page.goto("/2fa");
        await submitCode(page, secret);

        await expect(page).toHaveURL(/\/mail\/inbox/);
    });

    /**
     * A rejected code comes back to /2fa, not to /login.
     *
     * The distinction is the whole test: both are "you did not get in", but
     * landing on /login reads as the *password* having been wrong, and sends a
     * user who mistyped six digits off to re-check credentials that were fine.
     *
     * Cheap — no enrolment, and it reuses the 2FA the previous test switched
     * on, which is what the serial mode above is for.
     */
    test("rejects a wrong code and stays on the prompt", async ({ page }) => {
        await page.goto("/login");
        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/2fa/);

        await page.locator("#_auth_code").fill("000000");
        await page.getByRole("button", { name: "Continue" }).click();

        await expect(page).toHaveURL(/\/2fa/);
        await expect(page.locator("#_auth_code")).toBeVisible();
    });

    /**
     * The requirement the whole DB-backed trusted-device design exists for:
     * revoking a remembered device takes effect on its next request, not
     * whenever a signed cookie happens to expire.
     */
    test("remembers a device, then stops the moment it is revoked", async ({ page }) => {
        const secret = await signInWithSecret(page);

        await page.goto("/settings?section=security");
        await expect(page.getByText("this device")).toBeVisible();

        await page.goto("/logout");

        // Remembered: straight to the inbox, no code asked for.
        await page.goto("/login");
        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/mail\/inbox/);

        // Revoke it, then sign in again — the cookie is still in the browser,
        // and it must no longer be worth anything.
        await page.goto("/settings?section=security");
        await page.getByRole("button", { name: "Revoke every remembered device" }).click();
        await acceptConfirm(page);

        // The panel's whole sentence, not a prefix of it. The toast that
        // appears alongside says "No devices are remembered any more.", so
        // "No devices are remembered" matches both and strict mode refuses.
        // Matching the empty-state text in full is unambiguous against
        // anything the flash bag can add.
        await expect(
            page.getByText(
                "No devices are remembered. You will be asked for a code every time you sign in.",
            ),
        ).toBeVisible();

        await page.goto("/logout");
        await page.goto("/login");
        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/2fa/);

        // Put the session back for the next test in the serial run.
        await submitCode(page, secret);
        await expect(page).toHaveURL(/\/mail\/inbox/);
    });

    /**
     * Sign in with 2FA already on, ticking "don't ask again", and hand back the
     * secret. Re-enrols first so the test does not depend on which earlier test
     * left what behind.
     */
    async function signInWithSecret(page: Page): Promise<string> {
        consoleCommand(`app:user:2fa-disable ${TEST_USER.email} --force`);

        await login(page);
        const secret = await enrol(page);

        await page.goto("/logout");
        await page.goto("/login");
        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/2fa/);

        await page.locator("#_trusted").check();
        await submitCode(page, secret);

        await expect(page).toHaveURL(/\/mail\/inbox/);

        return secret;
    }
});
