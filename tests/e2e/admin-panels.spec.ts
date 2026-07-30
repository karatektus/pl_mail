import { test, expect } from "@playwright/test";
import { login } from "./support/config";
import { execSync } from "node:child_process";

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
const CONSOLE = process.env.E2E_CONSOLE ?? "php bin/console";

const ADMIN = {
    email: "e2e-admin@plmail.test",
    password: "e2e-admin-password",
};

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    execSync(
        `${CONSOLE} app:test:seed-user --admin --email=${ADMIN.email} --password=${ADMIN.password}`,
        { stdio: "inherit", env: { ...process.env, APP_ENV: "test" } },
    );
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
