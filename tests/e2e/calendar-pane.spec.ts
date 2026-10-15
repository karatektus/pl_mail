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

    // The pane mode persists SERVER-SIDE on the per-worker user, and that
    // user is shared with every other spec file this worker runs — today a
    // leaked `split` narrowed mail.spec's list until its hover actions
    // vanished. Every test here puts the furniture back.
    test.afterEach(async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.evaluate(async () => {
            const shellEl = document.querySelector("[data-controller~='ui--split']") as HTMLElement | null;

            if (null === shellEl) {
                return;
            }

            const body = new FormData();
            body.append("_token", shellEl.getAttribute("data-ui--split-token-value") ?? "");
            body.append("mode", "mail");
            await fetch(shellEl.getAttribute("data-ui--split-state-url-value") ?? "", {
                method: "POST",
                body,
                headers: { "X-Requested-With": "fetch" },
            });
        });
    });

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

        test("one toggle press covers the mail too, and a mail link still demotes", async ({ page }) => {
            await page.goto("/mail/inbox");

            // ONE press: mode `split` — which below lg app.css draws as the
            // calendar with the mail hidden. The user is in a fullscreen
            // calendar whose mode is not NAMED calendar, which is exactly the
            // state a mode-name check missed.
            await page.locator("[data-calendar-toggle]").first().click();
            await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");

            await page.getByRole("link", { name: "Starred" }).click();

            await expect(page).toHaveURL(/\/mail\/starred/);
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

/**
 * Settings → Calendar → Behaviour: the switch skips a position, so it has two
 * stops — the mail and one of the calendar's two — instead of three. The
 * server's half (the label, the value on the shell) is in
 * CalendarSwitchRenderTest and how the choice is kept is in
 * CalendarSwitchSkipTest; this is the click.
 *
 * The setting and the pane mode persist on the per-worker user that every
 * other spec file shares, so each test puts all of them back.
 */
test.describe("calendar button, skipping a position", () => {
    const shell = (page: Page) => page.locator("[data-controller~='ui--split']");
    const toggle = (page: Page) => page.locator("[data-calendar-toggle]").first();
    const label = (page: Page) => page.locator("[data-calendar-mode-label]").first();

    type Skip = "none" | "split" | "calendar";

    /**
     * Writes the choice the way the pane's form would. The token is the pane's
     * own: this form is not one of the `ajax` token's callers.
     */
    async function setSkip(page: Page, skip: Skip): Promise<void> {
        const html = await (await page.request.get("/settings?section=calendar-behaviour")).text();
        const token = /name="_token" value="([^"]*)"/.exec(html)?.[1] ?? "";

        const response = await page.request.post("/settings/calendar-behaviour", {
            form: { _token: token, skip },
        });

        expect(response.ok()).toBe(true);
    }

    async function storeMode(page: Page, mode: string): Promise<void> {
        await page.goto("/mail/inbox");
        await page.evaluate(async (wanted) => {
            const shellEl = document.querySelector("[data-controller~='ui--split']") as HTMLElement;
            const body = new FormData();
            body.append("_token", shellEl.getAttribute("data-ui--split-token-value") ?? "");
            body.append("mode", wanted);
            await fetch(shellEl.getAttribute("data-ui--split-state-url-value") ?? "", {
                method: "POST",
                body,
                headers: { "X-Requested-With": "fetch" },
            });
        }, mode);
    }

    test.afterEach(async ({ page }) => {
        await setSkip(page, "none");
        await storeMode(page, "mail");
    });

    test("skip split: one press opens the full calendar and the next returns to the mail", async ({ page }) => {
        await setSkip(page, "split");
        await storeMode(page, "mail");
        await page.goto("/mail/inbox");

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
        await expect(label(page)).toHaveText("Show the calendar full width");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");

        // What a press does next, for a screen reader, follows the setting.
        await expect(label(page)).toHaveText("Show the mail");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
        await expect(label(page)).toHaveText("Show the calendar full width");
    });

    test("skip split: a split that was stored before the setting went on closes to the mail", async ({ page }) => {
        await setSkip(page, "split");
        await storeMode(page, "split");
        await page.goto("/mail/inbox");

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
    });

    test("skip split: below lg a stored split is drawn as the mail, and one press opens the full calendar", async ({ page }) => {
        await page.setViewportSize({ width: 400, height: 800 });
        await setSkip(page, "split");
        await storeMode(page, "split");
        await page.goto("/mail/inbox");

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");
    });

    test("skip split: a mail link demotes the full calendar to the mail, not to a split the button skips", async ({ page }) => {
        await setSkip(page, "split");
        await storeMode(page, "mail");
        await page.goto("/mail/inbox");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");

        await page.getByRole("link", { name: "Starred" }).click();

        await expect(page).toHaveURL(/\/mail\/starred/);
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
    });

    test("skip calendar: one press opens the split and the next returns to the mail", async ({ page }) => {
        await setSkip(page, "calendar");
        await storeMode(page, "mail");
        await page.goto("/mail/inbox");

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
        await expect(label(page)).toHaveText("Show the calendar beside the mail");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
        await expect(label(page)).toHaveText("Show the mail");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
    });

    test("skip calendar: a full-width calendar that was stored before the setting went on closes to the mail", async ({ page }) => {
        await setSkip(page, "calendar");
        await storeMode(page, "calendar");
        await page.goto("/mail/inbox");

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");

        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
    });

    test("skip calendar: a mail link still demotes the full calendar to the split, the position the button has", async ({ page }) => {
        await setSkip(page, "calendar");
        await storeMode(page, "calendar");
        await page.goto("/mail/inbox");

        await page.getByRole("link", { name: "Starred" }).click();

        await expect(page).toHaveURL(/\/mail\/starred/);
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
    });

    test("the Behaviour pane is one choice of three, and the button follows it", async ({ page }) => {
        await setSkip(page, "none");
        await page.goto("/settings?section=calendar-behaviour");

        const group = page.getByRole("radiogroup", { name: "Calendar button" });
        const option = (name: string) => group.getByRole("radio", { name, exact: true });

        await expect(group.getByRole("radio")).toHaveCount(3);
        await expect(option("All three")).toBeChecked();

        // Submitting redirects back to the pane; wait for the page that comes
        // back, not for the post.
        const saved = () =>
            page.waitForResponse(
                (response) =>
                    "GET" === response.request().method() &&
                    response.url().includes("section=calendar-behaviour") &&
                    null !== response.request().redirectedFrom(),
            );
        // The radio itself is sr-only; its label is what a pointer meets.
        const choose = async (name: string) => {
            const back = saved();
            await group.locator("label").filter({ hasText: name }).click();
            await back;
        };

        await choose("Full width only");
        await expect(option("Full width only")).toBeChecked();
        await expect(option("All three")).not.toBeChecked();

        // The button follows: mail, calendar, mail.
        await storeMode(page, "mail");
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "calendar");

        // Straight to the other one. Nothing has to be switched off first.
        await page.goto("/settings?section=calendar-behaviour");
        await choose("Split only");
        await expect(option("Split only")).toBeChecked();
        await expect(option("Full width only")).not.toBeChecked();

        await storeMode(page, "mail");
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
    });

    test("off, the button still cycles mail, split, calendar, mail", async ({ page }) => {
        await setSkip(page, "none");
        await storeMode(page, "mail");
        await page.goto("/mail/inbox");

        for (const next of ["split", "calendar", "mail"]) {
            await toggle(page).click();
            await expect(shell(page)).toHaveAttribute("data-calendar-mode", next);
        }
    });
});
