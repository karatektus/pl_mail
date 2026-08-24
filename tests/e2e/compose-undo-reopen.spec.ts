import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, consoleCommand, mailRow, seed } from "./support/config";

/**
 * Undo-a-send has to put the window back where it was sent from — and the dock
 * has to survive it.
 *
 * The bug these pin was two symptoms of one cause. The dock's placement used to
 * ride on the <turbo-frame id="compose_dock"> itself, but the undo stream
 * reopens the window with `action="replace"` on that frame — swapping the whole
 * element and throwing its `fixed bottom-4 right-6 z-50` away with it. The
 * reopened window then rendered in normal flow, below the fold, so the send was
 * cancelled but the window looked like it never came back (A). Worse, that
 * classless frame stayed in the page: every later dock window — the Compose
 * button, a draft row — navigated INTO it and landed below the fold too, until a
 * full reload restored the markup (B). Positioning now lives on a stable
 * wrapper, so replacing the frame cannot move the dock.
 *
 * A window "reopened" is not enough to assert with toBeVisible(): an element in
 * normal flow below the fold is still visible to Playwright. These check where
 * it actually is.
 *
 * ── What "undo" means now ────────────────────────────────────────────────────
 * There is no toast and no countdown bar to find. A send leaves the window
 * exactly where it was and turns its Send pill into "Sending… click to cancel"
 * for the cancel window; clicking that pill is the undo, in the dock and in the
 * thread alike. sendThenCancel() below is that gesture, and the reopen it has
 * to survive is the same one it always was — the frame is still replaced
 * wholesale by the undo response.
 */

const DOCK = "#compose_dock";
const INLINE = "#compose_inline";
const VALID = "undo-reopen@example.test";

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

function toInput(page: Page) {
    return page.locator(`${DOCK} .ts-control input`).first();
}

function chips(page: Page) {
    return page.locator(`${DOCK} .ts-control`).first().locator(".item");
}

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
}

/**
 * The desktop Send pill. Addressed by its placement rather than by role,
 * deliberately: its accessible name is "Send" at rest and "Sending… click to
 * cancel" in flight — that IS the feature — so a getByRole("Send") locator
 * stops matching the button halfway through the very gesture being tested.
 * `bar` is the desktop copy; the `header` one is display:none above md.
 */
function sendPill(page: Page, scope: string) {
    return page.locator(`${scope} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`);
}

/**
 * Send, then call it back — both clicks on the same button.
 *
 * The wait between them is an assertion, not a sleep: until the pill says it
 * has become the cancel, a second click would be a second send.
 */
async function sendThenCancel(page: Page, scope: string): Promise<void> {
    const send = sendPill(page, scope);

    await send.click();
    await expect(send).toContainText("click to cancel", { timeout: 10_000 });
    await send.click();
}

async function fillDock(page: Page): Promise<void> {
    await toInput(page).fill(VALID);
    await toInput(page).press("Enter");
    await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill("Undo subject");
    await page
        .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
        .fill("Undo body text, long enough to save.");
}

/**
 * Where the dock window sits relative to the viewport. `onScreen` is the
 * assertion that matters: the whole window is within the viewport, tucked into
 * the bottom-right corner where the floating dock lives.
 */
async function dockWindowPlacement(page: Page) {
    return page.evaluate(() => {
        const w = document.querySelector("#compose_dock .compose-window") as HTMLElement | null;
        if (!w) return { present: false, onScreen: false };
        const r = w.getBoundingClientRect();
        const onScreen =
            r.top >= 0 &&
            r.left >= 0 &&
            r.bottom <= window.innerHeight + 1 &&
            r.right <= window.innerWidth + 1;
        const atBottomRight =
            window.innerHeight - r.bottom < 48 && window.innerWidth - r.right < 48;
        return { present: true, onScreen, atBottomRight };
    });
}

test.describe("compose undo reopens where it was sent from", () => {
    // ── A: the dock ───────────────────────────────────────────────────────

    test("a dock send reopens on undo — in place, on screen, and intact", async ({ page }) => {
        await openCompose(page);

        // The floating dock starts where it belongs.
        expect(await dockWindowPlacement(page)).toMatchObject({ onScreen: true, atBottomRight: true });

        await fillDock(page);
        await sendThenCancel(page, DOCK);

        // The window is back…
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible({ timeout: 10_000 });

        // …in the corner it left, not shoved into the document below the fold…
        expect(await dockWindowPlacement(page)).toMatchObject({ onScreen: true, atBottomRight: true });

        // …with everything that was typed in it (the field round-trip the undo does).
        await expect(chips(page)).toHaveCount(1);
        await expect(chips(page).first()).toContainText(VALID);
        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            "Undo subject",
        );
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`),
        ).toContainText("Undo body text");
    });

    test("the dock survives an undo — the next Compose still opens on screen", async ({ page }) => {
        await openCompose(page);
        await fillDock(page);
        await sendThenCancel(page, DOCK);
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible({ timeout: 10_000 });

        // Close, then open a fresh Compose — WITHOUT a reload. This is the second
        // symptom: a classless frame left behind used to take every later dock
        // window below the fold with it.
        await page.locator(DOCK).getByRole("button", { name: "Save draft and close" }).click();

        // Deliberately NOT given a longer budget. This failed once on CI with
        // the window resolving on all fourteen polls — continuously present for
        // the full five seconds, not slow to go — and a window that never
        // closes is not a window that closes late. Raising the wait would only
        // move where it fails.
        //
        // Two candidates, and the message is here so the next occurrence says
        // which: the click never reached close(), or something re-rendered the
        // dock after it did. The second is the live one — this is the undo
        // path, whose whole job is putting a window back, and a stream arriving
        // after the reader has closed it would do exactly this.
        await expect(
            page.locator(`${DOCK} .compose-window`),
            "the composer was still open after Save draft and close",
        ).toHaveCount(0);

        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
        expect(await dockWindowPlacement(page)).toMatchObject({ onScreen: true, atBottomRight: true });
    });

    // ── B: an inline reply ────────────────────────────────────────────────

    test("an inline reply reopens inline on undo, not in the dock", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();

        await page
            .locator(`${INLINE} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Inline reply body, long enough to save.");

        // Inline send: the window stays put and its own pill becomes the cancel.
        await sendThenCancel(page, INLINE);

        // Reopened where it was written — inline, in the thread…
        await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible({ timeout: 10_000 });
        await expect(
            page.locator(`${INLINE} [data-compose--compose-toolbar-target="editor"]`),
        ).toContainText("Inline reply body");

        // …and NOT in the dock (the secondary bug: a missing origin frame sent
        // an inline undo to the dock instead).
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);
    });

    // ── C: a Drafts-list row, arrived by a turbo navigation ───────────────

    test("a drafts-list row opens on the first click when it arrived by a turbo nav", async ({ page }) => {
        // A saved draft to click.
        await openCompose(page);
        const saved = page.waitForResponse(
            (r) => /\/compose\/draft/.test(r.url()) && r.request().method() === "POST",
        );
        await fillDock(page);
        await saved;
        await page.locator(DOCK).getByRole("button", { name: "Save draft and close" }).click();
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        // Arrive at Drafts through the sidebar — a turbo-frame navigation of the
        // list, not a full page load — so the rows are stream-rendered.
        await page.getByRole("link", { name: /^Drafts/ }).first().click();
        await expect(page).toHaveURL(/\/mail\/drafts/);

        // Wait for the streamed list to settle on our draft before touching it —
        // clicking a row mid-swap would detach it, which is a test race and not
        // what is under test here.
        const row = page
            .locator('#message-list li[data-controller="mail--message-row"]')
            .filter({ hasText: "Undo subject" })
            .first();
        await expect(row).toBeVisible();

        // First click, no reload: the editor opens in the dock, on screen.
        await row.click();
        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            "Undo subject",
        );
        expect(await dockWindowPlacement(page)).toMatchObject({ onScreen: true, atBottomRight: true });
    });
});
