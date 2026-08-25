import { test, expect, type Page } from "./support/test";
import { WORKER_SLOT, consoleCommand, login, seedUser } from "./support/config";
import { totp } from "./support/totp";
import { currentStepId, skipToStep } from "./support/onboarding";

/**
 * The setup wizard: it opens by itself for someone who has not been through it,
 * once, and the user menu is how they get back to it afterwards.
 *
 * Own user and own session, like the admin specs — and here it is not only
 * about roles. The wizard opens over a backdrop that is `fixed inset-0 z-50`
 * and swallows every click, so a shared user left pending would break every
 * other spec in the suite. `app:test:seed-user` finishes setup by default and
 * only leaves it pending when asked, which is what this spec asks for.
 *
 * Located by element id throughout, never by visible text.
 */
// Slot-suffixed like every other fixture user. This file cannot itself split
// across workers, but the address must still be unique per worker: a retry
// spawns a replacement worker, and two processes reseeding one pending user
// would fight over whether the wizard is finished.
const PENDING = {
    email: `e2e-onboarding-w${WORKER_SLOT}@plmail.test`,
    password: "e2e-onboarding-password",
};

test.use({ storageState: { cookies: [], origins: [] } });

function seedPendingUser(pending: boolean): void {
    seedUser({
        email: PENDING.email,
        password: PENDING.password,
        pendingOnboarding: pending,
    });
}

const backdrop = (page: Page) => page.locator("#modal-backdrop");
const wizard = (page: Page) => page.locator("#onboarding-wizard");

test.beforeEach(() => {
    // Re-seeded per test: finishing setup is persistent, so a second test would
    // otherwise run against a user the first one already took through it.
    seedPendingUser(true);

    // Two-factor is persistent too, and the seeder does not clear it. Without
    // this, the enrolment test below leaves the user needing a code, and every
    // later test's login stops at /2fa instead of the inbox — and on a re-run
    // it finds the step already done and never opens the panel at all.
    //
    // In beforeEach rather than afterAll on purpose: the point is that each
    // test starts from a known state, not that this file tidies up after
    // itself. afterAll fires too late to help the test that runs next.
    consoleCommand(`app:user:2fa-disable ${PENDING.email} --force`);
});

test("it opens by itself after signing in, without leaving the mail page", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);

    await expect(wizard(page)).toBeVisible();
    await expect(backdrop(page)).toBeVisible();

    // A modal, not a redirect: the app is behind it, already loaded.
    await expect(page).toHaveURL(/\/mail\/inbox/);
});

/**
 * Which step comes first depends on the user — a fresh one with no mailbox
 * starts at the account step — so the specs below assert that the step
 * *changed*, not which one it landed on.
 *
 * `currentStepId` and the skip loop used to live here. They are in
 * tests/e2e/support/onboarding.ts now, because ui-widgets.spec.ts walks the
 * wizard too and had grown a second, blind-sleeping copy of the same loop.
 */

test("Next advances a step without closing the dialog", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    // Skip forward to the profile step, whose fields the seeder has already
    // filled. The earlier steps open on blank required forms, where Next is
    // *supposed* to stay put.
    //
    // Through skipToStep() rather than a bare loop: this clicked skip a fixed
    // number of times without waiting for the step to change, which raced
    // Turbo disabling the button mid-submit and hung until the timeout.
    await skipToStep(page, "profile");

    const before = await currentStepId(page);

    await page.locator("#onboarding-next").click();

    // The step form submits, and the wizard must survive it: the modal closes
    // on any successful submit unless the content sits inside
    // [data-ui--modal-keep-open].
    await expect(backdrop(page)).toBeVisible();
    await expect(page.locator(`#${before}`)).toBeHidden();
});

test("skipping a step advances it too", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    const before = await currentStepId(page);

    await page.locator("#onboarding-skip").click();

    await expect(backdrop(page)).toBeVisible();
    await expect(page.locator(`#${before}`)).toBeHidden();
});

test("skipping setup ends it, and it does not come back on the next load", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);

    await page.locator("#onboarding-skip-all").click();
    await expect(page.locator("#onboarding-done")).toBeVisible();

    await page.goto("/mail/inbox");

    // Setup opens by itself exactly once. Anything else is nagging.
    await expect(backdrop(page)).toBeHidden();
});

test("the user menu opens it again once it has been finished", async ({ page }) => {
    seedPendingUser(false);

    await login(page, PENDING.email, PENDING.password);

    await expect(backdrop(page)).toBeHidden();

    // Retried, because the first click can be lost. The menu is opened by a
    // Stimulus controller, and Playwright's actionability checks say nothing
    // about whether a controller has connected — the button is visible and
    // enabled in the server's HTML, long before the module graph that animates
    // it has been fetched. A click that lands in that gap does nothing at all,
    // and no amount of waiting afterwards opens a menu, so the retry has to
    // wrap the click rather than follow it.
    //
    // It only ever showed up on a slow server: this page pulls 56 assets, and
    // FrankenPHP serves them fast enough that the gap has usually closed by the
    // time the test gets here. PHP's built-in server does not.
    await expect(async () => {
        await page.locator("#user-menu-btn").click();
        await expect(page.locator("#user-menu")).toBeVisible({ timeout: 1_000 });
    }).toPass();

    await page.locator("#user-menu-rerun-setup").click();

    await expect(wizard(page)).toBeVisible();
});

/**
 * Two-factor enrolment, done from inside the wizard.
 *
 * This exists because the step shipped broken in a way no other test could
 * see. The wizard body renders into a bare <turbo-frame> with no page layout,
 * and the step opened its panel with `data-turbo="false"` — a full navigation,
 * which rendered that fragment as an unstyled page. Behind it was a worse one:
 * the confirm field sat in a second <form> nested inside the wizard's, which
 * browsers drop, so the button submitted the wizard and advanced the step
 * without ever enrolling.
 *
 * PageRendersTest could not catch either — it asks for the step URL directly
 * and only checks for a 200, which is exactly what the broken version gave.
 * Both failures need a real click from inside the modal.
 */
test("enrols in two-factor authentication without leaving the wizard", async ({ page }) => {
    // Skips through several steps before it gets to the interesting part.
    test.setTimeout(45_000);

    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    await skipToStep(page, "security");

    await page.locator("#onboarding-2fa-open").click();

    // The regression: opening the panel must stay inside the modal. A full
    // navigation lands on the layout-less fragment, where the wizard shell and
    // its backdrop are both gone.
    await expect(wizard(page)).toBeVisible();
    await expect(backdrop(page)).toBeVisible();
    await expect(page).toHaveURL(/\/mail\/inbox/);

    await page.locator("#onboarding-step-security details summary").click();
    const secret = (await page.locator("#onboarding-2fa-secret").innerText()).trim();
    expect(secret).toMatch(/^[A-Z2-7]+$/);

    // No waiting for the TOTP window to roll over: otphp accepts a code minted
    // in window W for any submission in [30W - 15, 30W + 45), so one generated
    // right now is good for at least the next 15s. See submitCode() in
    // twofactor.spec.ts for the derivation.
    await page.locator("#onboarding-step-security input[type='text']").fill(totp(secret));
    await page.locator("#onboarding-2fa-confirm").click();

    // Still in the wizard, on the same step, now reporting success — the step
    // must not advance past the one screen the recovery codes are shown on.
    await expect(wizard(page)).toBeVisible();
    await expect(page.locator("#onboarding-step-security")).toBeVisible();
    await expect(page.locator("#onboarding-2fa-codes")).toBeVisible();

    // And it actually took, rather than the wizard merely looking pleased.
    await page.goto("/settings?section=security");
    // exact, because the success toast now says almost the same thing. The
    // flash used to reach the page as the raw key "two_factor.flash.enabled" —
    // nothing translated it — so this locator matched the panel heading alone
    // and passed for the wrong reason. Translating the flash (which is what a
    // user should see) made it match both, and strict mode rightly refused.
    // The heading has no trailing full stop; the sentence in the toast does.
    await expect(page.getByText("Two-factor authentication is on", { exact: true })).toBeVisible();
});

test("closing the dialog quietens it without ending setup", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);

    await expect(wizard(page)).toBeVisible();

    await page.keyboard.press("Escape");
    await expect(backdrop(page)).toBeHidden();

    // Quiet for the rest of the tab...
    await page.goto("/mail/inbox");
    await expect(backdrop(page)).toBeHidden();

    // ...but not finished. Closing used to mark setup done, which meant a
    // dialog closing for a reason nobody chose ended it for good.
    await page.context().clearCookies();
    await page.evaluate(() => sessionStorage.clear());
    await login(page, PENDING.email, PENDING.password);

    await expect(wizard(page)).toBeVisible();
});
