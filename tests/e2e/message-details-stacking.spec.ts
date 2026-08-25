import { test, expect } from "./support/test";
import { consoleCommand, seed } from "./support/config";

/**
 * The details panel is on top of the conversation, not inside one message of it.
 *
 * ⚠ Mostly CSS and one Stimulus attribute. Run `php bin/console asset-map:compile`
 * after changing anything under assets/, or Playwright reads the previous build.
 *
 * The panel carries z-30 and was still painted over by the message BELOW the
 * one it belongs to. Each message header is `sticky top-0 z-10`, which starts a
 * stacking context, so z-30 only ordered the panel within its own header; the
 * next header carried the same z-10 and won on document order.
 *
 * Which is why this asserts on what is actually on top at a point rather than
 * on a z-index: the CSS was already "higher" and the panel was still covered,
 * so any assertion phrased in z-index would have passed throughout the bug.
 *
 * Needs a conversation — a thread of one has nothing to be covered by — so it
 * seeds the demo mailbox, which carries a four-message one.
 *
 * ⚠ AND THEN PUTS IT BACK. app:test:seed-demo adds a SECOND account,
 * you@example.com, to the shared fixture user, and account-scope.spec.ts
 * asserts on the first account in the sidebar — so leaving it behind fails that
 * spec with "expected E2E Mailbox, received you@example.com", which reads as a
 * regression in account scoping and is not. screenshots.spec.ts carries the
 * same warning and prescribes exactly this cleanup.
 *
 * In afterAll rather than at the end of the test body, because a failing test
 * never reaches its last line — cleanup written inline is cleanup that is
 * skipped precisely when the residue does the most damage, and then poisons the
 * retry as well.
 */
test.beforeAll(() => {
    seed("seed-demo");
});

test.afterAll(() => {
    consoleCommand(`dbal:run-sql "DELETE FROM account WHERE email = 'you@example.com'"`);
});

const DETAILS = '[data-controller="mail--message-details"]';
const PANEL = '[data-mail--message-details-target="panel"]';

test("the details panel is not painted over by the next message", async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.locator("#message-list li").filter({ hasText: "Oak for the alcove" }).first().click();

    // The first message of the conversation, which has messages after it —
    // the last one is the only one open by default and would have nothing
    // below it to be covered by.
    const first = page.locator('[data-controller="mail--thread-message"]').first();
    await first.locator('[data-action*="mail--thread-message#toggle"]').first().click();

    const details = first.locator(DETAILS);
    await details.getByRole("button").first().click();

    const panel = details.locator(PANEL);
    await expect(panel).toBeVisible();

    const box = (await panel.boundingBox())!;

    // Sampled down the whole panel rather than at one point. The header that
    // covered it reaches over the MIDDLE — a single probe near an edge lands in
    // a gap and reports the panel, which is how this test passed against the
    // bug the first time it was written.
    const covering = await page.evaluate(
        ([x, top, height]) => {
            const over: string[] = [];

            for (let step = 0; step <= 10; step += 1) {
                // Inset by a pixel at each end so a border does not answer for
                // the element beyond it.
                const y = top + 1 + ((height - 2) * step) / 10;
                const node = document.elementFromPoint(x, y);

                if (null === node || null !== node.closest('[data-mail--message-details-target="panel"]')) {
                    continue;
                }

                over.push(`${Math.round(y - top)}px: ${(node.textContent ?? "").trim().slice(0, 40) || node.tagName}`);
            }

            return over;
        },
        [box.x + box.width / 2, box.y, box.height],
    );

    expect(covering, "something is painted over the details panel").toEqual([]);
});
