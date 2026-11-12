import { test, expect } from "./support/test";
import { TEST_DEMO, TEST_DEMO_ADMIN, ajaxPost, consoleCommand, login, seedUser } from "./support/config";

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
    await page.goto(`/settings/${section}`);

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
            await page.goto("/settings/reading");
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

/**
 * The v0.4.0 highlights. The demo mailbox again, apart from encryption, which
 * needs a keyring the browser has made and opened: a person of its own, signed
 * in through the form so that happens, writing to their own address, which is
 * the one recipient whose key is certain to be there.
 */
test.describe("What's new screenshots, v0.4.0", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO.email, password: TEST_DEMO.password });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO.email}`);
    });

    /** The add-account form with POP3 chosen: the toggle, the POP3 host and the delete choice. */
    test("pop3-accounts", async ({ page }) => {
        // Tall enough for the whole form, so the modal does not scroll the
        // delete choice away under its own footer.
        await page.setViewportSize({ width: 1200, height: 1400 });
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
        await page.goto("/settings/accounts");
        await page.locator("section").filter({ has: page.locator("#settings-account-list") })
            .getByRole("button", { name: "Add account" }).click();

        const modal = page.locator("#modal");
        await expect(modal.locator('input[name="account[imapHost]"]')).toBeVisible();

        await modal.getByRole("radiogroup").getByText("POP3", { exact: true }).click();
        await expect(modal.getByText("Incoming — POP3")).toBeVisible();
        await modal.locator('input[name="account[imapHost]"]').fill("pop.example.com");

        // From the protocol choice down to what happens to the server's copy:
        // the part of the form that is new. The top is the same as ever.
        const panel = page.locator("[data-ui--modal-panel]");
        const from = modal.getByText("Incoming protocol", { exact: false }).first();
        const to = modal.locator('select[name="account[pop3DeleteMode]"] + .ts-wrapper');

        await to.scrollIntoViewIfNeeded();
        await from.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);

        const [p, a, b] = [await panel.boundingBox(), await from.boundingBox(), await to.boundingBox()];
        expect(p && a && b, "pop3-accounts: the protocol choice and the delete choice are both on screen").toBeTruthy();

        await page.waitForLoadState("networkidle");
        await page.screenshot({
            path: `${OUT}/pop3-accounts.png`,
            clip: { x: p!.x, y: a!.y - 16, width: p!.width, height: b!.y + b!.height + 58 - (a!.y - 16) },
        });
    });

    /** Settings → Appearance → Logo with an upload worn: the "Your own" tile, and the top bar wearing it. */
    test("custom-logo", async ({ page }) => {
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
        await page.goto("/settings/appearance");

        const panel = page.locator('[data-controller="settings--appearance"]');
        await expect(panel).toBeVisible();

        try {
            await page.locator('[data-settings--appearance-target="logoUploadInput"]').setInputFiles({
                name: "raman-joinery.svg",
                mimeType: "image/svg+xml",
                buffer: Buffer.from(JOINERY_LOGO),
            });
            await expect(panel.locator('[data-logo-motif="custom"]')).toHaveAttribute("aria-pressed", "true");
            await expect(page.locator('[data-logo-live-icon="light"]')).toHaveAttribute("src", /\/branding\/logo\/custom\//);

            // The Logo section alone: the icon tiles, "Your own" chosen among them.
            const logo = panel.locator("section:has(> div > h3:text-is('Logo'))");
            await capture(page, "custom-logo", logo, 300);
        } finally {
            await ajaxPost(page, "/settings/appearance/logo/remove", {});
        }
    });

    /**
     * The app in Simplified Chinese: the sidebar and the top of the list, where
     * the interface's own words are. The demo mail itself stays in English.
     * Put back to English afterwards, as compose-safety.spec.ts does.
     */
    test("simplified-chinese", async ({ page }) => {
        const useLocale = async (locale: string): Promise<void> => {
            await page.goto("/settings/region");
            const button = page.locator(`form[action*='locale']:has(input[value='${locale}']) button`).first();

            if (false === (await button.isDisabled())) {
                await button.click();
                await expect(page).toHaveURL(/\/settings\/region/);
            }
        };

        await login(page, TEST_DEMO.email, TEST_DEMO.password);
        await useLocale("zh_CN");

        try {
            await page.goto("/mail/inbox");
            await expect(page.locator("#message-list li").nth(3)).toBeVisible();
            await expect(page.locator("html")).toHaveAttribute("lang", /^zh/);

            await page.waitForLoadState("networkidle");
            await page.waitForTimeout(600);
            await page.screenshot({ path: `${OUT}/simplified-chinese.png`, clip: { x: 0, y: 0, width: 760, height: 430 } });
        } finally {
            await useLocale("en");
        }
    });
});

/**
 * The v0.4.1 highlights, on the demo mailbox.
 *
 * The demo account's host does not exist, so it never syncs and has no folders
 * of its own. The two pictures that are about folders stage some in the
 * database, as the sso-only picture does with its box, and take them away in
 * `finally`, so every other picture still sees the account as it was seeded.
 */
const DEMO_ACCOUNT = `(SELECT a.id FROM account a JOIN \\"user\\" u ON u.id = a.usr_id WHERE a.username = 'demo@plmail.test' AND u.email = '${TEST_DEMO.email}')`;

/** The folders: name, path, special use, watched with IDLE. */
const DEMO_FOLDERS: [string, string, string | null, boolean][] = [
    ["INBOX", "INBOX", "Inbox", true],
    ["Archive", "Archive", "Archive", false],
    ["Drafts", "Drafts", "Drafts", false],
    ["Sent", "Sent", "Sent", false],
    ["Junk", "Junk", "Junk", false],
    ["Trash", "Trash", "Trash", false],
    ["Receipts", "Receipts", null, true],
    ["Travel", "Travel", null, false],
];

function stageFolders(): void {
    const rows = DEMO_FOLDERS.map(([name, path, use, idle]) =>
        `(${DEMO_ACCOUNT}, '${name}', '${path}', '/', ${null === use ? "NULL" : `'\\\\${use}'`}, true, ${idle}, NOW(), NOW())`,
    ).join(", ");

    consoleCommand(`dbal:run-sql "INSERT INTO mailbox (account_id, name, full_path, delimiter, special_use, is_sync_enabled, is_idle_enabled, created_at, updated_at) VALUES ${rows}"`);
    // Two of the demo's labels become folders on the account: what Move to offers.
    consoleCommand(`dbal:run-sql "UPDATE label_binding b SET mailbox_id = m.id FROM label l, mailbox m WHERE b.label_id = l.id AND b.account_id = ${DEMO_ACCOUNT} AND m.account_id = b.account_id AND m.name = l.name AND l.name IN ('Receipts', 'Travel')"`);
}

function clearFolders(): void {
    consoleCommand(`dbal:run-sql "DELETE FROM mailbox WHERE account_id = ${DEMO_ACCOUNT}"`);
}

/** A compose window addressed and written, ready to photograph. */
async function composeTo(page: Page, recipients: string[], subject: string, body: string): Promise<Locator> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");
    const to = dock.locator(".ts-control input").first();
    await expect(to).toBeVisible();

    for (const recipient of recipients) {
        await to.fill(recipient);
        await to.press("Enter");
    }

    await dock.locator('[data-compose--compose-target="subject"]').fill(subject);
    await dock.locator('[data-compose--compose-toolbar-target="editor"]').fill(body);

    return dock;
}

test.describe("What's new screenshots, v0.4.1", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO.email, password: TEST_DEMO.password });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO.email}`);
    });

    test.beforeEach(async ({ page }) => {
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
    });

    /** The account form under JMAP with Fastmail chosen: one host, one token, no SMTP. */
    test("jmap-accounts", async ({ page }) => {
        await page.setViewportSize({ width: 1200, height: 1400 });
        await page.goto("/settings/accounts");
        await page.locator("section").filter({ has: page.locator("#settings-account-list") })
            .getByRole("button", { name: "Add account" }).click();

        const modal = page.locator("#modal");
        await expect(modal.locator('input[name="account[imapHost]"]')).toBeVisible();

        await modal.getByRole("radiogroup").getByText("JMAP", { exact: true }).click();
        await expect(modal.getByText("Outgoing — JMAP")).toBeVisible();
        await modal.locator('input[name="account[email]"], input[name="account[emailAddress]"]').first().fill("priya@ramanjoinery.example");
        await modal.locator('input[name="account[username]"]').fill("priya@ramanjoinery.example");
        await modal.locator('input[name="account[imapHost]"]').fill("mail.ramanjoinery.example");

        await capture(page, "jmap-accounts", page.locator("[data-ui--modal-panel]"), 760);
    });

    /** Move to open on a message: the folders of the account, and nothing that is only a tag. */
    test("move-to-folder", async ({ page }) => {
        stageFolders();

        try {
            await page.goto("/mail/inbox");
            await page.locator("#message-list li").first().click();

            const button = page.getByRole("button", { name: "Move to", exact: true }).last();
            await button.click();

            const menu = page.getByRole("menu", { name: "Move to" }).last();
            await expect(menu).toBeVisible();
            await page.waitForTimeout(300);

            const box = await menu.boundingBox();
            expect(box, "move-to-folder: no menu").not.toBeNull();
            await page.screenshot({
                path: `${OUT}/move-to-folder.png`,
                clip: { x: box!.x - 70, y: box!.y - 50, width: box!.width + 150, height: box!.height + 62 },
            });
        } finally {
            clearFolders();
        }
    });

    /** A chip on its way from To to Cc: the ghost under the pointer, the field it would land in outlined. */
    test("drag-recipients", async ({ page }) => {
        const dock = await composeTo(
            page,
            ["priya@ramanjoinery.example", "tomas@brunnengasse.example", "lena@studio.example"],
            "Measurements for the Brunnengasse workshop",
            "The drawings are on their way.",
        );

        await dock.getByRole("button", { name: /^Cc$/ }).click();

        const chip = dock.locator(".ts-control").first().locator(".item").filter({ hasText: "lena@studio.example" });
        const cc = dock.locator(".ts-control").nth(1);
        const [from, to] = [await chip.boundingBox(), await cc.boundingBox()];
        expect(from && to, "drag-recipients: the chip or Cc is not on screen").toBeTruthy();

        await page.mouse.move(from!.x + 8, from!.y + from!.height / 2);
        await page.mouse.down();
        await page.mouse.move(to!.x + to!.width / 3, to!.y + to!.height / 2, { steps: 10 });
        await expect(dock.locator(".ts-wrapper.ts-drop-target")).toHaveCount(1);

        await capture(page, "drag-recipients", dock.locator(".compose-window"));
        await page.mouse.up();
    });

    /** Send's question, with the word that raised it in the message behind it. */
    test("forgotten-attachment", async ({ page }) => {
        const dock = await composeTo(
            page,
            ["priya@ramanjoinery.example"],
            "Quote for the Brunnengasse workshop",
            "Hello Priya, the quote is attached. Let me know what you think.",
        );

        await dock.getByRole("button", { name: "Send", exact: true }).click();
        await expect(dock.locator('[data-compose--compose-target="sendWarning"]')).toContainText(/mentions an attachment/i);

        await capture(page, "forgotten-attachment", dock.locator(".compose-window"));
    });

    /** The Details panel answering for two filters, one that matches and one that does not. */
    test("test-filters", async ({ page }) => {
        const names = ["Hotel bookings", "Newsletters"];

        const forget = (): void => consoleCommand(`dbal:run-sql "DELETE FROM mail_rule WHERE name IN ('${names.join("', '")}')"`);

        const saveFilter = async (name: string, subject: string): Promise<void> => {
            await page.goto("/settings/filters");
            const list = page.locator("#settings-filter-list");

            await list.getByRole("link", { name: "New filter" }).click();
            const editor = page.locator("#filter-editor");
            await editor.getByLabel("Name").fill(name);
            await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first().fill(subject);
            await editor.getByRole("button", { name: "Add action" }).click();
            await editor.getByRole("button", { name: "Save" }).click();
            await expect(list.getByText(name)).toBeVisible();
        };

        forget();
        await saveFilter(names[0], "Booking");
        await saveFilter(names[1], "Newsletter");

        await page.goto("/mail/inbox");
        await page.locator("#message-list li").filter({ hasText: "Booking confirmed" }).first().click();

        const details = page.locator('[data-controller="mail--message-details"]').first();
        await details.getByRole("button").first().click();
        await details.getByRole("button", { name: "Test my filters on this message" }).click();
        await expect(details.locator('[data-mail--message-details-target="filters"] turbo-frame > div')).toBeVisible();

        await capture(page, "test-filters", details.locator('[data-mail--message-details-target="panel"]'));
        forget();
    });

    /** An account's folder list: the bolts, and New folder above them. */
    test("idle-folders", async ({ page }) => {
        stageFolders();

        try {
            await page.setViewportSize({ width: 1200, height: 900 });
            await page.goto("/settings/accounts");
            await page.locator("#settings-account-list li").first()
                .getByRole("button", { name: "Folders", exact: true }).click();

            const modal = page.locator("#modal-backdrop");
            await expect(modal.getByText("Receipts")).toBeVisible();

            await capture(page, "idle-folders", page.locator("[data-ui--modal-panel]"));
        } finally {
            clearFolders();
        }
    });

    /** Settings → App passwords, from the Play badge down to Pair a device. */
    test("android-app", async ({ page }) => {
        await page.goto("/settings/app-passwords");
        await expect(page.getByTestId("native-apps-play")).toBeVisible();

        const card = page.locator("section", { has: page.getByTestId("native-apps-play") }).first();
        await capture(page, "android-app", card, 420);
    });
});

/** A workshop's mark: a plane over a board, in the demo's joinery colours. */
const JOINERY_LOGO = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
    <rect width="64" height="64" rx="14" fill="#7d6b4f"/>
    <rect x="10" y="40" width="44" height="8" rx="2" fill="#f4ead8"/>
    <path d="M18 34 L40 20 L48 26 L26 40 Z" fill="#f4ead8"/>
    <circle cx="43" cy="21" r="4" fill="#c9a96e"/>
</svg>`;

test.describe("What's new screenshots, encryption", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    /** A message to oneself, encrypted by itself: the lock on the recipient and Encrypted in the header. */
    test("encryption", async ({ page }) => {
        const email = `e2e-demo-crypto-${Date.now()}@plmail.test`;
        const password = "a-password-for-the-picture";

        seedUser({ email, password, stretched: true });
        consoleCommand(`app:test:seed-demo --email=${email}`);

        await login(page, email, password);
        await page.goto("/settings/encryption");
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 20000 });

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();

        const dock = page.locator("#compose_dock");
        const to = dock.locator(".ts-control input").first();
        await expect(to).toBeVisible();
        await to.fill("you@example.com");
        await to.press("Enter");

        await dock.locator('[data-compose--compose-target="subject"]').fill("Contract for the Brunnengasse workshop");
        await dock.locator('[data-compose--compose-toolbar-target="editor"]').fill(
            "Here is the signed contract, as agreed on Thursday. The bank details are on page 3.",
        );

        await expect(dock.locator("[data-testid=compose-encrypted]")).toBeVisible({ timeout: 15000 });
        await capture(page, "encryption", dock.locator(".compose-window"));
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

    /**
     * Single sign-on only (#169): the login page with the provider's button and
     * no password form, which is the feature as everybody else meets it.
     *
     * The provider is saved through Admin → SSO, since the client secret is
     * stored encrypted and SQL cannot write it. The box itself is set in the
     * database: the form only lets an administrator tick it who has signed in
     * through that provider, and this stack has no provider to sign in at.
     * Undone in `finally`, so the stack is left with password sign-in and no
     * provider, as the other pictures and the regression suite expect.
     */
    test("sso-only", async ({ page }) => {
        await login(page, TEST_DEMO_ADMIN.email, TEST_DEMO_ADMIN.password);
        await page.goto("/admin?section=sso");

        const panel = page.locator("turbo-frame#admin-sso");
        await panel.getByLabel("Issuer").fill("https://auth.example.com/application/o/plmail/");
        await panel.getByLabel("Client ID").fill("plmail");
        await panel.getByLabel("Client secret").fill("not-a-real-secret");
        await panel.getByLabel("Name on the button").fill("Authentik");
        await panel.getByRole("button", { name: "Save", exact: true }).click();
        await expect(panel.getByLabel("Issuer")).toHaveValue("https://auth.example.com/application/o/plmail/");

        try {
            consoleCommand(`dbal:run-sql "UPDATE oidc_config SET password_login_disabled = true"`);
            await page.context().clearCookies();
            await page.goto("/login");

            await expect(page.getByLabel("Password")).toHaveCount(0);
            await expect(page.getByText("Authentik")).toBeVisible();

            // With a margin of the page around it: cropped to the box, the
            // logo touches the top edge and the card's bottom border is lost.
            const box = await page.locator("div.max-w-sm").first().boundingBox();
            expect(box, "sso-only: no login card").not.toBeNull();
            await page.screenshot({
                path: `${OUT}/sso-only.png`,
                clip: { x: box!.x - 32, y: box!.y - 32, width: box!.width + 64, height: box!.height + 64 },
            });
        } finally {
            consoleCommand("app:sso:allow-password-login");
            consoleCommand(`dbal:run-sql "DELETE FROM oidc_config"`);
        }
    });
});

/**
 * A photo for the compose window: soft colour bands with some grain, so it
 * looks like a picture in its thumbnail and a smaller copy is really fewer
 * bytes. Drawn in the page, because the browser is what encodes JPEGs here.
 */
async function demoPhoto(page: Page, width: number, height: number): Promise<Buffer> {
    const base64 = await page.evaluate(async ([w, h]) => {
        const canvas = new OffscreenCanvas(w, h);
        const context = canvas.getContext("2d")!;
        const sky = context.createLinearGradient(0, 0, 0, h);
        sky.addColorStop(0, "#9cc3d5");
        sky.addColorStop(0.55, "#e8d9b5");
        sky.addColorStop(0.56, "#5f7f5a");
        sky.addColorStop(1, "#3e5a3c");
        context.fillStyle = sky;
        context.fillRect(0, 0, w, h);

        const pixels = context.getImageData(0, 0, w, h);
        for (let i = 0; i < pixels.data.length; i += 4) {
            const grain = ((i * 7919) % 41) - 20;
            pixels.data[i] += grain;
            pixels.data[i + 1] += grain;
            pixels.data[i + 2] += grain;
        }
        context.putImageData(pixels, 0, 0);

        const blob = await canvas.convertToBlob({ type: "image/jpeg", quality: 0.92 });
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = "";
        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
        }

        return btoa(binary);
    }, [width, height]);

    return Buffer.from(base64, "base64");
}

test.describe("What's new screenshots, v0.4.2", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO.email, password: TEST_DEMO.password });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO.email}`);
    });

    test.beforeEach(async ({ page }) => {
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
    });

    /**
     * The inboxes card with two of the reader's own beside the standard one.
     * Made through the card's own form and removed again afterwards, so the
     * other pictures keep the demo's single Inbox.
     */
    test("unified-inboxes", async ({ page }) => {
        const icons: Record<string, string> = { Work: "briefcase", Personal: "house" };
        const names = Object.keys(icons);
        const forget = (): void => consoleCommand(
            `dbal:run-sql "DELETE FROM unified_inbox WHERE name IN ('${names.join("', '")}') AND usr_id = (SELECT id FROM \\"user\\" WHERE email = '${TEST_DEMO.email}')"`,
        );

        forget();

        try {
            for (const name of names) {
                const inboxes = await card(page, "inboxes", "Unified inboxes");
                const field = inboxes.getByPlaceholder("e.g. Work");
                // The icon set is a popover; the radio inside it is what the form sends.
                await inboxes.locator(`#inbox-new-icon-${icons[name]}`).evaluate((el) => { (el as HTMLInputElement).checked = true; });
                await field.fill(name);
                await field.press("Enter");
                await expect(page.getByText(`Created "${name}".`)).toBeVisible();
            }

            await capture(page, "unified-inboxes", await card(page, "inboxes", "Unified inboxes"));
        } finally {
            forget();
        }
    });

    /** A draft with a photo attached and Medium picked: the row, and the message's size before and after. */
    test("image-size", async ({ page }) => {
        const dock = await composeTo(
            page,
            ["priya@ramanjoinery.example"],
            "Photos from the Brunnengasse workshop",
            "Here are the photos of the finished shelves.",
        );

        await dock.locator('input[data-compose--compose-target="fileInput"]').setInputFiles({
            name: "workshop-shelves.jpg",
            mimeType: "image/jpeg",
            buffer: await demoPhoto(page, 4000, 3000),
        });

        const row = dock.locator('[data-compose--compose-target="imageSize"]');
        await expect(row).toBeVisible();
        await row.getByRole("radio", { name: "Medium" }).click();
        await expect(dock.locator('[data-compose--compose-target="imageSize"]').getByRole("radio", { name: "Medium" }))
            .toHaveAttribute("aria-checked", "true", { timeout: 15000 });
        await page.waitForTimeout(800);

        await capture(page, "image-size", dock.locator(".compose-window"));
    });

    /** Notify for, with the standard choice: Primary, and nothing else until the reader ticks it. */
    test("notify-for", async ({ page }) => {
        await capture(page, "notify-for", await card(page, "notifications", "Notifications"), 620);
    });
});

/**
 * One provider switched on by the administrator, through the same Configure
 * dialog a person uses. The row's body is swapped in by a frame that comes
 * back with every row closed (integrations.spec.ts → openRow tells the story),
 * so opening and clicking retry together.
 */
async function enableProvider(page: Page, id: string, baseUrl: string): Promise<void> {
    await page.goto("/admin?section=integrations");
    await expect(page.locator("#admin-integrations")).toBeVisible();

    await expect(async () => {
        const row = page.locator(`#integration-provider-${id}`);
        if (false === (await row.evaluate((el) => (el as HTMLDetailsElement).open))) {
            await row.locator("summary").click();
        }
        await row.getByRole("button", { name: "Configure" }).click({ timeout: 2000 });
    }).toPass({ timeout: 20000 });

    const modal = page.locator("#modal");
    await modal.locator('input[name$="[isEnabled]"]').check();
    const url = modal.locator('input[name$="[baseUrl]"]');
    if (await url.count() > 0) {
        await url.fill(baseUrl);
    }
    await modal.getByRole("button", { name: "Save" }).click();
    await expect(page.locator(`#integration-provider-${id}`).getByText("Enabled")).toBeVisible();
}

/** The connect dialog of one service, opened from Settings → Integrations. */
async function connectDialog(page: Page, service: string): Promise<Locator> {
    await page.goto("/settings/integrations");
    const frame = page.locator("#settings-integrations-frame");
    await frame.getByRole("button", { name: service, exact: true }).click();

    const panel = page.locator("[data-ui--modal-panel]");
    await expect(panel).toBeVisible();

    return panel;
}

test.describe("What's new screenshots, v0.4.2 integrations", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO_ADMIN.email, password: TEST_DEMO_ADMIN.password, admin: true });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO_ADMIN.email}`);
    });

    test.beforeEach(async ({ page }) => {
        await login(page, TEST_DEMO_ADMIN.email, TEST_DEMO_ADMIN.password);
    });

    test("opencloud", async ({ page }) => {
        await enableProvider(page, "openCloud", "https://cloud.ramanjoinery.example");

        const panel = await connectDialog(page, "OpenCloud");
        const url = panel.locator('input[name$="[baseUrl]"]');
        if (await url.count() > 0) {
            await url.fill("https://cloud.ramanjoinery.example");
        }
        await panel.locator('input[name$="[username]"]').fill("priya");
        await panel.locator('input[name$="[username]"]').blur();

        await capture(page, "opencloud", panel, 760);
    });

    /** The connect dialog's new first choice: approve in Nextcloud instead of pasting an app password. */
    test("nextcloud-approval", async ({ page }) => {
        await enableProvider(page, "nextcloud", "https://cloud.ramanjoinery.example");

        const panel = await connectDialog(page, "Nextcloud");
        await expect(panel.getByRole("button", { name: "Connect with browser approval" })).toBeVisible();

        await capture(page, "nextcloud-approval", panel, 760);
    });
});

test.describe("What's new screenshots, v0.4.3", () => {
    test.skip(undefined === process.env.E2E_SCREENSHOTS, 'Demo mailbox required — run "npm run test:e2e:screenshots".');

    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        seedUser({ email: TEST_DEMO.email, password: TEST_DEMO.password });
        consoleCommand(`app:test:seed-demo --email=${TEST_DEMO.email}`);
    });

    test.beforeEach(async ({ page }) => {
        await login(page, TEST_DEMO.email, TEST_DEMO.password);
    });

    /**
     * The tree with a word in its filter (#569). "sign" rather than a page's
     * name: it finds cards in two areas, and Password by its keyword
     * "sign-in", which is what a search over the cards adds to a list of pages.
     */
    test("settings-search", async ({ page }) => {
        await page.goto("/settings/labels");

        const tree = page.locator("#settings-tree");
        await page.locator("#settings-filter").fill("sign");
        await expect(tree.locator("li[data-settings--nav-target='hit']:not([hidden])").first()).toBeVisible();

        await capture(page, "settings-search", tree);
    });

    /**
     * The compose window a mailto: link opens (#572), reached the way the
     * browser's handler reaches it: /compose/new?mailto=…, which the server
     * turns into the mailbox with the window in its dock. Not saved: a link's
     * window is no draft until the first autosave.
     */
    test("mailto-links", async ({ page }) => {
        const link = "mailto:priya@ramanjoinery.example?cc=jonas@ramanjoinery.example"
            + "&subject=Shelves%20for%20the%20Brunnengasse%20workshop"
            + "&body=Hello%20Priya%2C%0A%0AI%20saw%20the%20shelves%20on%20your%20website.";

        await page.goto(`/compose/new?mailto=${encodeURIComponent(link)}`);

        const dock = page.locator("#compose_dock");
        await expect(dock.locator('input[name="compose[subject]"]')).toHaveValue("Shelves for the Brunnengasse workshop");
        await expect(dock.locator('[data-compose--compose-target="ccField"]')).toContainText("jonas@ramanjoinery.example");

        await capture(page, "mailto-links", dock.locator(".compose-window"));
    });
});
