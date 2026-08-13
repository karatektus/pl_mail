import { expect } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand } from "./support/config";

/**
 * The two push failures, told apart on the page.
 *
 * WHY A BROWSER SPEC
 * ──────────────────
 * The controller tests already prove which verdict the inspector reaches. What
 * only meets the user as appearance — and what the original complaint was
 * actually about — is whether somebody looking at this page can tell WHICH
 * failure they have and check the reasoning:
 *
 *  - the two cards must not read alike. A user watched Gmail push deliver
 *    nothing for twelve hours and could not tell, from the app, whether the
 *    watch had expired or was alive and silent. Those send you to a scheduler
 *    and to a Cloud console respectively.
 *  - the dates behind the verdict must be ON the card, not behind the
 *    technical disclosure. An answer one click away is an answer most people
 *    never see.
 *  - both must survive 414px and both themes. `emulateMedia` does nothing in
 *    this app — the theme is `data-theme` on <html>, which is also how a real
 *    user's choice arrives.
 *
 * Seeds its own broken state and clears it afterwards, so it neither depends
 * on nor leaves behind a damaged account for the specs that assert on account
 * lists.
 */

const ACCOUNT = "E2E Mailbox";

/**
 * Scope by user, not by account name. Every parallel worker's seeded account is
 * called "E2E Mailbox", so an unscoped WHERE is four rows — the lesson
 * account-health.spec.ts already paid for.
 *
 * The `user` table needs its quotes to survive a template literal, a shell and
 * Postgres reserving the word.
 */
const OWNED_BY_THIS_WORKER =
    `email = '${ACCOUNT}' AND usr_id = (SELECT id FROM \\"user\\" WHERE email = '${TEST_USER.email}')`;

/**
 * A Gmail account with push on. The provider columns matter: only an OAuth2
 * account whose provider is google is claimed by the Gmail push manager, so
 * without them there is no push to be broken and no card at all.
 */
function gmailPush(extra: string): void {
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'oauth2', oauth_provider = 'google', oauth_access_token = 'stale', oauth_refresh_token = 'stale', oauth_last_refresh_error = NULL, push_enabled = true, ${extra} WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

/** The watch ran out — renewal did not run. A fact, not a threshold. */
function makeLapsed(): void {
    gmailPush(
        "gmail_watch_expiry = NOW() - INTERVAL '1 day', gmail_last_push_at = NOW() - INTERVAL '3 days', gmail_history_advanced_at = NULL",
    );
}

/**
 * The watch is alive and unexpired, and the mailbox demonstrably moved long
 * after the last push announced anything — a change push missed.
 */
function makeSilent(): void {
    gmailPush(
        "gmail_watch_expiry = NOW() + INTERVAL '5 days', gmail_last_push_at = NOW() - INTERVAL '6 hours', gmail_history_advanced_at = NOW() - INTERVAL '1 hour'",
    );
}

function restoreTheAccount(): void {
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'password', oauth_provider = NULL, oauth_access_token = NULL, oauth_refresh_token = NULL, oauth_last_refresh_error = NULL, push_enabled = false, gmail_watch_expiry = NULL, gmail_last_push_at = NULL, gmail_history_advanced_at = NULL WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

test.describe("push health", () => {
    test.afterAll(restoreTheAccount);

    test("a lapsed watch says the registration expired, and shows when", async ({
        page,
    }) => {
        makeLapsed();
        await page.goto("/settings?section=health");

        const card = page.locator('[data-health-kind="push_lapsed"]');

        await expect(card).toBeVisible();
        await expect(card.getByRole("heading")).toContainText("expired");

        // The facts are on the card, not behind the disclosure.
        await expect(card.locator("[data-health-facts]")).toBeVisible();
        await expect(
            card.locator(
                '[data-health-fact="settings.health.fact.push_expires"]',
            ),
        ).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);

        // And the renewal line, which is what explains the expiry above it.
        await expect(
            card.locator(
                '[data-health-fact="settings.health.fact.push_last_renewal"]',
            ),
        ).toBeVisible();

        // The re-arm repair is offered, and it is the control that already
        // existed rather than a second route.
        await expect(card.locator('form[action*="/push/repair"]')).toBeVisible();
    });

    test("a live-but-silent watch reads differently and points elsewhere", async ({
        page,
    }) => {
        makeSilent();
        await page.goto("/settings?section=health");

        const card = page.locator('[data-health-kind="push_degraded"]');

        await expect(card).toBeVisible();

        // Emphatically NOT the lapsed card.
        await expect(page.locator('[data-health-kind="push_lapsed"]')).toHaveCount(0);

        // It names the leg that is actually broken — the part of the path
        // plMail cannot see and the Cloud console can.
        await expect(card).toContainText("Pub/Sub");

        // And its expiry line is in the future, which is the whole point: this
        // is a registration that is alive.
        const expiry = card.locator(
            '[data-health-fact="settings.health.fact.push_expires"]',
        );
        await expect(expiry).toBeVisible();
        await expect(expiry).not.toHaveClass(/text-amber/);

        // Captured beside the lapsed shots so the two can be read side by side
        // — "do these say different things?" is a question about the rendered
        // page, not about the markup.
        for (const theme of ["light", "dark"] as const) {
            await page.evaluate(
                (t) => document.documentElement.setAttribute("data-theme", t),
                theme,
            );
            await page.screenshot({
                path: `var/shots/push-silent-${theme}-desktop.png`,
                fullPage: true,
            });
        }
    });

    /**
     * The original complaint: nothing told them. Both verdicts are facts now,
     * so both are worth the one interruption this feature has.
     */
    test("a broken push lights the topbar indicator", async ({ page }) => {
        restoreTheAccount();
        await page.goto("/");

        const badge = page.locator("#user-menu-btn + span");
        const baseline =
            0 === (await badge.count())
                ? 0
                : Number((await badge.textContent())!.trim());

        makeLapsed();
        await page.goto("/");

        await expect(badge).toBeVisible();
        await expect(badge).toHaveText(String(baseline + 1));
    });

    /**
     * The repair says what happened.
     *
     * It reported nothing at all before: the accounts pane submits this form
     * from inside a turbo-frame and gets the frame back, but the health page
     * has no such frame, so pressing the button returned a fragment for a frame
     * that was not there and the page came back looking IDENTICAL. A repair
     * that reports nothing cannot be told from a repair that did nothing — and
     * re-registering really can fail, which is the case this asserts, because
     * the seeded credentials are deliberately stale.
     */
    test("pressing the repair says what happened, and the button is never stuck", async ({
        page,
    }) => {
        makeLapsed();
        await page.goto("/settings?section=health");

        const button = page.locator(
            '[data-health-kind="push_lapsed"] form[action*="/push/repair"] button',
        );

        await expect(button).toBeVisible();
        await expect(button).toBeEnabled();

        await button.click();

        // The answer, in words, rather than a page that looks unchanged.
        await expect(
            page.getByText("Could not re-register instant delivery"),
        ).toBeVisible();

        // And nothing is left disabled mid-press: the redirect lands on a
        // freshly rendered page, so any control it offers is a live one.
        const stuck = page.locator("button[disabled][data-turbo-submits-with]");
        await expect(stuck).toHaveCount(0);
    });

    test("reads at 414px", async ({ page }) => {
        makeLapsed();
        await page.setViewportSize({ width: 414, height: 851 });
        await page.goto("/settings?section=health");

        await expect(
            page.locator('[data-health-kind="push_lapsed"]'),
        ).toBeVisible();

        // The facts grid must not push the page sideways — it is the one new
        // piece of layout here, and a two-column grid holding timestamps is
        // exactly the shape that would.
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        );

        expect(overflow).toBe(false);
    });

    for (const theme of ["light", "dark"] as const) {
        for (const [label, width, height] of [
            ["desktop", 1280, 900],
            ["mobile", 414, 851],
        ] as const) {
            test(`push cards render in the ${theme} theme at ${label}`, async ({
                page,
            }) => {
                makeLapsed();
                await page.setViewportSize({ width, height });
                await page.goto("/settings?section=health");

                await page.evaluate(
                    (t) =>
                        document.documentElement.setAttribute("data-theme", t),
                    theme,
                );

                const card = page.locator('[data-health-kind="push_lapsed"]');

                await expect(card).toBeVisible();

                // Proving it is styled rather than merely present: the expiry
                // that has passed is tinted, so the one line that proves the
                // verdict is the line the eye lands on.
                await expect(
                    card.locator(
                        '[data-health-fact="settings.health.fact.push_expires"]',
                    ),
                ).toHaveClass(/text-amber/);

                // Written to var/shots, the convention appearance-shots.spec
                // established: an attachment on a PASSING test is discarded by
                // the reporter, and these are exactly the captures somebody
                // wants to look at when nothing failed.
                await page.screenshot({
                    path: `var/shots/push-lapsed-${theme}-${label}.png`,
                    fullPage: true,
                });
            });
        }
    }
});
