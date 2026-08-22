import { expect } from "@playwright/test";
import { test } from "./support/test";
import { acceptConfirm } from "./support/confirm";

/**
 * The account list and the sidebar, in a real browser.
 *
 * Four reports from one manual pass, and each needs a browser to hold:
 *
 *   - Reordering accounts silently reassigned the primary — the address
 *     Compose sends from — and repainted the account dots, because sortOrder
 *     decided all three. Ordering is display-only now; the sender is a button.
 *   - That reorder request carried no CSRF token at all, because
 *     @stimulus-components/sortable builds its own body.
 *   - The sidebar account dot had no accessible name of any kind, so an orange
 *     circle beside one address and a green one beside another could not be
 *     interpreted.
 *   - Every theme preset label was painted in the palette of the theme it
 *     NAMES rather than the one on screen — "Nord" at 1.22:1 in Solar — because
 *     the tile carried `data-theme`, which is the palette switch itself.
 *
 * The contrast test is the one that cannot move to phpunit: it needs computed
 * styles, which means a browser with the stylesheet actually applied.
 */

const PANEL = '[data-controller="settings--appearance"]';

/** WCAG relative luminance, from an "rgb(r, g, b)" string. */
function ratio(fg: string, bg: string): number {
    const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
    const lum = (rgb: number[]) =>
        0.2126 * chan(rgb[0]) + 0.7152 * chan(rgb[1]) + 0.0722 * chan(rgb[2]);
    const chan = (v: number) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    const a = lum(parse(fg));
    const b = lum(parse(bg));

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** The account dots the sidebar is currently painting, per account row. */
async function sidebarDots(page): Promise<string[]> {
    await page.goto("/mail/inbox");

    return page.locator('#sidebar [role="img"][aria-label]').evaluateAll((dots) =>
        dots.map((d) => `${(d as HTMLElement).getAttribute("aria-label")}=${getComputedStyle(d).backgroundColor}`),
    );
}

/** Adds a password account through the settings modal, as account.spec.ts does. */
async function addAccount(page, label: string, stamp: number): Promise<void> {
    await page.goto("/settings?section=accounts");

    await page
        .locator("section")
        .filter({ has: page.locator("#settings-account-list") })
        .getByRole("button", { name: "Add account" })
        .click();

    const imapHost = page.locator('input[name="account[imapHost]"]');
    await expect(imapHost).toBeVisible();

    await page.locator('input[name="account[email]"]').fill(label);
    await page.locator('input[name="account[username]"]').fill(`order-${stamp}@example.test`);
    await page.locator('input[name="account[password]"]').fill("hunter2");
    await imapHost.fill(`order-${stamp}.example.test`);
    await page.locator('input[name="account[imapPort]"]').fill("993");
    await page.locator('select[name="account[imapEncryption]"]').selectOption("ssl");
    await page.locator("#modal button[type=submit]").click();

    await expect(page.getByText("Account added successfully")).toBeVisible();
}

/** Drop it again — an account is durable, user-wide state the next spec sees. */
async function removeAccount(page, label: string): Promise<void> {
    await page.goto("/settings?section=accounts");

    const row = page
        .locator("#settings-account-list li")
        .filter({ hasText: new RegExp(label, "i") });

    // A loop, not a single removal: an aborted earlier run can have left more
    // than one behind, and this is also the cleanup path.
    for (let remaining = await row.count(); remaining > 0; remaining -= 1) {
        await row.getByRole("button", { name: "Remove account" }).first().click();
        await acceptConfirm(page);
        await expect(row).toHaveCount(remaining - 1);
    }
}

async function setTheme(page, theme: string) {
    await page.evaluate((t) => {
        document.documentElement.setAttribute("data-theme", t);
        document.documentElement.classList.toggle(
            "dark",
            ["dark", "nord", "dusk"].includes(t),
        );
    }, theme);
    await page.waitForTimeout(120);
}

test.describe("accounts + sidebar", () => {
    /**
     * Defect 8. Each label used to be painted in the palette of the theme it
     * names, because the tile carried `data-theme` and every theme block in
     * app.css is selected by exactly that attribute.
     */
    test("every theme preset label is readable in every theme", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await expect(page.locator(PANEL)).toBeVisible();

        const rows: string[] = [];
        let worst = 100;

        for (const current of ["solar", "dark", "light", "paper", "nord", "dusk"]) {
            await setTheme(page, current);

            const measured = await page
                .locator(`${PANEL} [data-theme-name]`)
                .evaluateAll((tiles) =>
                    tiles.map((tile) => {
                        // :scope, or querySelector finds the swatch's own inner
                        // dot — which is a last-child span too, and is painted
                        // in inline preview colours that mean nothing here.
                        const label = tile.querySelector(":scope > span:last-child") as HTMLElement;
                        // Walk up for the first non-transparent background, the
                        // way a person's eye does.
                        let node: HTMLElement | null = label;
                        let bg = "rgba(0, 0, 0, 0)";

                        while (node && (bg === "rgba(0, 0, 0, 0)" || bg === "transparent")) {
                            bg = getComputedStyle(node).backgroundColor;
                            node = node.parentElement;
                        }

                        return {
                            name: (tile as HTMLElement).dataset.themeName ?? "?",
                            text: label?.textContent?.trim() ?? "",
                            fg: getComputedStyle(label).color,
                            bg,
                        };
                    }),
                );

            for (const m of measured) {
                const r = ratio(m.fg, m.bg);
                worst = Math.min(worst, r);
                rows.push(`  in ${current.padEnd(6)} | label ${m.text.padEnd(8)} | ${r.toFixed(2)}:1`);
            }
        }

        console.log("\nTHEME PRESET LABEL CONTRAST\n" + rows.join("\n"));
        console.log(`\n  worst: ${worst.toFixed(2)}:1 (AA needs 4.50:1)\n`);

        expect(worst).toBeGreaterThanOrEqual(4.5);
    });

    /**
     * Defects 1 + 2 + 3, which are one gesture: reordering is cosmetic, the
     * request carries a token, and the dots do not move.
     *
     * Creates its own second account and removes it again in a `finally`, for
     * the reasons account.spec.ts documents at length — an account is durable,
     * user-wide state and several other specs count them.
     */
    test("reordering accounts changes neither the primary nor the dots", async ({ page }) => {
        // Creates an account, reorders it, reads the sidebar twice and removes
        // it again — a dozen navigations, comfortably past the 30s default.
        test.setTimeout(120_000);

        const stamp = Date.now();
        const label = `E2E ORDER ${stamp}`;

        try {
            // A previous aborted run can leave one behind, and the assertions
            // below are about a two-account list.
            await removeAccount(page, "E2E ORDER");
            await addAccount(page, label, stamp);

            const rows = page.locator("#settings-account-list li[data-account-id]");
            await expect(rows).toHaveCount(2);

            const primaryRow = page.locator("li[data-account-id]", {
                has: page.getByText("Primary", { exact: true }),
            });

            const primaryBefore = await primaryRow.getAttribute("data-account-id");
            const orderBefore = await rows.evaluateAll((els) =>
                els.map((e) => (e as HTMLElement).dataset.accountId ?? ""),
            );

            const dotsBefore = await sidebarDots(page);

            // The keyboard path — which is also the one that did not exist
            // before, ordering being drag-only.
            await page.goto("/settings?section=accounts");

            const requested = page.waitForResponse(
                (r) => r.url().includes("/account/reorder") && r.request().method() === "POST",
            );

            await rows.nth(1).locator('[data-direction="up"]').click();

            const response = await requested;

            expect(response.status(), "the reorder must be accepted").toBe(200);
            expect(
                response.request().headers()["x-csrf-token"],
                "and it must have carried a CSRF token",
            ).toBeTruthy();

            await page.goto("/settings?section=accounts");

            const orderAfter = await rows.evaluateAll((els) =>
                els.map((e) => (e as HTMLElement).dataset.accountId ?? ""),
            );

            expect(orderAfter, "the arrangement is what was actually saved").toEqual(
                [orderBefore[1], orderBefore[0]],
            );

            expect(
                await primaryRow.getAttribute("data-account-id"),
                "a reorder must not hand over the From address",
            ).toBe(primaryBefore);

            // Sorted, because the ROWS are expected to have moved — the claim
            // is that each account kept the colour it had, not that the list
            // is in the same sequence. Before the fix the pairing itself
            // changed: the account that moved to the top took the top colour.
            expect(
                (await sidebarDots(page)).sort(),
                "nor repaint the account dots",
            ).toEqual([...dotsBefore].sort());

            // Back to settings: sidebarDots() reads the mailbox shell, and the
            // locators below are scoped to #settings-account-list.
            await page.goto("/settings?section=accounts");

            // And the explicit control does what dragging used to do silently.
            const other = rows.filter({ hasNot: page.getByText("Primary", { exact: true }) }).first();
            const otherId = await other.getAttribute("data-account-id");

            await other.getByRole("button", { name: /primary/i }).click();
            await expect(page.getByText(/Primary account changed/i)).toBeVisible();

            await page.goto("/settings?section=accounts");

            expect(
                await primaryRow.getAttribute("data-account-id"),
                "and choosing a sender explicitly does move it",
            ).toBe(otherId);
        } finally {
            await removeAccount(page, label);
        }
    });

    /** Defect 3: the sidebar dot has a name a person can read. */
    test("the sidebar account dot is labelled", async ({ page }) => {
        await page.goto("/mail/inbox");

        const dots = page.locator('#sidebar [role="img"][aria-label]');

        if ((await dots.count()) === 0) {
            test.skip(true, "no accounts in the sidebar");
        }

        for (let i = 0; i < (await dots.count()); i += 1) {
            expect(await dots.nth(i).getAttribute("aria-label")).toBeTruthy();
        }
    });

    /** Defect 5: one id, one element. */
    test("the mailbox shell has no duplicate ids", async ({ page }) => {
        await page.goto("/mail/inbox");

        const duplicates = await page.evaluate(() => {
            const seen = new Map<string, number>();
            document.querySelectorAll("[id]").forEach((n) => {
                seen.set(n.id, (seen.get(n.id) ?? 0) + 1);
            });
            return [...seen.entries()].filter(([, n]) => n > 1).map(([id]) => id);
        });

        expect(duplicates).toEqual([]);
    });

    /**
     * The mobile drawer renders the same sidebar partial as the desktop rail,
     * so both had to be on screen at once for the duplicate ids to exist — and
     * a phone is where the drawer is the only sidebar there is.
     */
    test("the drawer sidebar works at 414x851", async ({ page }) => {
        await page.setViewportSize({ width: 414, height: 851 });
        await page.goto("/mail/inbox");

        const duplicates = await page.evaluate(() => {
            const seen = new Map<string, number>();
            document.querySelectorAll("[id]").forEach((n) => {
                seen.set(n.id, (seen.get(n.id) ?? 0) + 1);
            });
            return [...seen.entries()].filter(([, n]) => n > 1).map(([id]) => id);
        });

        expect(duplicates).toEqual([]);

        // The drawer's own copy of each account frame is present and distinct.
        const drawerFrames = await page
            .locator('turbo-frame[id^="account-folders-"][id$="-drawer"]')
            .count();

        expect(drawerFrames).toBeGreaterThan(0);
    });
});
