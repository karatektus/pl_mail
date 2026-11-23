import { test, expect } from "./support/test";
import { consoleCommand, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * Translating a message (#713): from the message's ⋮ menu to a translation that
 * says it is one, and the one click between it and the original.
 *
 * The two states either side are server-rendered and pinned in
 * MessageTranslationTest: an untranslated message with the offer in its menu,
 * and a stored translation opening as the translation. What only a browser can
 * check is everything between: that the menu reaches the body it names, that
 * the bar appears with what the page must say about the text under it, that
 * the translation replaces the original as it arrives, and that the toggle
 * goes both ways.
 *
 * The server's frames are served by page.route(), without a model: what is
 * checked is what the page does with an answer, not what a model would say.
 * app:test:ai-translation points the installation at an address nothing
 * answers on.
 *
 * Lives in the chromium-exclusive project: AiSettings is a singleton, and
 * switching translation on puts "Translate" in every message's menu in the
 * suite.
 */
const BAR = '[data-testid="translation-bar"]';
const TEXT = '[data-testid="translation-text"]';
const TOGGLE = '[data-testid="translation-toggle"]';
const ORIGINAL = '[data-mail--message-translation-target="original"]';

test.beforeAll(() => {
    consoleCommand("app:test:ai-translation on");
});

// Always put it back: left on, every other message spec has an extra menu entry.
test.afterAll(() => {
    consoleCommand("app:test:ai-translation off");
});

test.describe("message translation", () => {
    test("translates from the menu, says it is the AI's, and switches to the original and back", async ({ page }) => {
        seed("seed-conversation");

        const errors: string[] = [];
        page.on("pageerror", (error) => errors.push(error.message));

        await page.route(/\/mail\/message\/\d+\/translation$/, (route) =>
            route.fulfill({
                status: 200,
                contentType: "application/x-ndjson",
                body: [
                    { type: "state", value: "waiting" },
                    { type: "ping", elapsed: 12 },
                    { type: "token", text: "Привет, " },
                    { type: "token", text: "это перевод." },
                    { type: "done", text: "Привет, это перевод.", from: "he", fromLabel: "Hebrew", partial: false, leavesOutQuote: true },
                ].map((frame) => JSON.stringify(frame) + "\n").join(""),
            }),
        );

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto("/mail/inbox");
        await settled(page);

        await mailRow(page, "E2E Conversation").click();

        // The newest message, which is the open one.
        const message = page.locator('[id^="thread_message_"]').last();
        const body = message.locator('[data-controller="mail--message-translation"]');

        await expect(body.locator(BAR)).toBeHidden();
        await expect(body.locator(ORIGINAL)).toBeVisible();

        await message.locator('[data-action="click->mail--message-actions#toggle"]').click();
        await message.locator('[data-testid="translate-message"]').click();

        // The bar, saying what the text under it is.
        const bar = body.locator(BAR);
        await expect(bar).toBeVisible();
        await expect(bar).toContainText("by AI");
        await expect(bar).toContainText("This is not the original text.");
        await expect(bar).toContainText("e2e-translation-model");
        await expect(bar).toContainText("The quoted earlier messages were left out");
        // The language the model named for the mail, once the answer is in.
        await expect(bar).toContainText("Translated from Hebrew into");

        // The translation in place of the original.
        await expect(body.locator(TEXT)).toHaveText("Привет, это перевод.");
        await expect(body.locator(TEXT)).toBeVisible();
        await expect(body.locator(ORIGINAL)).toBeHidden();

        // One click to the original…
        const toggle = body.locator(TOGGLE);
        await expect(toggle).toHaveText("Show original");
        await toggle.click();

        await expect(body.locator(ORIGINAL)).toBeVisible();
        await expect(body.locator(TEXT)).toBeHidden();
        await expect(toggle).toHaveText("Show translation");
        await expect(bar).toContainText("The original text.");

        // …and one back, still naming the language.
        await toggle.click();

        await expect(bar).toContainText("Translated from Hebrew into");
        await expect(body.locator(TEXT)).toBeVisible();
        await expect(body.locator(ORIGINAL)).toBeHidden();
        await expect(toggle).toHaveText("Show original");

        expect(errors).toEqual([]);
    });

    test("a refusal says why and leaves the original in place", async ({ page }) => {
        seed("seed-conversation");

        await page.route(/\/mail\/message\/\d+\/translation$/, (route) =>
            route.fulfill({
                status: 200,
                contentType: "application/x-ndjson",
                body: JSON.stringify({ type: "error", kind: "unreachable" }) + "\n",
            }),
        );

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto("/mail/inbox");
        await settled(page);

        await mailRow(page, "E2E Conversation").click();

        const message = page.locator('[id^="thread_message_"]').last();
        const body = message.locator('[data-controller="mail--message-translation"]');

        await message.locator('[data-action="click->mail--message-actions#toggle"]').click();
        await message.locator('[data-testid="translate-message"]').click();

        await expect(body.locator(BAR)).toContainText("The model host could not be reached.");
        await expect(body.locator('[data-mail--message-translation-target="again"]')).toBeVisible();
        await expect(body.locator(ORIGINAL)).toBeVisible();
        await expect(body.locator(TEXT)).toBeHidden();
        await expect(body.locator(TOGGLE)).toBeHidden();
    });
});
