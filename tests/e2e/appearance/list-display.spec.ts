import { test, expect, type Page } from "../support/test";

/**
 * The mail-list display settings, and the one the user actually asked for.
 *
 * "There should be an option to turn the account corners off. I personally
 * don't want them, but there are probably people who do." So the corner is
 * optional and ON by default, and the two halves of that promise are what this
 * file measures: it goes when switched off, it comes back when switched on, and
 * — the part that makes it a setting rather than a browser quirk — it is still
 * gone after a reload in a context that has never seen this browser's
 * localStorage. The preference lives on the user row, not in this tab.
 *
 * Everything here restores the defaults on the way out. The account is shared
 * with the rest of the suite and an appearance left switched off is a
 * screenshot diff in a spec that never asked about it.
 */

const PANEL = '[data-controller="settings--appearance"]';

/** The value the page is painted with, not the one in the form. */
const cssVar = (page: Page, name: string) =>
    page.evaluate(
        (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(),
        name,
    );

const open = async (page: Page) => {
    await page.goto("/settings?section=appearance");
    await expect(page.locator(PANEL)).toBeVisible();
};

/**
 * A segmented control's option, reached by its LABEL.
 *
 * The radios are `peer sr-only` — 1px boxes behind the visible pill — so a
 * click lands on the label, which is what a person clicks too. The density
 * spec next door does the same for the same reason.
 */
const option = (page: Page, name: string, value: string) =>
    page.locator(`${PANEL} label:has(input[name="${name}"][value="${value}"])`);

const pick = async (page: Page, name: string, value: string) => {
    await option(page, name, value).click();
    await expect(
        page.locator(`${PANEL} input[name="${name}"][value="${value}"]`),
    ).toBeChecked();
};

/** The checkbox for one of the on/off list settings. */
const toggle = (page: Page, field: string) =>
    page.locator(`${PANEL} input[type="checkbox"][data-toggles="${field}"]`);

const setToggle = async (page: Page, field: string, on: boolean) => {
    const box = toggle(page, field);

    if ((await box.isChecked()) !== on) {
        await box.click();
        // The panel debounces its POST; wait for the write, not for a timeout.
        await page.waitForResponse(
            (r) => r.url().includes("/settings/appearance") && r.request().method() === "POST",
        );
    }
};

test.describe("appearance — mail list display", () => {
    test.afterEach(async ({ page }) => {
        await open(page);
        await setToggle(page, "accountCorner", true);
        await setToggle(page, "listAvatars", true);
        await pick(page, "previewLines", "1");
        await pick(page, "unreadEmphasis", "standard");
        await page.waitForTimeout(700);
    });

    test("the account corner goes off and comes back", async ({ page }) => {
        await open(page);

        // On by default, and the corner is a real element in the list.
        expect(await cssVar(page, "--list-corner-display")).toBe("block");

        await setToggle(page, "accountCorner", false);
        await expect.poll(() => cssVar(page, "--list-corner-display")).toBe("none");

        await setToggle(page, "accountCorner", true);
        await expect.poll(() => cssVar(page, "--list-corner-display")).toBe("block");
    });

    /**
     * The corner actually disappears FROM THE LIST, with the geometry measured
     * rather than merely containment-checked: a 0px-tall box is inside its row
     * and inside the viewport, and would pass a containment assertion while
     * being invisible. So the "on" case asserts a real width and height.
     */
    test("the corner is drawn, or not, in the list itself", async ({ page }) => {
        await open(page);
        await setToggle(page, "accountCorner", true);

        await page.goto("/mail/inbox");

        const corner = page.locator("#message-list li [data-account-corner]").first();

        // A single-account install draws no corner at all — the setting cannot
        // be measured against a list that has nothing to say.
        const drawn = await corner.count();

        if (drawn > 0) {
            const box = await corner.boundingBox();
            expect(box).not.toBeNull();
            expect(box!.width).toBeGreaterThanOrEqual(8);
            expect(box!.height).toBeGreaterThanOrEqual(8);
        }

        await open(page);
        await setToggle(page, "accountCorner", false);
        await page.goto("/mail/inbox");

        if (drawn > 0) {
            await expect(
                page.locator("#message-list li [data-account-corner]").first(),
            ).toBeHidden();
        }

        // Whatever the account count, the token is what the row reads.
        expect(await cssVar(page, "--list-corner-display")).toBe("none");
    });

    /**
     * Per user and synced, not per browser.
     *
     * A brand-new context has an empty localStorage, so the only way the
     * setting can survive into it is by having been read off the user — which
     * is the whole of what "synced across devices" means here.
     */
    test("the preference is on the user, not the browser", async ({ page, browser }) => {
        await open(page);
        await setToggle(page, "accountCorner", false);

        const second = await browser.newContext({
            storageState: await page.context().storageState(),
            baseURL: page.context().pages()[0].url(),
        });

        try {
            const other = await second.newPage();
            await other.goto(new URL("/mail/inbox", page.url()).toString());

            expect(await cssVar(other, "--list-corner-display")).toBe("none");
        } finally {
            await second.close();
        }
    });

    test("two preview lines clamp in the list and stay one line when wide", async ({ page }) => {
        await open(page);

        await pick(page, "previewLines", "2");
        await expect.poll(() => cssVar(page, "--list-preview-display")).toBe("-webkit-box");
        expect(await cssVar(page, "--list-preview-lines")).toBe("2");
        // The wide branch never clamps: subject and preview share one line.
        expect(await cssVar(page, "--list-preview-display-wide")).toBe("block");

        await pick(page, "previewLines", "0");
        await expect.poll(() => cssVar(page, "--list-preview-display")).toBe("none");
    });

    test("strong unread emphasis paints a bar and subtle paints nothing", async ({ page }) => {
        await open(page);

        await pick(page, "unreadEmphasis", "strong");
        await expect.poll(() => cssVar(page, "--unread-bar-w")).toBe("3px");
        expect(await cssVar(page, "--unread-emphasis")).toBe("1.6");

        await pick(page, "unreadEmphasis", "subtle");
        await expect.poll(() => cssVar(page, "--unread-emphasis")).toBe("0");
        expect(await cssVar(page, "--unread-bar-w")).toBe("0px");
    });

    /**
     * Switching the discs off must not take the row's checkbox with them — the
     * disc IS the checkbox, and a list you cannot select from is a worse bug
     * than a disc you did not want.
     */
    test("sender discs off leaves the row still selectable", async ({ page }) => {
        await open(page);
        await setToggle(page, "listAvatars", false);

        await page.goto("/mail/inbox");

        const row = page.locator("#message-list li").first();
        await expect(row).toBeVisible();

        const face = row.locator("[data-row-avatar-face]");
        const box = await face.boundingBox();

        // Still there, still 36px, still a target.
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThanOrEqual(30);
        expect(box!.height).toBeGreaterThanOrEqual(30);

        await row.locator("[data-thread-select]").check({ force: true });
        await expect(row.locator("[data-thread-select]")).toBeChecked();
        await row.locator("[data-thread-select]").uncheck({ force: true });
    });

    /** The preview is a preview: nothing in it is fetched. */
    test("the live preview loads no mail", async ({ page }) => {
        const mailRequests: string[] = [];

        // `/assets/` is excluded on purpose and not as a convenience: the mail
        // Stimulus controllers are named after the feature they drive and live
        // under assets/controllers/mail/, so a naive "/mail/" filter catches
        // fifteen JavaScript files and nothing else. What this test is about is
        // whether the PREVIEW fetches mail, which would be a document or a
        // JSON endpoint, never a hashed asset.
        page.on("request", (request) => {
            const path = new URL(request.url()).pathname;

            if (path.startsWith("/assets/")) {
                return;
            }

            if (/\/mail\/|\/api\/|thread/.test(path)) {
                mailRequests.push(request.url());
            }
        });

        await open(page);
        await expect(page.locator("[data-preview-row]").first()).toBeVisible();

        // Three sample rows and a message, and not one request for any of it.
        await expect(page.locator("[data-preview-row]")).toHaveCount(3);
        expect(mailRequests).toEqual([]);
    });

    /** The preview wears the setting, which is the only reason it is there. */
    test("the live preview follows the controls", async ({ page }) => {
        await open(page);

        const previewCorner = page.locator("[data-preview-row] [data-account-corner]").first();
        await expect(previewCorner).toBeVisible();

        await setToggle(page, "accountCorner", false);
        await expect(previewCorner).toBeHidden();

        await setToggle(page, "accountCorner", true);
        await expect(previewCorner).toBeVisible();
    });
});

/**
 * Per-surface density: a dense list beside a comfortable reading pane.
 *
 * The list and the reading pane are one painted surface, so the only knob that
 * can honestly differ between them is the padding inside their rows — which is
 * what this measures, on the resolved tokens rather than on the radios.
 */
test.describe("appearance — per-surface density", () => {
    test.afterEach(async ({ page }) => {
        await open(page);
        await pick(page, "listDensity", "");
        await pick(page, "readingDensity", "");
        await pick(page, "density", "comfortable");
        await page.waitForTimeout(700);
    });

    test("a surface can differ from the global density, and reverts to following", async ({ page }) => {
        await open(page);

        await pick(page, "density", "comfortable");
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.625rem");
        expect(await cssVar(page, "--surface-reading-row-y")).toBe("1rem");

        await pick(page, "listDensity", "compact");

        // The list tightens; the reading pane does not move.
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.25rem");
        expect(await cssVar(page, "--surface-reading-row-y")).toBe("1rem");

        // Persisted on the user, not painted in this tab.
        await page.waitForTimeout(700);
        await page.reload();
        expect(await cssVar(page, "--surface-list-row-y")).toBe("0.25rem");

        // Back to following, and the global value takes over again.
        await pick(page, "listDensity", "");
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.625rem");
    });

    /** A following surface moves when the global control does. */
    test("a following surface tracks the global density", async ({ page }) => {
        await open(page);

        await pick(page, "readingDensity", "");
        await pick(page, "density", "compact");

        await expect.poll(() => cssVar(page, "--surface-reading-row-y")).toBe("0.5rem");
    });
});

/**
 * The interface text scale, and the thing it must not touch.
 *
 * The compose editor has its own font size, which goes out with the message.
 * If the UI scale moved it, writing at 1.25 would send mail 25% smaller than it
 * looked while being written and nothing would ever have said so.
 */
test.describe("appearance — typography", () => {
    test.afterEach(async ({ page }) => {
        await open(page);
        await page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`)
            .fill("1");
        await page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`)
            .dispatchEvent("input");
        await page.waitForTimeout(700);
    });

    test("the scale moves the root and leaves the compose editor alone", async ({ page }) => {
        await open(page);

        const rootSize = () =>
            page.evaluate(() => getComputedStyle(document.documentElement).fontSize);

        expect(await rootSize()).toBe("16px");

        const slider = page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`);
        await slider.fill("1.25");
        await slider.dispatchEvent("input");

        await expect.poll(rootSize).toBe("20px");

        await page.waitForTimeout(700);
        await page.goto("/mail/inbox");

        // The root carries the scale everywhere…
        expect(await rootSize()).toBe("20px");

        // …and the compose editor is pinned in px, outside it.
        await page.getByRole("link", { name: /compose|schreiben/i }).first().click();
        const editor = page.locator('[data-compose--compose-toolbar-target="editor"]');
        await expect(editor).toBeVisible();

        expect(await editor.evaluate((el) => getComputedStyle(el).fontSize)).toBe("14px");
    });
});
