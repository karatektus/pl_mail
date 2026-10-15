import { test, expect } from "./support/test";
import { TEST_DEMO, TEST_DEMO_ADMIN, consoleCommand, login, seedUser } from "./support/config";

/**
 * The pictures beside highlighted entries in What's new (#165).
 *
 * A highlight in CHANGELOG.md may name a screenshot —
 * `<!-- highlight screenshot=pull-to-sync -->` — and the dialog shows
 * public/whats-new/pull-to-sync.png beside it. Each picture is taken here,
 * against the demo mailbox, by the pull request that adds the feature: one
 * test per name, named as the file is. ChangelogReaderTest fails while a
 * highlight names a picture that does not exist.
 *
 * Opt-in, like the README's pictures, and run by the same command, whose
 * filter matches this file too:
 *
 *   npm run test:e2e:screenshots
 *
 * By name, not by release: the pull request does not know which release it
 * will ship in, and a picture is of a feature, which outlives the release.
 *
 * CROPPED TO WHAT IT IS OF. The dialog shows a picture at about 220 pixels
 * wide, and a whole window at that size is a sidebar and a blur; the card or
 * pane the feature lives in is something a person can read.
 */
const OUT = "public/whats-new";

test.use({ viewport: { width: 1200, height: 750 } });

type Page = import("@playwright/test").Page;
type Locator = import("@playwright/test").Locator;

async function capture(page: Page, name: string, of: Locator, maxHeight?: number): Promise<void> {
    await expect(page.locator('[class^="exception"], [class*=" exception"], .trace'), `${name}.png would have been an error page`).toHaveCount(0);
    await expect(page).not.toHaveTitle(/exception|forbidden|error/i);

    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(400);
    if (undefined === maxHeight) {
        await of.screenshot({ path: `${OUT}/${name}.png` });

        return;
    }

    // A panel taller than the window: its top, which is where the feature is.
    await of.scrollIntoViewIfNeeded();
    const box = await of.boundingBox();
    expect(box, `${name}: nothing to photograph`).not.toBeNull();
    await page.screenshot({ path: `${OUT}/${name}.png`, clip: { ...box!, height: Math.min(box!.height, maxHeight) } });
}

/** The settings card with this heading. */
async function card(page: Page, section: string, heading: string): Promise<Locator> {
    await page.goto(`/settings?section=${section}`);

    const found = page.locator("section", { has: page.locator("h2", { hasText: heading }) }).first();
    await expect(found).toBeVisible();

    return found;
}

test.describe("What's new screenshots", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO.email, password: TEST_DEMO.password });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO.email}`);
    });

    test.beforeEach(async ({ page }) => {
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
    });

    /** The dialog itself, for its own entry: opened from the ? the way somebody finds it again. */
    test("whats-new", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        await expect(page.locator("#modal [data-whats-new-notes]")).toBeVisible();
        await capture(page, "whats-new", page.locator("[data-ui--modal-panel]"));
    });

    test("inbox-tabs", async ({ page }) => {
        await capture(page, "inbox-tabs", await card(page, "categories", "Tabs"));
    });

    /** An address of its own, so the sender-name field under it is in the picture. */
    test("sender-names", async ({ page }) => {
        const addresses = await card(page, "aliases", "Email addresses");

        if (0 === (await addresses.getByText("priya.studio@example.com").count())) {
            await addresses.getByPlaceholder(/another address/i).fill("priya.studio@example.com");
            await addresses.getByRole("button", { name: "Add", exact: true }).click();
            await expect(addresses.getByText("priya.studio@example.com")).toBeVisible();
        }

        // Typed, not saved: the picture is of the field, not of a name anybody chose.
        await addresses.locator("input[placeholder]").filter({ hasNot: page.locator("[placeholder*='another address' i]") }).first().fill("Priya at the studio");

        await capture(page, "sender-names", addresses);
    });

    test("html-signature", async ({ page }) => {
        const signature = await card(page, "signature", "Signatures");

        await signature.getByRole("button", { name: "HTML", exact: true }).click();
        await signature.locator('[data-settings--signature-source-target="source"]').fill(
            '<p><strong>Priya Raman</strong><br><span style="color:#7d6b4f">Raman Joinery</span> · Workshop 3, Brunnengasse 12</p>'
            + '<p><a href="https://example.com">example.com</a> · +41 44 000 00 00</p>',
        );
        await signature.getByRole("button", { name: "Rendered", exact: true }).click();
        await page.waitForTimeout(500);

        await capture(page, "html-signature", signature);
    });

    test("tab-unread-counts", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();
        await capture(page, "tab-unread-counts", page.locator(".main-pane").first());
    });

    test("permanent-delete", async ({ page }) => {
        await capture(page, "permanent-delete", await card(page, "disposal", "Disposal"));
    });

    test("templates", async ({ page }) => {
        await capture(page, "templates", await card(page, "templates", "Templates"));
    });

    /** The pane beside the list, with a conversation open in it; put back to off afterwards. */
    test("reading-pane", async ({ page }) => {
        const pane = async (mode: "right" | "off"): Promise<void> => {
            await page.goto("/settings?section=reading-pane");
            const option = page.locator(`input[name="readingPane"][value="${mode}"]`);

            await page.locator("label", { has: option }).click();
            await expect(option).toBeChecked();
            await page.waitForLoadState("networkidle");
        };

        await pane("right");

        try {
            await page.goto("/mail/inbox");
            await page.locator("#message-list li").first().click();
            await page.waitForTimeout(800);
            await capture(page, "reading-pane", page.locator(".main-pane").first());
        } finally {
            await pane("off");
        }
    });
});

test.describe("What's new screenshots, as an administrator", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO_ADMIN.email, password: TEST_DEMO_ADMIN.password, admin: true });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO_ADMIN.email}`);
    });

    test("sso", async ({ page }) => {
        await login(page, TEST_DEMO_ADMIN.email, TEST_DEMO_ADMIN.password);
        await page.goto("/admin?section=sso");

        const panel = page.locator("turbo-frame#admin-sso");
        await expect(panel).toBeVisible();

        // Filled in for the picture and not saved, the way somebody setting
        // up Authentik would see it; the redirect address as a real install
        // shows it rather than this test stack's port.
        await panel.getByLabel("Issuer").fill("https://auth.example.com/application/o/plmail/");
        await panel.getByLabel("Client ID").fill("plmail");
        await panel.getByLabel("Name on the button").fill("Authentik");
        await panel.locator("input[readonly], code").first().evaluate((field) => {
            if (field instanceof HTMLInputElement) {
                field.value = "https://mail.example.com/login/oidc/callback";
            } else {
                field.textContent = "https://mail.example.com/login/oidc/callback";
            }
        });

        await capture(page, "sso", panel, 560);
    });
});
