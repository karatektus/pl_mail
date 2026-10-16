import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, seedUser } from "./support/config";

/**
 * Contacts' keys from public key servers, phase 6f of issue #28: a key that
 * only a key server has is found by the lookup under Contacts' keys, and the
 * list says which server it came from; with the person's switch off, the same
 * lookup asks no key server and finds nothing.
 *
 * The key servers are the test environment's stand-in (KeyServerStub, wired
 * under `when@test` in config/services.yaml), which answers keys.openpgp.org's
 * and keys.mailvelope.com's APIs from what app:test:seed-key-server planted —
 * never the internet. The address is on `.test`, which resolves nowhere, so
 * the WKD lookup that comes first finds nothing. The key is made in the page
 * with its own openpgp, as in contact-keys.spec.ts.
 *
 * A person and an address of their own per test: lookups are cached on the
 * server, and an address another test found would be found from there.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-key-servers";

test.describe("contacts' keys from key servers", () => {
    test("finds a key only a key server has, and says which server", async ({ page }) => {
        await person(page);

        const address = uniqueAddress();
        const key = await newKey(page, address);
        const frame = page.locator("turbo-frame#contact-keys");

        publish("keys.openpgp.org", address, key.publicKey);

        await expect(frame.getByTestId("key-servers-switch")).toContainText("keys.openpgp.org, keys.mailvelope.com");

        await lookUp(page, address);

        await expect(frame.getByTestId("contact-keys-notice")).toContainText("Found a key on the key server keys.openpgp.org");
        await expect(frame.getByTestId("contact-key-fingerprint")).toHaveText(grouped(key.fingerprint));
        await expect(frame.getByTestId("contact-key-origin")).toHaveText("From the key server keys.openpgp.org");
    });

    test("asks no key server once the person switches them off", async ({ page }) => {
        await person(page);

        const address = uniqueAddress();
        const key = await newKey(page, address);
        const frame = page.locator("turbo-frame#contact-keys");
        const switcher = frame.getByTestId("key-servers-switch");

        publish("keys.mailvelope.com", address, key.publicKey);

        await switcher.getByText("Off", { exact: true }).click();
        await expect(switcher.locator('input[value="0"]')).toBeChecked();

        await lookUp(page, address);

        await expect(frame.getByTestId("contact-keys-notice")).toContainText("The domain publishes no usable key");
        await expect(frame.getByTestId("contact-key")).toHaveCount(0);

        // Still off after a reload: the choice is stored, not just shown.
        await page.reload();
        await expect(page.locator("turbo-frame#contact-keys").getByTestId("key-servers-switch").locator('input[value="0"]')).toBeChecked();
    });
});

/** A signed-in person with a keyring, on Settings → Encryption. */
async function person(page: Page): Promise<void> {
    const email = `keyservers-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
}

function uniqueAddress(): string {
    return `kim-${Date.now()}-${Math.floor(Math.random() * 1e6)}@keyserver-only.test`;
}

/** Puts the key on the stand-in for $host, as if its owner had published it there. */
function publish(host: string, address: string, publicKey: string): void {
    consoleCommand(`app:test:seed-key-server --host=${host} --email=${address} --key=${Buffer.from(publicKey).toString("base64")}`);
}

async function lookUp(page: Page, address: string): Promise<void> {
    const frame = page.locator("turbo-frame#contact-keys");

    await frame.getByLabel("Email address").fill(address);
    await frame.getByRole("button", { name: "Look up", exact: true }).click();
}

type Key = { publicKey: string; fingerprint: string };

/** A contact's key, made with the page's own openpgp. */
async function newKey(page: Page, address: string): Promise<Key> {
    return page.evaluate(async (email) => {
        const openpgp = await import("openpgp");
        const { publicKey } = await openpgp.generateKey({ userIDs: [{ email }], format: "armored" });
        const key = await openpgp.readKey({ armoredKey: publicKey });

        return { publicKey, fingerprint: key.getFingerprint() };
    }, address);
}

function grouped(fingerprint: string): string {
    return fingerprint.toUpperCase().match(/.{1,4}/g)?.join(" ") ?? "";
}
