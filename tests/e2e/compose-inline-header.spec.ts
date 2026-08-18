import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The inline composer's reduced header.
 *
 * An inline window keeps its address block folded away — you rarely retarget a
 * reply — and what stood in the header was a summary of it: a button reading
 * "to Someone", or "No recipients" when there was nobody yet. On a forward that
 * was exactly backwards. A forward opens with the body already written and the
 * recipient the one thing missing, so the header showed a label where the only
 * field that mattered should have been, and typing an address meant unfolding
 * From, Cc, Bcc and Subject to get at it.
 *
 * The header now carries the real To field, and nothing else: From, Cc, Bcc and
 * Subject stay behind the chevron. See compose/_to_row.html.twig, which the
 * dock renders too — one row, one set of targets, either place.
 */

const INLINE = "#compose_inline";
const FIELDS = `${INLINE} [data-compose--compose-target="fields"]`;
const TO_PANEL = "#compose_inline_toAddresses-ts-dropdown";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openForward(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await page.getByRole("link", { name: "Forward", exact: true }).first().click();
    await expect(page.locator(`${INLINE} .compose-window`)).toBeVisible();
}

/** The To field's own text input, as Tom Select built it. */
function toInput(page: Page) {
    return page.locator(`${INLINE} [data-compose--compose-target="toField"] .ts-control input`);
}

test.describe("the inline composer's reduced header", () => {
    test("a forward opens with a typable To in the header and everything else folded", async ({
        page,
    }) => {
        await openForward(page);

        // The To field is IN the header — the row above the editor, not inside
        // the block the chevron unfolds.
        const header = page.locator(`${INLINE} .compose-window form > div`).first();
        await expect(header.locator('[data-compose--compose-target="toField"]')).toBeVisible();

        await expect(page.locator(FIELDS)).toHaveClass(/hidden/);

        // And the caret is already in it: a forward's first missing piece.
        await expect(toInput(page)).toBeFocused();
    });

    test("what is typed there becomes a real recipient", async ({ page }) => {
        await openForward(page);

        await toInput(page).fill("forward-header@example.test");
        await toInput(page).press("Enter");

        const chips = page
            .locator(`${INLINE} [data-compose--compose-target="toField"] .ts-control`)
            .locator(".item");

        await expect(chips).toHaveCount(1);
        await expect(chips.first()).toContainText("forward-header@example.test");

        // Still reduced: committing an address does not unfold the rest.
        await expect(page.locator(FIELDS)).toHaveClass(/hidden/);
    });

    test("the chevron is what unfolds From, Cc, Bcc and Subject", async ({ page }) => {
        await openForward(page);

        await page.locator(INLINE).getByRole("button", { name: /Show From/ }).click();

        await expect(page.locator(FIELDS)).not.toHaveClass(/hidden/);
        await expect(page.locator(`${INLINE} [data-compose--compose-target="subject"]`)).toBeVisible();
    });

    /**
     * Cc and Bcc are asked for from the To row, which is in the header, while
     * the rows they reveal are in the block below it. Without the unfold they
     * un-hid a row inside something still hidden — a button that did nothing.
     */
    test("Cc from the header unfolds the block it lives in", async ({ page }) => {
        await openForward(page);

        // "Cc" on its face is the accessible name; the title enlarges on it
        // rather than replacing it, which is the point of the pair.
        await page.locator(INLINE).getByRole("button", { name: "Cc", exact: true }).click();

        await expect(page.locator(FIELDS)).not.toHaveClass(/hidden/);
        await expect(page.locator(`${INLINE} [data-compose--compose-target="ccField"]`)).toBeVisible();
    });

    /**
     * The suggestion panel opens on TYPING and on nothing else.
     *
     * Tom Select reopens its panel whenever the field takes focus, and a
     * forward puts the caret in To the moment it opens — so the window used to
     * appear with an empty panel hanging over the body, offering "No results
     * found" about a query nobody had made. `openOnFocus: false` alone did not
     * settle it: it is read at focus time, and the focus can beat the code that
     * sets it. The panel now refuses to open on an empty query.
     */
    test("the suggestion panel stays shut until something is typed", async ({ page }) => {
        await openForward(page);

        // Focused, settled, and still nothing on screen.
        await expect(toInput(page)).toBeFocused();
        await page.waitForTimeout(1_000);
        await expect(page.locator(TO_PANEL)).toBeHidden();

        // Clicking the field is not typing either.
        await toInput(page).click();
        await expect(page.locator(TO_PANEL)).toBeHidden();

        // Typing is.
        await toInput(page).pressSequentially("forward", { delay: 20 });
        await expect(page.locator(TO_PANEL)).toBeVisible();
    });

    /**
     * …and when it does open, it overlays rather than pushes.
     *
     * The dock gives the panel real room — it hangs over the Subject row
     * otherwise, and a click aimed at Subject commits a contact nobody chose.
     * In the header there is no Subject below to cover, only the message body,
     * so the same reservation bought nothing and cost everything: the header
     * grew by the panel's height and the whole editor jumped down the page
     * every time a result arrived or left.
     */
    test("the panel overlays the body rather than growing the window", async ({ page }) => {
        await openForward(page);

        const header = page.locator(`${INLINE} .compose-window form > div`).first();
        const editor = page.locator(`${INLINE} [data-compose--compose-toolbar-target="editor"]`);

        const headerBefore = await header.boundingBox();
        const editorBefore = await editor.boundingBox();

        await toInput(page).pressSequentially("forward", { delay: 20 });
        await expect(page.locator(TO_PANEL)).toBeVisible();

        expect(await header.boundingBox()).toMatchObject({ height: headerBefore!.height });
        expect(await editor.boundingBox()).toMatchObject({ y: editorBefore!.y });

        // Overlaying, and inside the window it belongs to: the dock's panel
        // bleeds 4rem left into its label gutter, which the reduced header does
        // not have — uncorrected, that hung the panel off the window's edge.
        const panel = await page.locator(TO_PANEL).boundingBox();
        const window_ = await page.locator(`${INLINE} .compose-window`).boundingBox();

        expect(panel!.x).toBeGreaterThanOrEqual(window_!.x);
        expect(panel!.x + panel!.width).toBeLessThanOrEqual(window_!.x + window_!.width + 1);
    });
});
