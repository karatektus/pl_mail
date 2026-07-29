import { test, expect, type Page } from "@playwright/test";
import { login, seed } from "./support/config";
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

/**
 * The user side. A user may only connect to what an admin has turned on, so
 * these run against the provider state the admin tests above leave behind —
 * Nextcloud enabled and pinned, everything else off.
 */
test.describe("user integrations", () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN.email, ADMIN.password);

        // Ensure Nextcloud is on regardless of which admin test ran last.
        await page.goto("/admin?section=integrations");
        const row = providerRow(page, "Nextcloud");
        await row.locator("summary").click();
        await row.getByRole("button", { name: "Configure" }).click();
        await page.locator('#modal input[name="isEnabled"]').check();
        await page
            .locator('#modal input[name="baseUrl"]')
            .fill("https://cloud.example.com");
        await page.locator("#modal").getByRole("button", { name: "Save" }).click();
        await expect(row.getByText("Enabled")).toBeVisible();

        await page.goto("/settings?section=integrations");
        await expect(page.locator("#settings-integrations-frame")).toBeVisible();
    });

    test("offers enabled services and explains the rest", async ({ page }) => {
        const frame = page.locator("#settings-integrations-frame");

        await expect(
            frame.getByRole("button", { name: "Nextcloud" }),
        ).toBeVisible();

        // The unavailable group is the point: a user who was told "we use
        // Dropbox here" learns whether it is off or simply not built yet,
        // rather than finding nothing and guessing.
        await expect(frame.getByText("Not available here")).toBeVisible();
        await expect(frame.getByText("coming soon").first()).toBeVisible();
    });

    test("a connection whose credentials fail says why, and survives a reload", async ({
        page,
    }) => {
        const frame = page.locator("#settings-integrations-frame");
        await frame.getByRole("button", { name: "Nextcloud" }).click();

        const modal = page.locator("#modal");
        // The admin pinned the address, so the field must not be offered.
        await expect(modal.locator('input[name="baseUrl"]')).toHaveCount(0);

        await modal.locator('input[name="name"]').fill("Home cloud");
        await modal.locator('input[name="username"]').fill("alice");
        await modal.locator('input[name="secret"]').fill("not-a-real-password");
        await modal.getByRole("button", { name: "Connect" }).click();

        // cloud.example.com is unreachable from the test container, so the
        // probe fails — which is exactly the path worth pinning: the row is
        // still saved, and it carries the reason rather than looking healthy.
        await expect(frame.getByText("Home cloud")).toBeVisible();
        await expect(
            frame.locator(".text-danger").first(),
        ).toBeVisible();

        await page.reload();
        await expect(
            page.locator("#settings-integrations-frame").getByText("Home cloud"),
        ).toBeVisible();
    });

    test("a connection can be paused and disconnected", async ({ page }) => {
        const frame = page.locator("#settings-integrations-frame");
        await frame.getByRole("button", { name: "Nextcloud" }).click();

        const modal = page.locator("#modal");
        await modal.locator('input[name="name"]').fill("Scratch cloud");
        await modal.locator('input[name="username"]').fill("bob");
        await modal.locator('input[name="secret"]').fill("whatever");
        await modal.getByRole("button", { name: "Connect" }).click();

        const row = frame.locator("li").filter({ hasText: "Scratch cloud" });
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: "Pause" }).click();
        await expect(
            frame.locator("li").filter({ hasText: "Scratch cloud" }).getByText("(paused)"),
        ).toBeVisible();

        page.once("dialog", (dialog) => dialog.accept());
        await frame
            .locator("li")
            .filter({ hasText: "Scratch cloud" })
            .getByRole("button", { name: "Disconnect" })
            .click();

        await expect(
            frame.locator("li").filter({ hasText: "Scratch cloud" }),
        ).toHaveCount(0);
    });
});

/**
 * Compose picks up connected services.
 *
 * cloud.example.com is unreachable from the test container, so the picker
 * cannot list anything — which still exercises everything between the button
 * and the driver, and pins the failure path: an unreachable service reports
 * why instead of opening an empty file list that looks like an empty folder.
 */
test.describe("compose integration picker", () => {
    const dock = "#compose_dock";

    // Composing needs a mail account, which the admin user does not have — so
    // these run as the seeded mail user. Enabling a provider is admin-only,
    // but connecting to one is not, which is exactly the split being tested.
    test.beforeAll(() => {
        seed("seed-mail");
    });

    async function enableNextcloudAsAdmin(page: Page) {
        await login(page, ADMIN.email, ADMIN.password);
        await page.goto("/admin?section=integrations");

        const row = providerRow(page, "Nextcloud");
        await row.locator("summary").click();
        await row.getByRole("button", { name: "Configure" }).click();
        await page.locator('#modal input[name="isEnabled"]').check();
        await page
            .locator('#modal input[name="baseUrl"]')
            .fill("https://cloud.example.com");
        await page.locator("#modal").getByRole("button", { name: "Save" }).click();
        await expect(row.getByText("Enabled")).toBeVisible();
    }

    async function connectAsMailUser(page: Page, name: string) {
        // Still signed in as the admin, and /login redirects an authenticated
        // visitor straight back to the inbox — so the session has to go before
        // the second login can happen.
        await page.context().clearCookies();
        await login(page);
        await page.goto("/settings?section=integrations");

        const frame = page.locator("#settings-integrations-frame");
        await frame.getByRole("button", { name: "Nextcloud" }).click();
        await page.locator('#modal input[name="name"]').fill(name);
        await page.locator('#modal input[name="username"]').fill("alice");
        await page.locator('#modal input[name="secret"]').fill("app-password");
        await page.locator("#modal").getByRole("button", { name: "Connect" }).click();
        await expect(frame.getByText(name)).toBeVisible();
    }

    test("no button at all when nothing is connected", async ({ page }) => {
        await login(page);
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        await expect(page.locator(dock).getByText("New Message")).toBeVisible();
        await expect(page.locator(`${dock} [data-integration-id]`)).toHaveCount(0);
    });

    test("one connection gets a direct button that opens the picker", async ({
        page,
    }) => {
        await enableNextcloudAsAdmin(page);
        await connectAsMailUser(page, "Home cloud");

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).click();

        // One connection means a button, not a menu.
        const button = page.locator(`${dock} [data-integration-id]`);
        await expect(button).toHaveCount(1);

        await button.click();

        // The draft is force-saved on the way in, so the picker knows which
        // message to attach to before it renders. cloud.example.com is
        // unreachable here, so the picker must say so rather than render an
        // empty list that reads as an empty folder — matching the driver's own
        // wording, not just the provider name, which is also in the title.
        await expect(page.locator("#modal")).toContainText(
            /Could not reach the Nextcloud server/i,
        );
    });
});
