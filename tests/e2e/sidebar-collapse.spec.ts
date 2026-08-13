import { test, expect, type Page } from "./support/test";

/**
 * The sidebar's LABELS and ACCOUNTS sections collapse, and the state is the
 * user's rather than the browser's.
 *
 * Modelled on admin-panels.spec.ts, which is the same feature one screen over,
 * and the assertion that matters is the same one: after a reload the section
 * must come back ALREADY collapsed. A toggle that only works until the next
 * navigation is the bug this replaced — the trees used to be remembered in
 * localStorage and re-applied on connect, so a collapsed section was rendered
 * open and snapped shut a frame later, on every single click.
 *
 * Runs authenticated as this worker's own user, signed in by the worker fixture
 * in support/test.ts.
 */

const DESKTOP = { width: 1280, height: 900 };
const MOBILE = { width: 414, height: 851 };

const LABELS = "section:labels";
const ACCOUNTS = "section:accounts";

/**
 * The desktop rail's copy. Both sidebars render the same partial with the same
 * keys, so every locator here has to say WHICH one it means — `#sidebar` is the
 * inline column, `#sidebar-drawer-inner` the overlay.
 */
const section = (page: Page, key: string, root = "#sidebar") =>
    page.locator(`${root} details[data-collapse-key="${key}"]`);

/** Toggle, waiting on the persist rather than racing the next navigation. */
async function toggle(page: Page, key: string, root = "#sidebar") {
    const persisted = page.waitForResponse(
        (r) =>
            r.url().includes("/mail/sidebar/section-collapsed") &&
            r.status() === 200,
    );

    await section(page, key, root).locator("> summary").click();
    await persisted;
}

/**
 * Put every section back. This spec writes a per-user preference that outlives
 * the test, and leaving a worker's user with its labels collapsed would be a
 * booby trap for every other spec that looks in the sidebar.
 */
async function expandAll(page: Page) {
    for (const key of [LABELS, ACCOUNTS]) {
        await page.request.post("/mail/sidebar/section-collapsed", {
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": await csrf(page),
            },
            data: { key, collapsed: false },
        });
    }
}

async function csrf(page: Page): Promise<string> {
    return page.evaluate(
        () =>
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute("content") ?? "",
    );
}

test.beforeEach(async ({ page }) => {
    await page.goto("/mail/inbox");
    await expandAll(page);
});

test.afterEach(async ({ page }) => {
    await page.goto("/mail/inbox");
    await expandAll(page);
});

test.describe("sidebar section collapse (desktop)", () => {
    test.use({ viewport: DESKTOP });

    test("collapses labels and is still collapsed after a reload", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        await expect(section(page, LABELS)).toHaveAttribute("open", "");

        await toggle(page, LABELS);
        await expect(section(page, LABELS)).not.toHaveAttribute("open", "");

        // The real assertion: server-rendered collapsed on a fresh load, so
        // there is no frame in which the labels are visible.
        await page.reload();
        await expect(section(page, LABELS)).not.toHaveAttribute("open", "");

        // Per section — collapsing labels leaves the accounts alone.
        await expect(section(page, ACCOUNTS)).toHaveAttribute("open", "");

        // And it round-trips.
        await toggle(page, LABELS);
        await page.reload();
        await expect(section(page, LABELS)).toHaveAttribute("open", "");
    });

    test("survives a Turbo navigation, not just a reload", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page, LABELS);

        // A real in-app click rather than page.goto — the sidebar is
        // re-rendered per visit, which is exactly when the old localStorage
        // version snapped shut after paint.
        await page.locator("#sidebar a[href='/mail/sent']").first().click();
        await expect(page).toHaveURL(/\/mail\/sent/);

        await expect(section(page, LABELS)).not.toHaveAttribute("open", "");
    });

    test("the collapsed heading carries the unread roll-up, and shows it only when shut", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const badge = page.locator(
            "#sidebar [data-count-key='labels:unread']",
        );

        // Rendered either way — hidden rather than omitted, so refreshCounts()
        // always has something to patch and a reopened section is never stale.
        await expect(badge).toHaveCount(1);
        await expect(badge).toBeHidden();

        await toggle(page, LABELS);

        // Visible now, unless there is simply nothing unread under a label —
        // the badge hides itself at zero like every other badge here.
        const count = Number((await badge.textContent())?.trim() ?? "0");

        if (count > 0) {
            await expect(badge).toBeVisible();
        }
    });

    test("keyboard: the summary is focusable and Enter toggles it", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const summary = section(page, LABELS).locator("> summary");

        await summary.focus();
        await expect(summary).toBeFocused();

        // Native <details> semantics, which is why this file adds no key
        // handling of its own — the accessible name comes from the heading and
        // the expanded state from the element.
        await expect(summary).toHaveAttribute("aria-label", /labels/i);
        await expect(summary).toHaveAttribute("aria-expanded", "true");

        const persisted = page.waitForResponse((r) =>
            r.url().includes("/mail/sidebar/section-collapsed"),
        );
        await page.keyboard.press("Enter");
        await persisted;

        await expect(section(page, LABELS)).not.toHaveAttribute("open", "");
        await expect(summary).toHaveAttribute("aria-expanded", "false");
    });
});

test.describe("sidebar section collapse (mobile drawer)", () => {
    test.use({ viewport: MOBILE });

    test("the drawer renders the same state and toggles it too", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        await page
            .getByRole("button", { name: /show or hide the sidebar/i })
            .first()
            .click();

        const drawer = "#sidebar-drawer-inner";

        await expect(section(page, LABELS, drawer)).toBeVisible();
        await expect(section(page, LABELS, drawer)).toHaveAttribute("open", "");

        await toggle(page, LABELS, drawer);
        await expect(section(page, LABELS, drawer)).not.toHaveAttribute(
            "open",
            "",
        );

        // The same preference, so the desktop copy on the same page agrees
        // rather than waiting for the next render to find out.
        await expect(section(page, LABELS)).not.toHaveAttribute("open", "");

        await page.reload();
        await page
            .getByRole("button", { name: /show or hide the sidebar/i })
            .first()
            .click();

        await expect(section(page, LABELS, drawer)).not.toHaveAttribute(
            "open",
            "",
        );
    });

    test("the two sidebars do not share an element id", async ({ page }) => {
        await page.goto("/mail/inbox");

        // Both copies carry the same collapse KEY on purpose — it is one
        // preference — but ids must stay unique, which is why the disclosures
        // are keyed by a data attribute and have no id of their own.
        const ids = await page.evaluate(() => {
            const seen = new Set<string>();
            const duplicates: string[] = [];

            document.querySelectorAll("[id]").forEach((el) => {
                if (seen.has(el.id)) {
                    duplicates.push(el.id);
                }
                seen.add(el.id);
            });

            return duplicates;
        });

        expect(ids).toEqual([]);
    });
});
