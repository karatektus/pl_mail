import { expect, type Page } from "@playwright/test";
import { acceptConfirm } from "./support/confirm";
import { choose } from "./support/select";
import { test } from "./support/test";

/**
 * The tasks docked beside the mail, and how they share the row with the other two
 * panes: the calendar gives way below a wide monitor, the address book at every
 * width, and a phone gets the page.
 *
 * What is under test is the final state after a click settles, as in
 * contacts-pane.spec, because the rule is enforced in two places (tasks--pane, and
 * app.css for the first paint) and only the result says they agree.
 */
const dock = (page: Page) => page.locator("[data-side-pane='tasks']");
const calendar = (page: Page) => page.locator("[data-side-pane='calendar']");
const contacts = (page: Page) => page.locator("[data-side-pane='contacts']");
const toggle = (page: Page) => page.locator("[data-tasks--pane-target='toggle']");
const contactsToggle = (page: Page) => page.locator("[data-contacts--pane-target='toggle']");
const frame = (page: Page) => page.locator("turbo-frame#tasks-pane-frame");
const quickAdd = (page: Page) => frame(page).getByRole("textbox", { name: "Add a task" });
const modal = (page: Page) => page.locator("#modal-backdrop");
const stepFields = (page: Page) => modal(page).locator("input[name='steps[]']");
const toast = (page: Page, text: string) => page.locator("#toast-region").getByText(text);
const completed = (page: Page) => frame(page).locator("[data-tasks-section='completed']");

/** The gear's menu, and the one thing in it. */
async function openLists(page: Page): Promise<void> {
    await frame(page).getByRole("button", { name: "Task settings" }).click();
    await frame(page).getByRole("menuitem", { name: "Lists" }).click();
    await expect(modal(page).getByRole("heading", { name: "Lists" })).toBeVisible();
}

/** Completed starts folded; this unfolds it, which the pane then remembers. */
async function openCompleted(page: Page): Promise<void> {
    await expect(completed(page)).toBeAttached();

    if (null === (await completed(page).getAttribute("open"))) {
        await completed(page).locator("summary").click();
    }

    await expect(completed(page)).toHaveAttribute("open", "");
}

/** A name no earlier test of this worker's user can have left behind. */
const unique = (name: string) => `${name} ${Date.now()}${Math.floor(Math.random() * 1000)}`;

/** All panes back to closed. The state is server-side on the per-worker user. */
async function closeEverything(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.evaluate(async () => {
        const el = document.querySelector("[data-controller~='tasks--pane']") as HTMLElement | null;

        if (null === el) {
            return;
        }

        const post = async (url: string | null, token: string | null, fields: Record<string, string>) => {
            const body = new FormData();
            body.append("_token", token ?? "");
            Object.entries(fields).forEach(([key, value]) => body.append(key, value));
            await fetch(url ?? "", { method: "POST", body, headers: { "X-Requested-With": "fetch" } });
        };

        await post(el.getAttribute("data-tasks--pane-state-url-value"), el.getAttribute("data-tasks--pane-token-value"), { open: "0" });
        await post(el.getAttribute("data-contacts--pane-state-url-value"), el.getAttribute("data-contacts--pane-token-value"), { open: "0" });
        await post(el.getAttribute("data-ui--split-state-url-value"), el.getAttribute("data-ui--split-token-value"), { mode: "mail" });
    });
}

test.describe("tasks pane on a notebook", () => {
    test.use({ viewport: { width: 1600, height: 900 } });

    test.beforeEach(async ({ page }) => closeEverything(page));
    test.afterEach(async ({ page }) => closeEverything(page));

    test("the button opens and closes it, and a reload remembers", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(dock(page)).toBeHidden();

        await toggle(page).click();
        await expect(dock(page)).toBeVisible();
        await expect(frame(page).getByRole("heading", { name: "Tasks" })).toBeVisible();

        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(dock(page)).toBeVisible();
        await expect(toggle(page)).toHaveAttribute("aria-pressed", "true");

        await frame(page).getByRole("button", { name: "Close tasks" }).click();
        await expect(dock(page)).toBeHidden();
    });

    test("it stays open with the calendar closed, and takes the calendar's place", async ({ page }) => {
        await page.goto("/mail/inbox");

        await page.locator("[data-calendar-toggle]").first().click();
        await expect(calendar(page)).toBeVisible();

        await toggle(page).click();
        await expect(dock(page)).toBeVisible();
        await expect(calendar(page)).toBeHidden();
        await expect(page.locator("[data-mail-column]")).toBeVisible();
    });

    test("it takes turns with the address book", async ({ page }) => {
        await page.goto("/mail/inbox");

        await contactsToggle(page).click();
        await expect(contacts(page)).toBeVisible();

        await toggle(page).click();
        await expect(dock(page)).toBeVisible();
        await expect(contacts(page)).toBeHidden();

        await contactsToggle(page).click();
        await expect(contacts(page)).toBeVisible();
        await expect(dock(page)).toBeHidden();
    });

    test("quick add, tick off and the modal all stay in the pane", async ({ page }) => {
        const title = unique("Buy stamps");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        // On My Day, where a new task is due today so that it stays in view.
        await expect(row).toBeVisible();
        await expect(toast(page, "Task added.")).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/inbox/);

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await expect(modal(page).getByRole("heading", { name: "Edit task" })).toBeVisible();
        await modal(page).getByLabel("Notes").fill("See https://example.org/stamps");
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();
        await expect(toast(page, "Task saved.")).toBeVisible();

        await row.getByRole("checkbox").click();
        await expect(toast(page, "Task completed.")).toBeVisible();

        // Finished today, so still here and under its own heading until midnight.
        const finished = frame(page).locator("[data-task-row]", { hasText: title });
        await expect(frame(page).getByText("Completed")).toBeVisible();
        await openCompleted(page);
        await expect(finished.getByRole("checkbox")).toHaveAttribute("aria-checked", "true");

        await finished.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByRole("button", { name: "Delete" }).click();
        await expect(modal(page)).toBeHidden();
        await expect(frame(page).locator("[data-task-row]", { hasText: title })).toHaveCount(0);
    });

    test("the options appear under the field once it is in use, and go with the title", async ({ page }) => {
        const title = unique("Dentist");
        const options = frame(page).locator("[data-tasks-quick-options]");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await expect(quickAdd(page)).toHaveAttribute("placeholder", "Add a task");
        await expect(options).toBeHidden();

        await quickAdd(page).click();
        await expect(options).toBeVisible();

        // Empty and left: out of the way again.
        await page.locator("#message-list").click({ position: { x: 5, y: 5 } });
        await expect(options).toBeHidden();

        await quickAdd(page).fill(title);
        await options.getByRole("button", { name: "Due date" }).click();
        await options.locator("input[name='dueDate']").fill("2031-03-04");
        await expect(options.getByRole("button", { name: "Due date" })).toHaveAttribute("aria-expanded", "true");

        // Regression guard: choosing a date moves the focus off the field, and the
        // title and the date must both still be there to send.
        await options.getByRole("button", { name: "Repeat" }).click();
        await choose(options.locator("#tasks-quick-repeat"), "Repeat", "Every week");
        await quickAdd(page).press("Enter");

        // Ready for the next one straight away.
        await expect(quickAdd(page)).toHaveValue("");
        await expect(quickAdd(page)).toBeFocused();
        await expect(options).toBeVisible();

        await frame(page).getByRole("link", { name: "All", exact: true }).click();

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await expect(row.getByTitle("Repeats")).toBeVisible();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await expect(modal(page).locator("input[name='dueDate']")).toHaveValue("2031-03-04");
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("steps are worked through in the dialog, and a repeating task moves when ticked", async ({ page }) => {
        const title = unique("Weekly review");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        await stepFields(page).first().fill("Empty the inbox");
        await expect(stepFields(page)).toHaveCount(2);
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        // Regression guard: a submit inside the dialog closes it unless the form
        // says otherwise, and a tick that shut the dialog behind it would make a
        // list of steps one close per step.
        await modal(page).locator("[data-task-step]").getByRole("checkbox").click();
        await expect(modal(page).locator("[data-task-step]").getByRole("checkbox")).toHaveAttribute("aria-checked", "true");

        // The row behind the dialog has caught up already, so Cancel would not leave it stale.
        await expect(row.getByText("1 of 1")).toBeAttached();

        await choose(modal(page), "Repeat", "Every week");
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();

        await expect(row.getByText("1 of 1")).toBeVisible();

        await row.getByRole("checkbox").click();

        // Today's is done, under Completed, and the task itself has moved to next
        // week, which is off My Day: one row here, and it is the finished one.
        const done = frame(page).locator("[data-task-row]", { hasText: title });

        await expect(frame(page).getByText("Completed")).toBeVisible();
        await openCompleted(page);

        await expect(done).toHaveCount(1);
        await expect(done.getByRole("checkbox")).toHaveAttribute("aria-checked", "true");

        await frame(page).getByRole("link", { name: "All", exact: true }).click();

        // Both here now: the task on its next date, and today's entry under Completed.
        const rows = frame(page).locator("[data-task-row]", { hasText: title });
        const moved = rows.filter({ has: page.getByRole("checkbox", { name: `Mark “${title}” as completed` }) });

        await expect(moved.getByText("0 of 1")).toBeVisible();

        // A list's finished tasks load once their group is open and in view.
        await completed(page).scrollIntoViewIfNeeded();
        await expect(completed(page).getByRole("checkbox", { name: `Mark “${title}” as not completed` })).toBeVisible();
        await expect(rows).toHaveCount(2);

        await moved.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("a step is ticked as it is typed, and in the row without opening the task", async ({ page }) => {
        const title = unique("Pack bags");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });
        const arrow = row.getByRole("button", { name: `Steps of “${title}”` });

        await expect(row).toBeVisible();
        await expect(arrow).toHaveCount(0);

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        const newTicks = modal(page).locator("[data-task-new-step]").getByRole("checkbox");

        // Nothing to tick until there is something typed.
        await expect(newTicks.first()).toBeDisabled();

        await stepFields(page).first().fill("Socks");
        await newTicks.first().click();
        await expect(newTicks.first()).toHaveAttribute("aria-checked", "true");

        await stepFields(page).nth(1).fill("Shoes");
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();

        await expect(row.getByText("1 of 2")).toBeVisible();

        const socks = row.getByRole("checkbox", { name: "Socks", exact: true });
        const shoes = row.getByRole("checkbox", { name: "Shoes", exact: true });

        await expect(arrow).toHaveAttribute("aria-expanded", "false");
        await expect(shoes).toBeHidden();

        await arrow.click();
        await expect(arrow).toHaveAttribute("aria-expanded", "true");
        await expect(socks).toBeVisible();
        await expect(modal(page)).toBeHidden();

        await shoes.click();
        await expect(toast(page, "Step completed.")).toBeVisible();
        await expect(row.getByText("2 of 2")).toBeVisible();

        await expect(arrow).toHaveAttribute("aria-expanded", "true");
        await expect(shoes).toHaveAttribute("aria-checked", "true");

        // A step finishing leaves its task alone.
        await expect(row.getByRole("checkbox", { name: `Mark “${title}” as completed` })).toBeVisible();

        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(arrow).toHaveAttribute("aria-expanded", "true");

        await arrow.click();
        await expect(socks).toBeHidden();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    /** Adds a task with these steps, and folds them out. */
    async function taskWithSteps(page: Page, title: string, steps: string[]) {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        for (const [index, step] of steps.entries()) {
            await stepFields(page).nth(index).fill(step);
        }

        await modal(page).getByRole("button", { name: "Save", exact: true }).click();
        await expect(modal(page)).toBeHidden();
        await row.getByRole("button", { name: `Steps of “${title}”` }).click();

        return row;
    }

    test("a step's tick shows at once, and says so only once it is saved", async ({ page }) => {
        const title = unique("Slow server");
        const row = await taskWithSteps(page, title, ["Slow step"]);
        const tick = row.getByRole("checkbox", { name: "Slow step" });

        let release = () => {};
        const held = new Promise<void>((resolve) => {
            release = resolve;
        });

        await page.route("**/tasks/step/*/tick", async (route) => {
            await held;
            await route.continue();
        });

        await tick.click();
        await expect(tick).toHaveAttribute("aria-checked", "true");
        await expect(toast(page, "Step completed.")).toHaveCount(0);
        await expect(row.getByText("0 of 1")).toBeVisible();

        release();

        await expect(toast(page, "Step completed.")).toBeVisible();
        await expect(row.getByText("1 of 1")).toBeVisible();
        await expect(tick).toHaveAttribute("aria-checked", "true");

        await page.unroute("**/tasks/step/*/tick");
    });

    test("a step's tick that cannot be saved is taken back", async ({ page }) => {
        const title = unique("Broken server");
        const row = await taskWithSteps(page, title, ["Refused step"]);
        const tick = row.getByRole("checkbox", { name: "Refused step" });

        await page.route("**/tasks/step/*/tick", (route) => route.fulfill({ status: 500, body: "" }));

        await tick.click();

        await expect(toast(page, "Step completed.")).toHaveCount(0);
        await expect(page.locator("#toast-region").getByRole("alert")).toBeVisible();
        await expect(tick).toHaveAttribute("aria-checked", "false");
        await expect(row.getByText("0 of 1")).toBeVisible();

        await page.unroute("**/tasks/step/*/tick");
        await page.route("**/tasks/step/*/tick", (route) => route.abort());

        await tick.click();
        await expect(tick).toHaveAttribute("aria-checked", "false");

        await page.unroute("**/tasks/step/*/tick");

        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(row.getByText("0 of 1")).toBeVisible();
    });

    test("steps ticked in quick succession are all saved", async ({ page }) => {
        const title = unique("Quick hands");
        const row = await taskWithSteps(page, title, ["Quick one", "Quick two", "Quick three"]);

        await row.getByRole("checkbox", { name: "Quick one" }).click();
        await row.getByRole("checkbox", { name: "Quick two" }).click();
        await row.getByRole("checkbox", { name: "Quick three" }).click();

        await expect(row.getByText("3 of 3")).toBeVisible();

        await page.waitForLoadState("networkidle");
        await page.reload();
        await expect(row.getByText("3 of 3")).toBeVisible();
    });

    test("ticking a task off folds its steps away, and unticking it does not fold them out", async ({ page }) => {
        const title = unique("Finished with");
        const row = await taskWithSteps(page, title, ["Folded step"]);
        const arrow = row.getByRole("button", { name: `Steps of “${title}”` });

        await expect(arrow).toHaveAttribute("aria-expanded", "true");

        await row.getByRole("checkbox", { name: `Mark “${title}” as completed` }).click();
        await expect(toast(page, "Task completed.")).toBeVisible();
        await openCompleted(page);

        await expect(row.getByRole("checkbox", { name: `Mark “${title}” as not completed` })).toBeVisible();
        await expect(arrow).toHaveAttribute("aria-expanded", "false");
        await expect(row.getByRole("checkbox", { name: "Folded step" })).toBeHidden();

        await arrow.click();
        await expect(row.getByRole("checkbox", { name: "Folded step" })).toBeVisible();

        await row.getByRole("checkbox", { name: `Mark “${title}” as not completed` }).click();
        await expect(row.getByRole("checkbox", { name: `Mark “${title}” as completed` })).toBeVisible();
        await expect(arrow).toHaveAttribute("aria-expanded", "true");
    });

    test("ticking a step or a task far down the list leaves the list where it was", async ({ page }) => {
        const titles = Array.from({ length: 8 }, (_, index) => unique(`Long list ${index}`));
        const last = titles[titles.length - 1];

        await page.setViewportSize({ width: 1600, height: 520 });
        await page.goto("/mail/inbox");
        await toggle(page).click();

        for (const title of titles) {
            await quickAdd(page).fill(title);
            await quickAdd(page).press("Enter");
            await expect(frame(page).locator("[data-task-row]", { hasText: title })).toBeAttached();
            await expect(quickAdd(page)).toHaveValue("");
        }

        const row = frame(page).locator("[data-task-row]", { hasText: last });

        await row.getByRole("button", { name: `Edit “${last}”` }).click();
        await stepFields(page).first().fill("First");
        await stepFields(page).nth(1).fill("Second");
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();

        await row.getByRole("button", { name: `Steps of “${last}”` }).click();

        const scroller = frame(page).locator("[data-tasks-pane-scroll]");

        await scroller.evaluate((element) => {
            element.scrollTop = element.scrollHeight;
        });

        const before = await scroller.evaluate((element) => element.scrollTop);

        expect(before).toBeGreaterThan(0);

        await row.getByRole("checkbox", { name: "First", exact: true }).click();
        await expect(row.getByText("1 of 2")).toBeVisible();
        await expect.poll(() => scroller.evaluate((element) => element.scrollTop)).toBe(before);

        // This one answers with the whole pane.
        await frame(page).getByRole("checkbox", { name: `Mark “${titles[0]}” as completed` }).dispatchEvent("click");
        await expect(toast(page, "Task completed.")).toBeVisible();
        await expect.poll(() => scroller.evaluate((element) => element.scrollTop)).toBeGreaterThan(0);

        await openCompleted(page);

        for (const title of titles) {
            await frame(page).locator("[data-task-row]", { hasText: title }).getByRole("button", { name: `Edit “${title}”` }).click();
            await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
            await expect(modal(page)).toBeHidden();
            await expect(frame(page).locator("[data-task-row]", { hasText: title })).toHaveCount(0);
        }
    });

    test("a saved step is changed in its own field, and a new one taken away with its cross", async ({ page }) => {
        const title = unique("Editable steps");
        const row = await taskWithSteps(page, title, ["Old name"]);

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        const saved = modal(page).locator("[data-task-step] input[type='text']");

        await expect(saved).toHaveValue("Old name");
        await saved.fill("New name");

        await stepFields(page).first().fill("Keep me");
        await stepFields(page).nth(1).fill("Drop me");
        await expect(stepFields(page)).toHaveCount(3);

        // The empty one at the end has nothing to take away.
        const crosses = modal(page).locator("[data-task-new-step]").getByRole("button", { name: "Remove this step" });

        await expect(crosses.nth(2)).toBeHidden();

        await crosses.nth(1).click();
        await expect(stepFields(page)).toHaveCount(2);
        await expect(stepFields(page).first()).toHaveValue("Keep me");
        await expect(stepFields(page).nth(1)).toHaveValue("");

        await modal(page).getByRole("button", { name: "Save", exact: true }).click();
        await expect(modal(page)).toBeHidden();

        await expect(row.getByText("0 of 2")).toBeVisible();
        await expect(row.getByRole("checkbox", { name: "New name", exact: true })).toBeVisible();
        await expect(row.getByRole("checkbox", { name: "Keep me", exact: true })).toBeVisible();
        await expect(row.getByText("Drop me")).toHaveCount(0);

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("a new task takes its steps and its file from the dialog", async ({ page }) => {
        const title = unique("Move house");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await frame(page).getByRole("button", { name: "New task" }).click();

        await modal(page).getByLabel("Title").fill(title);

        // Typing brings the next field, with nothing to confirm.
        await expect(stepFields(page)).toHaveCount(1);
        await stepFields(page).nth(0).fill("Pack");
        await expect(stepFields(page)).toHaveCount(2);
        await stepFields(page).nth(1).fill("Drop this one");
        await expect(stepFields(page)).toHaveCount(3);

        // Enter moves on rather than saving half a list.
        await stepFields(page).nth(1).press("Enter");
        await expect(stepFields(page).nth(2)).toBeFocused();
        await expect(modal(page)).toBeVisible();

        // Emptied, it stops being one, and the column closes up.
        await stepFields(page).nth(1).fill("");
        await stepFields(page).nth(0).focus();
        await expect(stepFields(page)).toHaveCount(2);

        await stepFields(page).nth(1).fill("Unpack");
        await expect(stepFields(page)).toHaveCount(3);

        await modal(page).getByLabel("Choose a file").setInputFiles({
            name: "boxes.txt",
            mimeType: "text/plain",
            buffer: Buffer.from("twelve"),
        });
        await modal(page).locator("input[name='dueDate']").fill("2031-03-04");
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page)).toBeHidden();

        await frame(page).getByRole("link", { name: "All", exact: true }).click();

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await expect(row.getByText("0 of 2")).toBeVisible();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await expect(modal(page).getByRole("link", { name: "boxes.txt" })).toBeVisible();
        await expect(modal(page).getByLabel("Title")).toHaveValue(title);

        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("Enter in the dialog saves the task rather than deleting it", async ({ page }) => {
        const title = unique("Kept by Enter");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        // Regression guard: Enter presses the form's first submit button, and that
        // was the footer's Delete.
        await modal(page).getByLabel("Title").fill(`${title} renamed`);
        await modal(page).getByLabel("Title").press("Enter");
        await expect(modal(page)).toBeHidden();
        await expect(toast(page, "Task saved.")).toBeVisible();

        const renamed = frame(page).locator("[data-task-row]", { hasText: `${title} renamed` });

        await expect(renamed).toHaveCount(1);

        await renamed.getByRole("button", { name: `Edit “${title} renamed”` }).click();
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("ticking a step in the dialog keeps what was typed, and keeps it open", async ({ page }) => {
        const title = unique("Half typed");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await stepFields(page).first().fill("Saved step");
        await modal(page).getByRole("button", { name: "Save", exact: true }).click();
        await expect(modal(page)).toBeHidden();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByLabel("Notes").fill("Typed and not saved");
        await stepFields(page).first().fill("Typed step");
        await expect(stepFields(page)).toHaveCount(2);

        await modal(page).locator("[data-task-step]").getByRole("checkbox").click();
        await expect(modal(page).locator("[data-task-step]").getByRole("checkbox")).toHaveAttribute("aria-checked", "true");
        await expect(toast(page, "Step completed.")).toBeAttached();

        await expect(modal(page)).toBeVisible();
        await expect(modal(page).getByLabel("Notes")).toHaveValue("Typed and not saved");
        await expect(stepFields(page).first()).toHaveValue("Typed step");
        await expect(stepFields(page)).toHaveCount(2);

        await modal(page).getByRole("button", { name: "Save", exact: true }).click();
        await expect(modal(page)).toBeHidden();
        await expect(row.getByText("1 of 2")).toBeVisible();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("a file goes up with Save and comes back as a download", async ({ page }) => {
        const title = unique("With a file");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const row = frame(page).locator("[data-task-row]", { hasText: title });

        await row.getByRole("button", { name: `Edit “${title}”` }).click();
        await modal(page).getByLabel("Choose a file").setInputFiles({
            name: "plan.txt",
            mimeType: "text/plain",
            buffer: Buffer.from("the plan"),
        });
        await expect(modal(page).getByRole("button", { name: "Upload" })).toHaveCount(0);
        await modal(page).getByRole("button", { name: "Save", exact: true }).click();
        await expect(modal(page)).toBeHidden();

        await row.getByRole("button", { name: `Edit “${title}”` }).click();

        const link = modal(page).getByRole("link", { name: "plan.txt" });

        await expect(link).toBeVisible();

        const download = page.waitForEvent("download");
        await link.click();
        expect((await download).suggestedFilename()).toBe("plan.txt");

        await modal(page).getByRole("button", { name: "Delete", exact: true }).click();
        await expect(modal(page)).toBeHidden();
    });

    test("a link in a title opens the address rather than the editor", async ({ page }) => {
        const title = unique("Read https://example.org/article");

        await page.goto("/mail/inbox");
        await toggle(page).click();
        await quickAdd(page).fill(title);
        await quickAdd(page).press("Enter");

        const link = frame(page).locator("[data-task-row]", { hasText: title }).locator("p a");

        await expect(link).toHaveAttribute("href", /^https:\/\/example\.org\/article/);
        await expect(link).toHaveAttribute("rel", "noopener noreferrer");

        // Regression guard: the row's stretched button lies over the whole row, and
        // a title link under it could be seen and not clicked.
        const popup = page.waitForEvent("popup");
        await link.click();
        await (await popup).close();
        await expect(modal(page)).toBeHidden();
    });

    test("a list is made, renamed and deleted in the Lists dialog, and its tasks grouped", async ({ page }) => {
        const name = unique("Garden");

        await page.goto("/mail/inbox");
        await toggle(page).click();

        // Behind the gear now, not at the foot of the pane.
        await expect(frame(page).getByRole("button", { name: "New list" })).toHaveCount(0);
        await openLists(page);
        await modal(page).getByRole("link", { name: "New list" }).click();
        await modal(page).getByLabel("Name").fill(name);
        await modal(page).getByRole("button", { name: "Save" }).click();

        // Back in the dialog, which stays open, and the pane has the new pill.
        await expect(modal(page).locator("[data-task-list]", { hasText: name })).toBeVisible();
        await expect(toast(page, "List saved.")).toBeAttached();

        const pill = frame(page).getByRole("link", { name });

        await expect(pill).toBeVisible();

        await modal(page).getByRole("link", { name: `Edit “${name}”` }).click();
        await modal(page).getByLabel("Name").fill(`${name} renamed`);
        await modal(page).getByRole("button", { name: "Save" }).click();
        await expect(modal(page).locator("[data-task-list]", { hasText: `${name} renamed` })).toBeVisible();
        await expect(frame(page).getByRole("link", { name: `${name} renamed` })).toBeVisible();

        await modal(page).getByRole("button", { name: "Done" }).click();
        await expect(modal(page)).toBeHidden();

        await frame(page).getByRole("link", { name: `${name} renamed` }).click();
        await expect(frame(page).getByRole("link", { name: `${name} renamed` })).toHaveAttribute("aria-current", "page");

        await quickAdd(page).fill("Water the roses");
        await quickAdd(page).press("Enter");
        await expect(frame(page).getByText("No date")).toBeVisible();

        // No filters to pick from: what is done shows under its own group, below the rest.
        await expect(frame(page).getByRole("link", { name: "Completed" })).toHaveCount(0);

        const roses = frame(page).locator("[data-task-row]", { hasText: "Water the roses" });

        await roses.getByRole("checkbox", { name: "Mark “Water the roses” as completed" }).click();
        await expect(toast(page, "Task completed.")).toBeVisible();

        // Folded until somebody unfolds it, and then it stays so when the pane redraws.
        await expect(completed(page)).not.toHaveAttribute("open", "");
        await expect(roses).toBeHidden();
        await openCompleted(page);
        await expect(roses).toBeVisible();

        // Marked, so the redraw can be told from the group it replaces.
        await completed(page).evaluate((element) => element.setAttribute("data-before", ""));
        await frame(page).getByRole("link", { name: `${name} renamed` }).click();
        await expect(frame(page).locator("[data-tasks-section='completed'][data-before]")).toHaveCount(0);
        await expect(completed(page)).toHaveAttribute("open", "");
        await expect(roses).toBeVisible();
        await expect(frame(page).getByText("No date")).toHaveCount(0);

        // Put first with the arrows, which is what a drag does for a keyboard,
        // and the pane's pills follow.
        await openLists(page);
        const firstRow = modal(page).locator("[data-task-list]").first();

        while (0 === (await firstRow.filter({ hasText: `${name} renamed` }).count())) {
            await modal(page).getByRole("button", { name: `Move “${name} renamed” up` }).click();
        }

        await expect(frame(page).locator("nav a").nth(2)).toHaveText(new RegExp(`${name} renamed`));
        await modal(page).getByRole("button", { name: "Done" }).click();
        await expect(modal(page)).toBeHidden();

        await openLists(page);
        await expect(modal(page).locator("[data-task-list]").first()).toContainText(`${name} renamed`);
        await expect(modal(page).getByRole("button", { name: "Delete “Tasks”" })).toHaveCount(0);
        await modal(page).getByRole("button", { name: `Delete “${name} renamed”` }).click();
        await acceptConfirm(page);

        // Still open, and the pane that showed the list shows them all.
        await expect(modal(page).locator("[data-task-list]", { hasText: name })).toHaveCount(0);
        await expect(modal(page)).toBeVisible();
        await expect(frame(page).getByRole("link", { name: `${name} renamed` })).toHaveCount(0);
        await expect(frame(page).getByRole("link", { name: "All", exact: true })).toHaveAttribute("aria-current", "page");
    });
});

test.describe("tasks pane on a wide monitor", () => {
    test.use({ viewport: { width: 1920, height: 1000 } });

    test.beforeEach(async ({ page }) => closeEverything(page));
    test.afterEach(async ({ page }) => closeEverything(page));

    test("it docks beside the calendar and leaves the mail on screen", async ({ page }) => {
        await page.goto("/mail/inbox");

        await page.locator("[data-calendar-toggle]").first().click();
        await toggle(page).click();

        await expect(calendar(page)).toBeVisible();
        await expect(dock(page)).toBeVisible();
        await expect(page.locator("[data-mail-column]")).toBeVisible();
    });
});

test.describe("tasks on a phone", () => {
    test.use({ viewport: { width: 390, height: 800 } });

    test("the page gives them the whole width", async ({ page }) => {
        await page.goto("/tasks");

        await expect(frame(page).getByRole("heading", { name: "Tasks" })).toBeVisible();

        const box = await dock(page).boundingBox();

        expect(box?.width ?? 0).toBeGreaterThan(340);
        await expect(page.locator("[data-mail-column]")).toBeHidden();
    });
});
