import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, consoleOutput, login, mailRow, seedUser } from "./support/config";

/**
 * Writing encrypted mail, phase 5 of issue #28: the draft is sealed in the
 * browser before it is saved, and the message is built and encrypted here
 * before it is handed to the server to send.
 *
 * The test environment sends nothing — no worker takes the send queue — so
 * what is checked is what the browser hands over, which is the whole of what
 * is new: the server's copy of the draft (app:test:dump-sealed-message), and
 * the message built for sending, which is then delivered back into the inbox
 * (app:test:seed-crypto-mail) and opened by the reading pane like any
 * encrypted mail. Composer to reader, with no key but the person's own.
 *
 * Mail to oneself is encrypted to one's own key, which every person here has;
 * that is what makes a recipient with a key without a second person.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-writing";
const OWN = "mailbox@e2e.test";
const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

test.describe("writing encrypted mail", () => {
    test("seals the draft, and builds a message only the recipients can read", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed letter ${Date.now()}`;

        await compose(page, [OWN], subject, "The plan is in the attic.");
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeVisible();

        // The server keeps ciphertext and nothing else.
        await expect.poll(() => held(email, subject)?.sealedDraft ?? false, { timeout: 15000 }).toBe(true);

        const draft = held(email, subject)!;

        expect(draft.bodyText).toBeNull();
        expect(draft.bodyHtml).toBeNull();
        expect(draft.draft).toContain("-----BEGIN PGP MESSAGE-----");
        expect(draft.draft).not.toContain("attic");

        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const sent = held(email, subject)!;

        expect(sent.seal?.kind).toBe("encrypted");
        expect(sent.seal?.recipients).toEqual([OWN]);
        expect(sent.entity).toContain("multipart/encrypted");
        expect(sent.entity).not.toContain("attic");

        // Back in through the front door: the reading pane opens it.
        const echo = `${subject} (as received)`;

        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${echo}" --raw-base64=${Buffer.from(`From: ${OWN}\r\nTo: ${OWN}\r\nSubject: ${echo}\r\nMIME-Version: 1.0\r\n${sent.entity}`, "utf8").toString("base64")}`);

        await page.goto("/mail/inbox");
        await mailRow(page, echo).click();

        const frame = page.frameLocator("iframe[data-mail--encrypted-message-target=frame]");
        const text = page.locator("pre[data-mail--encrypted-message-target=text]");

        await expect(frame.getByText("The plan is in the attic.").or(text)).toBeVisible({ timeout: 15000 });
        await expect(page.getByTestId("crypto-banner")).toContainText("End-to-end encrypted");
    });

    test("seals an attachment in the browser, and delivers it inside the encrypted message", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed attachment ${Date.now()}`;

        await compose(page, [OWN], subject, "The plan is attached.");
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeVisible();

        await page.locator(`${DOCK} input[type=file][data-compose--compose-target=fileInput]`).setInputFiles({
            name: "plan.txt",
            mimeType: "text/plain",
            buffer: Buffer.from("Under the third floorboard."),
        });

        await expect(page.locator(`${DOCK} [data-testid=sealed-attachment]`)).toHaveText("plan.txt", { timeout: 15000 });
        expect(held(email, subject)?.parts).toEqual([{ name: "plan.txt", sealed: true }]);

        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const echo = `${subject} (as received)`;

        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${echo}" --raw-base64=${Buffer.from(`From: ${OWN}\r\nTo: ${OWN}\r\nSubject: ${echo}\r\nMIME-Version: 1.0\r\n${held(email, subject)!.entity}`, "utf8").toString("base64")}`);

        await page.goto("/mail/inbox");
        await mailRow(page, echo).click();

        await expect(page.getByRole("link", { name: "plan.txt" })).toBeVisible({ timeout: 15000 });
    });

    test("signs mail that goes out unencrypted to somebody whose key plMail knows", async ({ page }) => {
        const email = await person(page);
        const subject = `Signed, not sealed ${Date.now()}`;

        await compose(page, [OWN, "nobody-with-a-key@example.test"], subject, "Readable, and provably mine.");
        await expect(page.getByTestId("compose-crypto-hint")).toBeVisible({ timeout: 15000 });

        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const sent = held(email, subject)!;

        expect(sent.seal?.kind).toBe("signed");
        expect(sent.entity).toContain("multipart/signed");
        expect(sent.entity).toContain("application/pgp-signature");
        expect(sent.bodyText).toContain("Readable, and provably mine.");
    });

    test("keeps the subject inside the encryption, and shows it again where the key is", async ({ page }) => {
        const email = await person(page);
        const subject = `Pumpkin plans ${Date.now()}`;

        await compose(page, [OWN], subject, "The pumpkins arrive on Friday.");
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeVisible();
        await page.locator(`${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
        await expect.poll(() => held(email, subject)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

        const sent = held(email, subject)!;

        expect(sent.seal?.protectedSubject).toBe(true);
        expect(sent.entity).not.toContain("Pumpkin");

        // Delivered the way it leaves: "..." outside, the subject within.
        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="..." --raw-base64=${Buffer.from(`From: ${OWN}\r\nTo: ${OWN}\r\nSubject: ...\r\nMIME-Version: 1.0\r\n${sent.entity}`, "utf8").toString("base64")}`);

        await page.goto("/mail/inbox");
        await mailRow(page, "...").first().click();

        await expect(page.getByTestId("crypto-subject")).toContainText(subject, { timeout: 15000 });
        await expect(page.locator("[data-thread-subject]")).toHaveText(subject);

        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await expect(page.locator(`#compose_inline [data-compose--compose-target="subject"]`)).toHaveValue(`Re: ${subject}`, { timeout: 15000 });
    });

    test("opens a sealed draft again in the editor", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed draft ${Date.now()}`;

        await compose(page, [OWN], subject, "Second paragraph, still secret.");
        await expect.poll(() => held(email, subject)?.sealedDraft ?? false, { timeout: 15000 }).toBe(true);

        await page.goto("/mail/drafts");
        await mailRow(page, subject).click();

        await expect(page.locator(`${DOCK} ${EDITOR}, #compose_inline ${EDITOR}`).first()).toContainText("Second paragraph, still secret.", { timeout: 15000 });
    });

    test("lines an encrypted draft's row up like any other row", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed row ${Date.now()}`;

        await compose(page, [OWN], subject, "Nothing to see in the list.");
        await expect.poll(() => held(email, subject)?.sealedDraft ?? false, { timeout: 15000 }).toBe(true);

        // Reported at desktop width (#28, comment 6216252504): the subject and
        // the "Encrypted message" snippet sat two pixels above the recipient.
        // The row now says it with a lock in front of the subject and has no
        // placeholder snippet, and the subject still has to sit on the
        // recipient's line. 1280 is the two-line row, where the subject shares
        // the recipient's line; 1700 and 2200 are the one-line row.
        for (const width of [1280, 1700, 2200]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto("/mail/drafts");

            const row = mailRow(page, subject);

            await expect(row.locator("[data-row-subject] [data-row-encrypted]")).toBeVisible();
            await expect(row.locator("[data-row-snippet]")).toHaveText("");

            const middle = await row.evaluate((element) => {
                const centre = (selector: string): number => {
                    const box = element.querySelector(selector)!.getBoundingClientRect();

                    return box.top + box.height / 2;
                };

                return { sender: centre("[data-row-sender] .truncate"), subject: centre("[data-row-subject]") };
            });

            expect(Math.abs(middle.subject - middle.sender), `the subject against the recipient at ${width}px`).toBeLessThanOrEqual(1);
        }
    });

    test("says so when not every recipient can be reached encrypted", async ({ page }) => {
        await person(page);

        await compose(page, [OWN, "nobody-with-a-key@example.test"], `Mixed company ${Date.now()}`, "Hello, both of you.");

        await expect(page.getByTestId("compose-crypto-hint")).toContainText("nobody-with-a-key@example.test", { timeout: 15000 });
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeHidden();
    });

    test("quotes an encrypted message decrypted, in a reply that is encrypted too", async ({ page }) => {
        const email = await person(page);
        const subject = `Sealed question ${Date.now()}`;
        const armored = await page.evaluate(async (plaintext) => {
            const openpgp = await import("openpgp");
            const keyring = await (await fetch("/keyring")).json();
            const key = await openpgp.readKey({ armoredKey: keyring.addressKeys[0].publicKey });

            return openpgp.encrypt({ message: await openpgp.createMessage({ text: plaintext }), encryptionKeys: key, format: "armored" });
        }, "Where is the plan?");

        consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${Buffer.from([
            `From: E2E Sender <sender@e2e.test>`,
            `To: ${OWN}`,
            `Subject: ${subject}`,
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=utf-8",
            "",
            armored.trim(),
            "",
        ].join("\r\n"), "utf8").toString("base64")}`);

        await page.goto("/mail/inbox");
        await mailRow(page, subject).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const editor = page.locator(`#compose_inline ${EDITOR}`);

        await expect(editor).toContainText("Where is the plan?", { timeout: 15000 });
        await expect(page.locator("#compose_inline [data-compose--encryption-target=encryptButton]")).toHaveAttribute("aria-pressed", "true");
    });
});

/** A signed-in person with a keyring and the seeded mailbox address. */
async function person(page: Page): Promise<string> {
    const email = `writer-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
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
    bodyHtml: string | null;
    sealedDraft: boolean;
    seal: { kind: string; recipients: string[]; fingerprints: string[]; protectedSubject?: boolean } | null;
    draft: string | null;
    entity: string | null;
    parts: Array<{ name: string; sealed: boolean }>;
};

function held(email: string, subject: string): Held | null {
    return JSON.parse(consoleOutput(`app:test:dump-sealed-message --email=${email} --subject="${subject}"`).trim());
}
