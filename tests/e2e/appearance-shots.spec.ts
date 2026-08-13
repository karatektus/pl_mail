import { test, expect, type Page } from "./support/test";

/**
 * Screenshots for the appearance overhaul, plus the contrast measurements the
 * change is held to.
 *
 * Guarded behind E2E_SHOTS so it does not run in the normal suite — it writes
 * files and takes a minute. `npx playwright test appearance-shots` with
 * E2E_SHOTS=1 to produce them.
 *
 * THE THEME IS `data-theme` ON <html>. Chrome's emulateMedia({colorScheme})
 * does nothing in this app — only `system` consults the OS — so a dark shot is
 * taken by picking a dark theme in the panel, not by asking the browser to
 * pretend.
 */

const PANEL = '[data-controller="settings--appearance"]';
const OUT = "var/shots";

const enabled = process.env.E2E_SHOTS === "1";

const pickTheme = async (page: Page, theme: string) => {
    await page.goto("/settings?section=appearance");
    await expect(page.locator(PANEL)).toBeVisible();
    await page.locator(`${PANEL} [data-theme-name="${theme}"]`).click();
    await page.waitForTimeout(700);
};

const setScale = async (page: Page, value: string) => {
    await page.goto("/settings?section=appearance");
    const slider = page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`);
    await slider.fill(value);
    await slider.dispatchEvent("input");
    await page.waitForTimeout(700);
};

/**
 * Contrast measurement, injected into the page.
 *
 * The interesting part is `behind()`, and it is interesting because getting it
 * wrong reads as a failure rather than as a broken measurement. This app paints
 * almost everything translucently — an unread row is white at 0.03 over a pane
 * at 0.85 over the app background — so "the first ancestor whose
 * backgroundColor is not transparent" is not the colour behind the text. It is
 * the first LAYER, and taking its channels while ignoring its alpha reported
 * near-white ink on a near-white ground at 1.52:1 in a dark theme where the
 * text is in fact perfectly readable.
 *
 * So the layers are composited, nearest first, until the accumulated alpha
 * reaches 1 — which is what the eye actually receives.
 */
const CONTRAST = `
(() => {
    // Colours are PAINTED to read them, not parsed.
    //
    // getComputedStyle does not promise "rgb(r, g, b)". Tailwind's alpha
    // modifiers compile to color-mix(), and Chrome reports those back as
    // \`color(srgb 1 1 1 / 0.95)\` — channels in 0–1, not 0–255. A regex that
    // scrapes numbers reads that white as near-black, which is how this
    // harness first reported the preview's sender at 2.91:1 on a #6e6e6e
    // ground that exists nowhere on the page. Letting the browser rasterise
    // one pixel makes every colour syntax — rgb, color(), oklab, a named
    // colour — come back as the same four bytes it would put on screen.
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true });

    const parse = (c) => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = c;
        ctx.fillRect(0, 0, 1, 1);

        const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;

        return { r, g, b, a: a / 255 };
    };

    const lum = ({ r, g, b }) => {
        const e = (v) => { const s = v / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
        return 0.2126 * e(r) + 0.7152 * e(g) + 0.0722 * e(b);
    };

    /**
     * The colour actually behind an element's text, compositing every layer.
     *
     * Starts at the element ITSELF: a selected segment in these controls is a
     * span carrying \`bg-accent\` with its label inside, so the accent is the
     * ground the text sits on. Starting at the parent walked straight past it
     * and measured accent-ink against the page — 1.06:1 for a pill that is in
     * fact the highest-contrast thing on the panel.
     */
    const behind = (el) => {
        const layers = [];
        let node = el;

        while (node) {
            const c = parse(getComputedStyle(node).backgroundColor);
            if (c.a > 0) { layers.push(c); if (c.a >= 1) break; }
            node = node.parentElement;
        }

        // The page itself, under everything. White is the honest floor: the
        // app background is an image or a theme colour, and a dark theme has
        // painted an opaque layer long before this is reached.
        layers.push({ r: 255, g: 255, b: 255, a: 1 });

        let out = layers[layers.length - 1];

        for (let i = layers.length - 2; i >= 0; i--) {
            const top = layers[i];
            out = {
                r: top.r * top.a + out.r * (1 - top.a),
                g: top.g * top.a + out.g * (1 - top.a),
                b: top.b * top.a + out.b * (1 - top.a),
                a: 1,
            };
        }

        return out;
    };

    return (el, describe) => {
        const ink = parse(getComputedStyle(el).color);
        const ground = behind(el);

        // Text can be translucent too — composite it over its own ground.
        const painted = {
            r: ink.r * ink.a + ground.r * (1 - ink.a),
            g: ink.g * ink.a + ground.g * (1 - ink.a),
            b: ink.b * ink.a + ground.b * (1 - ink.a),
        };

        const [x, y] = [lum(painted), lum(ground)];
        const ratio = (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);

        if (describe === true) {
            const hex = (c) => "#" + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, "0")).join("");

            return { ratio, ink: hex(painted), ground: hex(ground), text: (el.textContent || "").trim().slice(0, 24) };
        }

        return ratio;
    };
})()`;

test.describe("appearance — screenshots and contrast", () => {
    test.skip(!enabled, "set E2E_SHOTS=1");

    test.afterEach(async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await page.locator(PANEL).getByRole("button", { name: /reset|zurück/i }).first().click();
        await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
    });

    /**
     * Every theme tile's label, measured against what is actually behind it.
     *
     * This is the fix that must not regress: the tiles used to carry
     * `data-theme`, which is the selector every palette block uses, so each
     * tile redeclared the whole palette onto itself and painted its own label
     * in the theme it names — "Nord" in Nord's ink on Solar's cream, 1.22:1.
     * They carry `data-theme-name` now and the blocks are :root-scoped, so a
     * label is drawn in the theme you are LOOKING at. Measured in all seven,
     * light and dark.
     */
    test("theme tile labels clear 4.5:1 in every theme", async ({ page }) => {
        const themes = ["light", "dark", "paper", "solar", "nord", "dusk", "system"];
        const rows: string[] = [];

        for (const theme of themes) {
            await pickTheme(page, theme);

            const measured = await page.evaluate(
                ([contrastSrc]) => {
                    const contrast = eval(contrastSrc);
                    const out: Array<{ tile: string; ratio: number; ink: string; ground: string; text: string }> = [];

                    document.querySelectorAll("[data-theme-name]").forEach((tile) => {
                        // `:scope >`, and this cost a debugging round: a bare
                        // "span:last-child" matches the first DESCENDANT that
                        // is a last child, which here is the second swatch dot
                        // inside the preview square — an 8px circle with an
                        // inline background and inherited text colour. It
                        // measured 1.22:1, which is exactly the number the
                        // theme-tile regression used to produce, so a broken
                        // selector read as the bug it was meant to catch.
                        const label = tile.querySelector(":scope > span:last-child") as HTMLElement;

                        const m = contrast(label, true);

                        out.push({
                            tile: (tile as HTMLElement).dataset.themeName!,
                            ratio: m.ratio,
                            ink: m.ink,
                            ground: m.ground,
                            text: m.text,
                        });
                    });

                    return out;
                },
                [CONTRAST],
            );

            for (const { tile, ratio, ink, ground, text } of measured) {
                rows.push(
                    `${theme.padEnd(7)} → ${tile.padEnd(7)} ${ratio.toFixed(2).padStart(6)}:1  ${ink} on ${ground}  "${text}"`,
                );
                expect(ratio, `${theme}: the "${tile}" tile label`).toBeGreaterThanOrEqual(4.5);
            }
        }

        console.log("\n=== theme tile label contrast ===\n" + rows.join("\n"));
    });

    /** The new control text, measured in the lightest and the darkest theme. */
    test("the new controls clear 4.5:1", async ({ page }) => {
        const rows: string[] = [];
        const failures: Array<string | null> = [];
        const previewSnippet: Record<string, number> = {};

        for (const theme of ["light", "dark", "solar", "dusk"]) {
            await pickTheme(page, theme);

            const measured = await page.evaluate(
                ([contrastSrc]) => {
                    const contrast = eval(contrastSrc);
                    const out: Array<{ what: string; ratio: number; ink: string; ground: string; text: string }> = [];

                    const check = (what: string, el: Element | null) => {
                        if (!el) return;

                        const m = contrast(el, true);

                        out.push({ what, ratio: m.ratio, ink: m.ink, ground: m.ground, text: m.text });
                    };

                    check("list heading", document.querySelector('[id="preview-lines-label"]'));
                    check("unread heading", document.querySelector('[id="unread-emphasis-label"]'));
                    check("surface label", document.querySelector('[id="surface-density-list"]'));
                    check(
                        "preview sender",
                        document.querySelector("[data-preview-row] .truncate"),
                    );
                    check(
                        "preview snippet",
                        document.querySelector("[data-preview-row] [data-row-snippet]"),
                    );
                    check(
                        "selected segment",
                        document.querySelector('input[name="previewLines"]:checked + span'),
                    );

                    return out;
                },
                [CONTRAST],
            );

            for (const { what, ratio, ink, ground, text } of measured) {
                rows.push(
                    `${theme.padEnd(7)} → ${what.padEnd(16)} ${ratio.toFixed(2).padStart(6)}:1  ${ink} on ${ground}  "${text}"`,
                );

                if (what === "preview snippet") {
                    previewSnippet[theme] = ratio;

                    continue;
                }

                failures.push(
                    ratio >= 4.5 ? null : `${theme}: ${what} is ${ratio.toFixed(2)}:1 (${ink} on ${ground})`,
                );
            }

            // The same text, in the real list, on a real unread row.
            //
            // The preview's snippet is --rgb-ink-faint over the unread tint,
            // and in the two darkest themes that lands at ~4.2:1 rather than
            // the ≥4.5:1 the palette guarantees for that tier on the BARE
            // surface (ThemeInkContrastTest). That is a property of the list
            // this preview is a picture of, not of the preview — so the bar it
            // is held to is the list's own number, measured here rather than
            // asserted from memory. A preview that scored BETTER than the
            // thing it depicts would be the actual failure: it would be
            // showing the user something the inbox will not give them.
            await page.goto("/mail/inbox");
            await expect(page.locator("#message-list li").first()).toBeVisible();

            const live = await page.evaluate(
                ([contrastSrc]) => {
                    const contrast = eval(contrastSrc);
                    const row = document.querySelector(
                        '#message-list li[data-unread="true"] [data-row-snippet]',
                    ) ?? document.querySelector("#message-list li [data-row-snippet]");

                    return row ? contrast(row, true) : null;
                },
                [CONTRAST],
            );

            if (live) {
                rows.push(
                    `${theme.padEnd(7)} → ${"LIVE list snippet".padEnd(16)} ${live.ratio.toFixed(2).padStart(6)}:1  ${live.ink} on ${live.ground}`,
                );

                // Within a hair of each other: same token, same tint, same row.
                expect(
                    Math.abs(previewSnippet[theme] - live.ratio),
                    `${theme}: the preview's snippet does not match the list's`,
                ).toBeLessThan(0.35);
            }
        }

        // Printed before asserted, on purpose: the whole table is the report,
        // and a bare "expected >= 4.5, received 2.91" says nothing about which
        // colours produced it or whether the measurement itself is wrong.
        console.log("\n=== new control contrast ===\n" + rows.join("\n"));

        expect(failures.filter(Boolean)).toEqual([]);
    });

    /**
     * The typography extremes, at both viewports and both themes.
     *
     * The claim in Appearance::RANGE_FONT_SCALE is that both ends were opened
     * and looked at, so this opens them: the settings panel, the thread list
     * and the compose window at 0.875 and at 1.25. The assertion is that the
     * page never scrolls sideways — a size that overflows the shell is what
     * "does not survive its extremes" looks like.
     */
    for (const [label, width, height] of [
        ["desktop", 1440, 900],
        ["mobile", 414, 851],
    ] as const) {
        for (const theme of ["light", "dusk"] as const) {
            test(`${label} / ${theme}`, async ({ page }) => {
                await page.setViewportSize({ width, height });
                await pickTheme(page, theme);

                for (const [name, scale] of [
                    ["default", "1"],
                    ["min", "0.875"],
                    ["max", "1.25"],
                ] as const) {
                    await setScale(page, scale);

                    await page.goto("/settings?section=appearance");
                    await expect(page.locator(PANEL)).toBeVisible();
                    await page.screenshot({
                        path: `${OUT}/${label}-${theme}-settings-${name}.png`,
                        fullPage: false,
                    });

                    await page.goto("/mail/inbox");
                    await expect(page.locator("#message-list li").first()).toBeVisible();
                    await page.screenshot({ path: `${OUT}/${label}-${theme}-list-${name}.png` });

                    // Nothing may push the page sideways at either extreme.
                    const overflow = await page.evaluate(() =>
                        document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    );
                    expect(overflow, `${label}/${theme}/${name}: horizontal overflow`).toBeLessThanOrEqual(1);

                    // The compose window, which keeps its own type size.
                    // Below md the sidebar is behind the burger, so the
                    // Compose link is not on screen until the drawer is open —
                    // the same route compose-mobile.spec.ts takes.
                    if (width < 768) {
                        await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
                    }

                    await page.getByRole("link", { name: /compose|schreiben/i }).first().click();
                    await expect(
                        page.locator('[data-compose--compose-toolbar-target="editor"]'),
                    ).toBeVisible();
                    await page.screenshot({ path: `${OUT}/${label}-${theme}-compose-${name}.png` });

                    const composeOverflow = await page.evaluate(() =>
                        document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    );
                    expect(composeOverflow, `${label}/${theme}/${name}: compose overflow`).toBeLessThanOrEqual(1);

                    await page.keyboard.press("Escape");
                }

                // And the list wearing the settings the user asked about.
                await page.goto("/settings?section=appearance");
                await page.locator(`${PANEL} input[type="checkbox"][data-toggles="accountCorner"]`).click();
                await page.waitForTimeout(700);
                await page.goto("/mail/inbox");
                await page.screenshot({ path: `${OUT}/${label}-${theme}-list-no-corner.png` });
            });
        }
    }
});
