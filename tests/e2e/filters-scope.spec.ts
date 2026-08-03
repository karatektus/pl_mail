import { test, expect } from "./support/test";

/**
 * A rule with no conditions, which is how "act on everything arriving in this
 * account" is written.
 *
 * Three layers each refused this independently — the editor put the last
 * condition straight back, the validator rejected an empty tree, and the
 * compiler had no case for it — so the browser is the only place the whole
 * path can be checked. The sentence matters as much as the save: it is what
 * somebody reads to decide whether the rule they just wrote is the rule they
 * meant, and it used to claim the whole mailbox whatever the rule was scoped
 * to.
 */

const URL = "/settings?section=filters";
const EDITOR = "#filter-editor";
const TREE = '[data-rules--rule-builder-target="tree"]';

test.describe("filters — scope", () => {
    test("the last condition can be removed, and the panel says what that means", async ({ page }) => {
        await page.goto(URL);
        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator(EDITOR);
        await expect(editor).toBeVisible();

        // The editor opens with one condition to fill in; removing it is the
        // gesture under test.
        await expect(editor.locator(`${TREE} select`)).toHaveCount(1);

        await editor.locator(`${TREE} button[title="Remove condition"]`).first().click();

        await expect(editor.locator(`${TREE} select`)).toHaveCount(0);
        await expect(editor.locator(TREE)).toContainText(/no conditions/i);
    });

    test("the sentence names the account once one is chosen", async ({ page }) => {
        await page.goto(URL);
        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator(EDITOR);
        await editor.getByLabel("Name").fill("Everything here");
        await editor.locator(`${TREE} button[title="Remove condition"]`).first().click();
        await editor.getByRole("button", { name: "Add action" }).click();

        const summary = editor.locator('[data-rules--rule-builder-target="summary"]');
        await expect(summary).toContainText("any message");

        const accounts = editor.locator('select[name="account"]');
        const scoped = await accounts.locator("option").count();

        test.skip(scoped < 2, "no mail account on this fixture user to scope to");

        await accounts.selectOption({ index: 1 });

        // Scoped, the account IS the rule — so it belongs in the sentence,
        // which is what "all mail" used to say instead.
        await expect(summary).toContainText(/any message in \S+/);
    });

    /** And it has to survive the round trip, since that is what will run. */
    test("a rule with no conditions saves and reopens as one", async ({ page }) => {
        const name = `Whole account ${Date.now()}`;

        await page.goto(URL);
        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator(EDITOR);
        await editor.getByLabel("Name").fill(name);
        await editor.locator(`${TREE} button[title="Remove condition"]`).first().click();
        await editor.getByRole("button", { name: "Add action" }).click();
        await editor.getByRole("button", { name: "Save" }).click();

        const row = page.locator("#settings-filter-list").getByText(name);
        await expect(row).toBeVisible();

        // Reopened, it is still conditionless — a rule that quietly grew a
        // condition on save would act on less mail than it was told to.
        await page.locator("#settings-filter-list")
            .locator(`li:has-text("${name}")`)
            .getByRole("link", { name: /^Edit filter/ })
            .click();

        await expect(page.locator(`${EDITOR} ${TREE} select`)).toHaveCount(0);
        await expect(page.locator(`${EDITOR} ${TREE}`)).toContainText(/no conditions/i);

        // Tidy up: the suite is not idempotent, and a rule left behind changes
        // what the next run's list assertions see.
        await page.locator("#settings-filter-list")
            .locator(`li:has-text("${name}")`)
            .getByRole("button", { name: /delete/i })
            .click();
    });
});
