import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, consoleOutput, login, mailRow, seedUser } from "./support/config";

/**
 * A signature with a blockquote in it looks the same in the sent mail as in
 * the editor (reported while testing the milestone-v0.4.0 build): flush left
 * while writing, indented by forty pixels in the Sent copy and in whatever the
 * recipient reads it with.
 *
 * The editor's stylesheet zeroes a blockquote's margins; the reading frame and
 * every mail program use the browser's default of 40px either side. And the
 * blockquote was taken for quoted text on the way out: the text/plain part
 * stopped at it, so the signature — and anything after — was missing there,
 * and the encrypted message's text part quoted it with "> ".
 *
 * Measured where it shows: the blockquote's computed margin in the editor
 * against the same in the Sent copy's frame, and the HTML and text that went
 * out (app:test:finish-send runs the real send job and prints the mail).
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.setTimeout(120_000);

const PASSWORD = "a-password-for-signing";
const OWN = "mailbox@e2e.test";
const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const SIGNATURE = '<p>Kind regards,</p><blockquote><p>Ada Lovelace<br>Analytical Engines Ltd</p></blockquote>';

test.describe("a signature with a blockquote in it", () => {
    test("goes out as it looks in the editor, in plain mail", async ({ page }) => {
        const email = await person(page);
        const subject = `Signed plainly ${Date.now()}`;

        await compose(page, ["friend@example.test"], subject, "The engine works.");

        const editorMargin = await page.locator(`${DOCK} ${EDITOR} [data-pl-signature] blockquote`).evaluate((node) => getComputedStyle(node).marginLeft);

        expect(editorMargin).toBe("0px");

        const wire = await send(page, email, subject);
        const html = part(wire, "text/html");
        const text = part(wire, "text/plain");

        expect(html).toMatch(/<blockquote[^>]*style="[^"]*margin:\s*0/);
        expect(text, "the signature is in the text part, after the writing").toContain("Analytical Engines Ltd");
        expect(text, "and not quoted as if somebody else had written it").not.toContain("> Ada Lovelace");

        await page.goto("/mail/sent");
        await mailRow(page, subject).click();

        const frame = page.frameLocator("iframe[data-mail--message-frame-target=frame]").first();

        await expect(frame.getByText("Analytical Engines Ltd")).toBeVisible({ timeout: 15000 });
        expect(await frame.locator("blockquote").evaluate((node) => getComputedStyle(node).marginLeft)).toBe(editorMargin);
    });

    test("goes out as it looks in the editor, in encrypted mail", async ({ page }) => {
        const email = await person(page);
        const subject = `Signed secretly ${Date.now()}`;

        await compose(page, [OWN], subject, "The engine works, quietly.");
        await expect(page.locator(`${DOCK} [data-testid=compose-encrypted]`)).toBeVisible();

        const editorMargin = await page.locator(`${DOCK} ${EDITOR} [data-pl-signature] blockquote`).evaluate((node) => getComputedStyle(node).marginLeft);

        await send(page, email, subject);

        await page.goto("/mail/sent");
        await mailRow(page, subject).click();

        const frame = page.frameLocator("iframe[data-mail--encrypted-message-target=frame]").first();

        await expect(frame.getByText("Analytical Engines Ltd")).toBeVisible({ timeout: 15000 });
        expect(await frame.locator("blockquote").evaluate((node) => getComputedStyle(node).marginLeft)).toBe(editorMargin);
    });

    test("is not cut off as quoted text in a reply that quotes", async ({ page }) => {
        const email = await person(page);

        await page.goto("/mail/inbox");

        const original = mailRow(page, "").first();
        const subject = `Re: ${(await original.locator("[data-row-subject]").innerText()).trim()}`;

        await original.click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const editor = page.locator(`#compose_inline ${EDITOR}`);

        await expect(editor.locator("[data-quoted], [data-quote-wrapped]").first()).toBeAttached({ timeout: 15000 });
        await expect(editor.locator("[data-pl-signature] blockquote")).toBeAttached();
        await editor.press("Control+Home");
        await editor.pressSequentially("Answering above the quote. ");

        const wire = await send(page, email, subject, "#compose_inline");
        const text = part(wire, "text/plain");

        expect(text).toContain("Answering above the quote.");
        expect(text, "the signature survives the cut at the quote").toContain("Analytical Engines Ltd");
    });
});

/** A signed-in person whose account signs with SIGNATURE, and can send. */
async function person(page: Page): Promise<string> {
    const email = `signer-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email} --sendable`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });

    // Through the settings editor, as a person pastes one in: the input event
    // is what mirrors the editor into the field the form posts.
    await page.goto("/settings?section=signature");

    const form = page.locator('form[action$="/signature"]').first();

    await form.locator('[contenteditable="true"]').evaluate((node, html) => {
        node.innerHTML = html;
        node.dispatchEvent(new Event("input", { bubbles: true }));
    }, SIGNATURE);
    await form.getByRole("button", { name: /Save signature/i }).click();
    await expect(page.getByText("Saved.")).toBeVisible();

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

    const editor = page.locator(`${DOCK} ${EDITOR}`);

    await expect(editor.locator("[data-pl-signature] blockquote")).toBeAttached();
    await editor.press("Control+Home");
    await editor.pressSequentially(body);
}

/** Send, let the cancel window pass, run the send job, and hand back what went out. */
async function send(page: Page, email: string, subject: string, window_ = DOCK): Promise<string> {
    await page.locator(`${window_} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`).click();
    await expect(page.locator(`${window_} .compose-window`)).toHaveCount(0, { timeout: 15000 });
    await page.waitForTimeout(9000);

    return consoleOutput(`app:test:finish-send --email=${email} --subject="${subject}"`);
}

/** One part of a sent message, its transfer encoding undone. */
function part(wire: string, type: string): string {
    const start = wire.search(new RegExp(`Content-Type: ${type.replace("/", "\\/")}`, "i"));

    expect(start, `a ${type} part`).toBeGreaterThan(-1);

    const rest = wire.slice(start);
    const headersEnd = rest.search(/\r?\n\r?\n/);
    const headers = rest.slice(0, headersEnd);
    const body = rest.slice(headersEnd).replace(/^\s+/, "").split(/\r?\n--/)[0];

    if (/quoted-printable/i.test(headers)) {
        return Buffer.from(body.replace(/=\r?\n/g, "").replace(/=([0-9A-F]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16))), "latin1").toString("utf8");
    }

    if (/base64/i.test(headers)) {
        return Buffer.from(body.replace(/\s+/g, ""), "base64").toString("utf8");
    }

    return body;
}
