import { test, expect, type Page } from "./support/test";
import { seed, mailRow, INBOX_SUBJECTS } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * All Mail (#57): every conversation outside Spam and the bin, across every
 * account or under one, opt-in through its eye in label settings.
 *
 * What a browser has to prove and PHPUnit cannot: that one switch brings both
 * rows, that the per-account row lines up with the folders beside it, and that
 * a row archived from All Mail stays on screen — which depends on the frame
 * telling the server which list a click came from after Turbo has swapped the
 * list in (mail-pane's _copyListContext, list_view.js).
 */

test.beforeEach(() => seed("seed-mail"));

// Back off afterwards: the row is opt-in, and every other spec signs in as
// the same person and expects the sidebar everybody starts with.
test.afterEach(async ({ page }) => {
    await setAllMail(page, false);
});

/** Switch the All Mail label on or off through the eye in label settings. */
async function setAllMail(page: Page, on: boolean): Promise<void> {
    await page.goto("/settings?section=labels");

    const toggle = page
        .locator("#settings-label-list li")
        .filter({ hasText: "All Mail" })
        .getByRole("button", { name: on ? "Show label" : "Hide label" });

    if (await toggle.count() > 0) {
        await toggle.click();
        await expect(
            page.locator("#settings-label-list li").filter({ hasText: "All Mail" })
                .getByRole("button", { name: on ? "Hide label" : "Show label" }),
        ).toHaveCount(1);
    }
}

/** The first account's own inbox link, as the sidebar draws it. */
async function firstAccountHref(page: Page): Promise<string> {
    await page.goto("/mail/inbox");

    const href = await page.locator("a[href]").evaluateAll((links) =>
        links.map((a) => a.getAttribute("href")).find((h) => /^\/mail\/account\/\d+$/.test(h ?? "")),
    );

    expect(href).toBeTruthy();

    return href!;
}

test("All Mail is off until its eye is switched on, and one switch shows both rows", async ({ page }) => {
    const accountHref = await firstAccountHref(page);

    await page.goto(accountHref);
    await expect(page.locator('#sidebar a[href="/mail/all"]')).toHaveCount(0);
    await expect(page.locator(`a[href="${accountHref}/all"]`)).toHaveCount(0);

    await setAllMail(page, true);

    await page.goto(accountHref);
    await expect(page.locator('#sidebar a[href="/mail/all"]:visible')).toHaveCount(1);

    const allLink = page.locator(`a[href="${accountHref}/all"]:visible`).first();

    if (await allLink.count() === 0) {
        await page.getByRole("button", { name: "Show or hide folders in E2E Mailbox" }).click();
    }

    await expect(allLink).toBeVisible();

    // Lined up with the account's other folder rows, not indented apart. The
    // name links only: a row's unread badge is a link of its own.
    const lefts = await allLink.evaluate((link) =>
        Array.from(link.closest("nav")!.querySelectorAll<HTMLElement>(':scope > [style="--label-depth: 12px"] > a[data-ui--sidebar-target="link"]'))
            .map((item) => ({ text: item.textContent?.trim(), left: item.getBoundingClientRect().left })),
    );

    expect(lefts.length).toBeGreaterThan(1);

    for (const row of lefts) {
        expect(Math.abs(row.left - lefts[0].left), `folder alignment: ${row.text}`).toBeLessThanOrEqual(1);
    }

    await setAllMail(page, false);

    await page.goto(accountHref);
    await expect(page.locator('#sidebar a[href="/mail/all"]')).toHaveCount(0);
    await expect(page.locator(`a[href="${accountHref}/all"]`)).toHaveCount(0);
});

test("an account's All Mail keeps an archived row, through a click, a selection and Undo", async ({ page }) => {
    const accountHref = await firstAccountHref(page);
    const accountId   = accountHref.split("/").pop()!;

    await setAllMail(page, true);
    await page.goto(accountHref);

    const allLink = page.locator(`a[href="${accountHref}/all"]:visible`).first();

    if (await allLink.count() === 0) {
        await page.getByRole("button", { name: "Show or hide folders in E2E Mailbox" }).click();
    }

    // Reached by clicking, so the list arrives through Turbo into a frame that
    // was the account inbox a moment ago — the case _copyListContext is for.
    await allLink.click();

    const frame = page.locator("#inbox-list-frame");

    await expect(frame).toHaveAttribute("data-sync-scope", "all_mail");
    await expect(frame).toHaveAttribute("data-list-scope-value", accountId);

    const row = mailRow(page, INBOX_SUBJECTS.archive);
    await expect(row).toBeVisible();

    const archived = page.waitForResponse((r) => r.url().includes("/status/thread/") && r.request().method() === "POST");
    await rowAction(row, "Archive");
    const response = await archived;

    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON()).toMatchObject({ scope: "all_mail", value: accountId });
    await expect(row).toBeVisible();

    await page.reload();
    await expect(row).toBeVisible();

    // Archived is out of the inbox, and Back brings All Mail with it.
    await page.goto(accountHref);
    await expect(row).toHaveCount(0);
    await page.goBack();
    await expect(row).toBeVisible();

    await page.getByRole("checkbox", { name: "Select all conversations" }).click();

    const bulk = page.waitForResponse((r) => r.url().includes("/status/bulk/archive") && r.request().method() === "POST");
    await page.locator('[data-mail--list-toolbar-target="actions"]').getByRole("button", { name: "Archive", exact: true }).click();

    expect((await bulk).status()).toBe(200);
    await expect(row).toBeVisible();

    const undo = page.waitForResponse((r) => r.url().includes("/status/undo/") && r.request().method() === "POST");
    await page.getByRole("button", { name: "Undo", exact: true }).last().click();

    expect((await undo).status()).toBe(200);

    await page.goto(accountHref);
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
});

test("All Mail across accounts refreshes on a sync and keeps an archived row", async ({ page }) => {
    await setAllMail(page, true);
    await page.goto("/settings");

    const link = page.locator('#sidebar a[href="/mail/all"]:visible').first();

    await expect(link).toBeVisible();
    await link.click();

    const frame = page.locator("#inbox-list-frame");

    await expect(frame).toHaveAttribute("data-sync-scope", "all_mail");
    await expect(frame).toHaveAttribute("data-list-scope-value", "");
    await expect(link).toHaveClass(/is-active/);

    // A sync of any folder can change a list that gathers every folder.
    const refreshed = page.waitForResponse((r) => r.request().headers()["x-list-fragment"] === "inbox-list-frame");
    await page.evaluate(() => document.body.dispatchEvent(new CustomEvent("core--mercure:mailbox-synced", {
        bubbles: true,
        detail: { specialUse: "archive" },
    })));
    expect((await refreshed).status()).toBe(200);

    const row = mailRow(page, INBOX_SUBJECTS.archive);

    const archived = page.waitForResponse((r) => r.url().includes("/status/thread/") && r.request().method() === "POST");
    await rowAction(row, "Archive");
    const response = await archived;

    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON()).toMatchObject({ scope: "all_mail", value: "" });
    await expect(row).toBeVisible();

    await page.reload();
    await expect(row).toBeVisible();
    await expect(link).toHaveClass(/is-active/);

    await page.getByRole("checkbox", { name: "Select all conversations" }).click();

    const bulk = page.waitForResponse((r) => r.url().includes("/status/bulk/archive") && r.request().method() === "POST");
    await page.locator('[data-mail--list-toolbar-target="actions"]').getByRole("button", { name: "Archive", exact: true }).click();

    expect((await bulk).status()).toBe(200);
    await expect(row).toBeVisible();
});
