import type { Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test, expect } from "./support/test";
import { acceptConfirm } from "./support/confirm";
import { consoleCommand, consoleOutput, login, mailRow, seedUser } from "./support/config";

/**
 * S/MIME, phase 6c of issue #28: a certificate of one's own from a PKCS#12
 * file, a contact's certificate from their signed mail, and mail encrypted
 * and signed with them in the browser.
 *
 * Every certificate is made here, by the openssl binary, the way a
 * certificate authority would make them: a CA, a certificate for the
 * person's own address in a .p12, one for the contact (sender@e2e.test, the
 * sender app:test:seed-crypto-mail delivers as). The contact's comes in on a
 * message they signed, which is the only way plMail picks certificates up.
 *
 * As in encryption-sending.spec.ts, nothing is really sent: what the browser
 * built is read back (app:test:dump-sealed-message), delivered into the inbox
 * (app:test:seed-crypto-mail) and opened by the reading pane — and checked
 * by OpenSSL too, which stands for every other mail program.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-smime";
const P12_PASSWORD = "the-p12-password";
const OWN = "mailbox@e2e.test";
const CONTACT = "sender@e2e.test";
const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

type Material = { dir: string; p12: Buffer; legacyP12: Buffer; contactSigned: string };

let material: Material;

test.beforeAll(() => {
    material = makeCertificates();
});

test.afterAll(() => {
    if (undefined !== material) {
        rmSync(material.dir, { recursive: true, force: true });
    }
});

test.describe("S/MIME", () => {
    test("imports a certificate, encrypts to a contact and oneself, and reads it back", async ({ page }) => {
        const email = await person(page);

        await importP12(page);

        // The contact's certificate arrives on their signed mail.
        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="Signed by the contact" --raw-base64=${Buffer.from(material.contactSigned, "utf8").toString("base64")}`);

        const subject = `S/MIME letter ${Date.now()}`;

        await compose(page, [OWN, CONTACT], subject, "The plan is in the cellar.");
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeVisible({ timeout: 15000 });
        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const sent = held(email, subject)!;

        expect(sent.seal?.kind).toBe("encrypted");
        expect(sent.seal?.scheme).toBe("smime");
        expect(sent.seal?.fingerprints).toHaveLength(2);
        expect(sent.entity).toContain("application/pkcs7-mime; smime-type=enveloped-data");
        expect(sent.entity).not.toContain("cellar");

        // The contact's mail program opens it: OpenSSL with the contact's key,
        // and the signature inside checks out against the CA.
        const raw = `From: ${OWN}\r\nTo: ${CONTACT}\r\nSubject: ...\r\nMIME-Version: 1.0\r\n${sent.entity}`;
        const opened = openssl(["smime", "-decrypt", "-recip", "contact.pem", "-inkey", "contact.key"], raw);

        // Every part is base64 (crypto/mime.js).
        expect(openssl(["smime", "-verify", "-CAfile", "ca.pem", "-purpose", "any"], opened)).toContain(Buffer.from("The plan is in the cellar.").toString("base64"));

        // And plMail opens it, from the person's own certificate.
        const echo = `${subject} (as received)`;

        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${echo}" --raw-base64=${Buffer.from(`From: ${OWN}\r\nTo: ${OWN}\r\nSubject: ${echo}\r\nMIME-Version: 1.0\r\n${sent.entity}`, "utf8").toString("base64")}`);

        await page.goto("/mail/inbox");
        await mailRow(page, echo).click();

        const frame = page.frameLocator("iframe[data-mail--encrypted-message-target=frame]");
        const text = page.locator("pre[data-mail--encrypted-message-target=text]");

        await expect(frame.getByText("The plan is in the cellar.").or(text.filter({ hasText: "The plan is in the cellar." }))).toBeVisible({ timeout: 15000 });
        await expect(page.getByTestId("crypto-banner")).toContainText("Encrypted with S/MIME");
        await expect(page.getByTestId("crypto-banner")).toContainText("Signed inside the encryption, with your own key");
        await expect(page.getByTestId("crypto-subject")).toContainText(subject);
    });

    test("signs with S/MIME when nobody else on the message has an OpenPGP key", async ({ page }) => {
        const email = await person(page);

        await importP12(page);

        const subject = `S/MIME signed ${Date.now()}`;

        await compose(page, [OWN, "nobody-with-a-key@example.test"], subject, "Readable, and signed the S/MIME way.");
        await expect(page.getByTestId("compose-crypto-hint")).toBeVisible({ timeout: 15000 });
        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const sent = held(email, subject)!;

        expect(sent.seal?.kind).toBe("signed");
        expect(sent.seal?.scheme).toBe("smime");
        expect(sent.entity).toContain('protocol="application/pkcs7-signature"; micalg=sha-256');
        expect(sent.bodyText).toContain("Readable, and signed the S/MIME way.");

        // Byte for byte what a recipient checks: OpenSSL agrees with the signature.
        const raw = `From: ${OWN}\r\nTo: nobody-with-a-key@example.test\r\nSubject: ${subject}\r\nMIME-Version: 1.0\r\n${sent.entity}`;

        expect(openssl(["smime", "-verify", "-CAfile", "ca.pem", "-purpose", "any"], raw)).toBeTruthy();
    });

    test("refuses a .p12 with the wrong password, and lists, exports and removes one", async ({ page }) => {
        await person(page);

        const legacyCode = fetchesOf(page, /pkcs12_legacy/);

        await page.locator("[data-testid=smime-file]").setInputFiles({ name: "mine.p12", mimeType: "application/x-pkcs12", buffer: material.p12 });
        await page.locator("#encryption-smime-password").fill("not the password");
        await page.locator("form:has([data-testid=smime-file]) button[type=submit]").click();
        await expect(page.getByTestId("smime-result")).toContainText("That password does not open the file.", { timeout: 15000 });

        await importP12(page);
        await expect(page.getByTestId("smime-certificate")).toHaveCount(1);
        await expect(page.getByTestId("smime-certificate")).toContainText(OWN);

        // An AES file never fetches the code for the old ciphers.
        expect(legacyCode).toEqual([]);

        await page.locator("#encryption-smime-export-password").fill("an-export-password");

        const download = page.waitForEvent("download");

        await page.getByTestId("smime-certificate").getByRole("button", { name: "Export as .p12" }).click();

        const file = join(material.dir, "exported.p12");

        await (await download).saveAs(file);
        expect(execFileSync("openssl", ["pkcs12", "-in", file, "-passin", "pass:an-export-password", "-nodes"], { cwd: material.dir, encoding: "utf8" })).toContain("BEGIN PRIVATE KEY");

        await page.getByTestId("smime-certificate").getByRole("button", { name: "Remove" }).click();
        await expect(page.locator("#confirm-dialog")).toBeVisible();
        await acceptConfirm(page);
        await expect(page.getByTestId("smime-certificate")).toHaveCount(0, { timeout: 15000 });
    });

    test("imports a .p12 locked the old way, with 3DES and RC2 (#372)", async ({ page }) => {
        await person(page);

        const legacyCode = fetchesOf(page, /pkcs12_legacy/);

        await importP12(page, material.legacyP12);
        await expect(page.getByTestId("smime-certificate")).toContainText(OWN);
        expect(legacyCode).toHaveLength(1);

        // The key that came out is the person's: exported again, with AES now,
        // it is the key OpenSSL made.
        await page.locator("#encryption-smime-export-password").fill("an-export-password");

        const download = page.waitForEvent("download");

        await page.getByTestId("smime-certificate").getByRole("button", { name: "Export as .p12" }).click();

        const file = join(material.dir, "exported-legacy.p12");

        await (await download).saveAs(file);

        const exported = execFileSync("openssl", ["pkcs12", "-in", file, "-passin", "pass:an-export-password", "-nocerts", "-nodes"], { cwd: material.dir, encoding: "utf8" });

        expect(openssl(["pkey", "-pubout"], exported)).toBe(execFileSync("openssl", ["pkey", "-in", "own.key", "-pubout"], { cwd: material.dir, encoding: "utf8" }));
    });
});

// ── Fixtures ────────────────────────────────────────────────────────────────

/** A signed-in person with a keyring and the seeded mailbox address. */
async function person(page: Page): Promise<string> {
    const email = `smime-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}

async function importP12(page: Page, p12: Buffer = material.p12): Promise<void> {
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
    await page.locator("[data-testid=smime-file]").setInputFiles({ name: "mine.p12", mimeType: "application/x-pkcs12", buffer: p12 });
    await page.locator("#encryption-smime-password").fill(P12_PASSWORD);
    await page.locator("form:has([data-testid=smime-file]) button[type=submit]").click();
    await expect(page.getByTestId("smime-result")).toContainText(`Imported. It now signs and decrypts mail for ${OWN}.`, { timeout: 15000 });
}

async function compose(page: Page, recipients: string[], subject: string, body: string): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

    const to = page.locator(`${DOCK} .ts-control input`).first();

    for (const recipient of recipients) {
        await to.fill(recipient);
        await to.press("Enter");
    }

    await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill(subject);
    await page.locator(`${DOCK} ${EDITOR}`).fill(body);
}

type Held = {
    bodyText: string | null;
    seal: { kind: string; scheme?: string; recipients: string[]; fingerprints: string[] } | null;
    entity: string | null;
};

function held(email: string, subject: string): Held | null {
    return JSON.parse(consoleOutput(`app:test:dump-sealed-message --email=${email} --subject="${subject}"`).trim());
}

/** The URLs matching $pattern the page fetches from now on, filled in as it goes. */
function fetchesOf(page: Page, pattern: RegExp): string[] {
    const urls: string[] = [];

    page.on("request", (request) => {
        if (pattern.test(request.url())) {
            urls.push(request.url());
        }
    });

    return urls;
}

/** Runs openssl in the material's directory with $input on stdin. */
function openssl(args: string[], input: string): string {
    return execFileSync("openssl", args, { cwd: material.dir, input, encoding: "utf8", stdio: ["pipe", "pipe", "pipe"] });
}

/**
 * A CA, the person's certificate as a .p12, and the contact's certificate
 * with a message they signed.
 */
function makeCertificates(): Material {
    const dir = mkdtempSync(join(tmpdir(), "plmail-smime-"));
    const run = (...args: string[]) => execFileSync("openssl", args, { cwd: dir, stdio: ["ignore", "pipe", "pipe"] });
    const extensions = (address: string) => {
        writeFileSync(join(dir, `${address}.cnf`), [
            "[ext]",
            "basicConstraints = CA:FALSE",
            "keyUsage = critical,digitalSignature,keyEncipherment",
            "extendedKeyUsage = emailProtection",
            `subjectAltName = email:${address}`,
            "",
        ].join("\n"));

        return `${address}.cnf`;
    };
    const issue = (name: string, address: string) => {
        run("req", "-newkey", "rsa:2048", "-nodes", "-keyout", `${name}.key`, "-out", `${name}.csr`, "-subj", `/CN=${name}/emailAddress=${address}`);
        run("x509", "-req", "-in", `${name}.csr`, "-CA", "ca.pem", "-CAkey", "ca.key", "-CAcreateserial", "-out", `${name}.pem`, "-days", "30", "-extfile", extensions(address), "-extensions", "ext");
    };

    run("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", "ca.key", "-out", "ca.pem", "-days", "30", "-subj", "/CN=plMail E2E CA");
    issue("own", OWN);
    issue("contact", CONTACT);
    run("pkcs12", "-export", "-in", "own.pem", "-inkey", "own.key", "-certfile", "ca.pem", "-out", "own.p12", "-passout", `pass:${P12_PASSWORD}`);
    // The same, the way OpenSSL 1.x and Windows' export lock it: the key with
    // 3DES, the certificates with RC2-40, a SHA-1 MAC.
    run("pkcs12", "-export", "-legacy", "-in", "own.pem", "-inkey", "own.key", "-certfile", "ca.pem", "-out", "own-legacy.p12", "-passout", `pass:${P12_PASSWORD}`);

    writeFileSync(join(dir, "contact-body.txt"), "Content-Type: text/plain; charset=utf-8\r\n\r\nHello from the contact.\r\n");
    run("smime", "-sign", "-in", "contact-body.txt", "-signer", "contact.pem", "-inkey", "contact.key", "-from", CONTACT, "-to", OWN, "-subject", "Signed by the contact", "-out", "contact-signed.eml");

    return {
        dir,
        p12: readFileSync(join(dir, "own.p12")),
        legacyP12: readFileSync(join(dir, "own-legacy.p12")),
        contactSigned: readFileSync(join(dir, "contact-signed.eml"), "utf8").replace(/\r?\n/g, "\r\n"),
    };
}
