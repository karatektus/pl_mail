import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, mailRow, seedUser } from "./support/config";
import { saveSetting } from "./support/settings";

/**
 * Decrypted mail kept on the server, phase 3b of issue #28: an account that
 * chose it has its encrypted mail decrypted in the background by any open
 * browser that holds the key, and the server then treats the text like any
 * other mail's — until the switch is turned off again, which takes it back out.
 *
 * The background decrypter is the page's own (crypto--decrypted-mail), and
 * the clean-up runs on the bulk queue the test stack consumes.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-keeping";
const ADDRESS = "mailbox@e2e.test";

test("keeps decrypted mail readable on the server, and takes it back out when switched off", async ({ page }) => {
    const email = await person(page);
    const word = `pineapple${Date.now()}`;
    const subject = `Kept seal ${Date.now()}`;

    await page.goto("/settings?section=encryption");

    const row = page.getByTestId("decrypted-mail-account").first();

    await row.getByRole("checkbox").check();
    await saveSetting(page, () => row.getByRole("button", { name: "Keep decrypted mail here" }).click());
    await expect(page.getByTestId("decrypted-mail-account").first()).toContainText("kept readable here");

    const armored = await page.evaluate(async (plaintext) => {
        const openpgp = await import("openpgp");
        const keyring = await (await fetch("/keyring")).json();
        const key = await openpgp.readKey({ armoredKey: keyring.addressKeys[0].publicKey });

        return openpgp.encrypt({ message: await openpgp.createMessage({ text: plaintext }), encryptionKeys: key, format: "armored" });
    }, `The ${word} arrives on Tuesday.`);

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${Buffer.from([
        "From: E2E Sender <sender@e2e.test>",
        `To: ${ADDRESS}`,
        `Subject: ${subject}`,
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=utf-8",
        "",
        armored.trim(),
        "",
    ].join("\r\n"), "utf8").toString("base64")}`);

    // A fresh page: the decrypter starts with it, finds the message waiting,
    // decrypts it here and hands it over.
    await page.goto("/mail/inbox");
    await expect.poll(async () => {
        await page.reload();

        return mailRow(page, subject).innerText();
    }, { timeout: 30000, intervals: [2000] }).toContain(word);

    // Its real preview, and still the lock in front of the subject: it was
    // encrypted, whatever the server now keeps of it.
    await expect(mailRow(page, subject).locator("[data-row-snippet]")).toContainText(word);
    await expect(mailRow(page, subject).locator("[data-row-subject] [data-row-encrypted]")).toBeVisible();

    await mailRow(page, subject).click();
    await expect(page.getByTestId("crypto-banner")).toContainText("stored readable on this server");

    // Off: the decrypted copy goes, and the message reads as encrypted again.
    await page.goto("/settings?section=encryption");
    await saveSetting(page, () =>
        page.getByTestId("decrypted-mail-account").first().getByRole("button", { name: "Stop, and delete the decrypted copies" }).click(),
    );

    await expect.poll(async () => {
        await page.goto("/mail/inbox");

        return mailRow(page, subject).locator("[data-row-snippet]").innerText();
    }, { timeout: 30000, intervals: [2000] }).toBe("");

    await expect(mailRow(page, subject).locator("[data-row-subject] [data-row-encrypted]")).toBeVisible();
});

/** A signed-in person with a keyring and the seeded mailbox address. */
async function person(page: Page): Promise<string> {
    const email = `keeper-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}
