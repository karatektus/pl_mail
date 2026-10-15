import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, mailRow, seedUser } from "./support/config";

/**
 * Reading OpenPGP mail, phase 3 of issue #28: the server keeps no body for an
 * encrypted message, and the browser decrypts the raw source with the keys it
 * holds and shows the result in the same sandboxed frame as any mail.
 *
 * The mail is encrypted here, in the page, to the address key the test user's
 * keyring just made — the page's own openpgp, so the test needs nothing the
 * product does not ship — and delivered as raw source by
 * app:test:seed-crypto-mail. A person of its own per test, as in
 * encryption.spec.ts.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-reading";
const ADDRESS = "mailbox@e2e.test";

test.describe("reading encrypted mail", () => {
    test("decrypts a PGP/MIME message into the reading frame, with its attachment and without its remote images", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed plan ${Date.now()}`;
        const remote: string[] = [];

        page.on("request", (request) => {
            if ("tracker.example" === new URL(request.url()).hostname) {
                remote.push(request.url());
            }
        });

        const entity = [
            "Content-Type: multipart/mixed; boundary=\"inner\"",
            "",
            "--inner",
            "Content-Type: text/html; charset=utf-8",
            "",
            "<p>The <b>secret</b> plan.</p><img src=\"https://tracker.example/pixel.gif\" width=\"1\" height=\"1\">",
            "--inner",
            "Content-Type: text/plain; name=\"notes.txt\"",
            "Content-Disposition: attachment; filename=\"notes.txt\"",
            "",
            "Bring snacks.",
            "--inner--",
            "",
        ].join("\r\n");

        const armored = await encryptToOwnKey(page, entity);
        const raw = [
            "From: E2E Sender <sender@e2e.test>",
            `To: ${ADDRESS}`,
            `Subject: ${subject}`,
            "MIME-Version: 1.0",
            "Content-Type: multipart/encrypted; protocol=\"application/pgp-encrypted\"; boundary=\"outer\"",
            "",
            "--outer",
            "Content-Type: application/pgp-encrypted",
            "",
            "Version: 1",
            "--outer",
            "Content-Type: application/octet-stream; name=\"encrypted.asc\"",
            "",
            armored.trim(),
            "--outer--",
            "",
        ].join("\r\n");

        deliver(email, subject, raw);

        await page.goto("/mail/inbox");

        const row = mailRow(page, subject);

        await expect(row).toContainText("Encrypted message");
        await row.click();

        await expect(page.getByTestId("crypto-banner")).toContainText("End-to-end encrypted");

        const frame = page.frameLocator("iframe[data-mail--encrypted-message-target=frame]");

        await expect(frame.getByText("plan.")).toBeVisible({ timeout: 15000 });
        await expect(frame.locator("b")).toHaveText("secret");
        await expect(frame.locator("img[data-plmail-blocked]")).toHaveCount(1);
        await expect(page.getByRole("link", { name: "notes.txt" })).toBeVisible();

        expect(remote, "a remote image in decrypted mail was fetched").toEqual([]);
    });

    test("decrypts an inline PGP message into plain text", async ({ page }) => {
        const email = await person(page);
        const subject = `Inline seal ${Date.now()}`;
        const armored = await encryptToOwnKey(page, "Meet at the old mill.");

        deliver(email, subject, [
            "From: E2E Sender <sender@e2e.test>",
            `To: ${ADDRESS}`,
            `Subject: ${subject}`,
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=utf-8",
            "",
            armored.trim(),
            "",
        ].join("\r\n"));

        await page.goto("/mail/inbox");
        await mailRow(page, subject).click();

        await expect(page.locator("pre[data-mail--encrypted-message-target=text]")).toHaveText("Meet at the old mill.", { timeout: 15000 });
    });
});

/** A signed-in person with a keyring and the seeded mailbox address. */
async function person(page: Page): Promise<string> {
    const email = `reader-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
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

function deliver(email: string, subject: string, raw: string): void {
    const base64 = Buffer.from(raw, "utf8").toString("base64");

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${base64}`);
}
