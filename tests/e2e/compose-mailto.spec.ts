import { test, expect } from "./support/test";
import { mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * `mailto:` links opening plMail's own composer (#572).
 *
 * In a browser because the half that can break is the browser's: the click
 * happens inside the sandboxed message frame, crosses to the page as a
 * postMessage, and only then becomes a compose window. Every one of those
 * steps can fail silently, and the failure looks like nothing at all — or like
 * the system's mail program opening, which a headless browser cannot even show.
 */

const dock = "#compose_dock";

/** Kept in sync with App\Command\Test\SeedRenderingCommand. */
const NEWSLETTER = "E2E Remote Images";

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering", "clear-drafts");
});

test("a mailto: in a message opens the dock with what the link carries", async ({ page }) => {
    await page.goto("/mail/inbox");
    await settled(page);
    await mailRow(page, NEWSLETTER).click();
    await page.waitForURL(/\/mail\/thread\/\d+/);

    const frame = page.frameLocator(".mail-message-body iframe").first();
    await frame.getByRole("link", { name: "Write to us" }).click();

    const window = page.locator(dock);
    await expect(window.locator('input[name="compose[subject]"]')).toHaveValue("A question about the digest");
    await expect(window.locator('[data-compose--compose-target="toField"]')).toContainText("digest@tracker.e2e-rendering.invalid");

    // Shown, not folded away: a Cc the sender cannot see is a recipient they
    // did not know about.
    const cc = window.locator('[data-compose--compose-target="ccField"]');
    await expect(cc).toBeVisible();
    await expect(cc).toContainText("editor@tracker.e2e-rendering.invalid");

    // Still on the conversation: the link was handled here rather than as a
    // navigation away from it.
    await expect(page).toHaveURL(/\/mail\/thread\/\d+/);
});

test("the settings button asks the browser to hand mailto: links to plMail", async ({ page }) => {
    // Recorded rather than performed: a headless browser has no prompt to
    // show, and what is worth pinning is the URL plMail offers.
    await page.addInitScript(() => {
        const calls: Array<[string, string]> = [];
        (window as unknown as { __mailtoCalls: typeof calls }).__mailtoCalls = calls;
        navigator.registerProtocolHandler = (scheme: string, url: string | URL) => {
            calls.push([scheme, String(url)]);
        };
    });

    await page.goto("/settings/compose");
    await page.getByRole("button", { name: "Use plMail" }).click();

    await expect(page.getByRole("status").filter({ hasText: "asks you to confirm" })).toBeVisible();

    const calls = await page.evaluate(() => (window as unknown as { __mailtoCalls: Array<[string, string]> }).__mailtoCalls);
    const origin = new URL(page.url()).origin;

    expect(calls).toEqual([["mailto", `${origin}/compose/new?mailto=%s`]]);
});
