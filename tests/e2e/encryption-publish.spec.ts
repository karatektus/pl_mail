import { execSync } from "node:child_process";
import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { acceptConfirm } from "./support/confirm";
import { consoleCommand, login, seedUser } from "./support/config";

/**
 * Publishing one's own key to keys.openpgp.org, phase 6g of issue #28 (#28,
 * comment 6216704815): per address key in Settings → Encryption, with a
 * confirmation before anything is sent, the state per address kept and
 * refreshed, the new key offered after Renew, and the revocation uploaded
 * when a published key is marked compromised.
 *
 * keys.openpgp.org is the test environment's stand-in (KeyServerStub, wired
 * under `when@test`), which speaks the VKS upload API and keeps what it was
 * told under var/test-key-servers/vks — never the internet. The person
 * clicking the link the server mailed them is app:test:seed-key-server
 * --confirm.
 *
 * A person of the test's own, whose sign-in address is their mailbox's
 * (`--own-address`), so the address confirmed is nobody else's.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-publishing";
const HOST = "keys.openpgp.org";

test("publishes the key after asking, follows the confirmation, offers the renewed key and revokes a compromised one", async ({ page }) => {
    test.setTimeout(90_000);

    const email = await person(page);
    const first = (await keyring(page)).addressKeys[0];
    const block = publication(page, first.fingerprint);

    await expect(block.getByTestId("key-publication-state")).toHaveAttribute("data-state", "none");

    // Asked first, in words that say what it does — and nothing goes out
    // until the person agrees.
    await block.getByRole("button", { name: `Publish to ${HOST}` }).click();
    await expect(page.locator("#confirm-dialog")).toContainText("ties these addresses to the key in public");
    expect(requests(first.fingerprint)).toEqual([]);
    await acceptConfirm(page);

    await expect(publication(page, first.fingerprint).getByTestId("key-publication-state")).toHaveAttribute("data-state", "pending");
    await expect(publication(page, first.fingerprint)).toContainText(`waiting for you: ${HOST} mailed this address a link`);
    expect(requests(first.fingerprint).map((call) => call.call)).toEqual(["upload", "request-verify"]);
    expect(requests(first.fingerprint)[1].addresses).toEqual([email]);

    // The person clicks the link in their inbox; "Check again" asks the server.
    consoleCommand(`app:test:seed-key-server --confirm --email=${email}`);
    await publication(page, first.fingerprint).getByRole("button", { name: "Check again" }).click();
    await expect(publication(page, first.fingerprint).getByTestId("key-publication-state")).toHaveAttribute("data-state", "published");
    expect(requests(first.fingerprint).map((call) => call.call)).toEqual(["upload", "request-verify", "upload"]);

    // Renew: the new key is offered on the server the old one was on.
    await page.locator(`[data-settings--encryption-target=keys] li`, { hasText: grouped(first.fingerprint) }).getByRole("button", { name: "Renew" }).click();
    await acceptConfirm(page);
    await expect(page.locator("#confirm-dialog")).toContainText(`Your previous key was published to ${HOST}`);
    await acceptConfirm(page);

    const second = (await keyring(page)).addressKeys.find((key: { fingerprint: string; status: string }) => key.fingerprint !== first.fingerprint && "active" === key.status);

    await expect(publication(page, second.fingerprint).getByTestId("key-publication-state")).toHaveAttribute("data-state", "pending");
    expect(requests(second.fingerprint).map((call) => call.call)).toEqual(["upload", "request-verify"]);

    // Compromised: the revoked key goes to the server it was published to.
    consoleCommand(`app:test:seed-key-server --confirm --email=${email}`);
    await page.locator(`[data-settings--encryption-target=keys] li`, { hasText: grouped(second.fingerprint) }).getByRole("button", { name: "Mark as compromised" }).click();
    await acceptConfirm(page);

    await expect(publication(page, second.fingerprint).getByTestId("key-publication-state")).toHaveAttribute("data-state", "revoked");

    const revocation = requests(second.fingerprint).at(-1);

    expect(revocation?.call).toBe("upload");
    expect(revocation?.revoked).toBe(true);
});

test("offers no publishing once the person switches key servers off", async ({ page }) => {
    await person(page);

    const frame = page.locator("turbo-frame#contact-keys");

    await frame.getByTestId("key-servers-switch").getByText("Off", { exact: true }).click();
    await expect(frame.getByTestId("key-servers-switch").locator('input[value="0"]')).toBeChecked();
    // Checked at the click, stored only when the switch's post answers: the
    // lookup hint naming no key server is drawn from that answer. Reloading
    // before it could cancel the post.
    await expect(frame.getByText("Asks the address's own domain for its key (Web Key Directory). The request goes through this server.")).toBeVisible({ timeout: 15000 });

    await page.reload();
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
    await openKeys(page);

    expect((await keyring(page)).publishTo).toEqual([]);
    await expect(page.getByTestId("key-publication")).toHaveCount(0);
});

/** A signed-in person with a keyring, on Settings → Encryption, the keys shown. */
async function person(page: Page): Promise<string> {
    const email = `publish-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email} --own-address`);

    await login(page, email, PASSWORD);
    await page.goto("/settings?section=encryption");
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
    await expect.poll(async () => (await keyring(page)).addressKeys.length).toBe(1);

    // The keyring made by the first visit; drawn from the server's copy now.
    await page.reload();
    await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
    await openKeys(page);

    return email;
}

async function openKeys(page: Page): Promise<void> {
    await page.getByText("Show keys", { exact: true }).click();
}

function publication(page: Page, fingerprint: string) {
    return page.locator(`[data-settings--encryption-target=keys] li`, { hasText: grouped(fingerprint) }).locator(`[data-testid=key-publication][data-host="${HOST}"]`);
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function keyring(page: Page): Promise<any> {
    return (await page.request.get("/keyring")).json();
}

type Call = { call: string; fingerprint: string; addresses?: string[]; revoked?: boolean };

/** What the stand-in took for this key, in order (KeyServerStub's request log). */
function requests(fingerprint: string): Call[] {
    // Read inside the app container, where the stand-in writes it: var/ is the
    // container's own volume, not the checkout.
    const log = execSync(`${process.env.E2E_COMPOSE} exec -T app sh -c 'cat var/test-key-servers/vks/requests.jsonl 2>/dev/null || true'`, { encoding: "utf8" });

    return log.split("\n").filter((line) => line.trim().startsWith("{")).map((line) => JSON.parse(line) as Call).filter((call) => call.fingerprint === fingerprint.toLowerCase());
}

function grouped(fingerprint: string): string {
    return fingerprint.toUpperCase().match(/.{1,4}/g)?.join(" ") ?? "";
}
