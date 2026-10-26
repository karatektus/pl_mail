import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, seedUser } from "./support/config";

/**
 * Pairing a device hands it the keyring (issue #28, phase 6), the way Signal
 * links a device: the browser seals the user keys under a transfer key, the
 * QR code carries that key in its fragment — drawn by the browser, since the
 * server never sees it — and the app gets the sealed copy with its app
 * password.
 *
 * Played here the way the app plays it: read `#k=` off the link, redeem the
 * code, open `keys` with the transfer key, and prove the user key in it is
 * the real one by opening an address key's token with it.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-pairing";

test("pairing a device with the keyring open hands over keys only the QR code can open", async ({ page }) => {
    await person(page);

    await page.goto("/settings?section=app-passwords");
    await page.getByRole("button", { name: "Pair a device" }).click();

    await expect(page.getByTestId("pairing-with-keys")).toBeVisible({ timeout: 15000 });

    const link = page.locator("[data-settings--pairing-qr-target=link]");

    await expect(link).toHaveAttribute("href", /#k=[A-Za-z0-9_-]{43}$/);
    await expect(page.locator("[data-settings--pairing-qr-target=image]")).toHaveAttribute("src", /^data:image\/svg\+xml;base64,/);

    const uri = new URL((await link.getAttribute("href"))!.replace("plmail://", "https://plmail.invalid/"));
    const code = uri.searchParams.get("code")!;
    const transferKey = uri.hash.replace("#k=", "");

    const redeemed = await page.request.post("/device/pair", { data: { code, deviceName: "Test phone" } });
    const body = await redeemed.json();

    expect(body.secret).toMatch(/^plmail_/);
    expect(body.keys).toMatch(/^v1\./);

    const proof = await page.evaluate(async ({ sealed, key }) => {
        const fromBase64 = (text: string) => Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
        const base64Url = (text: string) => fromBase64(text.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (text.length % 4)) % 4));
        const openWith = async (raw: Uint8Array, wrapped: string, purpose: string) => {
            const [, iv, ct] = wrapped.split(".");
            const cryptoKey = await crypto.subtle.importKey("raw", raw as unknown as BufferSource, "AES-GCM", false, ["decrypt"]);

            return new Uint8Array(await crypto.subtle.decrypt({ name: "AES-GCM", iv: fromBase64(iv), additionalData: new TextEncoder().encode(purpose) }, cryptoKey, fromBase64(ct)));
        };

        const bundle = JSON.parse(new TextDecoder().decode(await openWith(base64Url(key), sealed, "plmail/pairing-transfer")));
        const keyring = await (await fetch("/keyring")).json();
        const addressKey = keyring.addressKeys[0];
        const userKey = bundle.userKeys.find((entry: { id: number }) => entry.id === addressKey.userKeyId);
        const token = await openWith(fromBase64(userKey.key), addressKey.token, "plmail/address-key-token");

        return { version: bundle.v, tokenLength: token.length };
    }, { sealed: body.keys as string, key: transferKey });

    expect(proof.version).toBe(1);
    expect(proof.tokenLength).toBeGreaterThan(0);
});

async function person(page: Page): Promise<string> {
    const email = `pairer-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}
