import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, mailRow, seedUser } from "./support/config";

/**
 * Contacts' keys, phase 4 of issue #28: the keys plMail holds for other
 * people, trust on first use, and what the reading banner offers for a
 * signature it cannot vouch for yet.
 *
 * The keys are made in the page with its own openpgp — the test needs
 * nothing the product does not ship — and signed mail is delivered as raw
 * source by app:test:seed-crypto-mail, which also does what the enrich
 * workers would (collect the Autocrypt key, check the signature), since the
 * test stack runs none. WKD needs the internet and is covered in PHPUnit
 * (WkdLookupTest) instead.
 *
 * A person of its own per test, as in encryption-reading.spec.ts.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-contacts";
const SENDER = "sender@e2e.test";

test.describe("contacts' keys", () => {
    test("imports a key, pins it, and warns when a different key turns up", async ({ page }) => {
        await person(page);

        const first = await newKey(page, "carol@contacts.e2e");
        const second = await newKey(page, "carol@contacts.e2e");
        const frame = page.locator("turbo-frame#contact-keys");

        await importKey(page, first.publicKey);
        await expect(frame.getByTestId("contact-keys-notice")).toContainText("Imported");
        await expect(frame.getByTestId("contact-key-fingerprint")).toHaveText(grouped(first.fingerprint));

        await frame.getByRole("button", { name: "Mark as verified" }).click();
        await expect(frame.getByText("Verified", { exact: true })).toBeVisible();

        // TOFU: the second key is recorded and warned about, never swapped in.
        await importKey(page, second.publicKey);
        await expect(frame.getByTestId("contact-keys-notice")).toContainText("already had a different key");
        await expect(frame.getByTestId("contact-key-changed")).toContainText("A different key turned up");
        await expect(frame.getByTestId("contact-key")).toHaveCount(2);

        await frame.getByRole("button", { name: "Use this key instead" }).click();
        await expect(frame.getByTestId("contact-key-changed")).toHaveCount(0);
        await expect(frame.getByTestId("contact-key-fingerprint")).toHaveText(grouped(second.fingerprint));
    });

    test("a signed message's banner turns valid once its key is trusted", async ({ page }) => {
        const email = await person(page);
        const key = await newKey(page, SENDER);
        const subject = `Signed hello ${Date.now()}`;

        deliver(email, subject, await signedRaw(page, key, subject, "Hello from the sender."));

        await page.goto("/mail/inbox");
        await mailRow(page, subject).click();

        const banner = page.getByTestId("crypto-banner");

        await expect(banner).toContainText("nothing confirms who signed it");
        await expect(banner).toContainText(grouped(key.fingerprint));

        await banner.getByRole("button", { name: "Trust this key" }).click();

        await expect(banner).toContainText("Signed, and the signature checks out");
        await expect(banner).toContainText("Marked as verified.");
    });

    test("warns in the banner when a known sender signs with another key", async ({ page }) => {
        const email = await person(page);
        const known = await newKey(page, SENDER);
        const other = await newKey(page, SENDER);
        const first = `Known key ${Date.now()}`;
        const second = `Other key ${Date.now()}`;

        deliver(email, first, await signedRaw(page, known, first, "The first one."));
        deliver(email, second, await signedRaw(page, other, second, "The second one."));

        await page.goto("/mail/inbox");
        await mailRow(page, second).click();

        const banner = page.getByTestId("crypto-banner");

        await expect(banner).toContainText("Signed with a different key than the one plMail knows for this sender");
        await expect(banner.getByRole("button", { name: "Use the new key" })).toBeVisible();
    });
});

/** A signed-in person with a keyring and the seeded mailbox, on Settings → Encryption. */
async function person(page: Page): Promise<string> {
    const email = `contacts-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}

type Key = { publicKey: string; privateKey: string; fingerprint: string; binary: string };

/** A contact's key, made with the page's own openpgp. */
async function newKey(page: Page, address: string): Promise<Key> {
    return page.evaluate(async (email) => {
        const openpgp = await import("openpgp");
        const { privateKey, publicKey } = await openpgp.generateKey({ userIDs: [{ email }], format: "armored" });
        const key = await openpgp.readKey({ armoredKey: publicKey });
        const bytes = key.write();
        let binary = "";

        bytes.forEach((byte) => (binary += String.fromCharCode(byte)));

        return { privateKey, publicKey, fingerprint: key.getFingerprint(), binary: btoa(binary) };
    }, address);
}

/**
 * A PGP/MIME signed message from SENDER (RFC 3156), with the key in an
 * Autocrypt header the way a mail program that speaks Autocrypt sends it.
 * Signed over the exact bytes of the signed part, CRLF line ends included.
 */
async function signedRaw(page: Page, key: Key, subject: string, body: string): Promise<string> {
    const part = `Content-Type: text/plain; charset=utf-8\r\n\r\n${body}`;
    const signature = await page.evaluate(async ({ armoredKey, signed }) => {
        const openpgp = await import("openpgp");

        return openpgp.sign({
            message: await openpgp.createMessage({ binary: new TextEncoder().encode(signed) }),
            signingKeys: await openpgp.readPrivateKey({ armoredKey }),
            detached: true,
            format: "armored",
        });
    }, { armoredKey: key.privateKey, signed: part });

    return [
        `From: E2E Sender <${SENDER}>`,
        "To: mailbox@e2e.test",
        `Subject: ${subject}`,
        "MIME-Version: 1.0",
        `Autocrypt: addr=${SENDER}; keydata=${key.binary}`,
        "Content-Type: multipart/signed; micalg=pgp-sha512; protocol=\"application/pgp-signature\"; boundary=\"S\"",
        "",
        "--S",
        part,
        "--S",
        "Content-Type: application/pgp-signature; name=\"signature.asc\"",
        "",
        signature.trim().replace(/\r?\n/g, "\r\n"),
        "--S--",
        "",
    ].join("\r\n");
}

async function importKey(page: Page, armored: string): Promise<void> {
    const frame = page.locator("turbo-frame#contact-keys");

    await frame.getByLabel("Public key", { exact: true }).fill(armored);
    await frame.getByRole("button", { name: "Add key", exact: true }).click();
}

function deliver(email: string, subject: string, raw: string): void {
    const base64 = Buffer.from(raw, "utf8").toString("base64");

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${base64}`);
}

function grouped(fingerprint: string): string {
    return fingerprint.toUpperCase().match(/.{1,4}/g)?.join(" ") ?? "";
}
