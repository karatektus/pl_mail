import { test, expect } from "./support/test";
import { TEST_ADMIN, login, seedUser } from "./support/config";

/**
 * Admin panels collapse, and the state is remembered server-side.
 *
 * Server-side is the point of the feature — localStorage (what the sidebar
 * rail uses) would not survive a different browser, and the panels are
 * rendered on the server, so the state has to be known before the first paint
 * or every load would flash them all open. Reloading is therefore the
 * assertion that matters, not the toggle itself.
 *
 * Uses its own admin user and its own session rather than the shared storage
 * state: /admin needs ROLE_ADMIN, and granting that to the shared e2e user
 * mid-run would deauthenticate every other spec's session — Symfony treats a
 * token whose roles have changed as stale.
 */
// Per-worker, from support/config.ts. This file and integrations.spec.ts both
// used one hardcoded admin address; with files running in parallel they could
// land on different workers and overwrite each other's setup.
const ADMIN = TEST_ADMIN;

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: ADMIN.email, password: ADMIN.password, admin: true });
});

/** The panel's <details>, located fresh so it survives a reload. */
function panel(page: import("@playwright/test").Page, key: string) {
    return page.locator(`details[data-admin--admin-panel-key-value="${key}"]`);
}

async function toggle(page: import("@playwright/test").Page, key: string) {
    // Wait for the persist request rather than racing the reload against it.
    const persisted = page.waitForResponse(
        (r) => r.url().includes("/admin/panel/toggle") && r.status() === 200,
    );
    await panel(page, key).locator("summary").click();
    await persisted;
}

test.describe("admin panels", () => {
    test("collapse is remembered across a reload", async ({ page }) => {
        await login(page, ADMIN.email, ADMIN.password);
        await page.goto("/admin?section=system");

        await expect(panel(page, "processes")).toBeVisible();

        // Put the panel where this test needs to start, rather than trusting it
        // to be there. Collapse is stored PER ADMIN USER on the server and the
        // admin is shared, not per worker, so the starting state is whatever the
        // last run of this test left behind — and a run that fails anywhere
        // after the first toggle leaves it collapsed. The next run then failed
        // on its opening assertion, reporting a broken feature and really
        // reporting its own previous failure. One red run became a red run
        // every time until somebody clicked it open by hand.
        if (null === await panel(page, "processes").getAttribute("open")) {
            await toggle(page, "processes");
        }

        await expect(panel(page, "processes")).toHaveAttribute("open", "");

        await toggle(page, "processes");
        await expect(panel(page, "processes")).not.toHaveAttribute("open", "");

        // The real assertion: server-rendered as collapsed on a fresh load.
        await page.reload();
        await expect(panel(page, "processes")).toBeVisible();
        await expect(panel(page, "processes")).not.toHaveAttribute("open", "");

        // Stored per panel — collapsing one leaves the others alone.
        await expect(panel(page, "maintenance")).toHaveAttribute("open", "");

        // And it round-trips back to open.
        await toggle(page, "processes");
        await page.reload();
        await expect(panel(page, "processes")).toHaveAttribute("open", "");
    });
});
