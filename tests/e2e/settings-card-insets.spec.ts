import { test, expect } from "./support/test";

/**
 * A settings card's content does not touch the card's edges.
 *
 * settings/_card.html.twig pads a body only when asked, because most bodies
 * are lists that pad their own rows — so a card whose body does neither gets
 * no inset at all, and nobody notices until somebody looks at it. The Composing
 * card on the General page was exactly that: its labels began a few pixels LEFT
 * of the heading above them, and its segmented controls sat flush against the
 * right border.
 *
 * Measured, not asserted as a class name. There are two legitimate ways to fix
 * it — `padded` on the card, or the partial padding itself — and a card whose
 * rows are deliberately full-bleed with their own inner padding is fine too.
 * What is checked is the only thing that actually matters: no text touches the
 * border.
 */
test("the General cards inset their contents from the card edge", async ({ page }) => {
    await page.goto("/settings?section=general");

    const cards = page.locator("section:has(> header h2)");
    const count = await cards.count();

    expect(count, "the General section rendered no cards at all").toBeGreaterThan(2);

    const offenders = await page.evaluate(() => {
        const found: string[] = [];

        for (const card of document.querySelectorAll("section")) {
            const heading = card.querySelector(":scope > header h2");

            if (null === heading) {
                continue;
            }

            const cardBox = card.getBoundingClientRect();
            const body = card.querySelector(":scope > div:last-of-type");

            if (null === body) {
                continue;
            }

            for (const node of body.querySelectorAll("*")) {
                // Leaves only: a wrapper legitimately spans the card, and its
                // own padding is what holds the text off the edge.
                if (0 < node.children.length) {
                    continue;
                }

                const text = (node.textContent ?? "").trim();

                if ("" === text) {
                    continue;
                }

                const box = node.getBoundingClientRect();

                // Screen-reader labels are parked off-canvas at x = -10000 and
                // are "visible" by every other measure; anything outside the
                // card is not the card's content.
                if (0 === box.width || box.left < cardBox.left || box.right > cardBox.right) {
                    continue;
                }

                const left = Math.round(box.left - cardBox.left);
                const right = Math.round(cardBox.right - box.right);

                if (left < 8 || right < 8) {
                    found.push(
                        `${heading.textContent?.trim()}: "${text.slice(0, 30)}" sits ${Math.min(left, right)}px from the edge`,
                    );

                    break;
                }
            }
        }

        return found;
    });

    expect(offenders, "content is flush against a card border").toEqual([]);
});
