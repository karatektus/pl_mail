import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * The twelve-or-twenty-four-hour setting, honoured by the JAVASCRIPT clocks.
 *
 * Every time Twig prints goes through ClockGlobal and has always followed the
 * setting. The two menus that print a time from the browser did not, and could
 * not: `Intl.DateTimeFormat` asked for an hour and not told which kind falls
 * back to the LOCALE's default. So the send-later presets and the snooze times
 * said "8:00 AM" to a user who had asked for a 24-hour clock, sitting inches
 * from server-rendered timestamps that said 08:00.
 *
 * The setting is now stamped on <html> once per render and read back by
 * assets/clock_format.js. This spec is about the two ends of that wire.
 */

const SEND_OPTIONS = '[data-controller~="compose--schedule"]';

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

/** Choose a clock in Settings. `""` is "follow the interface language". */
async function chooseClock(page: Page, value: "12" | "24" | ""): Promise<void> {
    await page.goto("/settings?section=general");
    await page.locator("#settings-clock").selectOption(value);
    await expect(page.locator("html")).toHaveAttribute(
        "data-clock-hour12",
        value === "12" ? "true" : value === "24" ? "false" : /true|false/,
    );
}

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(
        page.locator('#compose_dock [data-compose--compose-toolbar-target="editor"]'),
    ).toBeVisible();
}

/** The times written onto the send-later presets, as the user reads them. */
async function presetTimes(page: Page): Promise<string[]> {
    await page.locator(`${SEND_OPTIONS}`).getByRole("button", { name: "Send options" }).click();

    const labels = page.locator(`${SEND_OPTIONS} [data-schedule-when]`);
    await expect(labels.first()).not.toBeEmpty();

    return (await labels.allTextContents()).filter((text) => text.trim() !== "");
}

test.describe("the clock setting reaches the browser", () => {
    // The wire itself. Without this attribute there is nothing for the
    // formatters to read, and they go back to guessing from the locale.
    test("is published on <html> for JavaScript to read", async ({ page }) => {
        await chooseClock(page, "24");
        await page.goto("/mail/inbox");
        await expect(page.locator("html")).toHaveAttribute("data-clock-hour12", "false");

        await chooseClock(page, "12");
        await page.goto("/mail/inbox");
        await expect(page.locator("html")).toHaveAttribute("data-clock-hour12", "true");
    });

    test("the send-later presets are drawn in a 24-hour clock when that is the setting", async ({
        page,
    }) => {
        await chooseClock(page, "24");
        await openCompose(page);

        for (const time of await presetTimes(page)) {
            expect(time).toMatch(/\b(08|13):00\b/);
            expect(time).not.toMatch(/[AaPp]\.?[Mm]/);
        }
    });

    test("and in a 12-hour clock when that is", async ({ page }) => {
        await chooseClock(page, "12");
        await openCompose(page);

        for (const time of await presetTimes(page)) {
            expect(time).toMatch(/[AaPp]\.?\s?[Mm]/);
        }
    });

    /**
     * The snooze menu is the other JavaScript clock in the app and had the
     * same bug. Here because the fix is one shared module: a regression in
     * prefersHour12() breaks both, and one spec that only covered compose
     * would report it as fixed.
     */
    test("the snooze menu follows it too", async ({ page }) => {
        await chooseClock(page, "24");
        await page.goto("/mail/inbox");

        // Through the list toolbar, which only appears once something is
        // selected — and which is the surface neither compose nor the thread
        // row owns, so this spec measures the shared module rather than one
        // caller's markup.
        await settled(page);
        await page.locator("[data-thread-select]").first().check({ force: true });
        await page.getByRole("button", { name: "Snooze" }).first().click();

        const times = page.locator("[data-snooze-when]:not(:empty)");
        await expect(times.first()).toBeVisible();

        for (const text of await times.allTextContents()) {
            expect(text).not.toMatch(/[AaPp]\.?\s?[Mm]/);
        }
    });
});
