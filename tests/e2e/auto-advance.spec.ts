import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { saveSetting } from "./support/settings";

/**
 * Auto-advance: what the reading pane shows after the conversation in it has
 * left the list. Back to the list is the default; the one below or the one
 * above is a choice under Settings → General → Layout & controls.
 *
 * Archived with the pane's own button rather than `e`, because the button is
 * what every way of archiving presses, and the setting is not a keyboard one.
 */
const ROWS = '#message-list li[data-controller="mail--message-row"]';
const GROUP = "Afterwards, show";

type Choice = "The list" | "The one below" | "The one above";

const reading = (page: Page) => page.locator('[data-mail--mail-pane-target="reading"]');
const showing = (page: Page, id: string) =>
    page.locator(`[data-mail--message-actions-entity-type-value="thread"][data-mail--message-actions-entity-id-value="${id}"]`);

async function choose(page: Page, choice: Choice): Promise<void> {
    await page.goto("/settings/layout");

    const radio = () => page.getByRole("radiogroup", { name: GROUP }).getByRole("radio", { name: choice, exact: true });

    if (false === (await radio().isChecked())) {
        await saveSetting(page, () => page.getByRole("radiogroup", { name: GROUP }).getByText(choice, { exact: true }).click());
    }

    await expect(radio()).toBeChecked();
}

/** Opens the conversation that gets archived, and says which rows were either side of it. */
async function openTheOneToArchive(page: Page): Promise<{ above: string; below: string }> {
    await page.goto("/mail/inbox");
    await expect(page.locator(ROWS).first()).toBeVisible();

    const ids = await page.locator(ROWS).evaluateAll((rows) =>
        rows.map((row) => row.getAttribute("data-mail--message-row-id-value") ?? ""));
    const at = ids.indexOf((await mailRow(page, INBOX_SUBJECTS.archive).getAttribute("data-mail--message-row-id-value")) ?? "");

    // A row on each side, or "the one above" and "back to the list" could not be told apart.
    expect(at).toBeGreaterThan(0);
    expect(at).toBeLessThan(ids.length - 1);

    await mailRow(page, INBOX_SUBJECTS.archive).click();
    await expect(reading(page)).toBeVisible();

    return { above: ids[at - 1]!, below: ids[at + 1]! };
}

async function archiveTheOpenOne(page: Page): Promise<void> {
    await reading(page).getByRole("button", { name: "Archive", exact: true }).locator("visible=true").click();
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
}

test.beforeEach(() => {
    seed("seed-mail");
});

test.afterEach(async ({ page }) => {
    await choose(page, "The list");
});

test.describe("auto-advance", () => {
    test("nobody who has not chosen is moved on: archiving goes back to the list", async ({ page }) => {
        await page.goto("/settings/layout");
        await expect(page.getByRole("radiogroup", { name: GROUP }).getByRole("radio", { name: "The list", exact: true })).toBeChecked();

        await openTheOneToArchive(page);
        await archiveTheOpenOne(page);

        await expect(reading(page)).toBeHidden();
        await expect(page).toHaveURL(/\/mail\/inbox/);
    });

    test("the one below opens in place of the one archived", async ({ page }) => {
        await choose(page, "The one below");

        const { below } = await openTheOneToArchive(page);
        await archiveTheOpenOne(page);

        await expect(reading(page)).toBeVisible();
        await expect(showing(page, below)).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/(thread|message)\//);
        await expect(page.locator(`${ROWS}[data-selected]`)).toHaveAttribute("data-mail--message-row-id-value", below);
    });

    test("the one above opens when that is the choice", async ({ page }) => {
        await choose(page, "The one above");

        const { above } = await openTheOneToArchive(page);
        await archiveTheOpenOne(page);

        await expect(showing(page, above)).toBeVisible();
    });

    test("Back skips the conversation that left and returns to the list", async ({ page }) => {
        await choose(page, "The one below");

        const { below } = await openTheOneToArchive(page);
        await archiveTheOpenOne(page);
        await expect(showing(page, below)).toBeVisible();

        await page.goBack();

        await expect(reading(page)).toBeHidden();
        await expect(page).toHaveURL(/\/mail\/inbox/);
    });
});
