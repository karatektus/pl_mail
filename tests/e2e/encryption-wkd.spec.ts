import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, seedUser } from "./support/config";

/**
 * The Web Key Directory, phase 6d of issue #28: plMail publishes a person's
 * key under the installation's own domains (the test stack serves
 * `plmail.test`, from .env.test), and the person can withdraw it.
 *
 * A person of the test's own, whose mailbox has their sign-in address — the
 * only address the directory publishes — so the keyring made on the first
 * sign-in holds a key for it. What is asserted beyond the card is the public
 * route itself, asked with no session the way a mail program asks it: the
 * switch is only worth something if "off" is a 404 out there.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-the-directory";

test("publishes the key of the sign-in address, and stops when switched off", async ({ page, playwright, baseURL }) => {
    const email = await person(page);

    const row = page.locator(`[data-testid="wkd-address"][data-email="${email}"]`);

    await expect(row).toBeVisible();
    await expect(row.getByTestId("wkd-state")).toHaveText(/^Published\./);

    const url = new URL((await row.getByTestId("wkd-url").textContent())?.trim() ?? "");

    expect(url.host).toBe("openpgpkey.plmail.test");
    expect(url.pathname).toMatch(/^\/\.well-known\/openpgpkey\/plmail\.test\/hu\/[ybndrfg8ejkmcpqxot1uwisza345h769]{32}$/);

    // No cookies, no session: what Thunderbird or gpg would get.
    const outside = await playwright.request.newContext({ baseURL });
    const ask = async () => outside.get(url.pathname + url.search);

    const served = await ask();

    expect(served.status()).toBe(200);
    expect(served.headers()["content-type"]).toBe("application/octet-stream");
    expect(served.headers()["access-control-allow-origin"]).toBe("*");

    // The key served is the one on the keyring, binary rather than armored.
    const fingerprint = await page.evaluate(async (bytes) => {
        const openpgp = await import("openpgp");
        const key = await openpgp.readKey({ binaryKey: new Uint8Array(bytes) });

        return key.getFingerprint();
    }, [...(await served.body())]);
    const keyring = await (await page.request.get("/keyring")).json();

    expect(fingerprint).toBe(keyring.addressKeys[0].fingerprint);

    await row.getByRole("radiogroup").getByText("Off", { exact: true }).click();
    await expect(page.locator(`[data-testid="wkd-address"][data-email="${email}"]`).getByTestId("wkd-state"))
        .toHaveText("Not published — you switched it off.");
    expect((await ask()).status()).toBe(404);

    await page.locator(`[data-testid="wkd-address"][data-email="${email}"]`).getByRole("radiogroup").getByText("On", { exact: true }).click();
    await expect(page.locator(`[data-testid="wkd-address"][data-email="${email}"]`).getByTestId("wkd-state"))
        .toHaveText(/^Published\./);
    expect((await ask()).status()).toBe(200);

    await outside.dispose();
});

/** A person of this test's own, signed in, on Settings → Encryption with the keyring open. */
async function person(page: Page): Promise<string> {
    const email = `wkd-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email} --own-address`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    // The keyring is made by this first visit; the directory card is drawn by
    // the server, so it shows the key once the page is loaded again.
    await expect.poll(async () => (await (await page.request.get("/keyring")).json()).addressKeys.length).toBe(1);
    await page.reload();

    return email;
}
