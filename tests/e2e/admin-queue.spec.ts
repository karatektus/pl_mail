import { test, expect, type Page } from "./support/test";
import { TEST_ADMIN, consoleCommand, login, seedUser } from "./support/config";

/**
 * The queue panel: what a worker is holding, and the backlog behind it.
 *
 * This is the screen somebody opens when mail has stopped arriving, so the
 * things worth pinning are the ones that would mislead them. The list is a
 * fixed-height scroller that pages, and its filter queries the whole queue
 * rather than the rows already fetched — a filter that only searched the page
 * would answer "nothing matches" about a queue containing exactly what was
 * asked for.
 *
 * Its own admin user and session, for the reason admin-panels.spec.ts gives:
 * granting ROLE_ADMIN to the shared user mid-run deauthenticates every other
 * spec.
 */

const ADMIN = TEST_ADMIN;

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: ADMIN.email, password: ADMIN.password, admin: true });

    // The transport is empty on a fresh test database, and an empty panel
    // renders none of what this file is about. Seeded through the console so
    // the rows are real serialised envelopes — see app:test:seed-queue.
    //
    // Through consoleCommand, like every other spec, rather than the private
    // copy of the docker invocation this used to carry. That copy hardcoded
    // `docker compose -f compose.test.yaml` with no `-p`, so it always talked
    // to the DEFAULT compose project: point the suite at a stack started under
    // any other project name — a worktree, a second port, CI — and the seed
    // either errored with "service app is not running" or, worse, succeeded
    // against somebody else's database while the browser looked at this one.
    // Either way the panel was empty and the failure read as "this spec needs
    // a running worker", which it never did: `delivered_at` is what "a worker
    // is holding it" means to the transport, and the seed writes it.
    consoleCommand("app:test:seed-queue");
});

const QUEUE = 'details[data-admin--admin-panel-key-value="queues"]';
const BROWSER = '[data-controller="admin--queue-browser"]';

async function openPanel(page: Page): Promise<void> {
    await login(page, ADMIN.email, ADMIN.password);
    await page.goto("/admin?section=system");

    // The live frame is lazy, and the panel may have been left collapsed by
    // another run — this file asserts about its contents either way.
    const panel = page.locator(QUEUE);
    await expect(panel).toBeVisible();

    if (null === (await panel.getAttribute("open"))) {
        await panel.locator("summary").click();
    }
}

test.describe("admin queue panel", () => {
    /**
     * The panel leads with what a worker is holding, named. A depth alone
     * cannot tell a stuck queue from an empty one, which is the whole reason
     * this section exists.
     */
    test("names the message a worker is holding right now", async ({ page }) => {
        await openPanel(page);

        await expect(page.locator(QUEUE).getByText(/Running now/i)).toBeVisible();
        await expect(page.locator(QUEUE).getByText("SyncAccountMessage").first()).toBeVisible();
        // The payload, not just the class — "which account" is the question.
        await expect(page.locator(QUEUE).getByText(/accountId: \d+/).first()).toBeVisible();
    });

    test("the backlog scrolls inside the panel rather than growing it", async ({ page }) => {
        await openPanel(page);

        const scroller = page.locator(`${BROWSER} [data-admin--queue-browser-target="scroller"]`);
        await expect(scroller).toBeVisible();

        // A max height is the whole point: an unbounded queue must not push
        // every other panel off the page.
        const overflow = await scroller.evaluate((node) => getComputedStyle(node).overflowY);
        expect(overflow).toBe("auto");
    });

    /**
     * The filter goes to the server. Typing something no message could contain
     * has to empty the list — if it filtered the fetched page in the browser,
     * an empty queue would look identical and the test would pass for the
     * wrong reason, which is why the count beside it is asserted too.
     */
    test("filtering asks the server about the whole queue", async ({ page }) => {
        await openPanel(page);

        const filtered = page.waitForResponse(
            (r) => r.url().includes("/admin/queues/waiting") && r.status() === 200,
        );

        await page.locator(`${BROWSER} input[type="search"]`).fill("nothing-matches-this-at-all");
        await filtered;

        await expect(
            page.locator(`${BROWSER} [data-admin--queue-browser-target="list"] [data-queue-row]`),
        ).toHaveCount(0);

        await expect(
            page.locator(`${BROWSER} [data-admin--queue-browser-target="count"]`),
        ).toContainText("0");
    });

    test("clearing the filter asks again rather than restoring a stale list", async ({ page }) => {
        await openPanel(page);

        const input = page.locator(`${BROWSER} input[type="search"]`);

        const filtered = page.waitForResponse((r) => r.url().includes("/admin/queues/waiting"));
        await input.fill("nothing-matches-this-at-all");
        await filtered;

        const cleared = page.waitForResponse(
            (r) => r.url().includes("/admin/queues/waiting") && r.url().includes("q=&"),
        );
        await input.fill("");
        await cleared;
    });

    /** The collapsed header has to carry the summary, or collapsing hides the answer. */
    test("collapsed, the panel still says how deep the queue is", async ({ page }) => {
        await openPanel(page);

        const panel = page.locator(QUEUE);

        await panel.locator("summary").click();
        await expect(panel).not.toHaveAttribute("open", /.*/);

        await expect(panel.locator("summary")).toContainText(/running|waiting/i);
    });
});
