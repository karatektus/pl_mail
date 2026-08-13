import { expect, type Page } from "@playwright/test";

/**
 * Walking the setup wizard, shared by the two specs that do it.
 *
 * It lived in onboarding.spec.ts, where the "wait for a *settled* step change"
 * rule was learned the hard way, while ui-widgets.spec.ts had its own copy that
 * skipped and then slept 250ms. That copy is the one this module exists to
 * retire: a fixed sleep is a bet on how long a Turbo frame takes to come back,
 * and the whole suite is other specs making the same app slower.
 *
 * The failure a fixed sleep buys is not a timeout, which would at least read as
 * one. The sleep expires, the step still looks like the old one, the loop asks
 * whether the skip button is enabled — and the reply arrives in that gap, so
 * the click that follows lands on the button of the step that has just been
 * swapped in. The wanted step is skipped past without ever being looked at, and
 * the loop then walks the rest of the wizard, finishes it, and reports whatever
 * is left on screen. Nothing about that error names the sleep.
 *
 * Waiting for the step id to become a settled, different one removes the gap:
 * every click happens against a DOM that has stopped moving.
 */

/** The id of the step currently rendered, or "" mid-swap. */
export async function currentStepId(page: Page): Promise<string> {
    return (
        (await page
            .locator("#onboarding-wizard")
            .locator("[id^='onboarding-step-']")
            .first()
            .getAttribute("id")) ?? ""
    );
}

/**
 * Skip forward until `step` (the enum value, e.g. "admin-integrations") is on
 * screen.
 *
 * Which steps come before it depends on the user and on what the rest of the
 * suite has left configured — the user-facing integrations step only exists
 * once an admin has enabled a provider, which is install-wide — so the count
 * cannot be hard-coded and this loops instead.
 *
 * Skip rather than the progress-rail pills: those submit the step form, which
 * for the two credential steps SAVES install-wide provider configuration on the
 * way past. A test that only wants to look at a step must not decide anything
 * for every other worker while getting there.
 */
export async function skipToStep(page: Page, step: string): Promise<void> {
    const wanted = page.locator(`#onboarding-step-${step}`);

    for (let i = 0; i < 8; i++) {
        if (await wanted.isVisible()) {
            return;
        }

        const before = await currentStepId(page);

        // Turbo disables a submit button while its request is in flight, so
        // this also waits out the previous skip rather than clicking a dead
        // button and hanging until the test budget runs out.
        await expect(page.locator("#onboarding-skip")).toBeEnabled();
        await page.locator("#onboarding-skip").click();

        // Must be a *settled* different step. Mid-swap the frame briefly has no
        // step element at all and currentStepId() answers "" — which is not
        // `before`, so a plain inequality check passes while the DOM is still
        // changing, and the next click lands on a step nobody has looked at.
        await expect
            .poll(async () => {
                const now = await currentStepId(page);

                return "" !== now && now !== before;
            })
            .toBe(true);
    }

    await expect(wanted).toBeVisible();
}
