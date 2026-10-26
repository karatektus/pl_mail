import { expect, type Page } from "@playwright/test";
import { INBOX_SUBJECTS } from "./support/config";
import { test } from "./support/test";

/**
 * The address book docked beside the mail, and how it shares the row with the
 * calendar: one or the other on a notebook, both on a wide monitor.
 *
 * What is under test is the final state after a click settles — which panes
 * are drawn and what the shell reports — because the rule is enforced in two
 * places (contacts--pane, and app.css for the first paint) and only the
 * result says they agree.
 */
const shell = (page: Page) => page.locator("[data-controller~='ui--split'][data-controller~='contacts--pane']");
const dock = (page: Page) => page.locator("[data-side-pane='contacts']");
const calendar = (page: Page) => page.locator("[data-side-pane='calendar']");
const toggle = (page: Page) => page.locator("[data-contacts--pane-target='toggle']");
const frame = (page: Page) => page.locator("turbo-frame#contacts-pane-frame");

/**
 * A contact for the sender every worker's seeded inbox is from, so its page
 * lists those threads under "Reference emails". Made in the docked pane, and
 * left open there.
 */
async function createSender(page: Page): Promise<void> {
    await frame(page).getByRole("link", { name: "New contact" }).first().click();
    await frame(page).getByLabel("Type or paste anything about them").fill("E2E Sender <sender@e2e.test>");
    await expect(frame(page).locator("[name='displayName']")).toHaveValue("E2E Sender");
    await frame(page).getByRole("button", { name: "Create contact" }).click();
    await expect(frame(page).locator("a[data-reference-email]").first()).toBeVisible();
}

/** Settings → Contacts → Behaviour, which the page saves on change. */
async function chooseSteps(page: Page, option: string): Promise<void> {
    await page.goto("/settings?section=contacts");
    await page.getByRole("radiogroup", { name: "Contacts button" }).getByText(option).click();
    await expect(page).toHaveURL(/section=contacts/);
    await expect(page.getByRole("radiogroup", { name: "Contacts button" }).getByRole("radio", { name: option })).toBeChecked();
}

/**
 * Both panes back to closed. The state is server-side on the per-worker user,
 * which every other spec file this worker runs shares — see calendar-pane.
 */
async function closeEverything(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.evaluate(async () => {
        const el = document.querySelector("[data-controller~='contacts--pane']") as HTMLElement | null;

        if (null === el) {
            return;
        }

        const post = async (url: string | null, token: string | null, fields: Record<string, string>) => {
            const body = new FormData();
            body.append("_token", token ?? "");
            Object.entries(fields).forEach(([key, value]) => body.append(key, value));
            await fetch(url ?? "", { method: "POST", body, headers: { "X-Requested-With": "fetch" } });
        };

        await post(el.getAttribute("data-contacts--pane-state-url-value"), el.getAttribute("data-contacts--pane-token-value"), { open: "0" });
        await post(el.getAttribute("data-ui--split-state-url-value"), el.getAttribute("data-ui--split-token-value"), { mode: "mail" });
    });
}

test.describe("contacts pane on a notebook", () => {
    test.use({ viewport: { width: 1600, height: 900 } });

    test.beforeEach(async ({ page }) => closeEverything(page));
    test.afterEach(async ({ page }) => closeEverything(page));

    test("the contacts button steps closed, beside the mail, full width, closed", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(dock(page)).toBeHidden();
        await expect(toggle(page)).toHaveAttribute("aria-label", "Show contacts beside the mail");

        await toggle(page).click();

        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(dock(page)).toBeVisible();
        await expect(frame(page).locator("[data-contacts-page]")).toBeVisible();
        await expect(toggle(page)).toHaveAttribute("aria-pressed", "true");
        await expect(toggle(page)).toHaveAttribute("aria-label", "Show contacts full width");

        // Full width is the full page's layout on the mailbox: the mail is
        // put away, not left, and the address is still the mail's.
        await toggle(page).click();

        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");
        await expect(page.locator("[data-mail-column]")).toBeHidden();
        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(toggle(page)).toHaveAttribute("aria-label", "Hide contacts");

        // The icon of the position it is in shows, on a reload as well:
        // restored beside the mail it was once an empty square.
        await toggle(page).click();
        await toggle(page).click();
        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(toggle(page).locator("[data-contacts-mode-icon='split']")).toBeVisible();
        await toggle(page).click();

        // Remembered: a reload comes back at full width. Once the write has
        // gone, as a person's reload would be: the writes are queued in order,
        // and one still waiting when the page goes is not sent.
        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");

        await toggle(page).click();

        await expect(dock(page)).toBeHidden();
        await expect(shell(page)).not.toHaveAttribute("data-contacts-full", "");
        await expect(page.locator("[data-mail-column]")).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/inbox/);
    });

    test("with full width skipped, the button goes between closed and beside the mail", async ({ page }) => {
        await chooseSteps(page, "Beside mail only");

        try {
            await page.goto("/mail/inbox");
            await toggle(page).click();
            await expect(dock(page)).toBeVisible();
            await expect(toggle(page)).toHaveAttribute("aria-label", "Hide contacts");

            await toggle(page).click();
            await expect(dock(page)).toBeHidden();
        } finally {
            await chooseSteps(page, "All three");
        }
    });

    test("the width beside the mail survives full width, a snap and a reload", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        // Narrower than it opened, with the arrow keys on its edge.
        const handle = dock(page).getByRole("separator");
        await handle.focus();
        await handle.press("ArrowRight");
        await handle.press("ArrowRight");
        const width = Math.round((await dock(page).boundingBox())?.width ?? 0);

        // Full width at once: the two writes must not undo each other.
        await toggle(page).click();
        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");
        await toggle(page).click();
        await toggle(page).click();
        await expect(dock(page)).toBeVisible();
        expect(Math.round((await dock(page).boundingBox())?.width ?? 0)).toBe(width);

        // Snapped closed and opened again.
        const box = await handle.boundingBox();

        if (null === box) {
            throw new Error("the pane's handle is not on screen");
        }

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + 700, box.y + box.height / 2, { steps: 20 });
        await page.mouse.up();
        await expect(dock(page)).toBeHidden();
        await toggle(page).click();
        expect(Math.round((await dock(page).boundingBox())?.width ?? 0)).toBe(width);

        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(dock(page)).toBeVisible();
        expect(Math.round((await dock(page).boundingBox())?.width ?? 0)).toBe(width);
    });

    test("dragging the edge past its widest snaps the address book to full width", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        const handle = dock(page).getByRole("separator");
        const box = await handle.boundingBox();

        if (null === box) {
            throw new Error("the pane's handle is not on screen");
        }

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 1200, box.y + box.height / 2, { steps: 20 });
        await page.mouse.up();

        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");
        await expect(page.locator("[data-mail-column]")).toBeHidden();
    });

    test("dragging the edge past its narrowest closes the address book", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        const handle = dock(page).getByRole("separator");
        const box = await handle.boundingBox();

        if (null === box) {
            throw new Error("the pane's handle is not on screen");
        }

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + 700, box.y + box.height / 2, { steps: 20 });
        await page.mouse.up();

        await expect(dock(page)).toBeHidden();
    });

    test("the whole address book runs inside the pane, and its × closes it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        await frame(page).getByRole("link", { name: "New contact" }).first().click();

        await expect(frame(page).getByRole("heading", { name: "New contact" })).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/inbox/);
        // The page's own topbar did not come with it: only the frame did.
        await expect(frame(page).locator("header, [data-controller~='ui--split']")).toHaveCount(0);

        await frame(page).getByRole("button", { name: "Close contacts" }).click();

        await expect(dock(page)).toBeHidden();
    });

    test("a contact's reference email opens in the mail pane, beside the contact", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await createSender(page);

        const reference = frame(page).locator("a[data-reference-email]", { hasText: INBOX_SUBJECTS.read });
        await reference.click();

        // In the pane, not a visit: the contact is still docked beside it.
        await expect(page).toHaveURL(/\/mail\/message\/\d+/);
        await expect(page.locator("[data-mail--mail-pane-target='reading']")).toContainText(INBOX_SUBJECTS.read);
        await expect(reference).toBeVisible();
    });

    test("the pane's arrows make it full width in place, beside nothing but the mail behind it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        await frame(page).getByRole("link", { name: "Show contacts full width" }).click();

        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");
        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(toggle(page)).toHaveAttribute("aria-label", "Hide contacts");
    });

    test("from the full contacts page, the mail comes in beside the contact, which stays as it was", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await createSender(page);

        // The contact's own page, full size, with the calendar beside it — the
        // state a notebook came back to on the calendar when this was a visit.
        // A visit by address: the pane's ↗ goes full width in place now (the
        // next test), and this is about the page.
        const contactUrl = await frame(page).getAttribute("src");
        await page.goto(contactUrl ?? "/contacts");
        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        await expect(shell(page)).toHaveAttribute("data-contacts-full", "");
        await page.locator("[data-calendar-toggle]").first().click();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
        await expect(dock(page)).toBeVisible();

        // Anything a reload or a visit would lose: a mark on the window, and
        // the contact's own element.
        await page.evaluate(() => {
            (window as unknown as { __stayed: boolean }).__stayed = true;
            (window as unknown as { __page: Element | null }).__page = document.querySelector("#contacts-pane-frame [data-contacts-page]");
        });

        await frame(page).locator("a[data-reference-email]", { hasText: INBOX_SUBJECTS.read }).click();

        // The message is fetched before the mail column comes in, and on a
        // cold stack it can queue behind the calendar's own first load.
        await expect(shell(page)).not.toHaveAttribute("data-contacts-full", "", { timeout: 15_000 });
        await expect(page).toHaveURL(/\/mail\/message\/\d+/);
        await expect(page.locator("[data-mail--mail-pane-target='reading']")).toContainText(INBOX_SUBJECTS.read);
        await expect(dock(page)).toBeVisible();
        // A notebook holds one pane beside the mail: the calendar made room.
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");

        const kept = await page.evaluate(() => {
            const w = window as unknown as { __stayed?: boolean; __page?: Element | null };

            return true === w.__stayed && w.__page === document.querySelector("#contacts-pane-frame [data-contacts-page]");
        });
        expect(kept).toBe(true);
    });

    test("it is remembered across a reload", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await expect(dock(page)).toBeVisible();

        await expect(async () => {
            await page.reload();
            await expect(dock(page)).toBeVisible();
            // Rendered with the page, not fetched after it.
            await expect(frame(page)).not.toHaveAttribute("src", /.+/);
        }).toPass({ timeout: 10_000 });
    });

    test("one pane at a time: the calendar and the address book take turns", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await expect(dock(page)).toBeVisible();

        await page.locator("[data-calendar-toggle]").first().click();

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
        await expect(calendar(page)).toBeVisible();
        await expect(dock(page)).toBeHidden();
        await expect(toggle(page)).toHaveAttribute("aria-pressed", "false");

        await toggle(page).click();

        await expect(dock(page)).toBeVisible();
        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "mail");
        await expect(calendar(page)).toBeHidden();
    });
});

test.describe("contacts pane on a wide monitor", () => {
    test.use({ viewport: { width: 2560, height: 1200 } });

    test.beforeEach(async ({ page }) => closeEverything(page));
    test.afterEach(async ({ page }) => closeEverything(page));

    test("the calendar and the address book are docked side by side", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await page.locator("[data-calendar-toggle]").first().click();

        await expect(shell(page)).toHaveAttribute("data-calendar-mode", "split");
        await expect(calendar(page)).toBeVisible();
        await expect(dock(page)).toBeVisible();
        await expect(page.locator("[data-ui--split-target='main']")).toBeVisible();
    });
});
