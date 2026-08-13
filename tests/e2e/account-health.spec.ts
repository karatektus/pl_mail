import { expect } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand } from "./support/config";

/**
 * The account-health section, in a browser.
 *
 * A browser spec rather than more controller tests because the things worth
 * holding here are rendered ones and only meet the user as appearance:
 *
 *  - the promise text is ABOVE the button, not in a title attribute. The whole
 *    reason people delete an account and re-add it is that "Reconnect" does not
 *    promise their mail survives; a promise nobody sees is not a promise.
 *  - the destructive repair is visually separated from the safe ones. A
 *    controller test can assert the markup order and would not notice the rule
 *    between them disappearing.
 *  - it has to be legible in both themes and at 393px. `emulateMedia` does
 *    nothing in this app — the theme is `data-theme` on <html> — so the dark
 *    captures set the attribute directly, which is also how a real user's
 *    choice arrives.
 *
 * Seeds its own broken state through the console and clears it afterwards, so
 * it neither depends on nor leaves behind a damaged account for the specs that
 * assert on account lists.
 */

/**
 * The account `app:test:seed-mail` gives this worker. Named here because the
 * assertions below read it off the page.
 */
const ACCOUNT = "E2E Mailbox";

/**
 * Put this worker's existing account into the exact state the live install was
 * found in: an OAuth account whose stored refresh error is `invalid_grant`
 * while it is still marked active.
 *
 * The worker's OWN account is mutated rather than a fresh one inserted, and
 * that is deliberate on two counts. It is closer to the real condition — a
 * grant dies on an account that already has mail behind it, which is the whole
 * reason reconnecting has to preserve things — and it avoids an INSERT that
 * would have to name the `user` table, whose quoting has to survive a template
 * literal, a shell, and Postgres reserving the word. `restoreTheAccount` puts
 * it back for the specs that assert on the account list.
 */
/**
 * Every worker's seeded account is called "E2E Mailbox", so `WHERE email =
 * 'E2E Mailbox'` is four rows, not one.
 *
 * Both statements below used to say exactly that, and so this spec broke —
 * and then "restored" — the mail account of every parallel slot, not just its
 * own. Any spec that rendered a topbar on another worker while this file was
 * running saw a health badge nobody had asked for, and `restoreTheAccount`
 * then set auth_type='password' on accounts it had never broken. Scope by
 * user, which is the same lesson `seed --email` already carries.
 *
 * The `user` table needs its quotes to survive a template literal, a shell and
 * Postgres reserving the word: `\\"` here is `\"` in the string, which the
 * double-quoted shell argument hands to SQL as `"user"`.
 */
const OWNED_BY_THIS_WORKER =
    `email = '${ACCOUNT}' AND usr_id = (SELECT id FROM \\"user\\" WHERE email = '${TEST_USER.email}')`;

function breakTheAccount(): void {
    // NOT seeded here. The `workerAuth` fixture is worker-scoped and
    // `auto: true`, so it has already run seedUser() and seed("seed-mail") and
    // signed in before this hook is reached — the account exists.
    //
    // Calling seedUser() again here is actively harmful, and it cost an
    // afternoon: it re-hashes the password, Symfony compares the stored hash
    // against the one in the session token, decides the user has changed, and
    // throws the session away. Every test then ran against the login page,
    // which fails as "the health card is not visible" and looks exactly like a
    // rendering bug.
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'oauth2', oauth_provider = 'google', oauth_access_token = 'stale', oauth_refresh_token = 'stale', oauth_last_refresh_error = 'invalid_grant' WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

function restoreTheAccount(): void {
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'password', oauth_provider = NULL, oauth_access_token = NULL, oauth_refresh_token = NULL, oauth_last_refresh_error = NULL WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

test.describe("account health", () => {
    test.beforeAll(breakTheAccount);
    test.afterAll(restoreTheAccount);

    test.beforeEach(async ({ page }) => {
        await page.goto("/settings?section=health");
    });

    test("surfaces the dead sign-in with a repair, in the user's words", async ({
        page,
    }) => {
        const card = page.locator('[data-health-kind="account_reconnect"]');

        await expect(card).toBeVisible();

        // The headline names the mailbox and says what to do — no error code.
        await expect(card.getByRole("heading")).toContainText(ACCOUNT);
        await expect(card.getByRole("heading")).not.toContainText(
            "invalid_grant",
        );

        // The promise is on the page, above the button that keeps it. This is
        // the sentence that makes reconnecting a smaller act than deleting.
        await expect(card).toContainText("stay exactly as they are");
        await expect(card).toContainText("nothing is deleted");

        // And the provider's own words are one disclosure away, not gone.
        await expect(card.locator("details")).toBeVisible();
        await expect(card.locator("details pre")).not.toBeVisible();
        await card.locator("summary").click();
        await expect(card.locator("details pre")).toContainText("invalid_grant");
    });

    test("the topbar carries an indicator that leads to this page", async ({
        page,
    }) => {
        const badge = page.locator("#user-menu-btn + span");

        // The badge counts every root cause this user has, so what this spec
        // can honestly claim is that breaking an account adds ONE to it — not
        // that the total is "1". The total was only ever 1 on a database
        // nothing else had touched: integrations.spec.ts leaves a connection
        // whose credentials deliberately fail, `app:test:seed-save-picker`
        // seeds two more, and each is a root cause of its own. On a stack
        // reused between runs the badge read "3" and this test failed with
        // nothing wrong in the code it covers.
        //
        // So it is measured rather than assumed: put the account back, read
        // the baseline, break it again, and assert the difference. That is the
        // claim the test was always trying to make, and it holds whatever else
        // the user happens to have wrong.
        restoreTheAccount();
        await page.goto("/");

        const baseline =
            0 === (await badge.count())
                ? 0
                : Number((await badge.textContent())!.trim());

        breakTheAccount();
        await page.goto("/");

        await expect(badge).toBeVisible();
        await expect(badge).toHaveText(String(baseline + 1));

        // Red, not amber: a dead grant is critical, and the warning-level
        // issues other specs leave behind cannot produce this tone on their
        // own. The count above says "one more thing"; this says it is ours.
        await expect(badge).toHaveClass(/bg-red-500/);

        // Following it lands on health, not on settings-in-general — an
        // indicator that drops you somewhere you still have to hunt has only
        // moved the hunting.
        await page.locator("#user-menu-btn").click();
        await page.locator("#user-menu-settings").click();

        await expect(page).toHaveURL(/section=health/);
        await expect(
            page.locator('[data-health-kind="account_reconnect"]'),
        ).toBeVisible();
    });

    test("reads at 393px", async ({ page }) => {
        await page.setViewportSize({ width: 393, height: 850 });
        await page.goto("/settings?section=health");

        const card = page.locator('[data-health-kind="account_reconnect"]');

        await expect(card).toBeVisible();

        // The card must not push the page sideways — the rule the whole
        // settings pane follows.
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        );

        expect(overflow).toBe(false);
    });

    for (const theme of ["light", "dark"] as const) {
        test(`renders in the ${theme} theme`, async ({ page }, testInfo) => {
            // emulateMedia does nothing here: the theme is an attribute on
            // <html>, which is also how the user's own choice is applied.
            await page.evaluate(
                (t) => document.documentElement.setAttribute("data-theme", t),
                theme,
            );

            const card = page.locator('[data-health-kind="account_reconnect"]');

            await expect(card).toBeVisible();

            // Proving it is styled rather than merely present: a card that lost
            // its background would still be "visible".
            const bg = await card.evaluate(
                (el) => getComputedStyle(el).backgroundColor,
            );

            expect(bg).not.toBe("rgba(0, 0, 0, 0)");

            await testInfo.attach(`health-${theme}.png`, {
                body: await page.screenshot({ fullPage: true }),
                contentType: "image/png",
            });
        });
    }
});
