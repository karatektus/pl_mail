import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * The search box, which had no browser test at all.
 *
 * Two things are being checked and they are different. The dropdown is a
 * keyboard-driven overlay over an input people type into fast — the failure
 * modes are Enter submitting a half-typed operator, Escape losing a query, and
 * a suggestion inserted over the wrong part of the string. And the search
 * itself has to answer the same way the parser promised: an operator it cannot
 * honour becomes text rather than being dropped, which is the difference
 * between "no results" and "your whole mailbox".
 */

const BOX = 'input[name="q"]';

/** The suggestion list, which shares its dropdown with recent searches. */
const OPTIONS = '[data-mail--search-target="recentsList"] button';

async function type(page: Page, value: string): Promise<void> {
    await page.locator(BOX).click();
    await page.locator(BOX).fill(value);
}

/**
 * Type, and wait for the list to actually be there.
 *
 * Suggestions are debounced, so a key pressed straight after typing arrives
 * while there is nothing to highlight — which is a real thing users do, and
 * exactly what the controller's early return handles, but not what these
 * tests are about.
 */
async function typeAndWait(page: Page, value: string): Promise<void> {
    await type(page, value);
    await expect(page.locator(OPTIONS).first()).toBeVisible();
}

test.describe("search", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/mail/inbox");
    });

    test("suggests the operators it understands, narrowing as you type", async ({ page }) => {
        await type(page, "fr");

        await expect(page.locator(OPTIONS).first()).toBeVisible();
        await expect(page.locator(OPTIONS)).toHaveCount(1);
        await expect(page.locator(OPTIONS).first()).toContainText("from:");

        // Widening the prefix widens the list rather than freezing it.
        await type(page, "is:");
        await expect(page.locator(OPTIONS).first()).toContainText("is:");
        expect(await page.locator(OPTIONS).count()).toBeGreaterThan(1);
    });

    test("Tab completes the highlighted suggestion into the box", async ({ page }) => {
        await typeAndWait(page, "sub");

        await page.locator(BOX).press("ArrowDown");
        await page.locator(BOX).press("Tab");

        await expect(page.locator(BOX)).toHaveValue("subject:");
    });

    /**
     * Enter means "take the highlighted suggestion" while the list is open.
     * Submitting instead would search for the half-typed operator, which is
     * both wrong and hard to undo — the page has already navigated.
     */
    test("Enter takes the highlighted suggestion rather than submitting", async ({ page }) => {
        await typeAndWait(page, "is:un");

        await page.locator(BOX).press("ArrowDown");
        await page.locator(BOX).press("Enter");

        await expect(page).not.toHaveURL(/\/mail\/search/);
        await expect(page.locator(BOX)).toHaveValue("is:unread ");
    });

    /** …and with nothing highlighted it does submit, which is the normal case. */
    test("Enter with no suggestion chosen searches", async ({ page }) => {
        await type(page, "E2E Star Me");
        await page.locator(BOX).press("Enter");

        await expect(page).toHaveURL(/\/mail\/search\?q=/);
        await expect(page.locator("#message-list li").first()).toBeVisible();
    });

    /**
     * The first Escape dismisses the list, the second clears the box. Losing a
     * typed query to a keypress meant for the dropdown is the thing this
     * ordering exists to prevent.
     */
    test("Escape closes the list before it clears the query", async ({ page }) => {
        await typeAndWait(page, "fro");

        // Hidden rather than emptied: the controller toggles the dropdown's
        // class and leaves the rendered list alone, so counting the buttons
        // would find them either way.
        await page.locator(BOX).press("Escape");
        await expect(page.locator(OPTIONS).first()).toBeHidden();
        await expect(page.locator(BOX)).toHaveValue("fro");

        await page.locator(BOX).press("Escape");
        await expect(page.locator(BOX)).toHaveValue("");
    });

    test("a completed search is offered again as a recent one", async ({ page }) => {
        await type(page, "E2E Read Me");
        await page.locator(BOX).press("Enter");
        await expect(page).toHaveURL(/\/mail\/search/);

        await page.goto("/mail/inbox");
        await page.locator(BOX).click();

        await expect(page.locator(OPTIONS).filter({ hasText: "E2E Read Me" })).toHaveCount(1);
    });

    test("from: finds the seeded sender and reports how many", async ({ page }) => {
        await type(page, "from:sender@e2e.test");
        await page.locator(BOX).press("Enter");

        await expect(page.locator("#message-list li").first()).toBeVisible();
        await expect(page.getByText(/\d+ results?/)).toBeVisible();
    });

    /**
     * The regression the parser was rewritten for: an operator that cannot be
     * honoured must not be dropped, because a dropped filter answers with
     * everything and looks exactly like a search that worked.
     */
    test("an operator it cannot honour finds nothing rather than everything", async ({ page }) => {
        await type(page, "is:important");
        await page.locator(BOX).press("Enter");

        await expect(page.getByText(/No results/i)).toBeVisible();
    });

    /** And a half-typed one is not a search for the whole mailbox either. */
    test("a bare operator searches nothing at all", async ({ page }) => {
        // No dropdown to dismiss: "from:" suggests contacts, and there is
        // nothing to match a contact against yet — so Enter submits.
        await type(page, "from:");
        await expect(page.locator(OPTIONS).first()).toBeHidden();

        await page.locator(BOX).press("Enter");

        await expect(page.getByText(/No results/i)).toBeVisible();
    });

    test("the parsed filters are shown back as pills", async ({ page }) => {
        await type(page, "is:unread from:sender@e2e.test");
        await page.locator(BOX).press("Enter");

        await expect(page.getByText("From:", { exact: false }).first()).toBeVisible();
        await expect(page.getByText("Is:", { exact: false }).first()).toBeVisible();
    });
});
