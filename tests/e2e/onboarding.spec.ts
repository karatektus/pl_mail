import { test, expect, type Page } from "@playwright/test";
import { login } from "./support/config";
import { secondsUntilNextWindow, totp } from "./support/totp";
import { execSync } from "node:child_process";

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
const CONSOLE = process.env.E2E_CONSOLE ?? "php bin/console";

const PENDING = {
    email: "e2e-onboarding@plmail.test",
    password: "e2e-onboarding-password",
};

test.use({ storageState: { cookies: [], origins: [] } });

function seedUser(pending: boolean): void {
    execSync(
        [
            CONSOLE,
            "app:test:seed-user",
            `--email=${PENDING.email}`,
            `--password=${PENDING.password}`,
            pending ? "--pending-onboarding" : "",
        ]
            .filter(Boolean)
            .join(" "),
        { stdio: "inherit", env: { ...process.env, APP_ENV: "test" } },
    );
}

const backdrop = (page: Page) => page.locator("#modal-backdrop");
const wizard = (page: Page) => page.locator("#onboarding-wizard");

test.beforeEach(() => {
    // Re-seeded per test: finishing setup is persistent, so a second test would
    // otherwise run against a user the first one already took through it.
    seedUser(true);

    // Two-factor is persistent too, and the seeder does not clear it. Without
    // this, the enrolment test below leaves the user needing a code, and every
    // later test's login stops at /2fa instead of the inbox — and on a re-run
    // it finds the step already done and never opens the panel at all.
    //
    // In beforeEach rather than afterAll on purpose: the point is that each
    // test starts from a known state, not that this file tidies up after
    // itself. afterAll fires too late to help the test that runs next.
    execSync(`${CONSOLE} app:user:2fa-disable ${PENDING.email} --force`, {
        stdio: "inherit",
        env: { ...process.env, APP_ENV: "test" },
    });
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
 */
async function currentStepId(page: Page): Promise<string> {
    return (await wizard(page).locator("[id^='onboarding-step-']").first().getAttribute("id")) ?? "";
}

/**
 * Skip forward until the wanted step is on screen.
 *
 * Which steps come before it depends on what the rest of the suite has left
 * configured, so the count cannot be hard-coded. Each iteration waits for the
 * step to actually change before clicking again: Turbo disables a submit
 * button while its request is in flight, so clicking blindly in a loop hits a
 * disabled button and hangs until the test times out.
 *
 * Skip rather than the progress-rail pills, which submit the wizard form —
 * from the account step of a fresh user that form is a blank required mailbox,
 * and the jump is refused.
 */
async function skipTo(page: Page, step: string): Promise<void> {
    const wanted = page.locator(`#onboarding-step-${step}`);

    for (let i = 0; i < 8; i++) {
        if (await wanted.isVisible()) {
            return;
        }

        const before = await currentStepId(page);

        await expect(page.locator("#onboarding-skip")).toBeEnabled();
        await page.locator("#onboarding-skip").click();

        // Must be a *settled* different step. Mid-swap the frame briefly has no
        // step element at all, and currentStepId() answers "" — which is not
        // `before`, so a plain inequality check passes while the DOM is still
        // changing. The loop then clicks skip again and silently loses a step,
        // sailing past the one it was looking for.
        await expect
            .poll(async () => {
                const now = await currentStepId(page);

                return "" !== now && now !== before;
            })
            .toBe(true);
    }

    await expect(wanted).toBeVisible();
}

test("Next advances a step without closing the dialog", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    // Skip forward to the profile step, whose fields the seeder has already
    // filled. The earlier steps open on blank required forms, where Next is
    // *supposed* to stay put.
    //
    // Through skipTo() rather than a bare loop: this clicked skip a fixed
    // number of times without waiting for the step to change, which raced
    // Turbo disabling the button mid-submit and hung until the timeout.
    await skipTo(page, "profile");

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
    seedUser(false);

    await login(page, PENDING.email, PENDING.password);

    await expect(backdrop(page)).toBeHidden();

    await page.locator("#user-menu-btn").click();
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
    // Skipping through steps, then possibly sitting out a TOTP window.
    test.setTimeout(90_000);

    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    await skipTo(page, "security");

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

    if (secondsUntilNextWindow() < 15) {
        await page.waitForTimeout(secondsUntilNextWindow() * 1000 + 500);
    }

    await page.locator("#onboarding-step-security input[type='text']").fill(totp(secret));
    await page.locator("#onboarding-2fa-confirm").click();

    // Still in the wizard, on the same step, now reporting success — the step
    // must not advance past the one screen the recovery codes are shown on.
    await expect(wizard(page)).toBeVisible();
    await expect(page.locator("#onboarding-step-security")).toBeVisible();
    await expect(page.locator("#onboarding-2fa-codes")).toBeVisible();

    // And it actually took, rather than the wizard merely looking pleased.
    await page.goto("/settings?section=security");
    await expect(page.getByText("Two-factor authentication is on")).toBeVisible();
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
