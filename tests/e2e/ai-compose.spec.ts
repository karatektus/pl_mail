import { test, expect, type Locator, type Page } from "./support/test";
import { INBOX_SUBJECTS, consoleCommand, mailRow, seed } from "./support/config";
import { clearAccountSignature, pressAction, setAccountSignature } from "./support/compose";

/**
 * What the composer says while a language model is thinking.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build and a fixed controller still fails.
 *
 * The reported symptom was "I click the AI button in the composer and nothing
 * happens". The button was fine and the request was running the whole time;
 * the status element lived INSIDE the dropdown, and the same click that starts
 * the request also closes the dropdown — so every word the controller said was
 * written into a subtree that had just been hidden. There was no surface left
 * on screen to say anything.
 *
 * That is invisible to a unit test and invisible to a reading of either file on
 * its own: the controller does say something, the template does have a target,
 * and only the two together with `#close` on the same click produce silence.
 * So it is pinned here, in a browser, by asserting the status is VISIBLE after
 * the menu has gone.
 *
 * The model host is an address nothing answers on (see app:test:ai-writing-help),
 * because the states worth pinning are the ones that were missing — "working"
 * and "it did not answer". Neither needs a GPU.
 *
 * Lives in the chromium-exclusive project: AiSettings is a singleton with no
 * user column, so switching writing help on puts an extra button in every
 * composer in the suite. Same reason integrations.spec.ts is there.
 */
const DOCK = "#compose_dock";
const STATUS = `${DOCK} [data-compose--ai-assist-target="status"]`;
const PROMPT_TOGGLE = `${DOCK} [data-compose--ai-assist-target="promptToggle"]`;
const PROMPT_FIELD = `${DOCK} [data-compose--ai-assist-target="promptField"]`;

test.beforeAll(() => {
    consoleCommand("app:test:ai-writing-help on");
});

// Always put it back: left on, this changes what every other composer spec sees.
test.afterAll(() => {
    consoleCommand("app:test:ai-writing-help off");
});

const EDITOR = '[data-compose--compose-toolbar-target="editor"]';

/**
 * Ask for `task` in `window` and accept `answer`, without the body ever having
 * been clicked or typed in — the untouched body is the case #657 is about.
 *
 * The stream is answered here rather than by a model: what is under test is
 * where the accepted text goes, and the test host has no model anyway.
 */
async function acceptAnswer(page: Page, window: Locator, task: string, answer: string): Promise<void> {
    await page.route("**/compose/assist/stream", (route) => route.fulfill({
        contentType: "application/x-ndjson",
        body: `${JSON.stringify({ type: "token", text: answer })}\n${JSON.stringify({ type: "done", text: answer })}\n`,
    }));

    await pressAction(window, "Help me write");
    await page.getByRole("button", { name: task }).click();
    await window.locator('[data-action="click->compose--ai-assist#accept"]').click();
    await expect(window.locator(EDITOR)).toContainText(answer);
}

test.describe("composer writing help", () => {
    /**
     * #657: an answer accepted into an UNTOUCHED reply went into the quote.
     *
     * The caret was put in the gap before the quoted block, and Chrome resolved
     * that gap into the quote. Send drops the quote before counting, so it said
     * "This message has no text" about a reply the writer could read — and
     * typing more did not help, because the caret stayed in the quote too.
     * Typing one character first hid it, which is why the reply tests that
     * typed something never saw it.
     */
    test("an answer accepted into an untouched reply is the reply, not part of the quote", async ({ page }) => {
        const answer = "Thanks for your message. Tuesday works well for me.";
        const sends: string[] = [];

        seed("seed-mail");

        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const window = page.locator("#compose_inline");
        await expect(window.locator(EDITOR)).toBeVisible();

        await acceptAnswer(page, window, "Draft a reply", answer);

        expect(await window.locator(EDITOR).evaluate((editor) => [...editor.querySelectorAll(
            "[data-quote-wrapped], [data-quoted], blockquote",
        )].some((quote) => quote.textContent?.includes("Tuesday works well")))).toBe(false);

        // Send's own judgement, which is what the writer met: no "no text"
        // question, and the message goes. Aborted on the wire — nothing here
        // is about delivering it.
        await page.route("**/compose/send**", (route) => {
            sends.push(route.request().url());
            return route.abort();
        });

        await window.getByRole("button", { name: "Send", exact: true }).click();

        await expect.poll(() => sends.length).toBe(1);
        await expect(window.locator('[data-compose--compose-target="sendWarning"]')).toBeHidden();
    });

    /**
     * #657's other half: in a new message that opens with a signature, the
     * caret went to the end of the box, after the signature, and Chrome made
     * the answer's lines as copies of the signature block — `data-pl-signature`
     * and all. The answer read as signature to everything that looks for one,
     * and changing From would have swapped it away.
     */
    test("an answer accepted into an untouched new message goes above the signature", async ({ page }) => {
        const answer = "Thanks for your message. Tuesday works well for me.";
        const signature = "Cheers, Robin";

        await setAccountSignature(page, signature);

        try {
            await page.goto("/mail/inbox");
            await page.getByRole("link", { name: "Compose" }).first().click();

            const window = page.locator(DOCK);
            await expect(window.locator(EDITOR)).toBeVisible();

            await acceptAnswer(page, window, "Fix spelling and grammar", answer);

            const blocks = window.locator(`${EDITOR} [data-pl-signature]`);
            await expect(blocks).toHaveCount(1);
            await expect(blocks).toHaveText(signature);

            // Above it, in the writing space the window opened with.
            expect(await window.locator(EDITOR).evaluate((editor) => {
                const text = editor.innerText;

                return text.indexOf("Tuesday works well") < text.indexOf("Cheers, Robin");
            })).toBe(true);
        } finally {
            await clearAccountSignature(page);
        }
    });

    test("says what it is doing once the menu it lived in has closed", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        // Nothing said before anything is asked for.
        await expect(page.locator(STATUS)).toBeHidden();

        await pressAction(page.locator(DOCK), "Help me write");

        // A REWRITING task, not "Draft a reply": this is a new message, and the
        // reply task is deliberately not offered here — see the test below.
        const task = page.getByRole("button", { name: "Fix spelling and grammar" });
        await expect(task).toBeVisible();
        await task.click();

        // The menu goes, as it should. The status must not go with it — this is
        // the whole regression.
        await expect(task).toBeHidden();

        const status = page.locator(STATUS);
        await expect(status).toBeVisible();
        await expect(status).not.toBeEmpty();

        // And it resolves into a plain sentence rather than sitting on "Writing…"
        // for ever, because the host does not answer.
        await expect(status).toHaveText(/gave no answer|could not be sent/i, { timeout: 30_000 });
    });

    /**
     * "Draft a reply" is only offered where there is something to reply TO.
     *
     * A new message has no message behind it, so the task had nothing to work
     * from and the server refused it — the option was always there and never
     * worked, which reads as a broken feature rather than an inapplicable one.
     *
     * Both halves are asserted in one test on purpose. Either alone passes for
     * the wrong reason: "absent on a new message" would pass if the menu never
     * rendered at all, and "present on a reply" would pass if the task were
     * simply always there. The pair is the claim.
     */
    test("offers to draft a reply only when there is something to reply to", async ({ page }) => {
        const replyTask = page.getByRole("button", { name: "Draft a reply" });
        const rewriteTask = page.getByRole("button", { name: "Fix spelling and grammar" });

        // ── A new message: no reply task, but the menu is there and populated.
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        await pressAction(page.locator(DOCK), "Help me write");

        await expect(rewriteTask).toBeVisible();
        await expect(replyTask).toHaveCount(0);

        // ── A reply: the task is offered, because now it has a message to read.
        seed("seed-mail");

        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        await expect(page.locator('#compose_inline [aria-label="Help me write"]').first()).toBeAttached();
        await pressAction(page.locator("#compose_inline"), "Help me write");

        await expect(page.getByRole("button", { name: "Draft a reply" })).toBeVisible();
    });

    /**
     * The standing instruction can be changed in the composer, for one attempt.
     *
     * Asserted on the REQUEST BODY, which is the only place the claim is
     * legible. What the box does to the draft is a model's business and this
     * host answers nothing; what has to be true is that the field is absent
     * until somebody opens the box and carries their text afterwards — and that
     * distinction is invisible from the page, because both cases look like a
     * request that failed.
     *
     * Absence is half the test and the more important half. The server reads
     * presence, not emptiness (see ComposeAssistController::note()), so a
     * composer that always sent the field would make "no standing instruction
     * for this attempt" unsayable — and would silently overwrite the saved note
     * with a prefilled copy of itself for every writer who never opened the box.
     */
    test("the standing instruction is editable for one attempt, and absent until it is", async ({ page }) => {
        const bodies: string[] = [];

        await page.route("**/compose/assist/stream", async (route) => {
            bodies.push(route.request().postData() ?? "");
            await route.continue();
        });

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        await pressAction(page.locator(DOCK), "Help me write");
        await page.getByRole("button", { name: "Fix spelling and grammar" }).click();

        await expect(page.locator(STATUS)).toBeVisible();
        expect(bodies).toHaveLength(1);
        expect(new URLSearchParams(bodies[0]).has("systemPrompt")).toBe(false);

        // The box is reachable while the panel is open — including after this
        // request has failed, which is when somebody actually wants it.
        const toggle = page.locator(PROMPT_TOGGLE);
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute("aria-expanded", "false");

        await toggle.click();
        await expect(toggle).toHaveAttribute("aria-expanded", "true");

        const field = page.locator(PROMPT_FIELD);
        await expect(field).toBeVisible();
        await field.fill("Answer in exactly one sentence.");

        await page.getByRole("button", { name: "Try again with this" }).click();

        await expect.poll(() => bodies.length).toBe(2);

        // Parsed rather than string-matched: this is a form body, so the spaces
        // are `+` and a `toContain` on the readable sentence fails against a
        // request that is perfectly correct.
        expect(new URLSearchParams(bodies[1]).get("systemPrompt")).toBe("Answer in exactly one sentence.");
    });
});
