import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * Live results under the search box.
 *
 * The feature is a promise about time: ten conversations, from a keystroke,
 * before the reader finishes the word. That promise is kept in the query (see
 * TypeAheadSearch) and cannot be asserted here — what CAN be asserted is
 * everything the promise is paid for with, and every one of these is a way the
 * preview could quietly become a lie:
 *
 *   - it shows mail, and the row opens that mail
 *   - Enter with nothing chosen still runs the complete search, because the
 *     preview is deliberately not the whole answer
 *   - it stands down for a query it cannot honour, rather than answering a
 *     different question with rows that look identical
 *   - the combobox says what it is showing, since a highlight that exists only
 *     as a background colour is a highlight only some people have
 */

const BOX = 'input[name="q"]';

/** The live rows, which share their popup with recents and operators. */
const RESULTS = '[data-mail--search-target="resultsList"] a[role="option"]';

/** The operator/recent rows above them. */
const SUGGESTIONS = '[data-mail--search-target="recentsList"] button';

async function type(page: Page, value: string): Promise<void> {
    await page.locator(BOX).click();
    await page.locator(BOX).fill(value);
}

/**
 * Type, and wait for the debounced request to have come back with rows.
 *
 * Retried rather than typed once, because the inbox behind this box is a
 * nineteen-thousand-message page and its modules finish loading some time
 * after the navigation does. A keystroke that lands before Stimulus has
 * connected is a keystroke nothing was listening for — a real race, but one
 * that only exists in the first second of a page's life and never while
 * somebody is typing into a box they have been looking at.
 */
async function typeAndWait(page: Page, value: string): Promise<void> {
    await page.locator(BOX).click();

    await expect(async () => {
        await page.locator(BOX).fill("");
        await page.locator(BOX).fill(value);
        await expect(page.locator(RESULTS).first()).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 15_000 });
}

test.describe("live search results", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/mail/inbox");
    });

    test("shows matching mail while the query is still being typed", async ({ page }) => {
        await typeAndWait(page, "E2E");

        await expect(page.locator(RESULTS).filter({ hasText: "E2E Star Me" })).toHaveCount(1);

        // Ten is the cap, and the point of the cap: a list long enough to
        // cover the mail behind it is a list nobody reads.
        expect(await page.locator(RESULTS).count()).toBeLessThanOrEqual(10);
    });

    test("clicking a result opens the conversation that row named", async ({ page }) => {
        await typeAndWait(page, "E2E");

        const row = page.locator(RESULTS).filter({ hasText: "E2E Star Me" }).first();

        // Against the row's own href rather than "some thread": the row could
        // open the right kind of page and the wrong conversation, and that is
        // the failure a suggestion list can least afford.
        const href = await row.getAttribute("href");

        await row.click();

        await expect(page).toHaveURL(new RegExp(`${href}$`));

        // The box empties itself on the way, and that is the rule the box has
        // always followed: it shows the query in the address bar, and the
        // conversation just opened has none. Asserted rather than assumed,
        // because a search box still holding a query over an unfiltered page
        // is the bug that rule was written for.
        await expect(page.locator(BOX)).toHaveValue("");
    });

    /**
     * The preview runs the cheap passes only, so it is not the answer — Enter
     * has to keep reaching the search that is. A preview that swallowed Enter
     * would leave no way to the results it left out.
     */
    test("Enter with nothing highlighted still runs the complete search", async ({ page }) => {
        await typeAndWait(page, "E2E");

        await page.locator(BOX).press("Enter");

        await expect(page).toHaveURL(/\/mail\/search\?q=E2E/);
    });

    test("arrowing onto a result and pressing Enter opens it", async ({ page }) => {
        await typeAndWait(page, "E2E");

        // Down until the highlight is on a live row rather than an operator
        // completion: "E2E" completes no operator today, but a list whose
        // first entry is assumed is a test that breaks when one is added.
        const suggestions = await page.locator(SUGGESTIONS).count();

        for (let i = 0; i <= suggestions; i++) {
            await page.locator(BOX).press("ArrowDown");
        }

        await page.locator(BOX).press("Enter");

        await expect(page).toHaveURL(/\/mail\/thread\/\d+/);
    });

    /**
     * The preview cannot honour operators, so it steps aside rather than
     * showing unfiltered rows under a filtered query — the two are
     * indistinguishable on screen, which is what makes the silent version bad.
     */
    test("a query carrying an operator gets no live results", async ({ page }) => {
        await type(page, "is:unread E2E");

        // Given time to be wrong in: the assertion is that nothing arrives,
        // and an assertion that races the request would pass either way.
        await page.waitForTimeout(600);

        await expect(page.locator(RESULTS)).toHaveCount(0);
    });

    /** Two characters match too much of a mailbox to be worth showing. */
    test("a query shorter than three characters asks for nothing", async ({ page }) => {
        await type(page, "E2");
        await page.waitForTimeout(600);

        await expect(page.locator(RESULTS)).toHaveCount(0);
    });

    test("the combobox reports the list and the row inside it", async ({ page }) => {
        const box = page.locator(BOX);

        await expect(box).toHaveAttribute("aria-expanded", "false");

        await typeAndWait(page, "E2E");
        await expect(box).toHaveAttribute("aria-expanded", "true");
        await expect(box).toHaveAttribute("aria-controls", "search-listbox");

        // Focus stays in the text box, so the highlighted row is only ever
        // announced through aria-activedescendant — it has to name a row that
        // is really there and really marked as the selected one.
        await box.press("ArrowDown");

        const activeId = await box.getAttribute("aria-activedescendant");
        expect(activeId).toBeTruthy();

        const active = page.locator(`#${activeId}`);
        await expect(active).toHaveAttribute("role", "option");
        await expect(active).toHaveAttribute("aria-selected", "true");

        // Dismissed means dismissed, in the accessibility tree as well as on
        // screen: a combobox left claiming to be expanded over a hidden list
        // announces a popup nobody can reach.
        await box.press("Escape");
        await expect(box).toHaveAttribute("aria-expanded", "false");
        await expect(box).not.toHaveAttribute("aria-activedescendant", /.*/);
    });
});
