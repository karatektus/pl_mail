import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { acceptConfirm } from "./support/confirm";
import { pressLineEnd } from "./support/keyboard";

/**
 * Templates, in the two places a browser is needed to see them work.
 *
 * THE EDITOR draws each variable as a chip and has to turn the chips back into
 * `{{tokens}}` before the form posts. If that last step is skipped the server
 * stores the chip's label — "First name" — as ordinary text, the tree still
 * shows the template, and nothing is visibly wrong until the day it is
 * inserted and greets everybody as "First name".
 *
 * THE COMPOSE WINDOW is handed a template with the recipient left open and has
 * to close it when a recipient appears, whichever came first, and has to ask
 * before sending with one still open.
 *
 * What is stored, who may read it and what the server fills in are the PHPUnit
 * tests' (TemplateSectionTest, ComposeTemplatePickerTest, TemplateRendererTest).
 *
 * Serial, and the first test writes what the others read: the template is made
 * through the editor because the editor is half of what is under test, and the
 * last test removes it so the next spec on this worker finds no templates.
 */

const DOCK = "#compose_dock";
const EDITOR = '[data-compose--compose-toolbar-target="editor"]';
const SUBJECT = '[data-compose--compose-target="subject"]';
const NAME = "E2E follow-up";

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} ${EDITOR}`)).toBeVisible();
}

/** Open the picker and choose the template this file made. */
async function insertTemplate(page: Page): Promise<void> {
    await page.locator(DOCK).getByRole("button", { name: "Insert template" }).click();
    await page.locator(DOCK).getByRole("button", { name: NAME }).click();
}

/** "Insert variable" → one entry, into whichever field has the caret. */
async function insertVariable(page: Page, label: string): Promise<void> {
    const menu = page.locator('[data-settings--template-editor-target="menu"]');

    await menu.locator("summary").click();
    await menu.getByRole("button", { name: label, exact: true }).click();
}

test.describe("templates", () => {
    test("the editor stores variables as tokens and shows them as chips", async ({ page }) => {
        await page.goto("/settings?section=templates");
        await page.getByRole("link", { name: "New template", exact: true }).click();

        const editor = page.locator("turbo-frame#template-editor");
        const body = editor.locator('[data-settings--template-editor-target="body"]');
        const subject = editor.locator('[data-settings--template-editor-target="subject"]');

        await editor.getByLabel("Name").fill(NAME);

        await subject.click();
        await page.keyboard.type("Following up ");
        await insertVariable(page, "Date");

        // The date chip was just inserted, so its settings are open. Seven
        // days on, written the way a filename would be.
        const inspector = editor.locator('[data-settings--template-editor-target="inspector"]');

        await expect(inspector).toBeVisible();
        await inspector.getByLabel("Offset").fill("7");
        await inspector.getByLabel("Format").selectOption("iso");

        await expect(subject.locator(".pl-var-chip")).toHaveText(/\+7 days · ISO/);
        await expect(inspector.locator('[data-settings--template-editor-target="example"]')).toHaveText(
            /^\d{4}-\d{2}-\d{2}$/,
        );

        await body.click();
        await page.keyboard.type("Hi ");
        await insertVariable(page, "First name");
        await page.keyboard.type(", I have your address as ");
        await insertVariable(page, "Recipient's address");

        await expect(body.locator(".pl-var-chip")).toHaveCount(2);

        // The variable is now the last thing on its line, which is where a
        // browser refuses to type after a non-editable element. Leave the
        // field, come back by clicking it, and finish the sentence.
        await editor.getByLabel("Name").click();
        await body.click();
        await pressLineEnd(page);
        await page.keyboard.type(".");
        // The zero-width space is the editor's own (it is what makes the
        // caret position exist) and is stripped before the form posts — the
        // compose tests below read the stored text and find none.
        await expect(body).toContainText(/Recipient's address\u200b?\.$/);

        await editor.getByRole("button", { name: "Save", exact: true }).click();

        await expect(page.getByText("Template saved.")).toBeVisible();
        await expect(page.locator("#settings-template-tree").getByRole("link", { name: NAME })).toBeVisible();

        // Reopened, the stored text is tokens — which the editor draws as the
        // same chips again. Had the labels been stored, there would be none.
        await page.locator("#settings-template-tree").getByRole("link", { name: NAME }).click();

        await expect(body.locator(".pl-var-chip")).toHaveCount(2);
        await expect(body.locator(".pl-var-chip").first()).toHaveAttribute("data-pl-token", "{{recipient.first_name}}");
        await expect(subject.locator(".pl-var-chip")).toHaveAttribute(
            "data-pl-token",
            "{{date|offset=+7d|format=iso}}",
        );
    });

    test("an inserted template fills in what a recipient can answer and asks about the rest", async ({ page }) => {
        await openCompose(page);
        await insertTemplate(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);

        // The server's half: the date is a date, and the subject line — empty
        // until now — has taken the template's.
        await expect(page.locator(`${DOCK} ${SUBJECT}`)).toHaveValue(/^Following up \d{4}-\d{2}-\d{2}$/);

        // The window's half is still open: nobody is in To.
        await expect(editor.locator("[data-pl-var]")).toHaveCount(2);

        // A typed address has an address and no name.
        const to = page.locator(`${DOCK} .ts-control input`).first();

        await to.fill("dana@example.org");
        await to.press("Enter");

        await expect(editor).toContainText("I have your address as dana@example.org.");
        await expect(editor.locator("[data-pl-var]")).toHaveCount(1);
        await expect(editor.locator("[data-pl-var]")).toHaveText("First name");

        // Sending with a placeholder open is a question, not a send.
        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const panel = page.locator(`${DOCK} [data-compose--compose-target="sendWarning"]`);

        await expect(panel).toBeVisible();
        await expect(panel).toContainText(/placeholder has not been filled in/i);
        await panel.getByRole("button", { name: "Keep editing" }).click();
        await expect(panel).toBeHidden();
    });

    test("a recipient with a name closes the first-name variable, in either order", async ({ page }) => {
        await openCompose(page);

        // A known contact's chip reads "Name <address>" — the autocomplete
        // field's choice_label. Put one in To the way the autocomplete does,
        // surname first, which no seeded contact is.
        await page.evaluate((dock) => {
            const select = document.querySelector<HTMLSelectElement & { tomselect: any }>(
                `${dock} [data-compose--compose-target="toField"] select`,
            )!.tomselect;

            select.addOption({ value: "e2e-named", text: "Whitfield, Dana <dana@example.org>" });
            select.addItem("e2e-named");
        }, DOCK);

        // Recipient first, template second: filled on insertion.
        await insertTemplate(page);

        const editor = page.locator(`${DOCK} ${EDITOR}`);

        await expect(editor).toContainText("Hi Dana, I have your address as dana@example.org.");
        await expect(editor.locator("[data-pl-var]")).toHaveCount(0);
    });

    /**
     * The reported bug: "in a reply the template does not load".
     *
     * A reply opens with the caret parked inside the <br> of its empty first
     * line, and nobody has clicked into the body yet. The template was
     * inserted INTO that <br>, where nothing renders it — the request
     * succeeded, the picker closed and the message stayed empty. The dock
     * tests above never saw it because a new message is typed into or
     * addressed first.
     *
     * The subject is the other half of what a reply is for: it keeps its own.
     */
    test("a template lands in a reply nobody has clicked into yet, and leaves its subject alone", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();

        const inline = page.locator("#compose_inline");
        const editor = inline.locator(EDITOR);

        await expect(editor).toBeVisible();

        await inline.getByRole("button", { name: "Insert template" }).click();
        await inline.getByRole("button", { name: NAME }).click();

        // By innerText, and that is the assertion. textContent — Playwright's
        // default — includes text that sits inside a <br>, so it reported the
        // template as present while the screen showed an empty reply.
        await expect(editor).toContainText("I have your address as", { useInnerText: true });

        // And it knows who it is answering. This recipient was put in To by
        // the SERVER, as a real contact, so its chip is spelled the way the
        // app spells one — which the test above cannot vouch for, since it
        // writes its own label. That gap is how a first version of the
        // parser shipped reading a format no chip has: the reply to a named
        // sender kept its "First name" placeholder.
        await expect(editor).toContainText("Hi E2E,", { useInnerText: true });
        await expect(editor.locator("[data-pl-var]")).toHaveCount(0);
        await expect(inline.locator(SUBJECT)).toHaveValue(new RegExp(`^Re: ${INBOX_SUBJECTS.read}`));
    });

    test("the template is deleted from its editor", async ({ page }) => {
        await page.goto("/settings?section=templates");
        await page.locator("#settings-template-tree").getByRole("link", { name: NAME }).click();
        await page.locator("turbo-frame#template-editor").getByRole("button", { name: "Delete" }).click();
        await acceptConfirm(page);

        await expect(page.getByText("Template deleted.")).toBeVisible();
        await expect(page.locator("#settings-template-tree").getByRole("link", { name: NAME })).toHaveCount(0);
    });
});
