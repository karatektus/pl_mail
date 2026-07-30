import { test, expect, type Page } from "@playwright/test";
import { login } from "./support/config";
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

test("Next advances a step without closing the dialog", async ({ page }) => {
    await login(page, PENDING.email, PENDING.password);
    await expect(wizard(page)).toBeVisible();

    // Skip forward to the profile step, whose fields the seeder has already
    // filled. Which steps come before it varies with what the rest of the suite
    // has configured — an admin who enables a provider makes the integrations
    // step applicable — and the earlier ones open on blank required forms,
    // where Next is *supposed* to stay put.
    for (let i = 0; i < 4; i++) {
        if (await page.locator("#onboarding-step-profile").isVisible()) {
            break;
        }

        await page.locator("#onboarding-skip").click();
    }

    await expect(page.locator("#onboarding-step-profile")).toBeVisible();

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
