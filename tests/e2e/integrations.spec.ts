import { test, expect, type Page } from "@playwright/test";
import { login } from "./support/config";
import { execSync } from "node:child_process";

/**
 * The admin side of integrations: every provider is listed whether or not
 * plMail can talk to it, the setup tutorial is readable inline, and enabling
 * one persists.
 *
 * Listing the unimplemented providers is the behaviour worth pinning. They are
 * deliberately visible so the roadmap is on screen and so an admin can
 * register credentials ahead of support landing — a future change that
 * "tidies" them out of the list would be a regression, not a cleanup.
 *
 * Own admin user and own session, for the same reason admin-panels.spec.ts
 * does it: granting ROLE_ADMIN to the shared e2e user mid-run invalidates
 * every other spec's token.
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

/** One provider's row, located fresh so it survives a frame replacement. */
function providerRow(page: Page, label: string) {
    return page.locator("#admin-integrations details").filter({
        has: page.getByText(label, { exact: true }),
    });
}

async function openIntegrations(page: Page) {
    await login(page, ADMIN.email, ADMIN.password);
    await page.goto("/admin?section=integrations");
    await expect(page.locator("#admin-integrations")).toBeVisible();
}

test.describe("admin integrations", () => {
    test("lists every provider, including ones with no driver yet", async ({
        page,
    }) => {
        await openIntegrations(page);

        for (const label of [
            "Nextcloud",
            "Immich",
            "Google Drive",
            "Google Photos",
            "OneDrive",
            "Dropbox",
        ]) {
            await expect(providerRow(page, label)).toHaveCount(1);
        }

        // The four without drivers say so rather than looking configurable.
        await expect(
            providerRow(page, "Google Drive").getByText("Not available yet"),
        ).toBeVisible();
        await expect(
            providerRow(page, "Nextcloud").getByText("Not available yet"),
        ).toHaveCount(0);
    });

    test("the setup tutorial is readable inline", async ({ page }) => {
        await openIntegrations(page);

        const row = providerRow(page, "Nextcloud");
        await row.locator("summary").click();

        await expect(row.getByText("Setup")).toBeVisible();
        await expect(
            row.getByText(/app password/i).first(),
        ).toBeVisible();
    });

    test("an OAuth provider shows a real redirect URI to register", async ({
        page,
    }) => {
        await openIntegrations(page);

        const row = providerRow(page, "Dropbox");
        await row.locator("summary").click();
        await row.getByRole("button", { name: "Configure" }).click();

        // Generated from the route, so it cannot drift from where the callback
        // actually lives.
        await expect(
            page.locator("#modal code", {
                hasText: "/integrations/oauth/dropbox/callback",
            }),
        ).toBeVisible();
    });

    test("enabling a provider persists across a reload", async ({ page }) => {
        await openIntegrations(page);

        const row = providerRow(page, "Nextcloud");
        await row.locator("summary").click();
        await row.getByRole("button", { name: "Configure" }).click();

        const modal = page.locator("#modal");
        await modal.locator('input[name="isEnabled"]').check();
        await modal
            .locator('input[name="baseUrl"]')
            .fill("https://cloud.example.com");
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(
            providerRow(page, "Nextcloud").getByText("Enabled"),
        ).toBeVisible();

        await page.reload();
        await expect(
            providerRow(page, "Nextcloud").getByText("Enabled"),
        ).toBeVisible();
        // The row collapses on reload, so the stored address has to be
        // reopened to be asserted on. The status chip above lives in the
        // summary and is visible either way.
        await providerRow(page, "Nextcloud").locator("summary").click();

        // Scoped to the <code> in the detail block: the Nextcloud tutorial
        // uses the same host as its worked example, so a plain text match hits
        // both and proves nothing.
        await expect(
            providerRow(page, "Nextcloud").locator("dd code", {
                hasText: "https://cloud.example.com",
            }),
        ).toBeVisible();
    });
});
