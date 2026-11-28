import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand } from "./support/config";

/**
 * The insight strip above the mail list keeps up with `insights.changed`
 * without a reload, in the two cases where it used to need one:
 *
 *  - it loaded EMPTY. The controller was mounted on the band, which only
 *    renders when there are rows, so nothing was listening.
 *  - it was waved away. The dismissal removed the frame itself, so a newer
 *    insight, which the server would show, had nowhere to load into.
 *
 * Insights are written straight into the database rather than extracted from
 * seeded mail: what is under test is the page reacting to the nudge, not the
 * extractor. The nudge is the DOM event core--mercure dispatches for an
 * `insights.changed` message, dispatched on <body> exactly as it does. The hub
 * delivering it is mercure.spec.ts's business.
 */

/** This worker's seeded account, as account-health.spec.ts scopes it. */
const ACCOUNT_OF_THIS_WORKER =
    `(SELECT a.id FROM account a JOIN \\"user\\" u ON u.id = a.usr_id WHERE u.email = '${TEST_USER.email}' ORDER BY a.id LIMIT 1)`;

/**
 * No insights, and no "Hide this strip" on record: a dismissal stamped by one
 * test would otherwise hide every insight the next one lands before it.
 */
function clearInsights(): void {
    consoleCommand(`dbal:run-sql "DELETE FROM mail_insight WHERE account_id = ${ACCOUNT_OF_THIS_WORKER}"`);
    consoleCommand(
        `dbal:run-sql "UPDATE \\"user\\" SET settings = (settings::jsonb - 'insights.pane_dismissed_at')::json WHERE email = '${TEST_USER.email}'"`,
    );
}

/**
 * A parcel arriving tomorrow, created `createdOffset` seconds from now. A
 * positive offset keeps it strictly newer than a dismissal stamped this
 * second (the column holds whole seconds); a negative one keeps it older.
 */
function landParcel(title: string, key: string, createdOffset = 0): void {
    const created = `(now() AT TIME ZONE 'UTC') + interval '${createdOffset} seconds'`;

    consoleCommand(
        `dbal:run-sql "INSERT INTO mail_insight (kind, title, payload, happens_at, extractor, dedupe_key, created_at, updated_at, account_id)`
        + ` VALUES ('parcel', '${title}', '{}', (now() AT TIME ZONE 'UTC') + interval '1 day', 'e2e', '${key}', ${created}, ${created}, ${ACCOUNT_OF_THIS_WORKER})"`,
    );
}

async function nudge(page: Page): Promise<void> {
    await page.evaluate(() => {
        document.body.dispatchEvent(new CustomEvent("core--mercure:insights-changed", { bubbles: true }));
    });
}

test.describe("insight strip, live", () => {
    test.beforeEach(() => clearInsights());
    test.afterAll(() => clearInsights());

    test("a strip that loaded empty shows an insight found afterwards", async ({ page }) => {
        await page.goto("/mail/inbox");

        const frame = page.locator("turbo-frame#insight_pane");
        await expect(frame).toHaveAttribute("complete", "");
        await expect(frame.locator("[data-insight-pane]")).toHaveCount(0);

        landParcel("E2E parcel found later", `e2e-late-${Date.now()}`);
        await nudge(page);

        await expect(frame.locator("[data-insight-card]")).toHaveText(/E2E parcel found later/);
    });

    test("a newer insight brings a dismissed strip back", async ({ page }) => {
        landParcel("E2E parcel before", `e2e-before-${Date.now()}`, -60);
        await page.goto("/mail/inbox");

        const frame = page.locator("turbo-frame#insight_pane");
        await expect(frame.locator("[data-insight-card]")).toHaveText(/E2E parcel before/);

        await frame.locator("[data-insight-pane-dismiss]").click();
        await expect(frame.locator("[data-insight-pane]")).toHaveCount(0);

        // The frame stays, and stays the strip's: the next nudge has
        // somewhere to load into.
        await expect(frame).toHaveCount(1);
        await expect(frame).toHaveAttribute("data-controller", "insight--pane");

        // A nudge with nothing new does not bring the dismissed one back.
        await nudge(page);
        await expect(frame).toHaveAttribute("complete", "");
        await expect(frame.locator("[data-insight-pane]")).toHaveCount(0);

        landParcel("E2E parcel after", `e2e-after-${Date.now()}`, 5);
        await nudge(page);

        await expect(frame.locator("[data-insight-card]").filter({ hasText: "E2E parcel after" })).toBeVisible();
    });

    test("dismissing the last card leaves the frame listening", async ({ page }) => {
        landParcel("E2E only parcel", `e2e-only-${Date.now()}`, -60);
        await page.goto("/mail/inbox");

        const frame = page.locator("turbo-frame#insight_pane");
        const card = frame.locator("[data-insight-card]");
        await expect(card).toHaveText(/E2E only parcel/);

        await card.getByRole("button", { name: "More actions", exact: true }).click();
        await card.locator("[data-insight-dismiss]").click();
        await expect(frame.locator("[data-insight-pane]")).toHaveCount(0);
        await expect(frame).toHaveCount(1);

        landParcel("E2E next parcel", `e2e-next-${Date.now()}`, 5);
        await nudge(page);

        await expect(frame.locator("[data-insight-card]")).toHaveText(/E2E next parcel/);
    });
});
