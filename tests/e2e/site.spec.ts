import { test, expect } from "@playwright/test";
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

    const links = await page.locator(".sidebar a").evaluateAll(
        (anchors) => anchors.map((anchor) => anchor.getAttribute("href") ?? ""),
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
