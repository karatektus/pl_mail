import type { Locator, Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, mailRow, seedUser } from "./support/config";

/**
 * An encrypted message's row: a lock in front of the subject, and no
 * placeholder where the preview goes (asked for while testing the
 * milestone-v0.4.0 build). The row used to append *Encrypted message* as its
 * snippet; the lock says the same in the place a reader looks first, in the
 * same icon set as the rest of the row, and with the words for a screen
 * reader.
 *
 * Every list draws its rows from one partial, so the inbox, a search result
 * and the phone width stand for the rest here; Drafts is
 * encryption-sending.spec.ts, and a message whose decrypted copy is kept on
 * the server — its real preview, still with the lock — is
 * encryption-decrypted.spec.ts.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-locks";
const OWN = "mailbox@e2e.test";

test.describe("an encrypted message in the list", () => {
    test("wears a lock in front of its subject, and no placeholder preview", async ({ page }) => {
        const email = await person(page);
        const subject = `Locked away ${Date.now()}`;

        await seedEncrypted(page, email, subject);
        await page.goto("/mail/inbox");

        const row = mailRow(page, subject);

        await expectLocked(row, subject);

        // In the search results, the same row.
        await page.goto(`/mail/search?q=${encodeURIComponent(subject)}`);
        await expectLocked(mailRow(page, subject), subject);

        // And on a phone.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto("/mail/inbox");
        await expectLocked(mailRow(page, subject), subject);
    });

    test("moves nothing else in the row", async ({ page }) => {
        const email = await person(page);
        const subject = `Locked in line ${Date.now()}`;

        await seedEncrypted(page, email, subject);

        // The widths of phase 6g's alignment fix: 1280 is the two-line row,
        // 1700 and 2200 the one-line row. The lock sits on the subject's line
        // and the subject on the sender's, as they did before it was there.
        for (const width of [1280, 1700, 2200]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto("/mail/inbox");

            const middle = await mailRow(page, subject).evaluate((element) => {
                const centre = (selector: string): number => {
                    const box = element.querySelector(selector)!.getBoundingClientRect();

                    return box.top + box.height / 2;
                };

                return { sender: centre("[data-row-sender] .truncate"), subject: centre("[data-row-subject]"), lock: centre("[data-row-encrypted]") };
            });

            expect(Math.abs(middle.subject - middle.sender), `the subject against the sender at ${width}px`).toBeLessThanOrEqual(1);
            expect(Math.abs(middle.lock - middle.subject), `the lock against the subject at ${width}px`).toBeLessThanOrEqual(2);
        }
    });
});

/** The lock, before the subject, named for a screen reader; no placeholder. */
async function expectLocked(row: Locator, subject: string): Promise<void> {
    const lock = row.locator("[data-row-subject] [data-row-encrypted]");

    await expect(lock).toBeVisible();
    await expect(lock.locator("i.fa-solid.fa-lock")).toHaveAttribute("aria-hidden", "true");
    await expect(lock).toContainText("End-to-end encrypted");
    await expect(row).not.toContainText("Encrypted message");
    await expect(row.locator("[data-row-snippet]")).toHaveText("");

    // Nothing but whitespace ahead of it in the subject's span: the lock is
    // the first thing on the subject's line.
    const ahead = await row.locator("[data-row-subject]").evaluate((element) => {
        const lockElement = element.querySelector("[data-row-encrypted]");
        let text = "";

        for (const child of Array.from(element.childNodes)) {
            if (child === lockElement) {
                break;
            }

            text += child.textContent ?? "";
        }

        return text.trim();
    });

    expect(ahead, `the lock comes before "${subject}"`).toBe("");
}

/** A signed-in person with a keyring and the seeded mailbox address. */
async function person(page: Page): Promise<string> {
    const email = `locked-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}

async function seedEncrypted(page: Page, email: string, subject: string): Promise<void> {
    const armored = await page.evaluate(async () => {
        const openpgp = await import("openpgp");
        const keyring = await (await fetch("/keyring")).json();
        const key = await openpgp.readKey({ armoredKey: keyring.addressKeys[0].publicKey });

        return openpgp.encrypt({ message: await openpgp.createMessage({ text: "Nothing to see in the list." }), encryptionKeys: key, format: "armored" });
    });

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --subject="${subject}" --raw-base64=${Buffer.from([
        "From: E2E Sender <sender@e2e.test>",
        `To: ${OWN}`,
        `Subject: ${subject}`,
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=utf-8",
        "",
        armored.trim(),
        "",
    ].join("\r\n"), "utf8").toString("base64")}`);
}
