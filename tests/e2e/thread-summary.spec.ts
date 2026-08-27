import { test, expect } from "./support/test";
import { consoleCommand, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * The summary card does not exist until somebody asks for one.
 *
 * REMINDER: run `php bin/console tailwind:build` and `asset-map:compile` after
 * any class or JS change, or Playwright reads the previous build and a fixed
 * controller still fails.
 *
 * WHY THIS IS A BROWSER TEST
 * ──────────────────────────
 * The two states either side of the click are server-rendered and pinned in
 * ThreadSummaryTest, which asserts on the DOM: no card and an offer beside the
 * subject on a conversation nobody has summarised, a rendered card on one that
 * carries a stored summary. The state BETWEEN them is not renderable at all —
 * the card is parked in a <template> and the controller swaps it in on the
 * first click — so the only place that can be checked is a browser.
 *
 * Three things are actually at stake and each of them was a way for this to be
 * quietly broken:
 *
 *   1. The offer has to survive the card not existing. It is bound to a
 *      controller rooted on the thread's content wrapper; bound to the card, as
 *      it was, the click that creates the card would have nothing listening.
 *   2. The parked card has to arrive complete, with the status line the
 *      controller writes into.
 *   3. Stimulus adopts the arriving targets through a MutationObserver, so
 *      everything written to them in the same task is written to nothing. The
 *      status line being non-empty after the click is what pins that wait.
 *
 * The model host is an address nothing answers on (see app:test:ai-summaries),
 * and the run is ended with Stop rather than by waiting for a refusal: Stop is
 * the one ending that does not depend on how long a dead address takes to give
 * up, and it exercises the same settle path.
 *
 * Lives in the chromium-exclusive project: AiSettings is a singleton with no
 * user column, so switching summaries on puts an offer beside the subject of
 * every multi-message conversation in the suite. Same reason ai-compose.spec.ts
 * is there.
 */
const OFFER = '[data-mail--thread-summary-target="run"]';
const STOP = '[data-mail--thread-summary-target="stop"]';
const STATUS = '[data-mail--thread-summary-target="status"]';
const CARD = "[data-thread-summary]";

test.beforeAll(() => {
    consoleCommand("app:test:ai-summaries on");
});

// Always put it back: left on, this changes what every other thread spec sees.
test.afterAll(() => {
    consoleCommand("app:test:ai-summaries off");
});

test.describe("thread summary", () => {
    test("offers beside the subject, and has no card until the offer is taken", async ({ page }) => {
        seed("seed-conversation");

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto("/mail/inbox");
        await settled(page);

        await mailRow(page, "E2E Conversation").click();

        const offer = page.locator(OFFER);
        await expect(offer).toBeVisible({ timeout: 10_000 });

        // Not a hidden card, not an empty one: no card. The markup is inside a
        // <template>, which is why this locator — querySelectorAll, the same
        // question a browser asks — cannot find it.
        await expect(page.locator(CARD)).toHaveCount(0);

        // On the subject's own line, at the right-hand end of it. Boxes rather
        // than classes: "beside the title" is what was asked for, and the
        // utilities that achieve it are not.
        const subject = page.locator("h1", { hasText: "E2E Conversation" }).first();
        const title = await subject.boundingBox();
        const control = await offer.boundingBox();

        expect(title).not.toBeNull();
        expect(control).not.toBeNull();
        expect(control!.x).toBeGreaterThan(title!.x + title!.width);
        expect(control!.y).toBeLessThan(title!.y + title!.height);

        await offer.click();

        // The card arrives, under the subject rather than wherever the click
        // happened to be, and carries the line the controller speaks through.
        const card = page.locator(CARD);
        await expect(card).toBeVisible();

        const box = await card.boundingBox();

        expect(box).not.toBeNull();
        expect(box!.y).toBeGreaterThan(title!.y + title!.height);

        // Non-empty is the whole point: a live region written to before
        // Stimulus had adopted it would be a visible card with nothing in it.
        const status = page.locator(STATUS);
        await expect(status).toBeVisible();
        await expect(status).not.toBeEmpty();

        // One control, in one place: Stop stands where the offer stood.
        await expect(page.locator(STOP)).toBeVisible();
        await expect(offer).toBeHidden();

        await page.locator(STOP).click();

        await expect(status).toHaveText(/Stopped/i);
        await expect(offer).toBeVisible();
        await expect(page.locator(STOP)).toBeHidden();

        // And the card stays. Somebody who stopped a run is still looking at
        // the surface they asked for, not at it disappearing under them.
        await expect(card).toBeVisible();
    });
});
