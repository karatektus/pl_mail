import { test, expect } from "./support/test";
import { consoleCommand, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * Translating a message (#713): from the message's ⋮ menu to a translation on
 * a sheet tagged as the AI's, and the switch between it and the original.
 *
 * The two states either side are server-rendered and pinned in
 * MessageTranslationTest: an untranslated message with the offer in its menu,
 * and a stored translation opening as the translation. What only a browser can
 * check is everything between: that the menu reaches the body it names, that
 * the switch appears and names both sides, that the translation replaces the
 * original as it arrives, and that the switch goes both ways.
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
const SWITCH = '[data-testid="translation-switch"]';
const SHEET = '[data-testid="translation-sheet"]';
const TEXT = '[data-testid="translation-text"]';
const TO_ORIGINAL = '[data-testid="translation-show-original"]';
const TO_TRANSLATION = '[data-testid="translation-show-translation"]';
const ORIGINAL = '[data-mail--message-translation-target="original"]';

test.beforeAll(() => {
    consoleCommand("app:test:ai-translation on");
});

// Always put it back: left on, every other message spec has an extra menu entry.
test.afterAll(() => {
    consoleCommand("app:test:ai-translation off");
});

test.describe("message translation", () => {
    test("translates from the menu onto the AI's sheet, and switches to the original and back", async ({ page }) => {
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

        // The switch, its translation half pressed, and the language the model
        // named for the mail once the answer is in.
        await expect(body.locator(SWITCH)).toBeVisible();
        const toOriginal = body.locator(TO_ORIGINAL);
        const toTranslation = body.locator(TO_TRANSLATION);
        await expect(toOriginal).toHaveText("Original · Hebrew");
        await expect(toTranslation).toContainText("· AI");
        await expect(toTranslation).toHaveAttribute("aria-pressed", "true");

        // The translation in place of the original, on the AI's sheet.
        const sheet = body.locator(SHEET);
        await expect(sheet).toBeVisible();
        await expect(sheet).toHaveAttribute("data-tag", "AI translation");
        await expect(sheet).toHaveAttribute("title", /Translated from Hebrew into .* by AI \(e2e-translation-model\)\. This is not the original text\./);
        await expect(sheet).toContainText("The quoted earlier messages were left out");
        await expect(body.locator(TEXT)).toHaveText("Привет, это перевод.");
        await expect(body.locator(ORIGINAL)).toBeHidden();

        // One click to the original…
        await toOriginal.click();

        await expect(body.locator(ORIGINAL)).toBeVisible();
        await expect(sheet).toBeHidden();
        await expect(toOriginal).toHaveAttribute("aria-pressed", "true");
        await expect(toTranslation).toHaveAttribute("aria-pressed", "false");

        // …and one back.
        await toTranslation.click();

        await expect(sheet).toBeVisible();
        await expect(body.locator(ORIGINAL)).toBeHidden();
        await expect(toTranslation).toHaveAttribute("aria-pressed", "true");

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
        await expect(body.locator(SHEET)).toBeHidden();
        await expect(body.locator(SWITCH)).toBeHidden();
    });

    test("a message already in the reader's language says so and stays as it is", async ({ page }) => {
        seed("seed-conversation");

        await page.route(/\/mail\/message\/\d+\/translation$/, (route) =>
            route.fulfill({
                status: 200,
                contentType: "application/x-ndjson",
                body: JSON.stringify({ type: "done", text: "", from: "en", fromLabel: "English", alreadyInTarget: true, partial: false, leavesOutQuote: false }) + "\n",
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

        await expect(body.locator(BAR)).toContainText("This message is already in English. There is nothing to translate.");
        await expect(body.locator(ORIGINAL)).toBeVisible();
        await expect(body.locator(SHEET)).toBeHidden();
        await expect(body.locator(SWITCH)).toBeHidden();
    });
});
