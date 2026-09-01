import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, consoleCommand, mailRow, seed } from "./support/config";

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
const MENU_BUTTON = `${DOCK} button[aria-label="Help me write"]`;
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

test.describe("composer writing help", () => {
    test("says what it is doing once the menu it lived in has closed", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        // Nothing said before anything is asked for.
        await expect(page.locator(STATUS)).toBeHidden();

        await page.locator(MENU_BUTTON).click();

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
        const menu = page.locator(MENU_BUTTON);
        const replyTask = page.getByRole("button", { name: "Draft a reply" });
        const rewriteTask = page.getByRole("button", { name: "Fix spelling and grammar" });

        // ── A new message: no reply task, but the menu is there and populated.
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

        await menu.click();

        await expect(rewriteTask).toBeVisible();
        await expect(replyTask).toHaveCount(0);

        // ── A reply: the task is offered, because now it has a message to read.
        seed("seed-mail");

        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const inlineMenu = page.locator('#compose_inline button[aria-label="Help me write"]');
        await expect(inlineMenu).toBeVisible();
        await inlineMenu.click();

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

        await page.locator(MENU_BUTTON).click();
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
