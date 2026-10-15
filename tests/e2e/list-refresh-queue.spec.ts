import { test, expect } from "./support/test";

/**
 * A list refresh asked for while another is in flight is taken afterwards,
 * not dropped.
 *
 * Found as an intermittent failure of bulk-select-all.spec.ts: a whole-view
 * archive runs as a job, a nudge from the job starts a refresh while the job is
 * still writing, the job's "done" arrives while that fetch is out — and was
 * thrown away, so the list kept the archived conversations on screen with
 * nothing left to refresh it. Whether it happened depended on how the two
 * timings fell under load. This makes them fall that way every time: the first
 * fetch is held at the network, and the second request arrives while it is.
 */
test("a refresh asked for during another is taken when the first lands", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(page.locator("#message-list li").first()).toBeVisible();

    let fetches = 0;
    let releaseFirst: () => void = () => {};
    const firstHeld = new Promise<void>((resolve) => {
        releaseFirst = resolve;
    });

    await page.route("**/mail/inbox**", async (route) => {
        if (undefined === route.request().headers()["x-list-fragment"]) {
            await route.continue();

            return;
        }

        fetches += 1;

        if (1 === fetches) {
            await firstHeld;
        }

        await route.continue();
    });

    const jobChanged = (state: string) =>
        page.evaluate((detail) => {
            document.body.dispatchEvent(new CustomEvent("core--mercure:jobs-changed", { detail, bubbles: true }));
        }, { state });

    // The first refresh starts and is held in flight…
    await jobChanged("done");
    await expect.poll(() => fetches).toBe(1);

    // …the job's last word arrives while it is…
    await jobChanged("done");

    // …and is taken once the first lands, rather than lost.
    releaseFirst();
    await expect.poll(() => fetches, { timeout: 5_000 }).toBe(2);
});
