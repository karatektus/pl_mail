import { test, expect, type Page } from "./support/test";
import { mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * The rendering-security guarantees, checked in a real browser — because every
 * one of them is a claim about what the BROWSER does, and none of them can be
 * proved by asserting on markup.
 *
 * "Remote images are blocked" is not "the src attribute looks different". It is
 * "no request left for that host", and only a browser with route interception
 * can say so. Likewise "the body is sandboxed" is only interesting if the frame
 * the browser actually built carries the attribute.
 */

/** Kept in sync with App\Command\Test\SeedRenderingCommand. */
const SUBJECTS = {
    remote: "E2E Remote Images",
    phish:  "E2E Phish Invoice",
    long:   "E2E Long Body",
} as const;

const REMOTE_HOST = "tracker.e2e-rendering.invalid";

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering");
});

/**
 * Records every request the page makes TO the sender's host, and aborts it so a
 * regression cannot turn into a 30-second DNS timeout.
 *
 * A URL predicate rather than a glob, and that is not a style preference — it
 * is the difference between this test meaning something and meaning nothing.
 * The obvious glob, `**\://host/**`, also matches
 *
 *   http://127.0.0.1:8011/mail/image-proxy?u=https://tracker…/asset-1.png
 *
 * because the sender's URL appears INSIDE the proxy's query string. That glob
 * counts every correctly-proxied load as a leak — it reports failure at the
 * exact moment the feature is working. Matching on `url.hostname` asks the only
 * question worth asking: which host did the browser open a connection to?
 */
async function watchRemoteHost(page: Page): Promise<string[]> {
    const hits: string[] = [];

    await page.route(
        (url) => url.hostname === REMOTE_HOST,
        (route) => {
            hits.push(route.request().url());
            return route.abort();
        },
    );

    return hits;
}

/** Opens a seeded conversation from the inbox and returns its body container. */
async function openMessage(page: Page, subject: string) {
    await page.goto("/mail/inbox");

    // The list arrives as a cascade — see MotionLevel::listStagger() — and a row
    // waiting its turn is parked above where it will sit, held there by the
    // backwards fill. A parked row is a STILL row, so a click aims at it happily
    // and can land on its neighbour, or land twice; either way a second visit to
    // a thread lands while this test is already acting inside the first one, and
    // the Turbo Stream the click asked for is swapped away before it renders.
    // Waiting for stillness costs milliseconds and removes the whole family.
    await settled(page);
    await mailRow(page, subject).click();

    // The row click is a Turbo navigation, and this waits for it to LAND
    // rather than for a body to appear. Those are not the same moment: the
    // previous page's body is still in the document while the new one is on
    // its way, so `.mail-message-body` was satisfied mid-swap and the caller
    // went on to click a button inside a body that the arriving navigation
    // then replaced — taking the Turbo Stream the click had asked for with
    // it. Under load that is a lost click and an assertion timing out on a
    // testid that never rendered.
    await page.waitForURL(/\/mail\/thread\/\d+/);

    const body = page.locator(".mail-message-body").first();
    await expect(body).toBeVisible();

    return body;
}

test.describe("K-06 · remote images", () => {
    test("blocks remote images by default — nothing reaches the sender", async ({ page }) => {
        const hits = await watchRemoteHost(page);

        const body = await openMessage(page, SUBJECTS.remote);

        // The bar is offered, and it counts what it hid.
        const bar = body.getByTestId("images-bar");
        await expect(bar).toBeVisible();
        await expect(bar).toContainText("blocked");

        // THE assertion. Not "the img has no src" — no request was made.
        await page.waitForTimeout(750);
        expect(hits, `expected no request to ${REMOTE_HOST}, saw: ${hits.join(", ")}`).toHaveLength(0);
    });

    test("shows images after opt-in, and still only through the proxy", async ({ page }) => {
        const hits = await watchRemoteHost(page);

        const proxied: string[] = [];
        page.on("request", (request) => {
            if (request.url().includes("/mail/image-proxy")) proxied.push(request.url());
        });

        const body = await openMessage(page, SUBJECTS.remote);
        await body.getByTestId("show-images").click();

        // The images now load — through our own origin.
        await expect
            .poll(() => proxied.length, { timeout: 5_000 })
            .toBeGreaterThan(0);

        // Every proxy URL carries the signature the server minted, and the
        // sender's host appears only INSIDE that parameter — never as a host
        // the browser was asked to connect to.
        for (const url of proxied) {
            expect(url).toMatch(/[?&]s=[0-9a-f]{32}/);
        }

        // And the point of the whole exercise: opting in did not expose the
        // reader either. The sender's server was still never contacted.
        expect(hits, `opt-in must not contact ${REMOTE_HOST}, saw: ${hits.join(", ")}`).toHaveLength(0);
    });

    /**
     * The durable half of the bar. "Show images" is a message posted into the
     * frame and forgotten; this one is a row in the database, so the test that
     * matters is the one after the reload.
     */
    test("'always for this sender' survives a reload", async ({ page }) => {
        const hits = await watchRemoteHost(page);

        let body = await openMessage(page, SUBJECTS.remote);
        await body.getByTestId("always-show-images").click();

        // The Turbo Stream swaps the body for its re-decided self: the offer is
        // gone, and the standing permission is stated where it can be taken back.
        await expect(body.getByTestId("images-trusted")).toBeVisible();
        await expect(body.getByTestId("images-bar")).toHaveCount(0);

        // Reopened from scratch — no bar at all this time, because the decision
        // is now the reader's stored preference rather than a click.
        body = await openMessage(page, SUBJECTS.remote);
        await expect(body.getByTestId("images-bar")).toHaveCount(0);
        await expect(body.getByTestId("images-trusted")).toBeVisible();

        // And still nothing reached the sender.
        expect(hits, `trusting a sender must not contact ${REMOTE_HOST}`).toHaveLength(0);

        // Put it back, so this spec leaves the user as it found them — though it
        // is no longer the only thing that does. app:test:seed-rendering clears
        // the trusted senders too, because when this line was the sole way back
        // a failure ANYWHERE above it left the sender trusted for good, and
        // every later run failed thirty lines earlier at a "Show images always"
        // button that is not offered for a sender already trusted. One flake
        // read as a second, unrelated bug.
        //
        // Asserted in both directions rather than only on the bar returning:
        // the standing permission disappearing is the optimistic half of the
        // swap and lands immediately, while the bar is the server's answer
        // coming back — so the two failures say different things, and a longer
        // budget is given to the half that involves a round trip.
        await body.getByRole("button", { name: /Stop showing/i }).click();
        await expect(body.getByTestId("images-trusted")).toHaveCount(0);
        await expect(body.getByTestId("images-bar")).toBeVisible({ timeout: 15_000 });
    });

    /**
     * Trusting a sender does not un-block a message the provider filed as spam.
     * The allowlist records a belief about a sender; a message in Spam is the
     * provider disagreeing about whether this really is that sender.
     */
    test("a trusted sender still gets no images inside Spam", async ({ page }) => {
        await page.goto("/mail/spam");
        await mailRow(page, SUBJECTS.phish).click();

        const body = page.locator(".mail-message-body").first();
        await expect(body.getByTestId("spam-warning")).toBeVisible();
        // No standing-permission offer is made where it could not be honoured.
        await expect(body.getByTestId("always-show-images")).toHaveCount(0);
    });

    test("the image proxy refuses an unsigned target", async ({ page }) => {
        // A 1×1 GIF, not a 4xx: the reason a fetch was refused is not something
        // this route tells anybody, because "refused" versus "timed out" against
        // an internal address is itself a port scan.
        const response = await page.request.get(
            "/mail/image-proxy?u=" + encodeURIComponent("https://example.com/x.png") + "&s=deadbeef",
        );

        expect(response.status()).toBe(200);
        expect(response.headers()["content-type"]).toBe("image/gif");
        // The transparent placeholder is 43 bytes; a real image would not be.
        expect((await response.body()).length).toBeLessThan(100);
    });
});

test.describe("K-07 · sandboxed rendering", () => {
    test("the body renders in a sandboxed frame with its own CSP", async ({ page }) => {
        const body = await openMessage(page, SUBJECTS.remote);

        const frame = body.locator("iframe");
        await expect(frame).toBeVisible();

        const sandbox = await frame.getAttribute("sandbox");

        // The tokens that carry the security property are the ones NOT here.
        expect(sandbox).toContain("allow-popups");
        expect(sandbox).toContain("allow-popups-to-escape-sandbox");
        expect(sandbox).not.toContain("allow-same-origin");
        expect(sandbox).not.toContain("allow-forms");
        expect(sandbox).not.toContain("allow-top-navigation");

        // Read the policy the way the BROWSER read it, not as a substring of
        // the srcdoc attribute. srcdoc is escaped twice on the way in — once by
        // Twig for the attribute, once for the values inside it — and the
        // browser undoes both, so a string match on the attribute is a match on
        // an encoding rather than on a policy.
        const csp = await page
            .frameLocator(".mail-message-body iframe")
            .first()
            .locator('meta[http-equiv="Content-Security-Policy"]')
            .getAttribute("content");

        expect(csp).toContain("default-src 'none'");

        // A HASH, and nothing else. It used to be a nonce, which could not work:
        // a srcdoc frame is governed by the EMBEDDING page's policy as well as
        // this one, and the page's nonce belongs to the request that rendered
        // the page — not to the later Turbo Frame request that rendered this
        // message. A hash is a property of the script text, so both policies can
        // name it without either knowing when the other was rendered.
        //
        // Asserted as the WHOLE directive: 'self' or 'unsafe-inline' creeping in
        // beside the hash would let an email's own markup execute, which is the
        // property this frame exists to deny.
        expect(csp).toMatch(/script-src 'sha256-[A-Za-z0-9+/=]+'\s*;/);
        expect(csp).not.toContain("script-src 'self'");
        expect(csp).not.toContain("unsafe-inline'; script");

        expect(csp).toContain("form-action 'none'");
        expect(csp).toContain("object-src 'none'");

        // img-src names our own origin and data: — and nothing else. A remote
        // image the blocker somehow missed is refused by the browser as well.
        expect(csp).toMatch(/img-src https?:\/\/[^ ;]+ data:/);
        expect(csp).not.toContain("img-src *");
    });

    test("the frame is measured and sized to its content", async ({ page }) => {
        const body = await openMessage(page, SUBJECTS.long);
        const frame = body.locator("iframe");

        // The height handshake is the one thing `allow-scripts` was added for;
        // if it stops working the mail is a 80px letterbox.
        await expect
            .poll(async () => (await frame.boundingBox())?.height ?? 0, { timeout: 5_000 })
            .toBeGreaterThan(400);
    });
});

test.describe("K-08 · phishing", () => {
    test("warns on a message in Spam", async ({ page }) => {
        await page.goto("/mail/spam");
        await mailRow(page, SUBJECTS.phish).click();

        const body = page.locator(".mail-message-body").first();
        await expect(body.getByTestId("spam-warning")).toBeVisible();
    });

    test("does not warn on an ordinary inbox message", async ({ page }) => {
        const body = await openMessage(page, SUBJECTS.remote);

        await expect(body.getByTestId("spam-warning")).toHaveCount(0);
        await expect(body.getByTestId("sender-mismatch-warning")).toHaveCount(0);
    });

    test("names the display-name / domain mismatch, and shows its working", async ({ page }) => {
        await page.goto("/mail/spam");
        await mailRow(page, SUBJECTS.phish).click();

        const warning = page.locator(".mail-message-body").first().getByTestId("sender-mismatch-warning");
        await expect(warning).toBeVisible();

        // The claim and the fact, both on screen — a reader can check this.
        await expect(warning).toContainText("Hetzner");
        await expect(warning).toContainText("ownkhalsick.com");
    });

    test("previews a link's real destination on hover", async ({ page }) => {
        await page.goto("/mail/spam");
        await mailRow(page, SUBJECTS.phish).click();

        const body = page.locator(".mail-message-body").first();
        const frame = page.frameLocator(".mail-message-body iframe").first();

        await frame.getByRole("link", { name: "Rechnung" }).hover();

        // Drawn by the parent, so the message cannot paint over it.
        const preview = body.getByTestId("link-preview");
        await expect(preview).toBeVisible();
        await expect(preview).toContainText("dereclamefabriek.nl");
    });
});

test.describe("H-13 / H-14 · reading layout", () => {
    test("attachment chips are above the fold, not under the body", async ({ page }) => {
        const body = await openMessage(page, SUBJECTS.long);

        const chip = page.getByRole("link", { name: /e2e-long-body\.txt/ }).first();
        await expect(chip).toBeVisible();

        // Above the body frame — which is the whole of H-13. Comparing against
        // the frame rather than against a pixel budget keeps this true whatever
        // the viewport is.
        const chipBox  = await chip.boundingBox();
        const frameBox = await body.locator("iframe").boundingBox();

        expect(chipBox).not.toBeNull();
        expect(frameBox).not.toBeNull();
        expect(chipBox!.y).toBeLessThan(frameBox!.y);
    });

    test("the message header stays put while the body scrolls", async ({ page }) => {
        await openMessage(page, SUBJECTS.long);

        const sender = page.locator("[data-controller='mail--thread-message']")
            .filter({ hasText: "E2E Long Sender" })
            .first();
        const header = sender.locator("> div").first();

        await expect(header).toBeVisible();

        const scroller = page.locator("[data-controller='mail--thread-read']").first();
        await scroller.evaluate((element) => element.scrollTo(0, 1500));
        await page.waitForTimeout(300);

        // Still on screen after 1500px of scrolling — which is the whole of
        // H-14. Note that "sticky" does NOT mean "never moves": the header
        // travels with the content until it reaches the top of the scrollport
        // and then stops there. So the assertion is where it STOPPED, not that
        // it stayed put.
        await expect(header).toBeVisible();

        const headerBox   = await header.boundingBox();
        const scrollerBox = await scroller.boundingBox();

        expect(headerBox).not.toBeNull();
        expect(scrollerBox).not.toBeNull();
        expect(headerBox!.y - scrollerBox!.y).toBeLessThan(16);
        expect(headerBox!.y).toBeGreaterThanOrEqual(scrollerBox!.y - 4);

        // And it still says who wrote this.
        await expect(header).toContainText("E2E Long Sender");
    });
});
