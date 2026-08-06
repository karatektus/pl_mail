import { test, expect, devices } from "@playwright/test";
import { execSync } from "node:child_process";
import { createServer } from "node:http";
import { readFileSync, statSync } from "node:fs";
import { extname, join, normalize } from "node:path";

/**
 * The published site: the language picker, the theme picker, and the navigation
 * that has to agree with them.
 *
 * Not part of the app suite — it imports `test` from @playwright/test rather
 * than from ./support/test, because it needs no database, no fixture user and
 * no signed-in session. It serves `build/site` and clicks it.
 *
 * It exists because two site bugs in a row were found by a person clicking
 * rather than by anything here: switching language on the landing page silently
 * did nothing, and every sidebar link on a German page pointed back at the
 * English one — so choosing German lasted exactly one click. Both were invisible
 * to the render check, which asks whether a link RESOLVES and never whether it
 * resolves somewhere sensible.
 *
 * Serves over HTTP rather than opening file:// URLs: localStorage is per-origin
 * and file:// gives every page an opaque one, so the remembered language — the
 * thing most worth testing — cannot work there at all.
 */

// Opt-in, like screenshots.spec.ts, and for the same kind of reason rather
// than the same one. It is not flaky and it needs no fixtures — it is simply
// not worth a PHP build and eight browser pages on every push, when what it
// guards is a static site whose failure is visible the moment anybody opens it.
// Run it when the site tooling changes:
//
//   npm run test:e2e:site
test.skip(
    undefined === process.env.E2E_SITE,
    'Site build required — run "npm run test:e2e:site".',
);

const SITE = join(process.cwd(), "build", "site");
const PORT = 8149;
const BASE = `http://127.0.0.1:${PORT}`;

const TYPES: Record<string, string> = {
    ".html": "text/html; charset=utf-8",
    ".css": "text/css",
    ".js": "text/javascript",
    ".json": "application/json",
    ".png": "image/png",
};

let server: ReturnType<typeof createServer>;

test.beforeAll(async () => {
    // Built here rather than assumed: this spec is about the OUTPUT, and a
    // stale build would test the previous commit's site and pass.
    execSync("php bin/build-site.php", { stdio: "inherit" });

    server = createServer((request, response) => {
        const asked = decodeURIComponent((request.url ?? "/").split("?")[0]);
        // normalize collapses any "..", so a crafted path cannot escape the
        // directory being served.
        let path = join(SITE, normalize(asked));

        try {
            if (statSync(path).isDirectory()) {
                path = join(path, "index.html");
            }

            response.writeHead(200, { "content-type": TYPES[extname(path)] ?? "text/plain" });
            response.end(readFileSync(path));
        } catch {
            response.writeHead(404).end("not found");
        }
    });

    await new Promise<void>((ready) => server.listen(PORT, "127.0.0.1", ready));
});

test.afterAll(async () => {
    await new Promise<void>((closed) => server.close(() => closed()));
});

test("the handbook is reachable in both languages", async ({ page }) => {
    await page.goto(`${BASE}/docs/`);
    await expect(page.locator("html")).toHaveAttribute("lang", "en");

    await page.goto(`${BASE}/docs/de/`);
    await expect(page.locator("html")).toHaveAttribute("lang", "de");
});

/**
 * The bug that made the picker look broken when the navigation was: the sidebar
 * was built against the English path on every page, so a German page linked
 * exclusively to English ones.
 */
test("a German page's navigation stays in German", async ({ page }) => {
    await page.goto(`${BASE}/docs/de/features/mail.html`);

    // Internal links only. The drawer also carries a link to GitHub, which is
    // absolute and has no language — asserting over every anchor would fail on
    // it for no reason and teach the next person to weaken the check.
    const links = await page.locator(".sidebar a").evaluateAll(
        (anchors) => anchors
            .map((anchor) => anchor.getAttribute("href") ?? "")
            .filter((href) => false === href.startsWith("http")),
    );

    expect(links.length).toBeGreaterThan(20);
    expect(links.filter((href) => false === href.includes("/docs/de/"))).toEqual([]);
});

test("switching language keeps you on the same page", async ({ page }) => {
    await page.goto(`${BASE}/docs/features/calendar.html`);
    await page.selectOption("#language", "de");

    await expect(page).toHaveURL(`${BASE}/docs/de/features/calendar.html`);

    await page.selectOption("#language", "en");
    await expect(page).toHaveURL(`${BASE}/docs/features/calendar.html`);
});

/**
 * The landing page is README.md and has no translation, so there is no
 * counterpart to send anybody to — and its URL carries no `/docs/` segment to
 * rewrite, which is why switching there once did nothing whatsoever.
 */
test("switching language on the landing page opens that language's handbook", async ({ page }) => {
    await page.goto(`${BASE}/index.html`);
    await page.selectOption("#language", "de");

    await expect(page).toHaveURL(`${BASE}/docs/de/`);
});

test("the chosen language survives arriving somewhere else", async ({ page }) => {
    await page.goto(`${BASE}/docs/features/mail.html`);
    await page.selectOption("#language", "de");
    await expect(page).toHaveURL(/\/docs\/de\//);

    // A fresh English URL — a bookmark, or somebody else's link.
    await page.goto(`${BASE}/docs/install/docker.html`);

    await expect(page).toHaveURL(`${BASE}/docs/de/install/docker.html`);
});

/** And choosing English back is not overridden by the memory that got you here. */
test("choosing English again sticks", async ({ page }) => {
    await page.goto(`${BASE}/docs/features/mail.html`);
    await page.selectOption("#language", "de");
    await expect(page).toHaveURL(/\/docs\/de\//);

    await page.selectOption("#language", "en");
    await expect(page).toHaveURL(`${BASE}/docs/features/mail.html`);

    await page.goto(`${BASE}/docs/install/docker.html`);
    await expect(page).toHaveURL(`${BASE}/docs/install/docker.html`);
});

/**
 * The front page must stay the front page. Following the remembered language
 * there turned every visit to the site into a redirect into the handbook.
 */
test("the landing page is not hijacked by a remembered language", async ({ page }) => {
    await page.goto(`${BASE}/docs/features/mail.html`);
    await page.selectOption("#language", "de");
    await expect(page).toHaveURL(/\/docs\/de\//);

    await page.goto(`${BASE}/index.html`);

    await expect(page).toHaveURL(`${BASE}/index.html`);
});

test("the theme picker offers plMail's own themes and remembers one", async ({ page }) => {
    await page.goto(`${BASE}/docs/`);

    const themes = await page.locator("#theme option").evaluateAll(
        (options) => options.map((option) => (option as HTMLOptionElement).value),
    );

    // Generated from Theme::swatch(), so this is the app's list or the build is
    // wrong. Solar is the default and must be selected before anything is picked.
    expect(themes).toEqual(["system", "light", "paper", "dark", "nord", "dusk", "solar"]);
    await expect(page.locator("#theme")).toHaveValue("solar");

    await page.selectOption("#theme", "nord");
    await expect(page.locator("html")).toHaveAttribute("data-theme", "nord");

    await page.goto(`${BASE}/docs/features/mail.html`);
    await expect(page.locator("html")).toHaveAttribute("data-theme", "nord");
});

/**
 * The phone.
 *
 * Three separate failures live here and each is invisible on a desktop run: a
 * page that scrolls sideways, a sidebar that puts thirty-four links above the
 * first paragraph, and a bar so crowded the search box collapses to a stub. The
 * last one is why the theme picker is hidden below 26rem — asserted, because
 * "make it narrower" is the tempting fix and it produces a 24px search box.
 */
test.describe("on a phone", () => {
    // defaultBrowserType comes with the device preset and Playwright refuses it
    // inside a describe — the viewport and the touch flags are what matter here.
    const { defaultBrowserType: _ignored, ...phone } = devices["iPhone SE"];

    test.use(phone);

    test("the drawer starts shut", async ({ page }) => {
        await page.goto(`${BASE}/docs/features/calendar.html`);

        await expect(page.locator("#sidebar")).toBeHidden();
        await expect(page.locator("#menu")).toBeVisible();
    });

    /**
     * Every page type, because they failed differently and the landing page
     * failed worst: `.layout` carries `align-items: flex-start` so the sidebar
     * does not stretch to the article's height, and in the COLUMN layout a
     * phone gets, that stops meaning "top" and starts meaning "as wide as your
     * content" — so <main> sized itself to its widest unbreakable line and came
     * out 1331px wide inside a 393px viewport. The handbook hid it, because
     * .prose caps at 46rem there and so was merely wrong by less.
     *
     * The second cause was a long URL in inline code, which has nowhere to go
     * and pushed a 320px phone over by seven pixels. Code inside a <pre> must
     * still NOT wrap — that block scrolls, because a wrapped shell command is a
     * command somebody pastes wrong.
     */
    for (const path of [
        "/index.html",
        "/docs/",
        "/docs/install/docker.html",
        "/docs/de/providers/google.html",
    ]) {
        test(`${path} does not scroll sideways`, async ({ page }) => {
            await page.goto(BASE + path);

            const [scroll, viewport] = await page.evaluate(
                () => [document.documentElement.scrollWidth, window.innerWidth],
            );

            expect(scroll, `${path} overflows by ${scroll - viewport}px`).toBeLessThanOrEqual(viewport);
        });
    }

    test("the burger opens the navigation and three things close it", async ({ page }) => {
        await page.goto(`${BASE}/docs/features/calendar.html`);

        await page.click("#menu");
        await expect(page.locator("#sidebar")).toBeVisible();
        await expect(page.locator("#menu")).toHaveAttribute("aria-expanded", "true");

        // Tapped by coordinate rather than by locator: the scrim covers the
        // whole viewport, so its CENTRE is underneath the open drawer and
        // Playwright's hit-testing refuses the click. A finger lands to the
        // right of the drawer, which is what this is.
        //
        // The point is derived from the drawer's own edge rather than written
        // as a number. `devices["iPhone SE"]` is the FIRST-generation SE at
        // 320×568, not the 375 the name suggests, so a hardcoded 350 was
        // off-screen and the tap silently hit nothing.
        const drawer = await page.locator("#sidebar").boundingBox();
        const viewport = page.viewportSize();

        await page.mouse.click(
            (drawer!.x + drawer!.width + viewport!.width) / 2,
            viewport!.height / 2,
        );
        await expect(page.locator("#sidebar")).toBeHidden();

        await page.click("#menu");
        await expect(page.locator("#sidebar")).toBeVisible();
        await page.keyboard.press("Escape");
        await expect(page.locator("#sidebar")).toBeHidden();

        // And following a link, which navigates and must not leave the old
        // page's drawer animating away over the new one.
        await page.click("#menu");
        await page.click('#sidebar a[href*="reminders"], #sidebar a[href*="calendar-alerts"]');
        await expect(page.locator("#sidebar")).toBeHidden();
    });

    test("the search box keeps a usable width, and 16px so Safari does not zoom", async ({ page }) => {
        await page.goto(`${BASE}/docs/`);

        const box = await page.locator("#search").boundingBox();

        expect(box?.width ?? 0).toBeGreaterThan(90);

        // Anything under 16px makes iOS zoom the page to focus the field, which
        // is the "why did my phone zoom in" that no viewport meta can fix.
        const size = await page.evaluate(
            () => getComputedStyle(document.getElementById("search")!).fontSize,
        );

        expect(parseFloat(size)).toBeGreaterThanOrEqual(16);
    });

    /**
     * Both pickers move into the drawer on a phone. They used to sit in the bar
     * and fight the search box for width — four controls across 375px left the
     * search 24 pixels wide — so one of them was hidden to settle it. Neither is
     * hidden now; they are somewhere with room.
     */
    test("the pickers are in the drawer, not the bar", async ({ page }) => {
        await page.goto(`${BASE}/docs/`);

        // Present in the DOM but not on screen while the drawer is shut.
        await expect(page.locator("#theme")).toBeHidden();
        await expect(page.locator("#language")).toBeHidden();

        await page.click("#menu");

        await expect(page.locator("#sidebar #theme")).toBeVisible();
        await expect(page.locator("#sidebar #language")).toBeVisible();
    });

    /** And switching language from inside the drawer works. */
    test("the drawer's language picker navigates", async ({ page }) => {
        await page.goto(`${BASE}/docs/features/mail.html`);
        await page.click("#menu");

        await page.selectOption("#sidebar #language", "de");

        await expect(page).toHaveURL(`${BASE}/docs/de/features/mail.html`);
    });

    /**
     * The landing page needs the drawer MORE than a handbook page does, not
     * less: .topbar-links are hidden at this width, so without it the front
     * page offers no navigation at all and somebody arriving from a link has a
     * page and no way off it.
     */
    test("the landing page has a drawer with a way into the handbook", async ({ page }) => {
        await page.goto(`${BASE}/index.html`);

        await expect(page.locator("#menu")).toBeVisible();

        await page.click("#menu");

        await expect(page.locator("#sidebar")).toBeVisible();
        await expect(page.locator(".drawer-links a").first()).toBeVisible();
    });
});

/**
 * And above the breakpoint the landing page is a front page again: no burger,
 * no docs sidebar beside the hero, and the bar's own links back.
 *
 * The rule that hides them is scoped to a min-width query rather than left to
 * source order — written as a plain rule it sat after the narrow block, matched
 * at every width and won on order, so the phone had a burger in the markup that
 * nothing could click.
 */
test.describe("on a desktop", () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    test("the landing page shows no sidebar and no burger", async ({ page }) => {
        await page.goto(`${BASE}/index.html`);

        await expect(page.locator("#menu")).toBeHidden();
        await expect(page.locator("#sidebar")).toBeHidden();
        await expect(page.locator(".topbar-links a").first()).toBeVisible();
    });

    test("a handbook page still shows its sidebar", async ({ page }) => {
        await page.goto(`${BASE}/docs/features/mail.html`);

        await expect(page.locator("#sidebar")).toBeVisible();
        await expect(page.locator("#menu")).toBeHidden();
    });
});
