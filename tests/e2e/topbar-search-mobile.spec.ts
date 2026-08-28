import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The search box on a phone, and the zoom that used to come with tapping it.
 *
 * Two faults, reported together, and they meet in this file because they met on
 * the same control:
 *
 *   • The field had about 120px of topbar to live in below md — too narrow to
 *     read a query back in, and the first thing a thumb met on the way to
 *     anything else. It is a button now, and the field drops out from under the
 *     topbar when the button asks for it.
 *
 *   • Tapping it zoomed the whole page in, and did not zoom back out. iOS does
 *     that to any focused control whose COMPUTED font-size is under 16px. A
 *     rule meant to prevent it had been in the stylesheet all along and had
 *     never once fired: it sat in `@layer base`, where every Tailwind `text-sm`
 *     outranked it.
 *
 * The second is why the sweep at the bottom is a sweep and not one assertion.
 * The rule is invisible when it works and invisible when it does not, so the
 * only thing that keeps it honest is measuring every control a thumb can land
 * on.
 */

const toggle = '[data-ui--mobile-search-target="toggle"]';
const shell = "#search-shell";
const field = 'input[name="q"]';

test.beforeAll(() => {
    seed("seed-mail");
});

/** Every control iOS would zoom into, with the size it actually computes. */
async function undersizedControls(page: Page): Promise<string[]> {
    return page.evaluate(() =>
        Array.from(
            document.querySelectorAll(
                'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=range]),' +
                    'textarea, select, [contenteditable="true"]',
            ),
        )
            .filter((el) => (el as HTMLElement).getClientRects().length > 0)
            .map((el) => {
                const e = el as HTMLElement;
                const size = parseFloat(getComputedStyle(e).fontSize);
                const name =
                    (e as HTMLInputElement).name ||
                    e.getAttribute("data-mail--search-target") ||
                    e.getAttribute("data-compose--compose-target") ||
                    e.tagName.toLowerCase();

                return { name, size };
            })
            .filter((c) => c.size < 16)
            .map((c) => `${c.name}=${c.size}px`),
    );
}

test.describe("the search box on a phone", () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test("is a button, with the field off the row until it is asked for", async ({ page }) => {
        await page.goto("/mail/inbox");

        await expect(page.locator(toggle)).toBeVisible();
        await expect(page.locator(shell)).toBeHidden();
    });

    /**
     * Focused in the same gesture that opened it. iOS raises the keyboard only
     * for a focus() inside the gesture that asked for it — deferred to a frame
     * or a transition callback, the field opens and waits to be tapped again.
     */
    test("opens the field and puts the cursor in it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();

        await expect(page.locator(shell)).toBeVisible();
        await expect(page.locator(field)).toBeFocused();
    });

    test("says whether it is open, by state and by name", async ({ page }) => {
        await page.goto("/mail/inbox");

        const button = page.locator(toggle);
        await expect(button).toHaveAttribute("aria-expanded", "false");
        await expect(button).toHaveAccessibleName("Search mail");

        await button.click();

        await expect(button).toHaveAttribute("aria-expanded", "true");
        // The name follows: "Search" on a button that closes the search is a
        // lie the icon cannot correct.
        await expect(button).toHaveAccessibleName("Close search");
    });

    /**
     * The header is laid out against `--pane-header-h` and the shell is built
     * on it, so the panel hangs UNDER the topbar rather than growing it. If
     * this ever fails, the whole app has shifted down by 58px.
     */
    test("hangs under the topbar without making it taller", async ({ page }) => {
        await page.goto("/mail/inbox");

        const header = page.locator("header").first();
        const before = (await header.boundingBox())!;

        await page.locator(toggle).click();

        const after = (await header.boundingBox())!;
        const panel = (await page.locator(shell).boundingBox())!;

        expect(after.height).toBe(before.height);
        expect(panel.y).toBeGreaterThanOrEqual(after.y + after.height - 1);
        // Full width of the topbar, not a corner of it.
        expect(panel.width).toBeGreaterThan(after.width - 4);
    });

    test("closes on a tap outside it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();
        await expect(page.locator(shell)).toBeVisible();

        // Well below the panel, which overlays the top of the list — a tap
        // inside it is not "outside".
        await page.locator("#message-list").click({ position: { x: 20, y: 260 } });

        await expect(page.locator(shell)).toBeHidden();
    });

    /**
     * mail--search already spends two Escapes: the first takes the suggestion
     * list down, the second clears the box. Putting the panel away is the third
     * rung, and it must not swallow the two below it — a half-typed query is
     * not worth losing to a press aimed at the list on top of it.
     */
    test("spends Escape one rung at a time", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();
        await page.locator(field).pressSequentially("E2E", { delay: 50 });

        const dropdown = page.locator('[data-mail--search-target="dropdown"]');
        await expect(dropdown).toBeVisible();

        // One: the list.
        await page.locator(field).press("Escape");
        await expect(dropdown).toBeHidden();
        await expect(page.locator(shell)).toBeVisible();
        await expect(page.locator(field)).toHaveValue("E2E");

        // Two: the query.
        await page.locator(field).press("Escape");
        await expect(page.locator(field)).toHaveValue("");
        await expect(page.locator(shell)).toBeVisible();

        // Three: the panel.
        await page.keyboard.press("Escape");
        await expect(page.locator(shell)).toBeHidden();
    });

    /**
     * The Escape ladder defers to mail--search only while the FIELD holds the
     * focus, because both of the rungs it defers to are spent by the field.
     * Tab into the open dropdown and `aria-expanded` stays "true" for good —
     * mail--search writes it from handlers bound to the input, which no longer
     * has the key — so a gate that read it unconditionally killed Escape from
     * that moment on and left the panel over the message list.
     */
    test("still closes on Escape after the focus has moved into the list", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();
        await page.locator(field).pressSequentially("E2E", { delay: 50 });
        await expect(page.locator('[data-mail--search-target="dropdown"]')).toBeVisible();

        await page.locator(field).press("Tab");
        await expect(page.locator(field)).not.toBeFocused();

        await page.keyboard.press("Escape");

        await expect(page.locator(shell)).toBeHidden();
    });

    test("still searches", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();
        await page.locator(field).fill("E2E Star Me");
        await page.locator(field).press("Enter");

        await expect(page).toHaveURL(/[?&]q=/);
    });
});

/**
 * Every boundary in this feature is 48rem, and that is load-bearing rather than
 * tidy. The button's visibility, the shell's positioning context and the row's
 * alignment are Tailwind `md:` variants — compiled to `(width < 48rem)` — while
 * the panel's geometry is a hand-written rule in app.css. rem in a media query
 * answers to the browser's default-font-size PREFERENCE, not to
 * `html { font-size }`, so a px spelling of the panel rule drifts away from the
 * other three the moment somebody changes that preference. And `html` is pinned
 * to 16px by the appearance scale, so the page still renders at a normal size:
 * there is no visual cue that a font preference is what broke it.
 */
test.describe("the search survives a browser font preference", () => {
    /** Chrome's own setting, which moves every rem media query in the app. */
    async function setDefaultFontSize(page: Page, px: number) {
        const cdp = await page.context().newCDPSession(page);
        await cdp.send("Page.setFontSizes", { fontSizes: { standard: px, fixed: px } });
    }

    /** How many ways into search are on screen: the button, the field, or both. */
    async function entryPoints(page: Page) {
        return {
            button: await page.locator(toggle).isVisible(),
            field: await page.locator(field).isVisible(),
            shellPosition: await page
                .locator(shell)
                .evaluate((el) => getComputedStyle(el).position),
        };
    }

    /**
     * 12px puts `md:` at 576px. With the panel rule in px it was still waiting
     * for 768px, so from 576 to 768 the button was hidden AND the panel was
     * hidden — and there is no other way to reach mail search in the app. It
     * was simply gone.
     */
    test("small default font: there is still a way in at 600px", async ({ page }) => {
        await page.setViewportSize({ width: 600, height: 800 });
        await setDefaultFontSize(page, 12);
        await page.goto("/mail/inbox");

        const seen = await entryPoints(page);

        expect(seen.button || seen.field).toBe(true);
    });

    /**
     * 20px puts `md:` at 960px. At 800px the button and the inline field both
     * rendered, the button was a no-op that still announced itself expanded,
     * and `md:relative` did not match — so the shell computed `static` and the
     * suggestions dropdown anchored to <header> and painted across the topbar.
     */
    test("large default font: exactly one way in at 800px, and the dropdown stays anchored", async ({ page }) => {
        await page.setViewportSize({ width: 800, height: 800 });
        await setDefaultFontSize(page, 20);
        await page.goto("/mail/inbox");

        const seen = await entryPoints(page);

        expect(seen.button && seen.field).toBe(false);
        expect(seen.button || seen.field).toBe(true);
        // Whichever shape it is in, the dropdown has something to hang off
        // other than the whole topbar.
        expect(seen.shellPosition).not.toBe("static");
    });
});

test.describe("the search box on a desktop", () => {
    test.use({ viewport: { width: 1280, height: 800 } });

    test("is still a field in the topbar row, with no button in the way", async ({ page }) => {
        await page.goto("/mail/inbox");

        await expect(page.locator(shell)).toBeVisible();
        await expect(page.locator(field)).toBeVisible();
        await expect(page.locator(toggle)).toBeHidden();

        // In the row, not hanging under it.
        const header = (await page.locator("header").first().boundingBox())!;
        const box = (await page.locator(shell).boundingBox())!;

        expect(box.y).toBeGreaterThanOrEqual(header.y);
        expect(box.y + box.height).toBeLessThanOrEqual(header.y + header.height);
    });
});

/**
 * Nothing a thumb can land on may compute under 16px, or iOS zooms the page in
 * on the tap and leaves it there. The rule that prevents it is in app.css and
 * has to stay UNLAYERED — moved back into `@layer base`, every one of these
 * goes to 14px again and the suite is the only thing that would notice.
 */
test.describe("nothing on a phone is small enough to zoom into", () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test("the mailbox, search box open", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();

        expect(await undersizedControls(page)).toEqual([]);
    });

    test("the compose window, editor and toolbar included", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: "Show or hide the sidebar" }).click();
        await page.getByRole("link", { name: "Compose" }).click();
        await expect(page.locator(".compose-window")).toBeVisible();

        // The editor is a contenteditable div rather than a textarea, and
        // Safari zooms into one on exactly the same rule — which is why the
        // selector list in app.css names it.
        expect(await undersizedControls(page)).toEqual([]);
    });

    test("the login form", async ({ page, context }) => {
        await context.clearCookies();
        await page.goto("/login");

        expect(await undersizedControls(page)).toEqual([]);
    });
});

/**
 * An iPhone in landscape is 812 to 932 CSS pixels — above md, so the search
 * there is the topbar field, and turning the phone upright crosses into the
 * panel layout. Mid-query that used to mean the element being typed into was
 * handed `display: none`: focus fell to <body>, the keyboard dropped, and
 * nothing said why.
 */
test.describe("turning the phone upright mid-search", () => {
    test.use({ viewport: { width: 844, height: 390 } });

    test("carries the query into the panel instead of hiding it", async ({ page }) => {
        await page.goto("/mail/inbox");

        // Landscape is above md: the field is in the row, no button.
        await expect(page.locator(field)).toBeVisible();
        await page.locator(field).fill("E2E Star");

        await page.setViewportSize({ width: 390, height: 844 });

        await expect(page.locator(shell)).toBeVisible();
        await expect(page.locator(field)).toHaveValue("E2E Star");
        await expect(page.locator(toggle)).toHaveAttribute("aria-expanded", "true");
    });

    test("leaves the panel shut when nothing was being typed", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.setViewportSize({ width: 390, height: 844 });

        await expect(page.locator(shell)).toBeHidden();
        await expect(page.locator(toggle)).toHaveAttribute("aria-expanded", "false");
    });
});

/**
 * `font-size: 16px` would be a PIN: it replaces the utility rather than raising
 * it, so below md nothing would answer to the appearance scale any more — and
 * above about 1.14 the fields would come out SMALLER than they were before the
 * rule started firing, and smaller than their own labels. The slider runs to
 * 1.25, so that is five stops of it, standing on exactly the people the rule's
 * accessibility argument is about.
 */
test.describe("the 16px is a floor and not a ceiling", () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test("fields still grow with the appearance scale", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator(toggle).click();

        const size = async () =>
            page.locator(field).evaluate((el) => parseFloat(getComputedStyle(el).fontSize));

        expect(await size()).toBe(16);

        await page.evaluate(() =>
            document.documentElement.style.setProperty("--app-font-scale", "1.25"),
        );

        // 0.875rem of a 20px root is 17.5px — bigger than the floor, so the
        // floor gets out of the way rather than clamping it back down.
        expect(await size()).toBeGreaterThan(16);
    });
});
