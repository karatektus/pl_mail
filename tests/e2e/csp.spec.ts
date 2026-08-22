import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * The application's own pages violate their own Content-Security-Policy nowhere.
 *
 * CODE-REVIEW.md S-03: the reading frame carries a carefully built policy and
 * the image proxy carries one, while the document holding the session — the
 * composer, settings, the admin panel — carried none. That is what turned the
 * two stored-XSS findings from "script in a contenteditable" into "take the
 * account", and it is why a policy that is merely *present* is not the point.
 * A policy is worth exactly what it refuses.
 *
 * So this walks the surfaces and asserts that nothing on them is refused —
 * which is the property that lets `script-src` be enforced rather than
 * perpetually reported. Under debug both headers are sent, the full policy as
 * Report-Only, and a violation raises `securitypolicyviolation` in the page
 * without breaking anything; in production that same policy is the enforced
 * one, so a violation here is a page that would be broken for real users.
 *
 * The one it caught while being written: every stylesheet in the application.
 * AssetMapper implements `import './app.css'` as a
 * `data:application/javascript,` module that appends a <link> at runtime, so
 * CSS arrived as script and the only way to allow it would have been
 * `script-src … data:`. They are <link>ed from the layout now.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

/** Pages that must render with no violation at all. */
const SURFACES: ReadonlyArray<readonly [string, string]> = [
    ["inbox", "/mail/inbox"],
    ["settings", "/settings"],
    ["settings: security", "/settings?section=security"],
    ["settings: appearance", "/settings?section=appearance"],
    ["settings: filters", "/settings?section=filters"],
    ["calendar", "/calendar"],
    ["search", "/mail/search?q=e2e"],
];

test.describe("content security policy", () => {
    test("both headers are sent, and the enforced one refuses the dangerous directives", async ({ page }) => {
        const response = await page.goto("/mail/inbox");
        const headers = response?.headers() ?? {};

        const enforced = headers["content-security-policy"] ?? "";

        // Enforced everywhere, debug included: each of these closes a real
        // escalation route and none can plausibly break this application.
        // base-uri is the one worth naming — an injected <base> silently
        // re-points every relative URL on the page, module imports included.
        expect(enforced).toContain("base-uri 'none'");
        expect(enforced).toContain("object-src 'none'");
        expect(enforced).toContain("form-action 'self'");
        expect(enforced).toContain("frame-ancestors 'self'");

        // Under debug the full policy rides along as a report. In production it
        // IS the enforced header — see ContentSecurityPolicyListener.
        const full = headers["content-security-policy-report-only"] ?? enforced;
        expect(full).toContain("script-src 'self' 'nonce-");
        expect(full).not.toContain("unsafe-inline'; script");
        expect(full, "data: in script-src gives the directive away").not.toMatch(/script-src[^;]*data:/);
    });

    /**
     * The nonce is per request, and the page and the header agree on it.
     *
     * Both halves matter and they fail differently. A nonce that does not
     * change is not a nonce — it can be read from one response and reused — and
     * FrankenPHP's worker mode keeps services alive across requests, so it is a
     * real risk here rather than a theoretical one. A nonce the header and the
     * document disagree about blocks the application's own scripts, which is
     * the classic way this feature is got wrong.
     */
    test("the nonce is fresh per request and matches the document", async ({ page }) => {
        const nonceOf = async () => {
            const response = await page.goto("/mail/inbox");
            const header = response?.headers()["content-security-policy-report-only"]
                ?? response?.headers()["content-security-policy"] ?? "";
            const fromHeader = /'nonce-([^']+)'/.exec(header)?.[1] ?? "";
            const inPage = await page.evaluate(
                () => [...document.querySelectorAll("script[nonce]")].map((s) => (s as HTMLScriptElement).nonce),
            );

            return { fromHeader, inPage };
        };

        const first = await nonceOf();
        expect(first.fromHeader).not.toBe("");
        expect(first.inPage.length, "the layout has nonced scripts").toBeGreaterThan(0);
        expect([...new Set(first.inPage)], "one nonce per document").toEqual([first.fromHeader]);

        const second = await nonceOf();
        expect(second.fromHeader, "the nonce is reused across requests").not.toBe(first.fromHeader);
    });

    for (const [label, url] of SURFACES) {
        test(`${label} raises no violation`, async ({ page }) => {
            await page.addInitScript(() => {
                (window as never as { __csp: string[] }).__csp = [];
                document.addEventListener("securitypolicyviolation", (event) => {
                    (window as never as { __csp: string[] }).__csp.push(
                        `${event.effectiveDirective} <- ${event.blockedURI}`,
                    );
                });
            });

            const response = await page.goto(url);
            expect(response?.status(), `${url} did not render`).toBeLessThan(400);

            // Stimulus connects and controllers fetch after load; a violation
            // raised by that work arrives later than the navigation does.
            await page.waitForTimeout(1500);

            const violations = await page.evaluate(() => (window as never as { __csp: string[] }).__csp);
            expect(violations, `${url} violates its own policy`).toEqual([]);
        });
    }

    test("the compose window raises no violation", async ({ page }) => {
        await page.addInitScript(() => {
            (window as never as { __csp: string[] }).__csp = [];
            document.addEventListener("securitypolicyviolation", (event) => {
                (window as never as { __csp: string[] }).__csp.push(
                    `${event.effectiveDirective} <- ${event.blockedURI}`,
                );
            });
        });

        await page.goto("/mail/inbox");

        // The mail first, then the composer: the reading frame carries its own,
        // stricter policy and a clash between the two would show here — and the
        // open dock sits over the list, so a row cannot be clicked afterwards.
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.waitForTimeout(1500);

        await page.getByRole("link", { name: "Compose" }).first().click();
        await expect(page.locator(".compose-window").first()).toBeVisible();
        await page.waitForTimeout(1500);

        const violations = await page.evaluate(() => (window as never as { __csp: string[] }).__csp);
        expect(violations, "the composer or the reading frame violates the policy").toEqual([]);
    });
});
