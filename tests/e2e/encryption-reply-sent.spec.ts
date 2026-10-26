import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, consoleOutput, login, mailRow, seedUser } from "./support/config";

/**
 * A reply to encrypted mail, from Send until it has gone (#28, reported from
 * the milestone-v0.4.0 build): the conversation did not show the sent reply
 * and did not open it, but said the keys were locked — in a browser where
 * they were open — and the sidebar's Drafts number stayed where it was.
 *
 * Every combination that renders the sent reply differently: the inline
 * reply card and the dock, each with both send-feedback settings (the
 * optimistic default puts the reply in the thread at once, "hold" when the
 * cancel window closes). The send itself is the real send job, run by
 * app:test:finish-send against a provider that writes the mail down instead
 * of delivering it — the suite has no worker on the send queue.
 *
 * The person signs in through the form, so the keyring is open in this
 * browser the whole time: nothing here may ask to unlock it.
 */
test.use({ storageState: { cookies: [], origins: [] } });

// Two console round trips, the cancel window and two decryptions.
test.setTimeout(120_000);

const PASSWORD = "a-password-for-replying";
const OWN = "mailbox@e2e.test";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const DRAFTS_BADGE = '[data-count-key="role:drafts"]';

for (const where of ["inline", "dock"] as const) {
    for (const feedback of ["optimistic", "hold"] as const) {
        test(`a reply to encrypted mail, sent from the ${where} composer (${feedback}), appears in the thread and opens`, async ({ page }) => {
            const email = await person(page);
            const subject = `Sealed question ${Date.now()} ${where} ${feedback}`;

            consoleCommand(`app:test:send-feedback ${feedback} --email=${email}`);
            await seedEncrypted(page, email, subject, "Where is the plan?");

            await page.goto("/mail/inbox");
            await expect(page.locator(DRAFTS_BADGE).first()).toHaveClass(/(^|\s)hidden(\s|$)/);

            await mailRow(page, subject).click();
            await page.getByRole("link", { name: "Reply", exact: true }).first().click();

            await expect(page.locator(`#compose_inline ${EDITOR}`)).toContainText("Where is the plan?", { timeout: 15000 });

            // The dock: the inline reply moved into the compose window, as
            // its "Open in compose window" button does.
            if ("dock" === where) {
                await page.locator("#compose_inline").getByRole("button", { name: "Open in compose window" }).click();
                await expect(page.locator(`#compose_dock ${EDITOR}`)).toContainText("Where is the plan?", { timeout: 15000 });
            }

            const composer = page.locator("dock" === where ? "#compose_dock" : "#compose_inline");
            const editor = composer.locator(EDITOR);

            await editor.press("Control+Home");
            await editor.pressSequentially("Under the floorboards. ");

            // Sealed and saved: the reply is a draft now, and the sidebar says so.
            await expect.poll(() => held(email, `Re: ${subject}`)?.sealedDraft ?? false, { timeout: 15000 }).toBe(true);
            await expect(page.locator(DRAFTS_BADGE).first()).toHaveText("1", { timeout: 15000 });

            await composer.locator('[data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]').click();
            await expect.poll(() => held(email, `Re: ${subject}`)?.entity ?? null, { timeout: 15000 }).not.toBeNull();

            // The composer goes: at once (optimistic), or when the cancel
            // window closes (hold). Either way the reply is in the thread.
            await expect(composer.locator(".compose-window")).toHaveCount(0, { timeout: 15000 });

            const thread = page.locator("[id^=thread_messages_]");
            const reply = thread.locator("[id^=thread_message_]").last();

            // Still being sent: it says so, and asks nothing.
            await expect(reply.getByTestId("sending-encrypted")).toBeVisible({ timeout: 15000 });
            await expect(reply).not.toContainText("Your keys are locked in this browser.");
            await expect(page.locator("#keyring-unlock-dialog")).not.toHaveAttribute("open", /.*/);

            // Past the cancel window, then the send job.
            await page.waitForTimeout(9000);

            const wire = consoleOutput(`app:test:finish-send --email=${email} --subject="Re: ${subject}"`);

            expect(wire).toContain("multipart/encrypted");
            expect(wire).not.toContain("floorboards");

            // Gone: the thread shows it as sent, and opens it with the
            // person's own key, as any encrypted message of their own.
            const frame = reply.frameLocator("iframe[data-mail--encrypted-message-target=frame]");
            const text = reply.locator("pre[data-mail--encrypted-message-target=text]");

            await expect(frame.getByText("Under the floorboards.").or(text.getByText("Under the floorboards."))).toBeVisible({ timeout: 15000 });
            await expect(reply).not.toContainText("Your keys are locked in this browser.");
            await expect(page.locator("#keyring-unlock-dialog")).not.toHaveAttribute("open", /.*/);

            // And the draft is no longer counted.
            await expect(page.locator(DRAFTS_BADGE).first()).toHaveClass(/(^|\s)hidden(\s|$)/, { timeout: 15000 });
        });
    }
}

/** A signed-in person with a keyring, unlocked by the sign-in form. */
async function person(page: Page): Promise<string> {
    const email = `replier-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email} --sendable`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    return email;
}

/** An encrypted message to the seeded mailbox address, from it. */
async function seedEncrypted(page: Page, email: string, subject: string, plaintext: string): Promise<void> {
    const armored = await page.evaluate(async (body) => {
        const openpgp = await import("openpgp");
        const keyring = await (await fetch("/keyring")).json();
        const key = await openpgp.readKey({ armoredKey: keyring.addressKeys[0].publicKey });

        return openpgp.encrypt({ message: await openpgp.createMessage({ text: body }), encryptionKeys: key, format: "armored" });
    }, plaintext);

    consoleCommand(`app:test:seed-crypto-mail --email=${email} --from=${OWN} --subject="${subject}" --raw-base64=${Buffer.from([
        `From: E2E Mailbox <${OWN}>`,
        `To: ${OWN}`,
        `Subject: ${subject}`,
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=utf-8",
        "",
        armored.trim(),
        "",
    ].join("\r\n"), "utf8").toString("base64")}`);
}

type Held = { sealedDraft: boolean; entity: string | null };

function held(email: string, subject: string): Held | null {
    return JSON.parse(consoleOutput(`app:test:dump-sealed-message --email=${email} --subject="${subject}"`).trim());
}
