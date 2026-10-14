import { test, expect, type Page } from "./support/test";

/**
 * The topbar sync button's lifecycle, as the rest of the page can see it.
 *
 * The button announces when a run starts and when it stops, so a gesture (pull to
 * refresh) can show the same spinning state whatever started the run. This tests
 * the button alone, at desktop width: the gesture is tested in pull-to-refresh.spec.ts.
 *
 * Clock arithmetic (sync_now_controller.js defaults): the first status check runs
 * pollInterval = 1500ms after the POST resolves, and each check that reads zero
 * pending adds one to a run of zeroes. The run is confirmed on the third zero
 * (confirmations = 3), so the earliest stop is the third poll, at 3 x 1500 = 4500ms
 * of clock time after the POST resolved. After the second poll it must not have
 * stopped: 2 x 1500 = 3000ms, with two zeroes, which is short of three.
 *
 * The clock is stepped 1500ms at a time, and each step waits for the status request
 * it causes, because a check only schedules the next one after its own fetch
 * resolves. One runFor(4500) would not give three checks.
 */
test.use({ viewport: { width: 1280, height: 800 } });

const BUTTON = '[data-controller~="mail--sync-now"]';
const ICON = `${BUTTON} [data-mail--sync-now-target="icon"]`;
const POLL_MS = 1500;

const isSync = (url: URL) => url.pathname === "/mail/sync";
const isStatus = (url: URL) => url.pathname === "/mail/sync/status";

/** The lifecycle events the page has heard, in order, from the document. */
async function heard(page: Page): Promise<string[]> {
    return page.evaluate(() => (window as unknown as { __syncEvents: string[] }).__syncEvents);
}

test("the button announces a started run on the click, and a stopped run on the third idle poll", async ({ page }) => {
    await page.clock.install();

    await page.route(isSync, (route) => {
        if (route.request().method() === "POST") {
            return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
        }

        return route.continue();
    });
    await page.route(isStatus, (route) =>
        route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
    );

    await page.goto("/mail/inbox");

    await page.evaluate(() => {
        const win = window as unknown as { __syncEvents: string[] };

        win.__syncEvents = [];
        document.addEventListener("mail--sync-now:started", () => win.__syncEvents.push("started"));
        document.addEventListener("mail--sync-now:stopped", () => win.__syncEvents.push("stopped"));
    });

    const posted = page.waitForResponse((response) => isSync(new URL(response.url())) && response.request().method() === "POST");

    await page.locator(BUTTON).click();
    await posted;

    // Presence: started has fired, on the click, before any poll.
    expect(await heard(page)).toEqual(["started"]);
    await expect(page.locator(ICON)).toHaveClass(/fa-spin/);

    // Poll 1 and poll 2: each reads zero, and neither is enough.
    for (let poll = 1; poll <= 2; poll++) {
        const checked = page.waitForResponse((response) => isStatus(new URL(response.url())));

        await page.clock.runFor(POLL_MS);
        await checked;
    }

    expect(await heard(page), "not stopped after two zeroes").toEqual(["started"]);
    await expect(page.locator(ICON), "still spinning after two zeroes").toHaveClass(/fa-spin/);

    // Poll 3: the third zero, and the run is over.
    const third = page.waitForResponse((response) => isStatus(new URL(response.url())));

    await page.clock.runFor(POLL_MS);
    await third;

    await expect.poll(() => heard(page)).toEqual(["started", "stopped"]);
    await expect(page.locator(ICON)).not.toHaveClass(/fa-spin/);
});
