import type { Page } from "@playwright/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { expect, test } from "./support/test";

/**
 * "Add to contacts" on a sender: the dialog it opens, and both ways out of it.
 *
 * ContactFromMailTest has what the routes do. This is the part only a browser
 * sees: the badge opens the modal, the search inside it re-renders without
 * closing it, and a save closes it and turns the badge into a link.
 *
 * Every worker's seeded inbox is from sender@e2e.test, and other specs
 * (contacts-pane) leave a contact for that address behind. So each test first
 * bins whatever holds it, and bins what it made on the way out.
 *
 * The test user is per worker; E2E_SLOT pins every worker to one, so run it
 * with --workers=1 then.
 */
const SENDER = "sender@e2e.test";

/** The other address of the contact the "existing" tests add the sender to. */
const HOME = "e2e.sender.home@e2e.test";

async function binOurs(page: Page): Promise<void> {
    await binContactsHolding(page, SENDER);
    await binContactsHolding(page, HOME);
}

test.beforeEach(async ({ page }) => {
    seed("seed-mail");
    await binOurs(page);
});

test.afterEach(async ({ page }) => binOurs(page));

const badge = (page: Page) => page.locator("[data-contact-badge]").first();
const dialog = (page: Page) => page.locator("turbo-frame#modal");

async function openSendersMail(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).first().click();
    await expect(badge(page).getByRole("button", { name: "Add to contacts" })).toBeVisible();
}

/** Every contact with this address, moved to the bin through its own delete form. */
async function binContactsHolding(page: Page, address: string): Promise<void> {
    const list = await (await page.request.get(`/contacts?q=${encodeURIComponent(address)}`)).text();
    const ids = [...new Set([...list.matchAll(/data-contact-card="(\d+)"/g)].map((m) => m[1]))];

    for (const id of ids) {
        const edit = await (await page.request.get(`/contacts/${id}/edit`)).text();
        const token = new RegExp(`action="/contacts/${id}/delete"[^>]*>\\s*<input type="hidden" name="_token" value="([^"]+)"`).exec(edit)?.[1];

        if (undefined !== token) {
            await page.request.post(`/contacts/${id}/delete`, { form: { _token: token }, maxRedirects: 0 });
        }
    }
}

/** A contact made through the editor's own form, with an address of its own. */
async function createContact(page: Page, name: string, email: string): Promise<void> {
    const form = await (await page.request.get("/contacts/new/full")).text();
    const token = /name="_token" value="([^"]+)"/.exec(form)?.[1] ?? "";

    const response = await page.request.post("/contacts", {
        form: { _token: token, displayName: name, "emails[0][value]": email, "emails[0][label]": "home" },
        maxRedirects: 0,
    });

    expect(response.status()).toBe(303);
}

test("the sender joins a contact that is already there", async ({ page }) => {
    // Named like the seeded sender, at another address: the dialog's search
    // starts with the sender's name and should find them before anything is typed.
    await createContact(page, "E2E Sender", HOME);

    await openSendersMail(page);
    await badge(page).getByRole("button", { name: "Add to contacts" }).click();

    await expect(dialog(page).locator("#from-mail-q")).toHaveValue("E2E Sender");
    const match = dialog(page).locator("[data-from-mail-matches] button").filter({ hasText: HOME });
    await expect(match).toBeVisible();

    // The search is live, and a search that finds nothing keeps the dialog
    // open, with the box still focused for the next try.
    await dialog(page).locator("#from-mail-q").fill("nobody by this name");
    await expect(dialog(page).getByText("No contact matches.")).toBeVisible();
    await expect(dialog(page).locator("#from-mail-q")).toBeFocused();

    await dialog(page).locator("#from-mail-q").fill("E2E Sender");
    await match.click();

    await expect(dialog(page)).toBeHidden();
    await expect(badge(page).getByRole("link", { name: "In your contacts" })).toBeVisible();

    // One person, two addresses: the list has one E2E Sender, holding both.
    const list = await (await page.request.get(`/contacts?q=${encodeURIComponent("E2E Sender")}`)).text();
    expect(new Set([...list.matchAll(/data-contact-card="(\d+)"/g)].map((m) => m[1])).size).toBe(1);
});

test("the sender becomes a new contact under the name given in the dialog", async ({ page }) => {
    await openSendersMail(page);
    await badge(page).getByRole("button", { name: "Add to contacts" }).click();

    await expect(dialog(page).locator("#from-mail-name")).toHaveValue("E2E Sender");
    await dialog(page).locator("#from-mail-name").fill("Sender From The Dialog");
    await dialog(page).getByRole("button", { name: "Save" }).click();

    await expect(dialog(page)).toBeHidden();
    await badge(page).getByRole("link", { name: "In your contacts" }).click();
    await expect(page.getByRole("heading", { name: "Sender From The Dialog" }).first()).toBeVisible();
});

test.describe("on a screen with no pane", () => {
    test.use({ viewport: { width: 900, height: 800 } });

    test("more details opens the full editor page with the sender filled in", async ({ page }) => {
        await openSendersMail(page);
        await badge(page).getByRole("button", { name: "Add to contacts" }).click();
        await dialog(page).getByRole("link", { name: "More details" }).click();

        await expect(page).toHaveURL(/\/contacts\/new\/full\?/);
        await expect(page.locator("#f-displayName")).toHaveValue("E2E Sender");
        await expect(page.locator(`input[name^="emails"][value="${SENDER}"]`)).toHaveCount(1);
    });
});

/**
 * Remembers the address book docked or not, the way contacts-pane's
 * closeEverything() does, so each pane test starts from a known state.
 */
async function rememberPane(page: Page, open: boolean): Promise<void> {
    await page.goto("/mail/inbox");
    await page.evaluate(async (open) => {
        const el = document.querySelector("[data-controller~='contacts--pane']") as HTMLElement | null;
        const body = new FormData();
        body.append("_token", el?.getAttribute("data-contacts--pane-token-value") ?? "");
        body.append("open", open ? "1" : "0");
        await fetch(el?.getAttribute("data-contacts--pane-state-url-value") ?? "", { method: "POST", body, headers: { "X-Requested-With": "fetch" } });
    }, open);
}

test.describe("with the address book beside the mail", () => {
    test.use({ viewport: { width: 1600, height: 900 } });

    const dock = (page: Page) => page.locator("[data-side-pane='contacts']");
    const pane = (page: Page) => page.locator("turbo-frame#contacts-pane-frame");

    test.afterEach(async ({ page }) => rememberPane(page, false));

    test("a closed pane opens on the contact just made", async ({ page }) => {
        await rememberPane(page, false);
        await openSendersMail(page);
        await expect(dock(page)).toBeHidden();

        await badge(page).getByRole("button", { name: "Add to contacts" }).click();
        await dialog(page).locator("#from-mail-name").fill("Opened In The Pane");
        await dialog(page).getByRole("button", { name: "Save" }).click();

        await expect(dock(page)).toBeVisible();
        await expect(pane(page).getByRole("heading", { name: "Opened In The Pane" }).first()).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/thread\/\d+/);
    });

    test("more details opens the editor in the pane, beside the mail", async ({ page }) => {
        await rememberPane(page, false);
        await openSendersMail(page);

        await badge(page).getByRole("button", { name: "Add to contacts" }).click();
        await dialog(page).getByRole("link", { name: "More details" }).click();

        await expect(dialog(page)).toBeHidden();
        await expect(dock(page)).toBeVisible();
        await expect(pane(page).locator("#f-displayName")).toHaveValue("E2E Sender");
        await expect(pane(page).locator(`input[name^="emails"][value="${SENDER}"]`)).toHaveCount(1);
        await expect(page).toHaveURL(/\/mail\/thread\/\d+/);
    });

    test("an open pane goes to the contact the address was added to", async ({ page }) => {
        await createContact(page, "E2E Sender", HOME);
        await rememberPane(page, true);
        await openSendersMail(page);
        await expect(dock(page)).toBeVisible();

        await badge(page).getByRole("button", { name: "Add to contacts" }).click();

        // Live: typing alone finds them, no button to press.
        await dialog(page).locator("#from-mail-q").fill("e2e send");
        await expect(dialog(page).locator("turbo-frame#from-mail-matches")).toHaveAttribute("src", /q=e2e\+send/);
        await expect(dialog(page).locator("turbo-frame#from-mail-matches")).not.toHaveAttribute("busy");
        const match = dialog(page).locator("[data-from-mail-matches] button").filter({ hasText: HOME });
        await match.click();

        await expect(dialog(page)).toBeHidden();
        await expect(pane(page).getByRole("heading", { name: "E2E Sender" }).first()).toBeVisible();
        await expect(pane(page).getByText(SENDER)).toBeVisible();
    });
});
