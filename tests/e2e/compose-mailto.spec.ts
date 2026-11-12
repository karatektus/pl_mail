import { test, expect, type Page } from "./support/test";
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

    await expect(page.getByRole("status").filter({ hasText: "choose Allow" })).toBeVisible();

    const calls = await page.evaluate(() => (window as unknown as { __mailtoCalls: Array<[string, string]> }).__mailtoCalls);
    const origin = new URL(page.url()).origin;

    expect(calls).toEqual([["mailto", `${origin}/compose/new?mailto=%s`]]);
});

/**
 * The installed app given a link while its window is open (#585). Chromium
 * focuses the window and hands the link to `window.launchQueue` without
 * navigating; headless Chromium has no installed app to launch, so the queue is
 * stood in for and the launch delivered by hand.
 */
async function stubLaunchQueue(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const w = window as unknown as { __launch?: (p: { targetURL: string }) => void };
        // Defined rather than assigned: Chromium has a read-only launchQueue
        // of its own on Window, and an assignment to it is silently ignored.
        Object.defineProperty(window, "launchQueue", {
            configurable: true,
            value: { setConsumer: (fn: (p: { targetURL: string }) => void) => { w.__launch = fn; } },
        });
    });
}

async function launch(page: Page, path: string): Promise<void> {
    await page.evaluate((url) => {
        (window as unknown as { __launch: (p: { targetURL: string }) => void }).__launch({ targetURL: url });
    }, new URL(path, page.url()).href);
}

test("a link launched into the open app opens in its dock", async ({ page }) => {
    await stubLaunchQueue(page);
    await page.goto("/mail/inbox");
    await settled(page);

    await launch(page, "/compose/new?mailto=" + encodeURIComponent("mailto:launch@example.invalid?subject=From%20outside"));

    const window = page.locator(dock);
    await expect(window.locator('input[name="compose[subject]"]')).toHaveValue("From outside");
    await expect(window.locator('[data-compose--compose-target="toField"]')).toContainText("launch@example.invalid");
    await expect(page).toHaveURL(/\/mail\/inbox/);
});

test("launching the app from its icon opens no composer", async ({ page }) => {
    await stubLaunchQueue(page);
    await page.goto("/mail/inbox");
    await settled(page);

    await launch(page, "/mail/inbox");
    // Anything off-origin is not ours to load either.
    await page.evaluate(() => {
        (window as unknown as { __launch: (p: { targetURL: string }) => void }).__launch({ targetURL: "https://elsewhere.invalid/compose/new" });
    });

    await expect(page.locator(`${dock} input[name="compose[subject]"]`)).toHaveCount(0);
});

test("the first launch, redirected into ?compose=, is not opened twice", async ({ page }) => {
    await stubLaunchQueue(page);
    const link = "/compose/new?mailto=" + encodeURIComponent("mailto:first@example.invalid?subject=Only%20once");
    await page.goto(link);
    await page.waitForURL(/\/mail\/inbox\?compose=/);

    const subject = page.locator(`${dock} input[name="compose[subject]"]`);
    await expect(subject).toHaveValue("Only once");
    await subject.fill("Typed by hand");

    // What Chromium delivers to the page that navigation produced.
    await launch(page, link);

    await expect(subject).toHaveValue("Typed by hand");
});
