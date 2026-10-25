import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, logout, seedUser } from "./support/config";

/**
 * The keyring of issue #28, phase 2: made on the first sign-in, open on every
 * page after it, carried across a password change, and brought back after a
 * reset by the recovery phrase.
 *
 * In a fresh, unauthenticated context like auth.spec.ts, and with a person of
 * its own per test: these tests sign in, reset passwords and make keys, which
 * is nothing to do to the account the other specs share.
 *
 * What is asserted is mostly the keyring as the server holds it (GET
 * /keyring), because that is what the next browser — or the next page — has
 * to open, and the settings page only on what a person reads there.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-the-keyring";
const ADDRESS = "mailbox@e2e.test";

test.describe("encryption keyring", () => {
    test("is made on the first sign-in and open on the next page without asking", async ({ page }) => {
        const email = await person();

        await login(page, email, PASSWORD);
        await openEncryptionSettings(page);

        const keyring = await keyringOf(page);

        expect(keyring.userKeys).toHaveLength(1);
        expect(keyring.userKeys[0].status).toBe("active");
        expect(keyring.addressKeys).toHaveLength(1);
        expect(keyring.addressKeys[0].addresses).toEqual([{ email: ADDRESS, primary: true }]);
        expect(keyring.addressKeys[0].privateKey).toContain("BEGIN PGP PRIVATE KEY BLOCK");
        expect(keyring.copies.filter((copy: { kind: string }) => "device" === copy.kind)).toHaveLength(1);

        // A full reload: nothing in memory survives it, so this is the session
        // unlock key and localStorage doing their job.
        await page.reload();
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible();
        expect((await keyringOf(page)).userKeys).toHaveLength(1);
    });

    test("is opened again with the recovery phrase after an operator reset the password", async ({ page, browser }) => {
        const email = await person();

        await login(page, email, PASSWORD);
        await openEncryptionSettings(page);
        await page.getByRole("button", { name: "Create recovery phrase" }).click();

        const phrase = page.locator("[data-settings--encryption-target=phraseWords]");

        await expect(phrase).toHaveText(/^\s*([a-z]+ ){11}[a-z]+\s*$/);

        const words = ((await phrase.textContent()) ?? "").trim();

        const before = await keyringOf(page);

        consoleCommand(`app:user:password ${email} --password=a-password-the-operator-set --no-change-required`);

        // A browser this person never used: no device key to reopen anything.
        const elsewhere = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

        await login(elsewhere, email, "a-password-the-operator-set");
        await openEncryptionSettings(elsewhere);

        const rebuilt = await keyringOf(elsewhere);

        expect(rebuilt.userKeys.map((key: { status: string }) => key.status).sort()).toEqual(["active", "locked"]);

        await elsewhere.locator("[data-settings--encryption-target=recoverWords]").fill(words);
        await elsewhere.getByRole("button", { name: "Open with recovery phrase" }).click();
        await expect(elsewhere.getByText("1 keys opened again.")).toBeVisible();

        const recovered = await keyringOf(elsewhere);

        expect(recovered.userKeys.every((key: { status: string }) => "active" === key.status)).toBe(true);
        // The old address key is still there, under the old user key, to open
        // whatever was sent to it.
        expect(recovered.addressKeys.map((key: { fingerprint: string }) => key.fingerprint))
            .toContain(before.addressKeys[0].fingerprint);

        await elsewhere.context().close();
    });

    test("is carried across a password change", async ({ page }) => {
        const email = await person();
        const chosen = "a-new-password-for-the-keyring";

        await login(page, email, PASSWORD);
        await openEncryptionSettings(page);

        const before = await keyringOf(page);

        await page.goto("/settings?section=security");

        const card = page.locator("#settings-password");

        await card.getByLabel("Current password").fill(PASSWORD);
        await card.getByLabel("New password", { exact: true }).fill(chosen);
        await card.getByLabel("Repeat the new password").fill(chosen);
        await card.getByRole("button", { name: "Change password" }).click();
        await expect(page.getByText("Password changed")).toBeVisible();

        const after = await keyringOf(page);

        expect(after.userKeys[0].wrapped).not.toBe(before.userKeys[0].wrapped);
        expect(after.userKeys[0].status).toBe("active");

        // Still open in this session after a reload, so the stored half of the
        // unlock was swapped for the new password's...
        await openEncryptionSettings(page);

        // ...and open on a fresh sign-in with the new password.
        await logout(page);
        await login(page, email, chosen);
        await openEncryptionSettings(page);
        expect((await keyringOf(page)).userKeys).toHaveLength(1);
    });

    test("imports a key the person already has, and exports every key", async ({ page }) => {
        const email = await person();

        await login(page, email, PASSWORD);
        await openEncryptionSettings(page);

        const generated = (await keyringOf(page)).addressKeys[0].fingerprint;
        const armored = await page.evaluate(async (address) => {
            const openpgp = await import("openpgp");
            const { privateKey } = await openpgp.generateKey({ userIDs: [{ email: address }], passphrase: "their-own-passphrase", format: "armored" });

            return privateKey;
        }, ADDRESS);

        await page.locator("[data-settings--encryption-target=importText]").fill(armored);
        await page.locator("[data-settings--encryption-target=importPassphrase]").fill("their-own-passphrase");
        await page.getByRole("button", { name: "Import", exact: true }).click();
        await expect(page.getByText("1 keys imported.")).toBeVisible();

        const keyring = await keyringOf(page);

        // The key plMail made a minute ago was never seen by anybody, so the
        // imported one replaces it rather than standing beside it.
        expect(keyring.addressKeys.map((key: { fingerprint: string }) => key.fingerprint)).not.toContain(generated);
        expect(keyring.addressKeys).toHaveLength(1);
        expect(keyring.addressKeys[0].origin).toBe("imported");

        await page.locator("[data-settings--encryption-target=exportPassphrase]").fill("an-export-passphrase");

        const download = page.waitForEvent("download");

        await page.getByRole("button", { name: "Export" }).click();

        const file = await (await download).path();
        const contents = await (await import("node:fs/promises")).readFile(file, "utf8");

        expect(contents).toContain("BEGIN PGP PRIVATE KEY BLOCK");
    });
});

/** A person of this test's own, with one mailbox address, signed out. */
async function person(): Promise<string> {
    const email = `keyring-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    return email;
}

async function openEncryptionSettings(page: Page): Promise<void> {
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function keyringOf(page: Page): Promise<any> {
    const response = await page.request.get("/keyring");

    expect(response.ok()).toBe(true);

    return response.json();
}
