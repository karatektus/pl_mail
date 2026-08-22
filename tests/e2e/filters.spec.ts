import { test, expect } from "./support/test";
import { seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";

/**
 * Mail rules, end to end through the settings UI.
 *
 * The rule engine itself is covered by unit tests; what this asserts is the
 * part only a browser can: that the JS-built condition tree survives the round
 * trip into storage and back out into the editor.
 */
const URL = "/settings?section=filters";

// beforeAll, not beforeEach: nothing in this file mutates mail or labels. The
// seeds exist only so the rule editor has an account and a label to point at,
// and every rule is created with a Date.now() name used inside its own test.
// Per-test reseeding was two console round trips a test for state nobody
// touched.
test.beforeAll(() => {
    seed("seed-mail", "seed-label");
});

test.describe("mail filters", () => {
    test("builds, saves and reopens a rule with a nested tree", async ({ page }) => {
        await page.goto(URL);

        const list = page.locator("#settings-filter-list");
        await expect(list).toBeVisible();

        await list.getByRole("link", { name: "New filter" }).click();

        const editor = page.locator("#filter-editor");
        await expect(editor).toBeVisible();

        const name = `E2E Filter ${Date.now()}`;
        await editor.getByLabel("Name").fill(name);

        // The root group starts with one condition; fill it in.
        const firstValue = editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first();
        await firstValue.fill("billing@acme.test");

        // Nest a group, which is the thing a flat form could not express.
        await editor.getByRole("button", { name: "Add group" }).click();
        await expect(
            editor.locator('[data-rules--rule-builder-target="tree"] select'),
        ).toHaveCount(2);

        // The nested group arrives with a blank condition, which is correctly
        // not a usable filter — fill it so the preview can evaluate.
        await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]')
            .nth(1).fill("invoice");

        // An action is required, or the save is rejected.
        await editor.getByRole("button", { name: "Add action" }).click();

        // The live readouts prove the preview endpoint round-tripped.
        await expect(editor.locator('[data-rules--rule-builder-target="summary"]')).toContainText("If");
        await expect(
            editor.locator('[data-rules--rule-builder-target="count"][data-state="ok"]'),
        ).toBeVisible();

        await editor.getByRole("button", { name: "Save" }).click();

        // Saved: the rule is listed and the editor has closed.
        await expect(list.getByText(name)).toBeVisible();
        await expect(page.locator("#filter-editor")).toBeEmpty();

        // Reopening must rebuild the tree from storage, not start blank.
        await page.locator("#settings-filter-list li", { hasText: name })
            .getByRole("link", { name: /^Edit filter/ })
            .click();

        const reopened = page.locator("#filter-editor");
        await expect(reopened.getByLabel("Name")).toHaveValue(name);
        await expect(
            reopened.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first(),
        ).toHaveValue("billing@acme.test");
        await expect(
            reopened.locator('[data-rules--rule-builder-target="tree"] select'),
        ).toHaveCount(2);
    });

    /**
     * Rejected via the *actions* check rather than the name: `required` on the
     * name input means the browser blocks that submission before it is ever
     * sent, so it could never exercise the server's 422 path.
     */
    test("refuses a rule with no actions and keeps the tree", async ({ page }) => {
        await page.goto(URL);

        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator("#filter-editor");
        await editor.getByLabel("Name").fill(`No actions ${Date.now()}`);
        await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first()
            .fill("keep-me@example.test");
        await editor.getByRole("button", { name: "Save" }).click();

        // Rejected, and — the point of the test — the tree survived the round
        // trip rather than resetting to a blank editor.
        await expect(page.locator("#filter-editor")).toContainText("at least one action");
        await expect(
            page.locator('#filter-editor [data-rules--rule-builder-target="tree"] input[data-rule-value]').first(),
        ).toHaveValue("keep-me@example.test");
    });

    test("toggles a rule off and on", async ({ page }) => {
        await page.goto(URL);

        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator("#filter-editor");
        const toggleName = `Toggle me ${Date.now()}`;
        await editor.getByLabel("Name").fill(toggleName);
        await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first().fill("x@example.test");
        await editor.getByRole("button", { name: "Add action" }).click();
        await editor.getByRole("button", { name: "Save" }).click();

        const row = page.locator("#settings-filter-list li", { hasText: toggleName });
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: "Disable filter" }).click();
        await expect(
            page.locator("#settings-filter-list li", { hasText: toggleName }),
        ).toContainText("Off");

        await page.locator("#settings-filter-list li", { hasText: toggleName })
            .getByRole("button", { name: "Enable filter" }).click();
        await expect(
            page.locator("#settings-filter-list li", { hasText: toggleName }),
        ).not.toContainText("Off");
    });

    /**
     * "Apply to existing mail" is queued, not run in the request — and the
     * status it reports has to survive a reload, because the person who
     * started it will close the tab.
     */
    test("queues a run and the status survives a reload", async ({ page }) => {
        await page.goto(URL);

        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator("#filter-editor");
        const name = `Runnable ${Date.now()}`;
        await editor.getByLabel("Name").fill(name);
        await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first().fill("E2E");
        await editor.getByRole("button", { name: "Add action" }).click();
        await editor.getByRole("button", { name: "Save" }).click();

        const row = () => page.locator("#settings-filter-list li", { hasText: name });
        await expect(row()).toBeVisible();

        await row().getByRole("button", { name: "Apply to existing mail" }).click();
        await acceptConfirm(page);

        // Queued state is rendered from the rule row, so it is on the page
        // rather than only in a toast.
        await expect(row()).toContainText(/Queued|Applying|Applied/);

        // The point of the test: it is still there after a full reload.
        await page.reload();
        await expect(row()).toContainText(/Queued|Applying|Applied/);
    });

    /**
     * Order decides which rule wins when one of them stops processing, so it
     * has to persist — and it has to be reachable without a drag, which is
     * what the move buttons are for.
     */
    test("reorders rules and the new order survives a reload", async ({ page }) => {
        const stamp = Date.now();
        const first = `Order A ${stamp}`;
        const second = `Order B ${stamp}`;

        for (const name of [first, second]) {
            await page.goto(URL);
            await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();
            const editor = page.locator("#filter-editor");
            await editor.getByLabel("Name").fill(name);
            await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first().fill("x@example.test");
            await editor.getByRole("button", { name: "Add action" }).click();
            await editor.getByRole("button", { name: "Save" }).click();
            await expect(page.locator("#settings-filter-list").getByText(name)).toBeVisible();
        }

        const names = () =>
            page.locator("#settings-filter-list li[data-rule-id]").allInnerTexts();

        const before = await names();
        const iA = before.findIndex((t) => t.includes(first));
        const iB = before.findIndex((t) => t.includes(second));
        expect(iA).toBeLessThan(iB);

        // Move the second one up via the button, not a drag.
        const reordered = page.waitForResponse(
            (r) => r.url().includes("/settings/filters/reorder") && r.status() === 200,
        );
        await page.locator(`#settings-filter-list li[data-rule-id]`, { hasText: second })
            .getByRole("button", { name: `Move "${second}" earlier` })
            .click();
        await reordered;

        await page.reload();

        const after = await names();
        expect(after.findIndex((t) => t.includes(second)))
            .toBeLessThan(after.findIndex((t) => t.includes(first)));

        // The edge buttons and the position numbers are rearranged in the DOM
        // without a re-render, so they have to be re-evaluated after a move —
        // otherwise the new top row keeps a live "up" that goes nowhere.
        const rows = page.locator("#settings-filter-list li[data-rule-id]");
        await expect(rows.first().locator('[data-direction="up"]')).toBeDisabled();
        await expect(rows.first().locator('[data-direction="down"]')).toBeEnabled();
        await expect(rows.last().locator('[data-direction="down"]')).toBeDisabled();
        await expect(rows.first().locator("[data-rule-position]")).toHaveText("1");

        // Same, without a reload — the states must update as the move happens.
        const moved = page.waitForResponse(
            (r) => r.url().includes("/settings/filters/reorder") && r.status() === 200,
        );
        await rows.first().getByRole("button", { name: /Move .* later/ }).click();
        await moved;

        await expect(rows.first().locator('[data-direction="up"]')).toBeDisabled();
        await expect(rows.first().locator("[data-rule-position]")).toHaveText("1");
        await expect(rows.last().locator('[data-direction="down"]')).toBeDisabled();
    });

    test("shows each rule in plain language", async ({ page }) => {
        await page.goto(URL);

        await page.locator("#settings-filter-list").getByRole("link", { name: "New filter" }).click();

        const editor = page.locator("#filter-editor");
        const name = `Described ${Date.now()}`;
        await editor.getByLabel("Name").fill(name);
        await editor.locator('[data-rules--rule-builder-target="tree"] input[data-rule-value]').first()
            .fill("billing@acme.test");
        await editor.getByRole("button", { name: "Add action" }).click();

        // The editor's live sentence comes from the server, so it must appear
        // even though nothing in the browser builds it.
        await expect(editor.locator('[data-rules--rule-builder-target="summary"]'))
            .toContainText("Subject contains billing@acme.test");

        await editor.getByRole("button", { name: "Save" }).click();

        // And the list words it identically, from the same describer.
        const row = page.locator("#settings-filter-list li[data-rule-id]", { hasText: name });
        await expect(row).toContainText("Subject contains billing@acme.test");

        // The action chip and the sentence must name the SAME label. They read
        // it two different ways, and a Twig merge over integer keys quietly
        // renumbered the chip's lookup so it named whichever label happened to
        // sit at that position.
        const sentence = await row.locator("p").first().innerText();
        const chip = await row.locator("[data-action-label]").first().innerText();
        expect(sentence).toContain(chip);
    });
});
