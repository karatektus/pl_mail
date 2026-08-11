import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";

/**
 * The three-way calendar switch, and the rule that a mail-bound click never
 * lands behind a fullscreen calendar.
 *
 * The demotion regressed once before it ever shipped: the first version
 * changed the mode client-side and trusted a keepalive persist to reach the
 * server before the navigation's page request — it lost that race, and the
 * calendar came back on top of the mail that had just been asked for. That is
 * why the assertions here run AFTER the navigation settles, not merely after
 * the click: the flash was never the bug, the final state was.
 */
test.describe("calendar pane demotion", () => {
    const shell = (page: Page) => page.locator("[data-controller~='ui--split']");

    /** Cycle the topbar switch until the shell reports the wanted mode. */
    async function enterMode(page: Page, mode: string): Promise<void> {
        for (let presses = 0; presses < 3; presses++) {
            if ((await shell(page).getAttribute("data-calendar-mode")) === mode) {
                return;
            }

            await page.locator("[data-calendar-toggle]").first().click();
        }

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", mode);
    }

    test("a sidebar mail link demotes a fullscreen calendar to split", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "calendar");

        await page.getByRole("link", { name: "Starred" }).click();

        await expect(page).toHaveURL(/\/mail\/starred/);
        // The final state is the assertion — see the header comment.
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
    });

    test("the demotion survives a reload, so the server heard about it too", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "calendar");

        await page.getByRole("link", { name: "Starred" }).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");

        // The handoff is read-once; what a reload shows is the server's own
        // stored answer, which the keepalive persist should have delivered by
        // now. Poll via reloads rather than sleeping a fixed beat.
        await expect(async () => {
            await page.reload();
            await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
        }).toPass({ timeout: 10_000 });
    });

    test("in split view a mail link swaps only the list, and the calendar holds still", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "split");

        // A sentinel property on the live DOM nodes: it survives only if the
        // node itself does. If the navigation replaced the body — or even just
        // re-rendered the pane — the sentinel is gone and so was the calendar,
        // however briefly. This is "neither moves nor flashes", made testable.
        await page.evaluate(() => {
            const frame = document.querySelector("turbo-frame#calendar-pane-frame") as Element & { __sentinel?: string };
            frame.__sentinel = "held-still";
        });

        await page.getByRole("link", { name: "Starred" }).click();

        await expect(page).toHaveURL(/\/mail\/starred/);
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");

        const sentinel = await page.evaluate(() => {
            const frame = document.querySelector("turbo-frame#calendar-pane-frame") as (Element & { __sentinel?: string }) | null;

            return frame?.__sentinel;
        });
        expect(sentinel).toBe("held-still");
    });

    test("arriving from the calendar page demotes a remembered fullscreen calendar", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "calendar");

        // The calendar PAGE has no split controller and no list frame — a mail
        // link there is a plain full visit, so the arriving page must demote
        // by itself or the remembered calendar covers the list.
        await page.goto("/calendar");
        await page.getByRole("link", { name: "Starred" }).click();

        await expect(page).toHaveURL(/\/mail\/starred/);
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
    });

    test("a reload paints the remembered mode server-side, with nothing to correct", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "split");

        // The flash was the server rendering `mail` and the controller
        // correcting it. The raw HTML — before any JavaScript — must already
        // carry the remembered mode.
        await expect(async () => {
            const raw = await (await page.request.get("/mail/inbox")).text();
            expect(raw).toContain('data-calendar-mode="split"');
            // And the pane BODY is in that same HTML — the calendar arrives
            // with the page, not as a spinner that fills in a beat later.
            expect(raw).toContain("data-pane-min-width");
        }).toPass({ timeout: 10_000 });
    });

    test.describe("just above lg", () => {
        test.use({ viewport: { width: 1100, height: 800 } });

        test("demoting with a wide stored pane still leaves readable mail", async ({ page }) => {
            await page.goto("/mail/inbox");

            // A width a 2560px window might have chosen, planted through the
            // real state endpoint so the demotion meets it the way a user's
            // stored preference would arrive.
            await page.evaluate(async () => {
                const shellEl = document.querySelector("[data-controller~='ui--split']") as HTMLElement;
                const body = new FormData();
                body.append("_token", shellEl.dataset["ui-SplitTokenValue"] ?? shellEl.getAttribute("data-ui--split-token-value") ?? "");
                body.append("width", "900");
                await fetch(shellEl.getAttribute("data-ui--split-state-url-value") ?? "", {
                    method: "POST",
                    body,
                    headers: { "X-Requested-With": "fetch" },
                });
            });
            await page.reload();

            await enterMode(page, "calendar");
            await page.getByRole("link", { name: "Starred" }).click();

            await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");

            // The point: the mail beside the demoted calendar is a usable
            // column, not a sliver left over from a bigger window's width.
            const list = page.locator("#message-list");
            await expect(list).toBeVisible();
            const width = (await list.boundingBox())?.width ?? 0;
            expect(width).toBeGreaterThan(250);
        });
    });

    test.describe("below lg", () => {
        test.use({ viewport: { width: 800, height: 900 } });

        test("a mail link demotes the fullscreen calendar to mail alone", async ({ page }) => {
            await page.goto("/mail/inbox");
            await enterMode(page, "calendar");

            await page.getByRole("link", { name: "Starred" }).click();

            await expect(page).toHaveURL(/\/mail\/starred/);
            // Below lg, split is not a real place — the whole row goes back
            // to the mail.
            await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
            await expect(page.locator("turbo-frame#inbox-list-frame")).toBeVisible();
        });
    });

    test("a click inside the calendar leaves the fullscreen calendar alone", async ({ page }) => {
        await page.goto("/mail/inbox");
        await enterMode(page, "calendar");

        // Anything interactive inside the pane will do — the assertion is that
        // no non-/mail click trips the demotion, and the pane's own controls
        // are the clicks a fullscreen-calendar user actually makes.
        const inPane = page
            .locator("turbo-frame#calendar-pane-frame a[href], turbo-frame#calendar-pane-frame button")
            .locator("visible=true")
            .first();

        await inPane.click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");
    });
});
