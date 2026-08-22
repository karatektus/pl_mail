import { test, expect, type Page } from "./support/test";
import { TEST_ADMIN, login, seed, seedUser } from "./support/config";
import { acceptConfirm } from "./support/confirm";

/**
 * The admin side of integrations: every provider is listed with its own
 * configuration state, the setup tutorial is readable inline, and enabling one
 * persists.
 *
 * All six providers have drivers now, so the thing worth pinning is that an
 * unconfigured provider reads as *disabled* — the admin's choice — rather than
 * as missing. A provider added later without a driver would show as "not
 * available yet" instead, which is why that string is asserted absent rather
 * than deleted from the templates.
 *
 * Own admin user and own session, for the same reason admin-panels.spec.ts
 * does it: granting ROLE_ADMIN to the shared e2e user mid-run invalidates
 * every other spec's token.
 */
/**
 * This file runs in its own Playwright project, alone and after everything
 * else — see the `chromium-exclusive` project in playwright.config.ts.
 * IntegrationProviderConfig and MailProviderConfig are unique on `provider`
 * with no user column, so unlike mail or labels this state cannot be split per
 * worker however many users we invent.
 */
const ADMIN = TEST_ADMIN;

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: ADMIN.email, password: ADMIN.password, admin: true });
});

/**
 * One provider's row, by id and located fresh so it survives a frame swap.
 *
 * By id rather than by visible text: filtering on the label matched the
 * tutorial body inside the row as well, and the same trap cost several rounds of
 * chasing locators.
 */
const PROVIDER_IDS: Record<string, string> = {
    Nextcloud: "nextcloud",
    Immich: "immich",
    "Google Drive": "googleDrive",
    "Google Photos": "googlePhotos",
    OneDrive: "oneDrive",
    Dropbox: "dropbox",
};

function providerRow(page: Page, label: string) {
    return page.locator(`#integration-provider-${PROVIDER_IDS[label]}`);
}

async function openIntegrations(page: Page) {
    await login(page, ADMIN.email, ADMIN.password);
    await page.goto("/admin?section=integrations");
    await expect(page.locator("#admin-integrations")).toBeVisible();
}

test.describe("admin integrations", () => {
    test("lists every provider with its own configuration state", async ({
        page,
    }) => {
        await openIntegrations(page);

        for (const label of Object.keys(PROVIDER_IDS)) {
            await expect(providerRow(page, label)).toHaveCount(1);
        }

        // All six have drivers now, so nothing is "not available yet" —
        // untouched providers read as disabled, which is the admin's choice
        // rather than a gap in plMail.
        await expect(
            page.locator("#admin-integrations").getByText("Not available yet"),
        ).toHaveCount(0);
        await expect(
            providerRow(page, "Google Drive").getByText("Disabled"),
        ).toBeVisible();

        // Each row names how it authenticates. Scoped to the summary: the
        // tutorial body below repeats these words, so an unscoped match hits
        // two elements and proves nothing about the chip.
        await expect(
            providerRow(page, "Google Drive")
                .locator("summary")
                .getByText("sign-in", { exact: true }),
        ).toBeVisible();
        await expect(
            providerRow(page, "Nextcloud")
                .locator("summary")
                .getByText("app password", { exact: true }),
        ).toBeVisible();
    });

    test("mail sign-in sits above the services and reads from the environment", async ({
        page,
    }) => {
        await openIntegrations(page);

        const frame = page.locator("#admin-integrations");

        await expect(frame.getByRole("heading", { name: "Mail sign-in" })).toBeVisible();

        // Scoped to the row, not searched for across the frame. Once mail
        // credentials exist, every Google-backed service grows a "Reuse Gmail
        // sign-in" button, so an unscoped getByText("Gmail sign-in") matches
        // three elements and dies on strict mode — passing alone and failing
        // in a full run, because the test below is what stores them.
        await expect(
            page.locator("#mail-provider-google").getByText("Gmail sign-in", { exact: true }),
        ).toBeVisible();
        await expect(
            page.locator("#mail-provider-microsoft").getByText("Microsoft mail sign-in", { exact: true }),
        ).toBeVisible();

        // Every row offers configuration, and each has exactly one status chip —
        // "From environment" or "Enabled" depending on whether anything has been
        // stored. Which one is not this test's business: another test in this
        // file stores credentials, so asserting a particular chip here would
        // make the two order-dependent.
        // Scoped by a stable hook rather than by DOM shape: filtering divs by a
        // descendant heading picked a different wrapper depending on what the
        // rows happened to contain, which made this pass alone and fail in a
        // full run.
        await expect(
            frame.locator("#admin-mail-providers").getByRole("button", { name: "Configure" }),
        ).toHaveCount(2);
    });

    test("saving mail credentials enables reuse on the matching integrations", async ({
        page,
    }) => {
        await openIntegrations(page);

        // No credentials stored yet, so nothing offers to reuse them.
        await expect(
            page.locator("#admin-integrations").getByRole("button", { name: /Reuse/ }),
        ).toHaveCount(0);

        const gmail = page.locator("#mail-provider-google");
        await gmail.getByRole("button", { name: "Configure" }).click();

        const modal = page.locator("#modal");
        await modal.locator('input[name$="[clientId]"]').fill("gmail-client-id");
        await modal.locator('input[name$="[clientSecret]"]').fill("gmail-client-secret");
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(
            page.locator("#admin-integrations").getByText("Enabled").first(),
        ).toBeVisible();

        // Both Google integrations share that Cloud project, so both offer it;
        // Dropbox has no mail counterpart and must not.
        const drive = providerRow(page, "Google Drive");
        await drive.locator("summary").click();
        await expect(drive.getByRole("button", { name: /Reuse Gmail sign-in/ })).toBeVisible();

        const dropbox = providerRow(page, "Dropbox");
        await dropbox.locator("summary").click();
        await expect(dropbox.getByRole("button", { name: /Reuse/ })).toHaveCount(0);

        await drive.getByRole("button", { name: /Reuse Gmail sign-in/ }).click();

        // Wait for the copy to land before reopening: the response replaces the
        // whole frame, so a row opened before then is replaced closed again.
        await expect(page.locator("#toast-region")).toContainText("Credentials copied");

        // The client id crossed over; the secret did too, but only server-side —
        // nothing in the page should ever contain it.
        const refreshed = providerRow(page, "Google Drive");
        await refreshed.locator("summary").click();
        await expect(refreshed.getByText("gmail-client-id")).toBeVisible();
        await expect(refreshed.getByText("Stored")).toBeVisible();
        await expect(page.locator("body")).not.toContainText("gmail-client-secret");
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
        await modal.locator('input[name$="[isEnabled]"]').check();
        await modal
            .locator('input[name$="[baseUrl]"]')
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
        await page.locator('#modal input[name$="[isEnabled]"]').check();
        await page
            .locator('#modal input[name$="[baseUrl]"]')
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
        // Dropbox here" learns that an admin has not enabled it, rather than
        // finding nothing and guessing whether the feature exists.
        await expect(frame.getByText("Not available here")).toBeVisible();
        await expect(frame.getByText("not enabled").first()).toBeVisible();
    });

    test("a connection whose credentials fail says why, and survives a reload", async ({
        page,
    }) => {
        const frame = page.locator("#settings-integrations-frame");
        await frame.getByRole("button", { name: "Nextcloud" }).click();

        const modal = page.locator("#modal");
        // The admin pinned the address, so the field must not be offered.
        await expect(modal.locator('input[name$="[baseUrl]"]')).toHaveCount(0);

        await modal.locator('input[name$="[name]"]').fill("Home cloud");
        await modal.locator('input[name$="[username]"]').fill("alice");
        await modal.locator('input[name$="[secret]"]').fill("not-a-real-password");
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
        await modal.locator('input[name$="[name]"]').fill("Scratch cloud");
        await modal.locator('input[name$="[username]"]').fill("bob");
        await modal.locator('input[name$="[secret]"]').fill("whatever");
        await modal.getByRole("button", { name: "Connect" }).click();

        const row = frame.locator("li").filter({ hasText: "Scratch cloud" });
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: "Pause" }).click();
        await expect(
            frame.locator("li").filter({ hasText: "Scratch cloud" }).getByText("(paused)"),
        ).toBeVisible();

        await frame
            .locator("li")
            .filter({ hasText: "Scratch cloud" })
            .getByRole("button", { name: "Disconnect" })
            .click();
        await acceptConfirm(page);

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
        await page.locator('#modal input[name$="[isEnabled]"]').check();
        await page
            .locator('#modal input[name$="[baseUrl]"]')
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
        await page.locator('#modal input[name$="[name]"]').fill(name);
        await page.locator('#modal input[name$="[username]"]').fill("alice");
        await page.locator('#modal input[name$="[secret]"]').fill("app-password");
        await page.locator("#modal").getByRole("button", { name: "Connect" }).click();
        await expect(frame.getByText(name)).toBeVisible();
    }

    test("no button at all when nothing is connected", async ({ page }) => {
        await login(page);

        // Establishes its own premise rather than assuming a fresh database:
        // this file's other tests connect a service, so on a re-run against a
        // dirty database the button would legitimately be there and the failure
        // would say nothing about the behaviour under test.
        await page.goto("/settings?section=integrations");
        const frame = page.locator("#settings-integrations-frame");

        // Wait for the frame's *content*, not the frame: it loads lazily and the
        // placeholder is already visible, so querying too early finds nothing to
        // disconnect and the premise silently fails to hold.
        await expect(frame.getByText("Connect a service")).toBeVisible();

        // Counted down rather than waited to zero. `.first()` resolves to one
        // element for as long as any remain, so asserting it reaches zero after
        // a single disconnect only holds when there was exactly one connection
        // — which stopped being true the moment another spec left a calendar
        // connection on this worker's user.
        for (;;) {
            const disconnects = frame.getByRole("button", { name: "Disconnect" });
            const remaining = await disconnects.count();

            if (0 === remaining) {
                break;
            }

            await disconnects.first().click();
            await acceptConfirm(page);
            await expect(disconnects).toHaveCount(remaining - 1);
        }

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

    /**
     * The attachment chip gained a "Save to…" half and was extracted into a
     * shared partial, so both of its call sites had to keep working. This
     * covers the thread view: the menu opens where a human can press it, and
     * choosing a service now opens the destination picker rather than saving on
     * the spot.
     */
    test("an attachment offers save-to, which opens the destination picker", async ({
        page,
    }) => {
        seed("seed-attachment");

        await enableNextcloudAsAdmin(page);
        await connectAsMailUser(page, "Home cloud");

        await page.goto("/mail/inbox");
        await page
            .locator("#message-list li")
            .filter({ hasText: "E2E Attachment" })
            .first()
            .click();

        const chip = page.locator('[data-controller="ui--dropdown"]').filter({
            hasText: "e2e-attachment.txt",
        });
        await expect(chip).toBeVisible();

        await chip.getByRole("button", { name: "Save to" }).click();

        // Not merely un-`hidden`: on screen and pressable. The chip rounded
        // its corners with `overflow-hidden` on the same element the menu was
        // positioned against, and the menu opens *below* that box — so it was
        // clipped away entirely. It had a bounding rect, Playwright called it
        // visible and clicked it happily, and a human saw nothing at all and
        // could press nothing. What tells the two apart is hit-testing: the
        // topmost element at the menu's own centre has to be the menu.
        const menu = chip.locator('[data-ui--dropdown-target="menu"]');
        await expect(menu).toBeVisible();
        await expect
            .poll(() =>
                menu.evaluate((el) => {
                    const box = el.getBoundingClientRect();
                    const hit = document.elementFromPoint(
                        Math.round(box.x + box.width / 2),
                        Math.round(box.y + box.height / 2),
                    );

                    return null !== hit && el.contains(hit);
                }),
            )
            .toBe(true);

        // Choosing a service now opens a destination picker in the modal rather
        // than firing a save — that browse is the picker, in destination mode.
        const browsed = page.waitForRequest(
            (request) =>
                /\/integrations\/\d+\/browse\?.*mode=destination/.test(request.url()),
        );

        await chip.getByRole("button", { name: "Home cloud" }).click();
        await browsed;

        // cloud.example.com is unreachable from the test stack, so the folder
        // listing cannot render — but the picker is still a picker, with its
        // "Save here" targeting the top level even when a subfolder would not
        // load. That save carries the CSRF token and an explicit destination
        // field, and answers with a toast the reader sees.
        const modal = page.locator("#modal");
        await expect(modal).toContainText(/Could not reach the Nextcloud server/i);

        const posted = page.waitForRequest(
            (request) =>
                "POST" === request.method()
                && /\/integrations\/\d+\/save-attachment\/\d+$/.test(request.url()),
        );

        await modal.getByRole("button", { name: "Save here" }).click();

        const body = (await posted).postData() ?? "";
        expect(body).toContain("_token=");
        expect(body).toContain("destination=");

        await expect(page.locator("#toast-region")).toContainText(/Could not save/i);
    });
});

/**
 * The save picker proper: the mime gate on which services are offered per
 * attachment, and the folder chooser a file-store save opens into.
 *
 * Own describe with its own seed, which writes both connections and a
 * two-attachment thread straight to the database — deterministic and off the
 * network, so what is under test is only which menu each attachment offers and
 * that the chosen destination reaches the server, not a live provider. It lives
 * in this file because it is in the same isolated Playwright project: it reads
 * connections but touches no install-wide provider config, yet the gate it
 * checks is the same taxonomy the rest of this file exercises.
 */
test.describe("attachment save destination", () => {
    test.beforeAll(() => {
        seed("seed-mail", "seed-save-picker");
    });

    async function openSeededThread(page: Page) {
        await login(page);
        await page.goto("/mail/inbox");
        await page
            .locator("#message-list li")
            .filter({ hasText: "E2E Save Picker" })
            .first()
            .click();
    }

    function chipFor(page: Page, filename: string) {
        return page
            .locator('[data-controller="ui--dropdown"]')
            .filter({ hasText: filename });
    }

    /**
     * A photo library is offered for an image and withheld for a PDF; a file
     * store is offered for both. This is the whole point of the gate — "Save to
     * Immich" on a document is a guaranteed failure the user must never see.
     */
    test("offers a photo library for an image but not a PDF", async ({ page }) => {
        await openSeededThread(page);

        const photo = chipFor(page, "e2e-photo.png");
        await photo.getByRole("button", { name: "Save to" }).click();
        const photoMenu = photo.locator('[data-ui--dropdown-target="menu"]');
        await expect(
            photoMenu.getByRole("button", { name: "E2E SavePicker Photos" }),
        ).toBeVisible();
        await expect(
            photoMenu.getByRole("button", { name: "E2E SavePicker Cloud" }),
        ).toBeVisible();

        const doc = chipFor(page, "e2e-document.pdf");
        await doc.getByRole("button", { name: "Save to" }).click();
        const docMenu = doc.locator('[data-ui--dropdown-target="menu"]');
        // The file store stays; the photo library is gone, because a PDF cannot
        // land in it.
        await expect(
            docMenu.getByRole("button", { name: "E2E SavePicker Cloud" }),
        ).toBeVisible();
        await expect(
            docMenu.getByRole("button", { name: "E2E SavePicker Photos" }),
        ).toHaveCount(0);
    });

    /**
     * Saving to the file store opens the folder chooser, and "Save here" carries
     * the chosen destination to the server. The seeded host is unreachable, so
     * the request boundary is what is asserted, not a landed file — a live
     * server is what only a real Nextcloud can confirm.
     */
    test("a file-store save opens the folder picker and carries a destination", async ({
        page,
    }) => {
        await openSeededThread(page);

        const doc = chipFor(page, "e2e-document.pdf");
        await doc.getByRole("button", { name: "Save to" }).click();

        const browsed = page.waitForRequest((request) =>
            /\/integrations\/\d+\/browse\?.*mode=destination.*part=\d+/.test(request.url()),
        );
        await doc.getByRole("button", { name: "E2E SavePicker Cloud" }).click();
        await browsed;

        const modal = page.locator("#modal");
        // The folder chooser's own action, present whether or not the listing
        // loaded — the seeded host does not resolve.
        const saveHere = modal.getByRole("button", { name: "Save here" });
        await expect(saveHere).toBeVisible();

        const posted = page.waitForRequest(
            (request) =>
                "POST" === request.method()
                && /\/integrations\/\d+\/save-attachment\/\d+$/.test(request.url()),
        );
        await saveHere.click();

        // The save reached the server as a destination-bearing request — the
        // field the picker threads through, absent from the old fire-and-forget
        // post.
        expect((await posted).postData() ?? "").toContain("destination=");
    });
});
