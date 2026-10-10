import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow } from "./support/config";

/**
 * The reading pane beside the message list.
 *
 * Off is how plMail always worked — the list and the open message take turns on
 * the same space — and it stays the default. Right puts the message beside the
 * list when the mail card has room for both, and the divider between them moves
 * a share, in percent of that card, that the server remembers.
 *
 * What is measured here is what a person would notice, not the attribute the
 * layout is drawn from: whether the list is still on screen after a click,
 * whether the divider is there, how wide the message actually is as a fraction
 * of the card. The attribute is the unit tests' business.
 *
 * Three viewports, and the reason is the card. It is the MAIL CARD's width that
 * decides, not the window's: at 1920 it is comfortably over the threshold
 * (52rem), at 1000 the sidebar leaves it under, and at 1280 it is wide enough
 * until a calendar is docked beside it, which takes it under again. That last
 * case is the one the container query exists for — a window that looks wide
 * and a card that is not.
 *
 * Each test sets the calendar explicitly rather than trusting the default,
 * because the default for a new user is a docked calendar and whether it is on
 * screen at first paint depends on what the page restored.
 *
 * Every test puts the mode back to Off on the way out. The account is shared
 * with the rest of the suite, and a pane left beside the list is a layout
 * change in a spec that never asked for one.
 */

const WIDE = { width: 1920, height: 1000 };

/** Wide enough that the sidebar is drawn and the card is still under 52rem. */
const NARROW = { width: 1000, height: 800 };

/** Above the phone layout, but with the calendar docked the mail card is under 52rem. */
const MIDDLE = { width: 1280, height: 800 };

/** User::READING_PANE_DEFAULT_PCT, and the range around it. */
const DEFAULT_PCT = 55;

const card = (page: Page) => page.locator("[data-reading-pane]");
const list = (page: Page) => page.locator("#message-list");
const reading = (page: Page) => page.locator('[data-mail--mail-pane-target="reading"]');
const placeholder = (page: Page) => page.locator("[data-reading-placeholder]");
const handle = (page: Page) => page.locator("[data-reading-handle]");

/**
 * The mode and share, set the way the controls do: a POST with the token the
 * page renders. Read off the settings page because that is the only place the
 * real one comes from.
 */
const store = async (page: Page, fields: Record<string, string>) => {
    const html = await (await page.request.get("/settings?section=appearance")).text();
    const token = /data-settings--reading-pane-token-value="([^"]*)"/.exec(html)?.[1] ?? "";

    const response = await page.request.post("/settings/appearance/reading-pane", {
        form: { _token: token, ...fields },
    });

    expect(response.ok(), "the endpoint refused the write").toBe(true);
};

/** The message pane's share of the mail card, as the screen draws it. */
const share = async (page: Page) => {
    const cardBox = (await card(page).boundingBox())!;
    const paneBox = (await reading(page).boundingBox())!;

    return (paneBox.width / cardBox.width) * 100;
};

/**
 * Put the calendar pane in a known position, the way calendar-pane.spec does:
 * a POST with the token the shell element carries, then a fresh load so the
 * page is painted from what was stored.
 */
const dockCalendar = async (page: Page, mode: "mail" | "split") => {
    await page.goto("/mail/inbox");

    await page.evaluate(async (wanted) => {
        const shell = document.querySelector("[data-controller~='ui--split']")!;
        const body = new FormData();

        body.append("_token", shell.getAttribute("data-ui--split-token-value") ?? "");
        body.append("mode", wanted);

        await fetch(shell.getAttribute("data-ui--split-state-url-value") ?? "", {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
        });
    }, mode);

    await page.goto("/mail/inbox");
};

const openMessage = async (page: Page) => {
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await expect(reading(page)).toBeVisible();
};

test.afterEach(async ({ page }) => {
    await store(page, { mode: "off", width: String(DEFAULT_PCT) });
});

test.describe("reading pane off", () => {
    test.use({ viewport: WIDE });

    test("opening a message still takes the place of the list", async ({ page }) => {
        await store(page, { mode: "off" });
        await dockCalendar(page, "mail");

        await openMessage(page);

        await expect(list(page)).toBeHidden();
        await expect(handle(page)).toBeHidden();
        await expect(placeholder(page)).toBeHidden();
    });
});

test.describe("reading pane right", () => {
    test.use({ viewport: WIDE });

    test.beforeEach(async ({ page }) => {
        await store(page, { mode: "right", width: String(DEFAULT_PCT) });
        await dockCalendar(page, "mail");
    });

    test("the list stays beside the message that was opened", async ({ page }) => {
        // Before anything is open: the hint stands where the message will be.
        await expect(list(page)).toBeVisible();
        await expect(handle(page)).toBeVisible();
        await expect(placeholder(page)).toBeVisible();
        await expect(reading(page)).toBeHidden();

        await openMessage(page);

        await expect(list(page)).toBeVisible();
        await expect(placeholder(page)).toBeHidden();

        // Beside, not stacked: the message starts where the list ends.
        const listBox = (await list(page).boundingBox())!;
        const readingBox = (await reading(page).boundingBox())!;

        expect(readingBox.x).toBeGreaterThanOrEqual(listBox.x + listBox.width - 1);
        expect(Math.abs(readingBox.y - listBox.y)).toBeLessThan(2);
    });

    test("the open row says which message is showing", async ({ page }) => {
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(0);

        await openMessage(page);

        const selected = page.locator('li[data-selected="true"]');

        await expect(selected).toHaveCount(1);
        await expect(selected).toContainText(INBOX_SUBJECTS.read);
        await expect(selected).toHaveAttribute("aria-current", "true");
    });

    test("the open row is still marked after the server replaces it", async ({ page }) => {
        // Opening a message posts that it was read, and the answer is a
        // turbo-stream that swaps the whole <li> for one the server rendered —
        // which knows nothing about what is open. The mark was set on the old
        // node and went with it about a hundred milliseconds later, and every
        // other assertion in this file looks at the row before that happens.
        // So this one waits for the replacement it is about, and fails loudly
        // rather than passing early if the row is ever not replaced.
        const before = await mailRow(page, INBOX_SUBJECTS.read).elementHandle();

        await mailRow(page, INBOX_SUBJECTS.read).click();
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(1);

        await page.waitForFunction((row) => false === row?.isConnected, before);

        const selected = page.locator('li[data-selected="true"]');

        await expect(selected).toHaveCount(1);
        await expect(selected).toContainText(INBOX_SUBJECTS.read);
        await expect(selected).toHaveAttribute("aria-current", "true");
    });

    test("the list keeps following the mailbox while a message is open, asking for its own address", async ({ page }) => {
        await openMessage(page);

        // A refresh asks for the page's own address by default, and with a
        // message open that is the MESSAGE's, whose list frame is empty by
        // design and is dropped on arrival — so the list would stop updating
        // for as long as anything was open. Beside the list it must ask for the
        // list's address instead. Observed as the request itself: nothing in
        // the seeded mailbox changes on cue, and a refresh that fetched the
        // wrong address also changes nothing on screen, which is what made it
        // easy to ship.
        const refresh = page.waitForRequest(
            (request) => "inbox-list-frame" === request.headers()["x-list-fragment"],
        );

        await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));

        expect(new URL((await refresh).url()).pathname).toBe("/mail/inbox");
    });

    test("the back arrow closes the message and leaves the list where it was", async ({ page }) => {
        await openMessage(page);

        await reading(page).getByRole("button", { name: /back/i }).first().click();

        await expect(list(page)).toBeVisible();
        await expect(reading(page)).toBeHidden();
        await expect(placeholder(page)).toBeVisible();
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(0);
    });

    test("the message takes the stored share of the card", async ({ page }) => {
        await openMessage(page);

        expect(await share(page)).toBeGreaterThan(DEFAULT_PCT - 2);
        expect(await share(page)).toBeLessThan(DEFAULT_PCT + 2);
    });

    test("dragging the divider changes the share, and it is still there after a reload", async ({ page }) => {
        await openMessage(page);

        const before = await share(page);
        const box = (await handle(page).boundingBox())!;
        const x = box.x + box.width / 2;
        const y = box.y + 200;

        // Left makes the message wider: the divider moves with the pointer.
        await page.mouse.move(x, y);
        await page.mouse.down();
        await page.mouse.move(x - 200, y, { steps: 10 });
        await page.mouse.up();

        const dragged = await share(page);

        expect(dragged).toBeGreaterThan(before + 5);

        // The write is fire-and-forget from the page's side; the reload is what
        // proves it landed, because the first paint is drawn from the stored
        // share and not from anything this tab remembers.
        await page.waitForTimeout(500);
        await page.reload();
        await openMessage(page);

        expect(Math.abs((await share(page)) - dragged)).toBeLessThan(2);
    });

    test("the arrow keys move the divider and a double-click puts it back", async ({ page }) => {
        const separator = handle(page);

        await separator.focus();
        await expect(separator).toHaveAttribute("aria-valuenow", String(DEFAULT_PCT));

        await page.keyboard.press("ArrowLeft");
        await expect(separator).toHaveAttribute("aria-valuenow", String(DEFAULT_PCT + 2));

        await page.keyboard.press("ArrowRight");
        await page.keyboard.press("ArrowRight");
        await expect(separator).toHaveAttribute("aria-valuenow", String(DEFAULT_PCT - 2));

        await separator.dblclick();
        await expect(separator).toHaveAttribute("aria-valuenow", String(DEFAULT_PCT));
    });

    test("a message opened by its own address still has a list beside it", async ({ page }) => {
        const href = await mailRow(page, INBOX_SUBJECTS.read)
            .locator('a[data-action~="click->mail--mail-pane#open"]')
            .getAttribute("href");

        expect(href).not.toBeNull();

        // The thread route has no list behind it; with the pane beside the list
        // that would be an empty column. The Inbox is drawn instead, with the
        // open conversation marked if it is on the page.
        await page.goto(href!);

        await expect(reading(page)).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        await expect(page.locator('li[data-selected="true"]')).toContainText(INBOX_SUBJECTS.read);
    });
});

test.describe("reading pane right, on a card too narrow for two panes", () => {
    /**
     * The setting is on and the card cannot hold it: no divider, no hint, and
     * opening a message takes the list's place as it always did.
     */
    const fallsBackToOnePane = async (page: Page) => {
        await expect(handle(page)).toBeHidden();
        await expect(placeholder(page)).toBeHidden();

        await openMessage(page);

        await expect(list(page)).toBeHidden();
    };

    test.describe("a narrow window", () => {
        test.use({ viewport: NARROW });

        test("falls back to one pane at a time", async ({ page }) => {
            await store(page, { mode: "right" });
            await dockCalendar(page, "mail");

            await fallsBackToOnePane(page);
        });
    });

    test.describe("a window wide enough, with the calendar docked beside the mail", () => {
        test.use({ viewport: MIDDLE });

        test("falls back too, because the card is what is measured", async ({ page }) => {
            await store(page, { mode: "right" });

            // The presence companion, in the same window: with no calendar the
            // card is wide enough and the divider is drawn. Without this the
            // fallback below would pass for a window that never had room.
            await dockCalendar(page, "mail");
            await expect(handle(page)).toBeVisible();

            await dockCalendar(page, "split");
            await fallsBackToOnePane(page);
        });
    });
});

test.describe("reading pane right, on a card where the minimum widths bind", () => {
    /**
     * Its own width, and the reason is what the clamp is for. At 1920 the mail
     * card is so wide that neither pane's minimum ever binds, so the dragged
     * share can reach the ends of the server's range 25 to 75 and nothing
     * needs clamping — the first version of this test ran there and let a
     * clamp-less drag through. At 1280, with no calendar docked, the card is
     * a little over 1000px: 24rem is well over a quarter of it and the list's
     * 22rem leaves well under 75%, so the ends are real limits.
     */
    test.use({ viewport: MIDDLE });

    test.beforeEach(async ({ page }) => {
        await store(page, { mode: "right", width: String(DEFAULT_PCT) });
        await dockCalendar(page, "mail");
    });

    test("Home and End go to the two ends of what the card can actually draw", async ({ page }) => {
        // With a message open, so the pane is on screen to be measured.
        await openMessage(page);

        const separator = handle(page);

        await separator.focus();

        for (const key of ["End", "Home"]) {
            await page.keyboard.press(key);

            const stored = Number(await separator.getAttribute("aria-valuenow"));

            expect(stored).toBeGreaterThanOrEqual(25);
            expect(stored).toBeLessThanOrEqual(75);

            // The figure and the screen agree. The range is 25 to 75 but the two
            // panes keep a minimum width, so on this card the ends are a few
            // percent inside it, and a share the layout cannot draw would still
            // be inside 25 to 75: asserting only that let an unclamped drag
            // through. What must hold is that what is stored is what is shown.
            expect(Math.abs((await share(page)) - stored), `after ${key}`).toBeLessThan(1);
        }

        // And the two ends are different ends.
        await page.keyboard.press("End");
        const high = Number(await separator.getAttribute("aria-valuenow"));

        await page.keyboard.press("Home");
        const low = Number(await separator.getAttribute("aria-valuenow"));

        expect(high).toBeGreaterThan(DEFAULT_PCT);
        expect(low).toBeLessThan(DEFAULT_PCT);
    });
});
