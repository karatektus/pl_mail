import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The compose window's accidental-send guards, and the window's own manners.
 *
 * All of this comes from one external bug report against a live install, and
 * the first item is the reason the rest were found: typing a string with no
 * `@` into To and pressing Enter *sent the message* — no subject, no body, a
 * garbage recipient — leaving only the 10s undo window to take it back, and
 * that window did not work either.
 *
 * The mechanism was implicit form submission. Tom Select calls preventDefault
 * on Enter only when it has something to do with the keystroke: an active
 * suggestion to commit, or a `createItem()` that succeeds. A string matching no
 * contact AND failing `createFilter` (no `@`) satisfies neither, so the keydown
 * reached the <form> — whose action is the send URL.
 *
 * That is why compose-recipients.spec.ts never caught it: every address it
 * types is valid, so `createItem()` always succeeds and always swallows the
 * key. The invalid address is the whole test.
 */

declare global {
    interface Window {
        /** The `document` keydown handlers currently registered, for the teardown test below. */
        __keydownFns?: Set<EventListenerOrEventListenerObject>;
    }
}

const DOCK = "#compose_dock";
const GARBAGE = "keine-gueltige-adresse";
const VALID = "safety-valid@example.test";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
}

function toInput(page: Page) {
    return page.locator(`${DOCK} .ts-control input`).first();
}

function chips(page: Page) {
    return page.locator(`${DOCK} .ts-control`).first().locator(".item");
}

/**
 * Records every send POST the page makes. Asserting "nothing was sent" needs a
 * witness that survives the assertion — waitForResponse would only report a
 * send that happened inside its own timeout.
 */
function watchSends(page: Page): string[] {
    const sends: string[] = [];

    page.on("request", (request) => {
        if (request.method() === "POST" && /\/compose\/send/.test(request.url())) {
            sends.push(request.url());
        }
    });

    return sends;
}

async function fillMessage(page: Page, address: string): Promise<void> {
    await toInput(page).fill(address);
    await toInput(page).press("Enter");
    await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill("Safety subject");
    await page
        .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
        .fill("Safety body text, long enough to save.");
}

test.describe("compose safety", () => {
    // ── K-01 ──────────────────────────────────────────────────────────

    test("Enter on an invalid address does not send the message", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);

        await toInput(page).fill(GARBAGE);
        await toInput(page).press("Enter");

        // Give a send that was going to happen the time to happen.
        await page.waitForTimeout(1_000);

        expect(sends).toHaveLength(0);

        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`),
        ).toBeVisible();
    });

    test("Enter on a valid address commits a chip and does not send", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);

        await toInput(page).fill(VALID);
        await toInput(page).press("Enter");

        await expect(chips(page)).toHaveCount(1);
        await expect(chips(page).first()).toContainText(VALID);

        await page.waitForTimeout(500);
        expect(sends).toHaveLength(0);
    });

    /** Ctrl+Enter stays a send — the one deliberate keyboard shortcut. */
    test("Ctrl+Enter sends", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);
        await fillMessage(page, VALID);

        await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).press("Control+Enter");

        await expect.poll(() => sends.length, { timeout: 10_000 }).toBe(1);
    });

    // ── K-02 ──────────────────────────────────────────────────────────

    test("an invalid address is refused rather than chipped, and says so", async ({ page }) => {
        await openCompose(page);

        await toInput(page).fill(GARBAGE);
        await toInput(page).press("Enter");

        await expect(chips(page)).toHaveCount(0);
        await expect(page.locator(`${DOCK} [data-compose--compose-target="errors"]`)).toContainText(
            GARBAGE,
        );
    });

    test("sending with no recipient is refused", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);
        await page.locator(`${DOCK} [data-compose--compose-target="subject"]`).fill("No recipient");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Body with no addressee at all.");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        await page.waitForTimeout(1_000);

        expect(sends).toHaveLength(0);
        await expect(page.locator(`${DOCK} [data-compose--compose-target="errors"]`)).toBeVisible();
    });

    /**
     * The server's own refusal, reached past the window entirely.
     *
     * "The API must refuse, not just the UI" is the point: the guards above all
     * live in a controller the user can simply not run.
     */
    test("the server refuses a send with no valid recipient", async ({ page }) => {
        await openCompose(page);

        const status = await page.evaluate(async (garbage) => {
            const form = document.querySelector("#compose_dock form") as HTMLFormElement;
            const body = new FormData(form);

            body.set("compose[toAddresses][]", garbage);

            const response = await fetch("/compose/send", {
                method: "POST",
                body,
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            return { ok: response.ok, text: (await response.text()).slice(0, 4000) };
        }, GARBAGE);

        // Not a send toast — the window comes back carrying the refusal.
        expect(status.text).not.toContain("toast-region");
        expect(status.text).toMatch(/recipient|Empfänger/i);
    });

    /**
     * The question is asked IN THE APP, not by the browser.
     *
     * It was `window.confirm()`, which froze a tester's session three times: a
     * native dialog blocks the whole tab, takes no styling, and sat a few
     * centimetres from the in-app panel this window already uses to ask whether
     * to remove formatting. Two kinds of confirmation for two versions of one
     * question.
     *
     * The old version of this test dismissed a `dialog` event, which is why it
     * would keep passing whether the dialog appeared or not — nothing was sent
     * either way. It now names the panel, and fails if a native dialog is what
     * comes back.
     */
    test("an empty subject asks before sending, in the app rather than in a browser dialog", async ({
        page,
    }) => {
        const sends = watchSends(page);
        const native: string[] = [];

        page.on("dialog", (dialog) => {
            native.push(dialog.message());
            void dialog.dismiss();
        });

        await openCompose(page);

        await toInput(page).fill(VALID);
        await toInput(page).press("Enter");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Body but no subject line.");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const panel = page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`);

        await expect(panel).toBeVisible();
        await expect(panel).toHaveAttribute("role", "alertdialog");
        await expect(panel).toContainText(/no subject/i);
        expect(native, "the browser must not be the one asking").toHaveLength(0);

        // Declining sends nothing and keeps the window as it was.
        await panel.getByRole("button", { name: "Keep editing" }).click();

        await expect(panel).toBeHidden();
        await page.waitForTimeout(1_000);
        expect(sends).toHaveLength(0);
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible();
    });

    /** And accepting it actually sends — the panel is a gate, not a wall. */
    test("the send-anyway panel lets the message through when it is accepted", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);

        await toInput(page).fill(VALID);
        await toInput(page).press("Enter");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Body but no subject line.");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const panel = page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`);
        await expect(panel).toBeVisible();

        await panel.getByRole("button", { name: "Send anyway" }).click();

        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0, { timeout: 10_000 });
        expect(sends.length, "the accepted send goes out").toBeGreaterThan(0);
    });

    /**
     * Both reasons at once are one question, asked once.
     *
     * Sequential `window.confirm()`s meant a message with neither subject nor
     * body asked twice, and the second dialog arrived after the first had been
     * answered with no way back to it.
     */
    test("a message missing both subject and text asks once, naming both", async ({ page }) => {
        await openCompose(page);

        await toInput(page).fill(VALID);
        await toInput(page).press("Enter");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const panel = page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`);

        await expect(panel).toBeVisible();
        await expect(panel).toContainText(/no subject/i);
        await expect(panel).toContainText(/no text/i);
    });

    /**
     * Closing the window with the question still on screen takes its Escape
     * handler with it.
     *
     * The panel binds a keydown listener to `document` so Escape can decline
     * it, and unbound it only from its two answer buttons — so a window torn
     * down with the question unanswered left the listener behind, holding the
     * dead controller and its whole DOM subtree alive and swallowing Escape
     * (the handler calls stopPropagation) for whatever came next. Its sibling,
     * the plain-text warning, was already closed in disconnect(); this one was
     * not, which is exactly why it was easy to miss.
     *
     * Asserted by counting `document` keydown registrations rather than by a
     * visible symptom, because there is not much of one: cancelSendAnyway() on
     * a detached controller quietly does nothing. The leak IS the bug, so the
     * leak is what is measured. Net zero across the window's whole life —
     * anything this test's own actions add and remove cancels out, so the
     * number only moves if something is genuinely left behind.
     */
    test("a window discarded with the send question open leaves no listener behind", async ({ page }) => {
        // A Set of handler functions, not a counter, and addInitScript rather
        // than evaluate(). Both are load-bearing. A counter went to -10 here,
        // because this codebase removes listeners defensively — _closeSendWarning
        // and _closePlainWarning both unbind whether or not they ever bound, so
        // "removals minus additions" measures coding style, not leaks. Set
        // membership is immune: deleting a function that was never added is a
        // no-op. And the probe has to predate every page script, or handlers
        // registered during load are deleted at teardown from a Set that never
        // saw them added.
        await page.addInitScript(() => {
            const live = new Set<EventListenerOrEventListenerObject>();
            window.__keydownFns = live;

            const add = document.addEventListener.bind(document);
            const remove = document.removeEventListener.bind(document);

            document.addEventListener = ((...args: Parameters<typeof add>) => {
                if ("keydown" === args[0] && args[1]) { live.add(args[1]); }

                return add(...args);
            }) as typeof document.addEventListener;

            document.removeEventListener = ((...args: Parameters<typeof remove>) => {
                if ("keydown" === args[0] && args[1]) { live.delete(args[1]); }

                return remove(...args);
            }) as typeof document.removeEventListener;
        });

        await page.goto("/mail/inbox");
        await expect(page.getByRole("link", { name: "Compose" }).first()).toBeVisible();

        const balance = () => page.evaluate(() => window.__keydownFns?.size ?? 0);
        const before = await balance();

        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        await toInput(page).fill(VALID);
        await toInput(page).press("Enter");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const panel = page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`);
        await expect(panel).toBeVisible();

        // The question is up and its handler is bound: strictly more listeners
        // than before the window existed, or the rest of this proves nothing.
        expect(await balance()).toBeGreaterThan(before);

        // Close it with the question unanswered. The dock's own close button,
        // not a navigation: a dock window deliberately SURVIVES a Turbo visit,
        // so navigating away never tears the controller down and would have
        // proved nothing.
        await page.locator(DOCK).getByRole("button", { name: /close/i }).first().click();
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0);

        expect(
            await balance(),
            "the send-warning Escape handler outlived the window it belonged to",
        ).toBe(before);
    });

    /**
     * Enter on a toolbar control is neither a send nor a failed address.
     *
     * The typeface and size pickers are Tom Selects too, so "is this a Tom
     * Select?" is not the same question as "is this an address field?" — the
     * Enter handler keys off the address rows, not off `.ts-wrapper`.
     *
     * (These two pickers hold three and four options, below ui--select's
     * search-box threshold, so they render no text input and the handler
     * declines them a step earlier still. The assertion is on the behaviour
     * either way: Enter here does nothing at all.)
     */
    test("Enter on a formatting control neither sends nor reports an address", async ({ page }) => {
        const sends = watchSends(page);

        await openCompose(page);

        const typeface = page.locator(`${DOCK} [data-compose--compose-toolbar-target="fontFamily"]`);
        await expect(typeface).toBeAttached();

        // The widget Tom Select built in its place, which is what has focus.
        await page
            .locator(`${DOCK} [data-compose--compose-target="formatBar"] .ts-control`)
            .first()
            .press("Enter");

        await expect(page.locator(`${DOCK} [data-compose--compose-target="errors"]`)).toBeHidden();

        await page.waitForTimeout(500);
        expect(sends).toHaveLength(0);
    });

    // ── K-03 / K-04 ───────────────────────────────────────────────────

    /**
     * Undo has to put the window back, with everything that was typed in it.
     *
     * It used to 500: the undo response rendered the compose window without
     * `pickerIntegrations`, and the cancel flag was already flushed by then —
     * so the send was called off, the toast faded, and the draft was filed with
     * no way back to it.
     *
     * There is no toast to wait for any more. The window stays open through
     * the cancel window and its own Send pill becomes the way out — one
     * surface, in every editor — so the cancel is a second click on the button
     * that was just pressed.
     */
    test("undo reopens the window with the recipient intact", async ({ page }) => {
        await openCompose(page);
        await fillMessage(page, VALID);

        // Addressed by placement, not by role: the pill's accessible name
        // changes to "Sending… click to cancel" as it changes jobs, which is
        // exactly what a getByRole("Send") locator would stop matching.
        const send = page.locator(
            `${DOCK} [data-compose-send-pill="bar"] [data-compose--compose-target="sendBtn"]`,
        );
        await send.click();

        // The same button, now offering the way back.
        await expect(send).toContainText("click to cancel", { timeout: 10_000 });
        await send.click();

        // The window is back…
        await expect(page.locator(`${DOCK} .compose-window`)).toBeVisible({ timeout: 10_000 });

        // …and so is everything that was in it.
        await expect(chips(page)).toHaveCount(1);
        await expect(chips(page).first()).toContainText(VALID);
        await expect(page.locator(`${DOCK} [data-compose--compose-target="subject"]`)).toHaveValue(
            "Safety subject",
        );
        await expect(
            page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`),
        ).toContainText("Safety body text");
    });

    // ── A-03 / A-05 ───────────────────────────────────────────────────

    test("every compose control has an accessible name", async ({ page }) => {
        await openCompose(page);

        const dock = page.locator(DOCK);

        for (const name of [
            "To",
            "Send",
            "Minimize",
            "Expand",
            "Attach files",
            "Insert emoji",
            "Insert image",
            "Encrypt message",
            "Insert signature",
            "More options",
            "Text colour",
            "Typeface",
            "Text size",
        ]) {
            await expect(
                dock.getByRole("button", { name, exact: true })
                    .or(dock.getByRole("combobox", { name, exact: true }))
                    .or(dock.getByRole("textbox", { name, exact: true }))
                    .first(),
                `"${name}" should be reachable by its accessible name`,
            ).toBeAttached();
        }

        // A-05: the text-colour control was not in the accessibility tree at
        // all — a <label> wrapping a 0×0, pointer-events:none, tabindex="-1"
        // input. It has to be focusable.
        const colour = dock.getByRole("textbox", { name: "Text colour" });
        await colour.focus();
        await expect(colour).toBeFocused();

        // No control may be left nameless.
        const unnamed = await dock.evaluate((root) =>
            Array.from(root.querySelectorAll("button"))
                .filter((b) => {
                    const el = b as HTMLElement;
                    if (el.offsetParent === null) return false;
                    const name =
                        el.getAttribute("aria-label") ??
                        el.getAttribute("title") ??
                        el.textContent?.trim() ??
                        "";
                    return name === "";
                })
                .map((b) => b.outerHTML.slice(0, 120)),
        );

        expect(unnamed).toEqual([]);
    });
});

// ── I-01 / I-03: the window speaks the UI's language ──────────────────

/**
 * Switch the signed-in user's language through the real settings form.
 *
 * The active language's button is `disabled`, so asking for the one already in
 * force is a no-op rather than a failed click — which matters in the cleanup
 * hook, where the test may have failed before it ever changed anything.
 */
async function useLocale(page: Page, locale: string): Promise<void> {
    await page.goto("/settings?section=general");

    const button = page
        .locator(`form[action*='locale']:has(input[value='${locale}']) button`)
        .first();

    if (await button.isDisabled()) {
        return;
    }

    await button.click();
    await expect(page).toHaveURL(/section=general/);
}

test.describe("compose localization", () => {
    // Restore English for whatever runs next on this worker's user.
    test.afterEach(async ({ page }) => {
        await useLocale(page, "en");
    });

    test("the compose window is German in a German UI", async ({ page }) => {
        await useLocale(page, "de");

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: /Schreiben|Compose/ }).first().click();

        const dock = page.locator(DOCK);
        await expect(dock.locator(".ts-control").first()).toBeVisible();

        const text = await dock.innerText();

        // The words the report named, in German.
        for (const german of ["Neue Nachricht", "Von", "An", "Betr", "Senden"]) {
            expect(text).toContain(german);
        }

        // And none of the English it used to show beside "Serifenlos".
        for (const english of ["New Message", "Subj", "Write your message"]) {
            expect(text).not.toContain(english);
        }

        // The dock chrome and the formatting toolbar too (I-03).
        for (const name of [
            "Minimieren",
            // ß, not Swiss ss. The compose labels were the only corner of the
            // UI spelling it "vergrössern" — and this list is why it survived
            // an audit: the spec asserted the misspelling, so the catalogue and
            // the test agreed with each other and with nothing else.
            "Fenster vergrößern",
            "Linksbündig ausrichten",
            "Nummerierte Liste",
            "Rückgängig",
            "Einzug vergrößern",
        ]) {
            await expect(
                dock.getByRole("button", { name, exact: true }),
                `the toolbar should offer "${name}"`,
            ).toBeAttached();
        }
    });
});
