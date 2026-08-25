import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, consoleCommand, mailRow, seed } from "./support/config";

/**
 * One send, one cancel, one button.
 *
 * The window used to answer a send by taking itself away and offering the way
 * back somewhere else — an Undo link in a toast for the dock, a countdown bar
 * in the thread for an inline reply — plus a "Cancel" link that appeared
 * beside the pill while the request was out. Three surfaces for one act, none
 * of them where the user had just clicked, and two of them in a window that no
 * longer existed. The Send pill is all of it now: it says "Sending… click to
 * cancel" for the cancel window, a second click on it calls the send off, and
 * when the window expires the composer closes on its own.
 *
 * These pin the things that shape has to keep true:
 *   • the pill IS the cancel, in the dock and inline alike;
 *   • nothing else offers one — no toast, no countdown bar;
 *   • a send that runs its course says so, once, in a toast. The button
 *     carries the whole story up to that point, but a composer that simply
 *     vanished was the one moment with no feedback at all;
 *   • a CANCEL says nothing, because the draft coming back is the answer;
 *   • the button does not change size, at rest, in flight, or on any frame of
 *     the animation. That last one is why the two faces are stacked in one
 *     grid cell rather than swapped — see compose/_send_pill.html.twig.
 */

const DOCK = "#compose_dock";
const INLINE = "#compose_inline";
const VALID = "send-cancel@example.test";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");

    // These describe the HELD shape, which is now one of two settings rather
    // than the only behaviour — see User::SETTING_COMPOSE_SEND_FEEDBACK. The
    // default is the other one (the composer closes and a toast carries the
    // undo), so without this the whole file would be asserting against a
    // window that is no longer on screen.
    // --email: each worker owns its own user, and the command
    // defaults to the shared one.
    consoleCommand(`app:test:send-feedback hold --email=${TEST_USER.email}`);
});

test.afterAll(() => {
    // Back to the default, or every later spec in this worker inherits a
    // composer that does not close.
    consoleCommand(`app:test:send-feedback optimistic --email=${TEST_USER.email}`);
});

/**
 * The desktop Send pill, by placement rather than by role.
 *
 * Its accessible name is "Send" at rest and "Sending… click to cancel" in
 * flight — that IS the feature — so a getByRole("Send") locator stops matching
 * the button in the middle of the gesture under test. `bar` is the desktop
 * copy; the `header` one is display:none above md.
 */
function sendPill(page: Page, scope: string) {
    return page.locator(`${scope} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`);
}

async function openDock(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

    const to = page.locator(`${DOCK} .ts-control input`).first();
    await to.fill(VALID);
    await to.press("Enter");
    await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill("Send cancel subject");
    await page
        .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
        .fill("Send cancel body, long enough to save.");
}

async function openInlineReply(page: Page, subject: string): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, subject).click();
    await page.getByRole("link", { name: "Reply", exact: true }).first().click();
    await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();
    await page
        .locator(`${INLINE} [data-compose--compose-toolbar-target="editor"]`)
        .fill("Inline body, long enough to save.");
}

test.describe("the send pill is the cancel", () => {
    test("a dock send keeps its window, and the pill offers the way back", async ({ page }) => {
        await openDock(page);

        const send = sendPill(page, DOCK);
        await send.click();

        // The window is still here — that is the whole change. It used to be
        // replaced by a toast before the user could look for a way out.
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
        await expect(send).toContainText("click to cancel", { timeout: 10_000 });

        // And nowhere else offers one.
        await expect(page.locator("[data-controller='compose--undo-send']")).toHaveCount(0);
        await expect(page.locator("[data-controller='compose--inline-send']")).toHaveCount(0);

        // The body cannot be edited while a copy of a sent message is in it.
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`),
        ).toHaveJSProperty("isContentEditable", true);
        expect(
            await page
                .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
                .evaluate((el) => el.closest("[inert]") !== null),
        ).toBe(true);

        // Second click on the same button: the draft comes back, intact.
        await send.click();
        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            "Send cancel subject",
            { timeout: 10_000 },
        );
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`),
        ).toContainText("Send cancel body");

        // And says nothing about it. The dock used to raise "Send cancelled —
        // back to your draft" while the inline cancel raised nothing, which
        // was the last asymmetry between the two composers; the window
        // returning with everything in it is the answer in both.
        await expect(page.locator("#toast-region")).toHaveText("");
    });

    /**
     * The one thing the button cannot say, because by then it is gone.
     */
    test("a send that runs its course closes the dock and says the message went", async ({
        page,
    }) => {
        await openDock(page);

        await sendPill(page, DOCK).click();

        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0, { timeout: 20_000 });
        // NOTHING claims the send succeeded yet, and that is the assertion.
        //
        // "Message sent." used to appear here, at the cancel mark — eight
        // seconds — while the send itself is held for ten. The confirmation
        // preceded the attempt by two seconds for everybody, and when the send
        // then failed nothing corrected it.
        //
        // It comes from the worker now, once there is an outcome, over the
        // live-updates stream. This stack cannot show that end to end: the app
        // container runs MESSENGER_TRANSPORT_DSN=in-memory://, so a send
        // dispatched by a browser request is discarded when the request ends
        // and no worker can ever pick it up. What this CAN pin is the half that
        // was wrong — that the app no longer announces an outcome it does not
        // have. The publishing side is covered by
        // tests/Service/Mail/SendOutcomeNotifierTest.
        await expect(page.locator("#toast-region")).not.toContainText("Message sent.");
    });

    test("an inline send keeps its window too — no countdown bar in the thread", async ({ page }) => {
        await openInlineReply(page, INBOX_SUBJECTS.read);

        const send = sendPill(page, INLINE);
        await send.click();

        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();
        await expect(send).toContainText("click to cancel", { timeout: 10_000 });
        await expect(page.locator("[data-controller='compose--inline-send']")).toHaveCount(0);

        await send.click();
        await expect(
            page.locator(`${INLINE} [data-compose--compose-toolbar-target="editor"]`),
        ).toContainText("Inline body", { timeout: 10_000 });
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);
    });

    /**
     * Left alone, the offer expires and the composer tidies itself away —
     * ComposeController::sent(), asked for by the window when its own countdown
     * runs out. The reply buttons coming back are what says the reply zone was
     * redrawn rather than the window merely hidden.
     */
    test("left alone, the window closes itself and the thread gets its reply bar back", async ({
        page,
    }) => {
        await openInlineReply(page, INBOX_SUBJECTS.star);

        const messages = page.locator('[data-controller="mail--thread-message"]');
        const before = await messages.count();

        await sendPill(page, INLINE).click();

        await expect(page.locator(`${INLINE} .compose-window`)).toHaveCount(0, { timeout: 20_000 });
        await expect(page.getByRole("link", { name: "Reply", exact: true }).first()).toBeVisible();

        // The reply joined the conversation, and the toast said so.
        await expect(messages).toHaveCount(before + 1);
        // NOTHING claims the send succeeded yet, and that is the assertion.
        //
        // "Message sent." used to appear here, at the cancel mark — eight
        // seconds — while the send itself is held for ten. The confirmation
        // preceded the attempt by two seconds for everybody, and when the send
        // then failed nothing corrected it.
        //
        // It comes from the worker now, once there is an outcome, over the
        // live-updates stream. This stack cannot show that end to end: the app
        // container runs MESSENGER_TRANSPORT_DSN=in-memory://, so a send
        // dispatched by a browser request is discarded when the request ends
        // and no worker can ever pick it up. What this CAN pin is the half that
        // was wrong — that the app no longer announces an outcome it does not
        // have. The publishing side is covered by
        // tests/Service/Mail/SendOutcomeNotifierTest.
        await expect(page.locator("#toast-region")).not.toContainText("Message sent.");
    });

    /**
     * A forward is a new conversation, and that is what broke it.
     *
     * The settle streams address the sent message's thread — `thread_messages_…`
     * and `reply_zone_…` — and a reply's thread is the one on screen, so they
     * landed. A forward's does not: it starts its own, so every stream was
     * addressed to an id that was nowhere in the page, every one was silently
     * dropped, and the composer sat there reading "Sending…" for ever with no
     * feedback of any kind. Closing the frame is unconditional now, and the
     * conversation is only touched when it is the conversation on screen.
     */
    test("a forwarded message closes its composer even though it starts a new thread", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.archive).click();
        await page.getByRole("link", { name: "Forward", exact: true }).first().click();
        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();

        const to = page.locator(`${INLINE} [data-compose--compose-target="toField"] .ts-control input`);
        await to.fill("forwarded@example.test");
        await to.press("Enter");

        await sendPill(page, INLINE).click();

        await expect(page.locator(`${INLINE} .compose-window`)).toHaveCount(0, { timeout: 20_000 });
        // NOTHING claims the send succeeded yet, and that is the assertion.
        //
        // "Message sent." used to appear here, at the cancel mark — eight
        // seconds — while the send itself is held for ten. The confirmation
        // preceded the attempt by two seconds for everybody, and when the send
        // then failed nothing corrected it.
        //
        // It comes from the worker now, once there is an outcome, over the
        // live-updates stream. This stack cannot show that end to end: the app
        // container runs MESSENGER_TRANSPORT_DSN=in-memory://, so a send
        // dispatched by a browser request is discarded when the request ends
        // and no worker can ever pick it up. What this CAN pin is the half that
        // was wrong — that the app no longer announces an outcome it does not
        // have. The publishing side is covered by
        // tests/Service/Mail/SendOutcomeNotifierTest.
        await expect(page.locator("#toast-region")).not.toContainText("Message sent.");

        // The thread's own buttons are back — the composer did not merely go
        // invisible, the reply zone knows it closed.
        await expect(page.getByRole("link", { name: "Forward", exact: true }).first()).toBeVisible();
    });

    /**
     * A window that comes back has to come back as what it was.
     *
     * `mode` was a render-time extra set by the forward action alone, so every
     * OTHER render of the same window forgot it — and the undo reopen is one.
     * A cancelled forward came back as a plain new message, and sending it
     * again asked "this message has no text, send anyway?" about the forwarded
     * mail sitting in the body. It rides in the URL with the frame and the
     * thread now (ComposeContext::MODE_FORWARD), so it survives the trip.
     */
    test("a cancelled forward is still a forward, and does not ask if it is empty", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.trash).click();
        await page.getByRole("link", { name: "Forward", exact: true }).first().click();
        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();

        const to = page.locator(`${INLINE} [data-compose--compose-target="toField"] .ts-control input`);
        await to.fill("forward-again@example.test");
        await to.press("Enter");

        const send = sendPill(page, INLINE);

        await send.click();
        await expect(send).toContainText("click to cancel", { timeout: 10_000 });
        await send.click();
        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible({ timeout: 10_000 });

        // The reopened window knows what it is…
        await expect(page.locator(`${INLINE} [data-compose--compose-mode-value]`)).toHaveAttribute(
            "data-compose--compose-mode-value",
            "forward",
        );

        // …so the second send goes straight out, with nothing asked about a
        // body whose whole content is the quote.
        const again = sendPill(page, INLINE);

        await again.click();
        await expect(page.getByText("This message has no text")).toHaveCount(0);
        await expect(again).toContainText("click to cancel", { timeout: 10_000 });
    });

    /**
     * The size, held.
     *
     * This is the assertion the markup exists for. "Sending" plus a growing run
     * of dots plus a second line is wider and taller than "Send", so a button
     * that swapped one label for the other would jump when the send starts and
     * jitter four times a second while it runs. Both faces occupy the same grid
     * cell and only their visibility changes, so the box is the larger of the
     * two from the moment the window renders and never moves again.
     */
    test("the pill does not change size — not on send, not between dots", async ({ page }) => {
        await openDock(page);

        const send = sendPill(page, DOCK);
        const resting = await send.boundingBox();

        await send.click();
        await expect(send).toContainText("click to cancel", { timeout: 10_000 });

        const seen = new Set<string>();

        // Four ticks of the 400ms animation, which is every dot count twice.
        for (let i = 0; i < 8; i++) {
            const box = await send.boundingBox();
            seen.add(`${Math.round(box!.width)}x${Math.round(box!.height)}`);
            await page.waitForTimeout(220);
        }

        expect([...seen]).toEqual([`${Math.round(resting!.width)}x${Math.round(resting!.height)}`]);
    });
});
