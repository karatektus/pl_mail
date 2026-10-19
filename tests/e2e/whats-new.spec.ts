import { test, expect } from "./support/test";

/**
 * What's new in a browser: the ? menu, the dialog, and the parts of it that
 * only work with the script running (#165).
 *
 * The users here are created after the migration that gives everybody who
 * already existed 0.3.1, so they start on the running release and nothing
 * opens by itself — WhatsNewTest covers that on the server, pinned to a
 * release. What it cannot see is the dialog's wiring: links that stay in the
 * shared modal's frame, the footer's checkbox submitting without closing it,
 * and Done closing it.
 */
test.describe("What's new", () => {
    test("opens from the ? and moves between releases inside the dialog", async ({ page }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        const notes = dialog.locator("[data-whats-new-notes]");

        await expect(notes).toBeVisible();
        const first = await notes.getAttribute("data-whats-new-notes");

        // The full changelog starts folded, and opening it shows older releases.
        const history = dialog.locator("[data-whats-new-history]");
        await expect(history).not.toHaveAttribute("open", /.*/);
        await history.locator("summary").click();

        const older = history.locator("[data-whats-new-release]").nth(2);
        const tag = await older.getAttribute("data-whats-new-release");
        await older.click();

        await expect(notes).toHaveAttribute("data-whats-new-notes", tag ?? "");
        expect(tag).not.toBe(first);
        await expect(dialog, "a release link stays in the dialog").toBeVisible();
        await expect(page).toHaveURL(/settings/);
    });

    test("the footer's checkbox keeps the dialog open, and Done closes it", async ({ page }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        await expect(dialog.locator("[data-whats-new-notes]")).toBeVisible();

        const quiet = dialog.getByLabel("Don't show this after updates");
        await quiet.check();

        // Drawn again by the server with the box ticked, still open.
        await expect(dialog.getByLabel("Don't show this after updates")).toBeChecked();
        await expect(dialog).toBeVisible();

        // And put back, so the per-worker user is left as it was found.
        await dialog.getByLabel("Don't show this after updates").uncheck();
        await expect(dialog.getByLabel("Don't show this after updates")).not.toBeChecked();

        await dialog.getByRole("button", { name: "Done" }).click();
        await expect(dialog).toBeHidden();
    });

    /**
     * A highlight's screenshot grows to fill the dialog rather than opening a
     * new tab (#179), and the X, Escape and Back each put it back and nothing
     * else: the shared modal's Escape is on document, and Turbo and the mail
     * pane both answer a popstate. v0.3.5 is a release whose highlights carry
     * screenshots.
     */
    test("a screenshot fills the dialog, and X, Escape and Back put it back", async ({ page, context }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        const body = dialog.locator("[data-whats-new]");
        await expect(dialog.locator("[data-whats-new-notes]")).toBeVisible();

        // Marked first, so the wait below is for the frame drawn by this click:
        // v0.3.5 may already be the release on show, and a thumbnail clicked
        // before the new frame lands is replaced along with everything else.
        await dialog.locator("[data-whats-new-notes]").evaluate((notes) => { notes.dataset.stale = ""; });
        await dialog.locator("[data-whats-new-history] summary").click();
        await dialog.locator('[data-whats-new-release="v0.3.5"]').first().click();
        await expect(dialog.locator('[data-whats-new-notes="v0.3.5"]:not([data-stale])')).toBeVisible();

        const pages = context.pages().length;
        const url = page.url();
        const thumbnail = dialog.getByRole("link", { name: "Open the screenshot full size" }).first();
        const shot = dialog.locator("[data-whats-new-screenshot]");
        // The modal's own X, relabelled while the picture is up: the picture
        // has no X of its own, so there is one X on screen, not two (#187).
        const close = dialog.getByRole("button", { name: "Close the screenshot" });
        const modalX = dialog.getByRole("button", { name: "Close", exact: true });

        const open = async () => {
            await thumbnail.click();
            await expect(shot).toBeVisible();
            await expect(close).toBeFocused();
        };

        const putBack = async (how: string) => {
            await expect(shot, how).toBeHidden();
            await expect(dialog, `What's new is still open after ${how}`).toBeVisible();
            await expect(thumbnail, `the keyboard is back on the thumbnail after ${how}`).toBeFocused();
            expect(page.url(), how).toBe(url);
        };

        await open();
        expect(context.pages(), "no new tab").toHaveLength(pages);
        await expect(shot.getByRole("button"), "the picture brings no X of its own").toHaveCount(0);
        await expect(modalX, "the modal's X is the one that shrinks it").toHaveCount(0);
        await expect(shot.locator("img")).toHaveAttribute("src", (await thumbnail.getAttribute("href")) ?? "");

        // It fills the body of the dialog — within the body's 1px top rule —
        // and the picture ends up bigger than its thumbnail.
        const [layer, inner, small] = await Promise.all([shot.boundingBox(), body.boundingBox(), thumbnail.boundingBox()]);
        expect(Math.abs((layer?.width ?? 0) - (inner?.width ?? 0))).toBeLessThanOrEqual(2);
        expect(Math.abs((layer?.height ?? 0) - (inner?.height ?? 0))).toBeLessThanOrEqual(2);
        await expect.poll(async () => (await shot.locator("img").boundingBox())?.width ?? 0).toBeGreaterThan(small?.width ?? 0);

        // Tab has nowhere else to go.
        await page.keyboard.press("Tab");
        await expect(close).toBeFocused();

        await close.click();
        await putBack("the X");
        await expect(modalX, "and the X is What's new's again").toBeVisible();

        await open();
        await page.keyboard.press("Escape");
        await putBack("Escape");

        await open();
        await page.goBack();
        await putBack("Back");

        // And the next Escape is What's new's again.
        await page.keyboard.press("Escape");
        await expect(dialog).toBeHidden();
    });

    /**
     * The X pressed twice: once to put the picture back, once more to close
     * What's new (#187).
     */
    test("the modal's X puts a screenshot back first, then closes What's new", async ({ page }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        await expect(dialog.locator("[data-whats-new-notes]")).toBeVisible();

        await dialog.locator("[data-whats-new-notes]").evaluate((notes) => { notes.dataset.stale = ""; });
        await dialog.locator("[data-whats-new-history] summary").click();
        await dialog.locator('[data-whats-new-release="v0.3.5"]').first().click();
        await expect(dialog.locator('[data-whats-new-notes="v0.3.5"]:not([data-stale])')).toBeVisible();

        const shot = dialog.locator("[data-whats-new-screenshot]");

        await dialog.getByRole("link", { name: "Open the screenshot full size" }).first().click();
        await expect(shot).toBeVisible();

        await dialog.getByRole("button", { name: "Close the screenshot" }).click();
        await expect(shot).toBeHidden();
        await expect(dialog, "the first press only puts the picture back").toBeVisible();

        await dialog.getByRole("button", { name: "Close", exact: true }).click();
        await expect(dialog).toBeHidden();
    });

    /**
     * Over the calendar, whose day headings painted on top of the menu while
     * it was an ordinary dropdown inside the header's stacking context. Asked
     * the way menu-escapes-pane.spec.ts asks it: what is at the menu's centre
     * is the menu.
     */
    test("the ? menu is above the calendar", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.locator("[data-whats-new-menu] > button").click();
        const item = page.getByRole("menuitem", { name: "What's new" });
        await expect(item).toBeVisible();

        const onTop = await item.evaluate((el) => {
            const box = el.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

            return null !== hit && el.contains(hit);
        });

        expect(onTop, "something is painted over the What's new item").toBe(true);

        // And choosing it closes the menu, so it does not stay above the dialog.
        await item.click();
        await expect(page.locator("#modal [data-whats-new-notes]")).toBeVisible();
        await expect(item).toBeHidden();
    });

    /**
     * A refusal from something in front of plMail — here a proxy's 413 for a
     * large body — is said in the form, and the dialog stays. It used to be
     * visited as a page by Turbo, which closed the dialog and said nothing.
     */
    test("a refused feedback request says so and the dialog stays", async ({ page }) => {
        await page.route("**/whats-new/feedback", (route) => route.fulfill({
            status: 413,
            contentType: "text/html",
            body: "<html><body><h1>413 Request Entity Too Large</h1></body></html>",
        }));

        await page.goto("/settings?section=general");
        await page.locator("[data-whats-new-menu] > button").click();

        const feedback = page.getByRole("menuitem", { name: "Send feedback" });
        test.skip(0 === (await feedback.count()), "the fixture user has no account to send from");
        await feedback.click();

        const form = page.locator("#modal [data-whats-new-feedback]");
        await form.locator("textarea[name=message]").fill("Hello");
        await form.getByLabel("Attach a screenshot of the page you were on").check();
        await expect(form.locator("input[name=screenshot]")).not.toHaveValue("", { timeout: 15_000 });

        await form.getByRole("button", { name: "Send feedback" }).click();

        await expect(form.getByRole("alert")).toContainText("413");
        await expect(page.locator("#modal-backdrop")).toBeVisible();
        await expect(form.locator("textarea[name=message]")).toHaveValue("Hello");
    });

    /**
     * Sent with a screenshot: the answer is drawn in the dialog and the
     * dialog stays. The server's side of a real send is WhatsNewTest's; the
     * answer here is the one it gives, so this proves the page's side.
     */
    test("feedback with a screenshot ends on the sent screen, in the dialog", async ({ page }) => {
        let body = "";

        await page.route("**/whats-new/feedback", async (route) => {
            body = route.request().postData() ?? "";

            await route.fulfill({
                status: 200,
                contentType: "text/html",
                body: '<turbo-frame id="modal"><div role="status" data-whats-new-feedback-sent><h3>Feedback sent.</h3></div></turbo-frame>',
            });
        });

        await page.goto("/mail/inbox");
        await page.locator("[data-whats-new-menu] > button").click();

        const feedback = page.getByRole("menuitem", { name: "Send feedback" });
        test.skip(0 === (await feedback.count()), "the fixture user has no account to send from");
        await feedback.click();

        const form = page.locator("#modal [data-whats-new-feedback]");
        await form.locator("textarea[name=message]").fill("With a picture");
        await form.getByLabel("Attach a screenshot of the page you were on").check();
        await expect(form.locator("input[name=screenshot]")).toHaveValue(/^data:image\/jpeg;base64,/, { timeout: 15_000 });

        await form.getByRole("button", { name: "Send feedback" }).click();

        await expect(page.locator("#modal [data-whats-new-feedback-sent]")).toBeVisible();
        await expect(page.locator("#modal-backdrop")).toBeVisible();
        expect(body).toContain("data:image/jpeg;base64,");
    });
});

