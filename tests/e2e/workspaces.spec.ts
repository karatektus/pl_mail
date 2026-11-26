import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { INBOX_LINK } from "./support/sidebar";
import { calendar, col, only, pane, row, useWorkspace, useWorkspaces, type Kind } from "./support/workspace";

/**
 * Workspaces (#755): panes arranged by the user, side by side and above and
 * below, from each pane's menu, the keyboard, the Layout menu and edit mode.
 *
 * What is measured is where the panes end up on screen — a pane that is in the
 * DOM but drawn nowhere, or two panes stacked where they should sit side by
 * side, is the failure that matters — and that a reload draws the same thing,
 * because the server draws the first paint and the controller only moves panes
 * afterwards. WorkspaceShellTest has the first paint's markup.
 *
 * Every test starts from, and returns to, the list alone: the workspace is
 * stored on the worker's user, which the rest of the suite shares.
 */

const WIDE = { width: 1600, height: 900 };

const NAMES: Record<Kind, string> = { list: "Mail list", message: "Message", calendar: "Calendar", contacts: "Contacts", tasks: "Tasks" };

/** ContactAssistController::parse(), which quick add asks after a pause in typing. */
const PARSE_PATH = "/contacts/parse";

const menu = (page: Page) => page.locator(".layout-menu[role='menu']");

/** Open a pane's ⋯ menu. */
async function openMenu(page: Page, kind: Kind): Promise<void> {
    await pane(page, kind).locator("[data-pane-menu-button]").first().click();
    await expect(menu(page)).toBeVisible();
}

/** ⋯ → Split right / below → a pane. */
async function split(page: Page, kind: Kind, direction: "row" | "col", add: Kind): Promise<void> {
    await openMenu(page, kind);

    const group = menu(page).locator(`[data-split-direction="${direction}"]`);

    await group.locator(":scope > button").hover();
    await group.getByRole("menuitem", { name: NAMES[add] }).click();
}

/**
 * The next save's answer. A change is sent after a short pause, so it is
 * waited for as this rather than as "networkidle", which can be reached
 * before the save has even started: a reload then draws the workspace from
 * before it. Start waiting before the change.
 */
function nextSave(page: Page) {
    return page.waitForResponse((response) => response.url().endsWith("/layout/workspaces") && "POST" === response.request().method());
}

/** Layout → Edit this workspace. */
async function editWorkspace(page: Page): Promise<void> {
    await page.getByRole("button", { name: "Layout" }).click();
    await page.locator(".layout-presets").getByRole("button", { name: /Edit this workspace/ }).click();
    await expect(page.locator("[data-layout-editing]")).toHaveCount(1);
}

/** The picker on a pane's card in edit mode. */
function editPicker(page: Page, kind: Kind) {
    return pane(page, kind).locator(":scope > .layout-edit-card select");
}

/** The two panes' boxes, for "side by side" and "above and below". */
async function boxes(page: Page, a: Kind, b: Kind) {
    return [(await pane(page, a).boundingBox())!, (await pane(page, b).boundingBox())!] as const;
}

test.beforeEach(async ({ page }) => {
    seed("seed-mail");
    await page.setViewportSize(WIDE);
    await useWorkspace(page, only("list"));
});

test.afterEach(async ({ page }) => {
    // A test that closes its tab puts the list back from the one it opened.
    if (false === page.isClosed()) {
        await useWorkspace(page, only("list"));
    }
});

test.describe("the pane menu", () => {
    test("split right puts a pane beside, and a reload draws it there", async ({ page }) => {
        const saved = nextSave(page);

        await split(page, "list", "row", "calendar");

        await expect(pane(page, "calendar").locator("turbo-frame#calendar-pane-frame")).toBeVisible();

        const [list, calendar] = await boxes(page, "list", "calendar");
        expect(calendar.x).toBeGreaterThan(list.x + list.width - 1);
        expect(Math.abs(calendar.y - list.y)).toBeLessThan(2);

        // Saved: the server draws it on the next load, before any script.
        expect((await saved).ok()).toBe(true);
        await page.reload();
        await expect(pane(page, "calendar")).toBeVisible();
        await expect(page.locator("[data-layout-split] [data-layout-pane='calendar']")).toHaveCount(1);
    });

    test("a change still waiting to be sent goes when the tab is closed", async ({ page, context }) => {
        // A change is sent after a short pause, and a tab closed inside it
        // never ran the timer: the change was gone. It is sent as the page
        // goes now (pagehide, keepalive). That request has no answer the test
        // can wait for, so the server is asked: a new tab loads the mail until
        // it draws the change. Dropped, no load ever does.
        await split(page, "list", "row", "calendar");
        await page.close({ runBeforeUnload: true });

        const next = await context.newPage();

        await next.setViewportSize(WIDE);
        await expect(async () => {
            await next.goto("/mail/inbox");
            await expect(next.locator("[data-layout-split] [data-layout-pane='calendar']")).toHaveCount(1, { timeout: 500 });
        }).toPass({ timeout: 5_000 });

        await useWorkspace(next, only("list"));
    });

    test("split below puts a pane underneath, sharing the height", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));

        await split(page, "calendar", "col", "contacts");

        const [calendar, contacts] = await boxes(page, "calendar", "contacts");
        expect(contacts.y).toBeGreaterThan(calendar.y + calendar.height - 1);
        expect(Math.abs(contacts.x - calendar.x)).toBeLessThan(2);
        expect(Math.abs(contacts.width - calendar.width)).toBeLessThan(2);
    });

    test("a split offers only the panes the workspace does not show, and calendars up to four", async ({ page }) => {
        await useWorkspace(page, row("list", "contacts", "calendar"));
        await openMenu(page, "list");

        const group = menu(page).locator('[data-split-direction="row"]');
        await group.locator(":scope > button").hover();

        await expect(group.getByRole("menuitem", { name: "Contacts" })).toBeHidden();
        await expect(group.getByRole("menuitem", { name: "Tasks" })).toBeVisible();
        // A calendar is offered beside one on screen: each keeps its own view.
        await expect(group.getByRole("menuitem", { name: "Calendar" })).toBeVisible();

        await page.keyboard.press("Escape");
        await useWorkspace(page, row("list", "calendar", calendar("c2"), calendar("c3"), calendar("c4")));
        await openMenu(page, "list");
        await menu(page).locator('[data-split-direction="row"] > button').hover();

        await expect(menu(page).locator('[data-split-direction="row"]').getByRole("menuitem", { name: "Calendar" })).toBeHidden();
    });

    test("show instead swaps two panes that are both on screen", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));
        await openMenu(page, "calendar");

        const sub = menu(page).locator(".layout-menu-sub").filter({ hasText: "Show instead" });
        await sub.locator(":scope > button").hover();
        await sub.getByRole("menuitemradio", { name: "Mail list" }).click();

        const [list, calendar] = await boxes(page, "list", "calendar");
        expect(list.x).toBeGreaterThan(calendar.x);
    });

    test("close takes a pane away, and the last one cannot be closed", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));
        await openMenu(page, "calendar");
        await menu(page).getByRole("menuitem", { name: "Close pane" }).click();

        await expect(pane(page, "calendar")).toBeHidden();

        await openMenu(page, "list");
        await expect(menu(page).getByRole("menuitem", { name: "Close pane" })).toBeDisabled();
    });

    test("a split with no room says so instead of squeezing", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await useWorkspace(page, row("list", "calendar", "contacts"));

        await split(page, "list", "row", "message");

        await expect(page.locator("#toast-region")).toContainText("Not enough room beside Mail list");
        await expect(pane(page, "message")).toBeHidden();
    });

    test("full width gives one pane the area, and going to the mail brings the layout back", async ({ page }) => {
        await useWorkspace(page, row("list", "contacts"));
        await openMenu(page, "contacts");
        await menu(page).getByRole("menuitem", { name: "Full width" }).click();

        await expect(pane(page, "list")).toBeHidden();
        await expect(pane(page, "contacts").getByRole("button", { name: "Back to the layout" })).toBeVisible();

        await page.locator(INBOX_LINK).click();

        await expect(pane(page, "list")).toBeVisible();
        await expect(pane(page, "contacts")).toBeVisible();
    });

    test("without a list, a folder goes to the first workspace that has one", async ({ page }) => {
        await useWorkspaces(page, [
            { name: "Planning", layout: row("calendar", "tasks") },
            { name: "Triage", layout: row("list", "contacts") },
            { name: "People", layout: row("contacts", "calendar") },
        ], 2);

        const saved = nextSave(page);

        await page.locator(INBOX_LINK).click();

        const tabs = page.locator("[data-layout--workspace-target='workspaceTabs'] button");

        await expect(tabs.filter({ hasText: "Triage" })).toHaveAttribute("aria-pressed", "true");
        await expect(pane(page, "list")).toBeVisible();
        await expect(pane(page, "list").getByRole("button", { name: "Back to the layout" })).toBeHidden();

        // The highlight has slid under the tab it went to.
        await expect.poll(async () => {
            const [thumb, tab] = [await page.locator("[data-workspace-thumb]").boundingBox(), await tabs.filter({ hasText: "Triage" }).boundingBox()];

            return Math.abs(thumb!.x - tab!.x) + Math.abs(thumb!.width - tab!.width);
        }, { timeout: 2_000 }).toBeLessThan(2);
        // Said by the tab, not a toast — with a spark, which is plMail's
        // switch; one the user makes blooms instead.
        await expect(page.locator("#toast-region").getByText("Triage")).toHaveCount(0);
        await expect(page.locator(".workspace-tabs")).toHaveAttribute("data-glow", /^spark-/);

        await tabs.filter({ hasText: "Planning" }).click();
        await expect(page.locator(".workspace-tabs")).toHaveAttribute("data-glow", /^bloom-/);

        // And slid there, rather than jumping: a lookup that read undefined
        // once left every switch a jump with the highlight in the right place.
        await expect(page.locator(".workspace-tabs")).toHaveAttribute("data-thumb-sliding", "");
        await tabs.filter({ hasText: "Triage" }).click();

        // And stays: the switch is saved, as a click on the tab would be.
        expect((await saved).ok()).toBe(true);
        await page.reload();
        await expect(tabs.filter({ hasText: "Triage" })).toHaveAttribute("aria-pressed", "true");
    });

    test("with no workspace that has a list, a folder brings the list at full width, and the next folder leaves it where it is", async ({ page }) => {
        await useWorkspace(page, row("calendar", "contacts"));
        await page.locator(INBOX_LINK).click();

        await expect(pane(page, "list").getByRole("button", { name: "Back to the layout" })).toBeVisible();
        await expect(pane(page, "calendar")).toBeHidden();

        // The cell the list was put in. A render builds new cells and moves
        // every pane into them, which starts each pane's controllers over: the
        // second folder asks for what is already so, and must not cause one.
        // Waited for as the frame's load event, which layout--workspace hears
        // first (its listener is older): by then whatever it does has happened.
        await pane(page, "list").evaluate((el) => {
            const probe = window as unknown as { cell: Element | null; loaded: Promise<void> };

            probe.cell = el.parentElement;
            probe.loaded = new Promise((resolve) => document.addEventListener("turbo:frame-load", (event) => {
                if ("inbox-list-frame" === (event.target as Element).id) resolve();
            }));
        });
        await page.locator("aside").getByRole("link", { name: "Starred" }).click();
        await page.evaluate(() => (window as unknown as { loaded: Promise<void> }).loaded);

        await expect(page).toHaveURL(/\/mail\/starred/);
        expect(await pane(page, "list").evaluate((el) => el.parentElement === (window as unknown as { cell: Element | null }).cell)).toBe(true);
    });
});

test.describe("the keyboard", () => {
    test("⌥\\ splits the focused pane and ⌥⇧← moves a pane", async ({ page }) => {
        await pane(page, "list").click({ position: { x: 200, y: 20 } });
        await page.keyboard.press("Alt+Backslash");

        await expect(pane(page, "message")).toBeVisible();

        let [list, message] = await boxes(page, "list", "message");
        expect(message.x).toBeGreaterThan(list.x);

        await page.keyboard.press("Alt+Shift+ArrowLeft");

        [list, message] = await boxes(page, "list", "message");
        expect(message.x).toBeLessThan(list.x);
    });
});

test.describe("dividers", () => {
    test("a drag moves the divider, and this device remembers where", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));

        const divider = page.locator(".layout-divider").first();
        const box = (await divider.boundingBox())!;
        const before = (await pane(page, "list").boundingBox())!.width;

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 150, box.y + box.height / 2, { steps: 8 });
        await page.mouse.up();

        const dragged = (await pane(page, "list").boundingBox())!.width;
        expect(dragged).toBeLessThan(before - 100);

        await page.reload();
        expect(Math.abs((await pane(page, "list").boundingBox())!.width - dragged)).toBeLessThan(3);
    });
});

test.describe("workspaces and the Layout menu", () => {
    test("a shape saved as a new workspace becomes a tab, and the tabs switch", async ({ page }) => {
        await page.getByRole("button", { name: "Layout" }).click();
        await page.locator(".layout-presets").getByRole("button", { name: "Top + two" }).click();

        const slots = page.locator(".layout-presets [data-preset-preview] select");
        await expect(slots).toHaveCount(3);
        await slots.nth(0).selectOption("calendar");
        await page.locator(".layout-presets").getByRole("button", { name: "Save as new workspace" }).click();

        const tabs = page.locator("[data-layout--workspace-target='workspaceTabs'] button");
        await expect(tabs).toHaveCount(2);
        await expect(tabs.nth(1)).toHaveAttribute("aria-pressed", "true");

        const [calendar, list] = await boxes(page, "calendar", "list");
        expect(list.y).toBeGreaterThan(calendar.y + calendar.height - 1);

        await tabs.nth(0).click();
        await expect(tabs.nth(0)).toHaveAttribute("aria-pressed", "true");
        await expect(pane(page, "calendar")).toBeHidden();
    });
});

test.describe("edit mode", () => {
    test("a pane dragged from the tray onto an edge is added there, and kept on save", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));

        await page.getByRole("button", { name: "Layout" }).click();
        await page.locator(".layout-presets").getByRole("button", { name: /Edit this workspace/ }).click();

        const tray = page.locator("[data-layout--workspace-target='tray']");
        await expect(tray).toBeVisible();

        const chip = tray.locator("[data-tray-kind='tasks']");
        const target = (await pane(page, "calendar").boundingBox())!;
        const from = (await chip.boundingBox())!;

        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();
        await page.mouse.move(target.x + target.width / 2, target.y + target.height - 20, { steps: 12 });
        await page.mouse.up();

        await expect(pane(page, "tasks")).toBeVisible();

        // Save sends at once; waited for all the same (see nextSave).
        const saved = nextSave(page);

        await page.getByRole("button", { name: "Save workspace" }).click();
        await expect(tray).toBeHidden();
        expect((await saved).ok()).toBe(true);

        await page.reload();

        const [calendar, tasks] = await boxes(page, "calendar", "tasks");
        expect(tasks.y).toBeGreaterThan(calendar.y + calendar.height - 1);
    });

    test("cancel puts back what was there", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));

        await page.getByRole("button", { name: "Layout" }).click();
        await page.locator(".layout-presets").getByRole("button", { name: /Edit this workspace/ }).click();

        await pane(page, "calendar").getByRole("button", { name: "Remove pane" }).click();
        await expect(pane(page, "calendar")).toBeHidden();

        await page.getByRole("button", { name: "Cancel" }).click();
        await expect(pane(page, "calendar")).toBeVisible();
    });

    test("cancel leaves the server's copy as it was too", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));
        await editWorkspace(page);

        // Edit mode is a draft, not saved as it goes. A pick in a card's
        // picker was, so Cancel put the calendar back here while the next
        // load drew the tasks in its place.
        await editPicker(page, "calendar").selectOption("tasks");
        await expect(pane(page, "tasks")).toBeVisible();

        // Longer than the pause a save waits out, as anyone takes to decide:
        // a Cancel inside it once got away with it, because the save that
        // went then carried what Cancel had put back.
        await page.waitForTimeout(500);

        const saved = nextSave(page);

        await page.getByRole("button", { name: "Cancel" }).click();
        await expect(pane(page, "calendar")).toBeVisible();

        expect((await saved).ok()).toBe(true);
        await page.reload();
        await expect(pane(page, "calendar")).toBeVisible();
        await expect(pane(page, "tasks")).toBeHidden();
    });

    test("leaving edit mode by leaving the page keeps what was saved", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));
        await editWorkspace(page);

        await editPicker(page, "calendar").selectOption("tasks");
        await expect(pane(page, "calendar")).toBeHidden();

        // Twice: a save sent as the page went could land after the first
        // load was drawn. Nothing is sent from edit mode, so neither has it.
        await page.reload();
        await page.reload();
        await expect(pane(page, "calendar")).toBeVisible();
    });

    test("the tray offers a further calendar, and the picker keeps each card's", async ({ page }) => {
        await page.setViewportSize({ width: 1900, height: 900 });
        await useWorkspace(page, row("list", "calendar"));
        await editWorkspace(page);

        const tray = page.locator("[data-layout--workspace-target='tray']");
        const calendars = page.locator("[data-layout--workspace-target='body'] [data-pane-kind='calendar']");

        await expect(tray.locator("[data-tray-kind='calendar']")).toBeVisible();
        await tray.locator("[data-tray-kind='calendar']").click();
        await expect(calendars).toHaveCount(2);

        // Each card names a calendar, and a third is still on offer.
        await expect(calendars.nth(1).locator(":scope > .layout-edit-card select")).toHaveValue("calendar");
        await expect(tray.locator("[data-tray-kind='calendar']")).toBeVisible();

        await page.getByRole("button", { name: "Cancel" }).click();
        await expect(calendars).toHaveCount(1);
    });

    test("nothing a pane draws shows above its card", async ({ page }) => {
        await useWorkspace(page, row("list", "tasks"));

        // As on a theme with no glass: a card with a backdrop-filter is a
        // stacking context of its own and holds its rows below the edit card
        // by accident. Without one, the rows' z-10 buttons (and the task's
        // tick) were drawn over it.
        await page.addStyleTag({ content: ".layout-card { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }" });
        await editWorkspace(page);

        const above = await page.evaluate(() => {
            let count = 0;

            document.querySelectorAll("[data-layout-pane]").forEach((slot) => {
                const card = slot.querySelector(":scope > .layout-edit-card");

                slot.querySelectorAll(":scope > :not(.layout-edit-card) *").forEach((el) => {
                    const box = el.getBoundingClientRect();

                    if (null === card || box.width < 4 || box.height < 4) {
                        return;
                    }

                    const top = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);

                    if (top && slot.contains(top) && false === card.contains(top)) {
                        count++;
                    }
                });
            });

            return count;
        });

        expect(above).toBe(0);
        await page.getByRole("button", { name: "Cancel" }).click();
    });

    test("every card's picker names its own pane, after a swap too", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar", "tasks"));
        await editWorkspace(page);

        const picker = (kind: Kind) => editPicker(page, kind);

        // The list's card picks the tasks: the two swap places, and each card
        // follows the pane it is on. Every render moves the cards, and the
        // enhanced select they had fell back to its first option on a move.
        await picker("list").selectOption("tasks");

        for (const kind of ["list", "calendar", "tasks"] as const) {
            await expect(picker(kind)).toHaveValue(kind);
            await expect(picker(kind).locator("option:checked")).toHaveText(NAMES[kind]);
        }

        const [tasks, list] = await boxes(page, "tasks", "list");
        expect(list.x).toBeGreaterThan(tasks.x);

        await page.getByRole("button", { name: "Cancel" }).click();
    });
});

test.describe("the panes in a workspace", () => {
    test("the address book runs inside its pane, and a reference email opens beside it", async ({ page }) => {
        await useWorkspace(page, row(col("list", "message"), "contacts"));

        const frame = page.locator("turbo-frame#contacts-pane-frame");

        await frame.getByRole("link", { name: "New contact" }).first().click();
        // Read on the server after a pause in typing: waited for as that
        // answer, which under load took longer than an assertion's five seconds.
        const parsed = page.waitForResponse((response) => response.url().endsWith(PARSE_PATH) && "POST" === response.request().method());

        await frame.getByLabel("Type or paste anything about them").fill("E2E Sender <sender@e2e.test>");
        await parsed;
        await expect(frame.locator("[name='displayName']")).toHaveValue("E2E Sender");
        await frame.getByRole("button", { name: "Create contact" }).click();

        await expect(page).toHaveURL(/\/mail\/inbox/);

        const reference = frame.locator("a[data-reference-email]", { hasText: INBOX_SUBJECTS.read });
        await reference.click();

        await expect(pane(page, "message").locator("[data-mail--mail-pane-target='reading']")).toContainText(INBOX_SUBJECTS.read);
        await expect(reference).toBeVisible();
    });

    test("two calendars side by side keep a view each", async ({ page }) => {
        await page.setViewportSize({ width: 1900, height: 900 });
        await useWorkspace(page, row("list", "calendar", calendar("c2")));

        const first = page.locator("turbo-frame#calendar-pane-frame");
        const second = page.locator("turbo-frame#calendar-pane-frame-c2");
        const current = (frame: typeof first) => frame.locator("[data-calendar-views] a[aria-current='page']");

        await expect(first).toBeVisible();
        await expect(second).toBeVisible();

        await first.locator("[data-calendar-views] a[aria-label='Day']").click();
        await expect(current(first)).toHaveAttribute("aria-label", "Day");
        await second.locator("[data-calendar-views] a[aria-label='Agenda']").click();
        await expect(current(second)).toHaveAttribute("aria-label", "Agenda");

        // Each one's choice left the other alone, and both are kept.
        await expect(current(first)).toHaveAttribute("aria-label", "Day");
        await page.reload();
        await expect(current(page.locator("turbo-frame#calendar-pane-frame"))).toHaveAttribute("aria-label", "Day");
        await expect(current(page.locator("turbo-frame#calendar-pane-frame-c2"))).toHaveAttribute("aria-label", "Agenda");
    });

    test("a calendar in each of two workspaces keeps its own view", async ({ page }) => {
        await useWorkspaces(page, [
            { name: "Mail", layout: row("list", "calendar") },
            { name: "Planning", layout: row("calendar", "list") },
        ], 0);

        const tabs = page.locator("[data-layout--workspace-target='workspaceTabs'] button");
        const shown = () => page.locator("[data-layout--workspace-target='body'] [data-pane-kind='calendar']");
        const current = () => shown().locator("[data-calendar-views] a[aria-current='page']");

        await shown().locator("[data-calendar-views] a[aria-label='Day']").click();
        await expect(current()).toHaveAttribute("aria-label", "Day");

        // Two workspaces, two calendars: picking Agenda in Planning's leaves
        // Mail's on Day. They were one pane, so it switched both.
        await tabs.filter({ hasText: "Planning" }).click();
        await shown().locator("[data-calendar-views] a[aria-label='Agenda']").click();
        await expect(current()).toHaveAttribute("aria-label", "Agenda");

        const saved = nextSave(page);

        await tabs.filter({ hasText: "Mail" }).click();
        await expect(current()).toHaveAttribute("aria-label", "Day");

        // And after a reload, each still has its own.
        expect((await saved).ok()).toBe(true);
        await page.reload();
        await expect(current()).toHaveAttribute("aria-label", "Day");
        await tabs.filter({ hasText: "Planning" }).click();
        await expect(current()).toHaveAttribute("aria-label", "Agenda");
    });

    test("a split can add another calendar beside one, up to four", async ({ page }) => {
        await page.setViewportSize({ width: 1900, height: 900 });
        await useWorkspace(page, row("list", "calendar"));

        await openMenu(page, "calendar");
        const group = menu(page).locator('[data-split-direction="col"]');
        await group.locator(":scope > button").hover();

        const saved = nextSave(page);

        await group.getByRole("menuitem", { name: "Calendar" }).click();

        const calendars = page.locator("[data-layout--workspace-target='body'] [data-pane-kind='calendar']");
        await expect(calendars).toHaveCount(2);

        // A pane of its own: a frame named by its id, which loads.
        await expect(calendars.nth(1).locator("turbo-frame[id^='calendar-pane-frame-'] [data-calendar-views]")).toBeVisible();

        // Saved, and drawn by the server on the next load.
        expect((await saved).ok()).toBe(true);
        await page.reload();
        await expect(calendars).toHaveCount(2);
    });

    test("choosing seven days widens the calendar pane to fit them", async ({ page }) => {
        await useWorkspace(page, { t: "split", id: "s1", dir: "row", sizes: [4, 1], children: [{ t: "pane", kind: "list" }, { t: "pane", kind: "calendar" }] });

        const frame = page.locator("turbo-frame#calendar-pane-frame");
        await frame.getByRole("link", { name: "7 days" }).click();

        await expect.poll(async () => (await pane(page, "calendar").boundingBox())!.width, { timeout: 3_000 }).toBeGreaterThanOrEqual(700);

        // But the divider can still take it narrower: its floor is Day's,
        // not the view's. It used to be stuck at the view's width.
        const divider = page.locator(".layout-divider").first();
        const box = (await divider.boundingBox())!;

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + 300, box.y + box.height / 2, { steps: 8 });
        await page.mouse.up();

        const narrowed = (await pane(page, "calendar").boundingBox())!.width;
        expect(narrowed).toBeLessThan(600);

        // And stepping on does not throw it wide again: only a change of view widens.
        const loaded = page.waitForResponse((response) => response.url().includes("/calendar/") && 200 === response.status());
        await frame.getByRole("link", { name: "Next" }).click();
        await loaded;
        await page.waitForTimeout(300);

        expect(Math.abs((await pane(page, "calendar").boundingBox())!.width - narrowed)).toBeLessThan(3);
    });

    test("a divider that goes away mid-drag does not leave the resize cursor behind", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar"));

        const divider = page.locator(".layout-divider").first();
        const box = (await divider.boundingBox())!;

        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 40, box.y + box.height / 2, { steps: 3 });
        await expect(page.locator(".is-resizing-row")).toHaveCount(1);

        // Taken out of the page with the pointer still down, as a re-render
        // does: no pointerup ever reaches it.
        await divider.evaluate((el) => el.remove());
        await page.mouse.up();

        await expect(page.locator(".is-resizing-row, .is-resizing-col")).toHaveCount(0);
    });

    // #678: one rule across the window, from under Compose through every
    // pane's header, whichever panes are side by side.
    test("every pane header's rule continues the one under Compose", async ({ page }) => {
        await useWorkspace(page, row("list", "calendar", "contacts"));
        await expect(page.locator("[data-contacts-page]")).toBeVisible();

        const bottom = (selector: string) =>
            page.locator(selector).first().evaluate((el) => Math.round(el.getBoundingClientRect().bottom));
        const line = await bottom(".compose-wrap:visible");

        expect(await bottom("[data-toolbar-row]:visible >> xpath=..")).toBe(line);
        expect(await bottom("[data-layout-pane='contacts'] [data-contacts-page] > div")).toBe(line);
        expect(await bottom("[data-layout-pane='calendar'] .border-b:has([data-calendar-position])")).toBe(line);
    });

    test("the contacts page is the pane at full width, and leaving it is the mail", async ({ page }) => {
        await page.goto("/contacts");

        await expect(page.locator("[data-layout-root]")).toHaveAttribute("data-layout-max", "contacts");
        await pane(page, "contacts").getByRole("button", { name: "Back to the layout" }).click();

        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(pane(page, "list")).toBeVisible();
    });
});

test.describe("on a phone", () => {
    test("the panes are tabs, the message is not one, and a conversation takes the list's place", async ({ page }) => {
        await useWorkspace(page, row("list", "message", "calendar"));
        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto("/mail/inbox");

        const tabs = page.locator("[data-layout--workspace-target='paneTabs'] button");
        await expect(tabs).toHaveText(["Mail list", "Calendar"]);

        await tabs.nth(1).click();
        await expect(pane(page, "calendar")).toBeVisible();
        await expect(pane(page, "list")).toBeHidden();

        await tabs.nth(0).click();
        await mailRow(page, INBOX_SUBJECTS.read).click();

        await expect(pane(page, "list").locator("[data-mail--mail-pane-target='reading']")).toBeVisible();
        await expect(page.locator("#message-list")).toBeHidden();
    });
});
