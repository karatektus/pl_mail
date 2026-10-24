import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, mailRow, rememberedSession, seedUser } from "./support/config";

/**
 * Encryption for sessions the password form did not open, phase 6e of issue
 * #28: an account that signs in only through single sign-on sets encryption up
 * with a passphrase of its own, and every such session — remember-me, single
 * sign-on — is asked for its secret only when something needs the keys, in one
 * shared dialog, once per session.
 *
 * A single sign-on sign-in is stood in for by what it leaves behind, a
 * REMEMBERME cookie with no session (app:test:remember-me): the provider is
 * not what is under test, and the test stack has none. A person of its own
 * per test, as in encryption.spec.ts.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-lazy-unlock";
const PASSPHRASE = "a passphrase only for my keys";
const CHANGED = "a changed passphrase for my keys";
const ADDRESS = "mailbox@e2e.test";

test.describe("unlocking when needed", () => {
    test("a single sign-on account sets up a passphrase and is asked for it only when it opens encrypted mail", async ({ page, browser }) => {
        const email = person({ withoutPassword: true });

        await rememberedSession(page.context(), email);
        await page.goto("/mail/inbox");

        // Signed in, and asked nothing: no dialog, no toast about keys.
        await expect(page.getByRole("button", { name: `User menu for ${email}` })).toBeVisible();
        await settled(page);
        await expect(unlockDialog(page)).toBeHidden();

        await page.goto("/settings?section=encryption");
        await expect(page.getByText("Not set up yet.", { exact: false })).toBeVisible({ timeout: 15000 });
        await expect(unlockDialog(page)).toBeHidden();

        const setup = page.locator("[data-settings--encryption-target=setupForm]");

        await setup.getByLabel("Encryption passphrase", { exact: true }).fill(PASSPHRASE);
        await setup.getByLabel("Repeat the passphrase").fill(PASSPHRASE);
        await setup.getByRole("button", { name: "Set up encryption" }).click();
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

        const keyring = await (await page.request.get("/keyring")).json();

        expect(keyring.root).toBe("passphrase");
        expect(keyring.rootKdf.salt).toEqual(expect.any(String));
        expect(keyring.userKeys).toHaveLength(1);
        expect(keyring.addressKeys[0].addresses).toEqual([{ email: ADDRESS, primary: true }]);

        const first = `Sealed for SSO ${Date.now()}`;
        const second = `Second seal ${Date.now()}`;

        deliver(email, first, await encryptToOwnKey(page, "Only the passphrase opens this."));
        deliver(email, second, await encryptToOwnKey(page, "And this one, without asking again."));

        // Changed in the browser, which re-wraps the key; the sign-in is not
        // involved, and the old passphrase stops opening anything.
        const change = page.locator("[data-settings--encryption-target=passphraseCard]");

        await change.getByLabel("Current passphrase").fill(PASSPHRASE);
        await change.getByLabel("New passphrase", { exact: true }).fill(CHANGED);
        await change.getByLabel("Repeat the new passphrase").fill(CHANGED);
        await change.getByRole("button", { name: "Change passphrase" }).click();
        await expect(change.getByText("Passphrase changed.", { exact: false })).toBeVisible({ timeout: 15000 });

        // A fresh session: a browser that only has the cookie. The old
        // passphrase is tried first, and opens nothing any more.
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const fresh = await context.newPage();

        await rememberedSession(context, email);
        await fresh.goto("/mail/inbox");
        await settled(fresh);
        await expect(unlockDialog(fresh)).toBeHidden();

        await mailRow(fresh, first).click();

        const dialog = unlockDialog(fresh);

        await expect(dialog).toBeVisible({ timeout: 15000 });
        await expect(dialog.getByLabel("Encryption passphrase")).toBeVisible();

        await dialog.getByLabel("Encryption passphrase").fill(PASSPHRASE);
        await dialog.getByRole("button", { name: "Unlock" }).click();
        await expect(dialog.getByText("That passphrase does not open your keys.")).toBeVisible({ timeout: 15000 });

        await dialog.getByLabel("Encryption passphrase").fill(CHANGED);
        await dialog.getByRole("button", { name: "Unlock" }).click();
        await expect(dialog).toBeHidden({ timeout: 15000 });
        await expect(fresh.locator("pre[data-mail--encrypted-message-target=text]")).toHaveText("Only the passphrase opens this.", { timeout: 15000 });

        // Not again in this session: another message, and a full reload.
        await fresh.goto("/mail/inbox");
        await mailRow(fresh, second).click();
        await expect(fresh.locator("pre[data-mail--encrypted-message-target=text]")).toHaveText("And this one, without asking again.", { timeout: 15000 });
        await expect(unlockDialog(fresh)).toBeHidden();

        await context.close();
    });

    test("a password account on a remember-me session is asked for its password when it opens encrypted mail", async ({ page, browser }) => {
        const email = person();
        const subject = `Sealed after a while ${Date.now()}`;

        await login(page, email, PASSWORD);
        await page.goto("/settings?section=encryption");
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
        deliver(email, subject, await encryptToOwnKey(page, "Opened with the password."));

        // The session gone, the remember-me cookie kept: a returning visitor.
        const remembered = (await page.context().cookies()).filter((cookie) => "REMEMBERME" === cookie.name);

        expect(remembered).toHaveLength(1);

        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const returning = await context.newPage();

        await context.addCookies(remembered);
        await returning.goto("/mail/inbox");
        await expect(returning.getByRole("button", { name: `User menu for ${email}` })).toBeVisible();
        await settled(returning);
        await expect(unlockDialog(returning)).toBeHidden();

        await mailRow(returning, subject).click();

        const dialog = unlockDialog(returning);

        await expect(dialog).toBeVisible({ timeout: 15000 });
        await dialog.getByLabel("Password").fill(PASSWORD);
        await dialog.getByRole("button", { name: "Unlock" }).click();
        await expect(returning.locator("pre[data-mail--encrypted-message-target=text]")).toHaveText("Opened with the password.", { timeout: 15000 });

        await context.close();
    });

    test("cancelling the dialog leaves the message locked, and asking again opens it", async ({ page, browser }) => {
        const email = person();
        const subject = `Not now ${Date.now()}`;

        await login(page, email, PASSWORD);
        await page.goto("/settings?section=encryption");
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
        deliver(email, subject, await encryptToOwnKey(page, "Later, then."));

        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const returning = await context.newPage();

        await rememberedSession(context, email);
        await returning.goto("/mail/inbox");
        await mailRow(returning, subject).click();

        const dialog = unlockDialog(returning);

        await expect(dialog).toBeVisible({ timeout: 15000 });
        await dialog.getByRole("button", { name: "Not now" }).click();
        await expect(dialog).toBeHidden();

        const status = returning.locator("[data-mail--encrypted-message-target=status]");

        await expect(status).toContainText("Your keys are locked in this browser.");
        await expect(returning.locator("pre[data-mail--encrypted-message-target=text]")).toBeHidden();

        // Nothing else broke: the page still works, and asking again opens it.
        await status.getByRole("button", { name: "Unlock them" }).click();
        await expect(dialog).toBeVisible();
        await dialog.getByLabel("Password").fill(PASSWORD);
        await dialog.getByRole("button", { name: "Unlock" }).click();
        await expect(returning.locator("pre[data-mail--encrypted-message-target=text]")).toHaveText("Later, then.", { timeout: 15000 });

        await context.close();
    });
});

/** A person of this test's own, with one mailbox address, signed out. */
function person(options: { withoutPassword?: boolean } = {}): string {
    const email = `lazy-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true !== options.withoutPassword, withoutPassword: options.withoutPassword });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    return email;
}

function unlockDialog(page: Page) {
    return page.getByRole("dialog", { name: "Unlock your encryption keys" });
}

/**
 * The page's keyring has asked the server where it stands — the moment a
 * sign-in prompt would have appeared, if there were one.
 */
async function settled(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
}

/** Encrypted in the page, with its own openpgp, to the keyring's address key. */
async function encryptToOwnKey(page: Page, text: string): Promise<string> {
    return page.evaluate(async (plaintext) => {
        const openpgp = await import("openpgp");
        const keyring = await (await fetch("/keyring")).json();
        const key = await openpgp.readKey({ armoredKey: keyring.addressKeys[0].publicKey });

        return openpgp.encrypt({ message: await openpgp.createMessage({ text: plaintext }), encryptionKeys: key, format: "armored" });
    }, text);
}

function deliver(email: string, subject: string, armored: string): void {
    const raw = [
        "From: E2E Sender <sender@e2e.test>",
        `To: ${ADDRESS}`,
        `Subject: ${subject}`,
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=utf-8",
        "",
        armored.trim(),
        "",
    ].join("\r\n");

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${Buffer.from(raw, "utf8").toString("base64")}`);
}
