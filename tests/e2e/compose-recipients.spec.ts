import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Addressing a message.
 *
 * The recipient fields are the part of compose that silently loses data: a
 * chip that never committed is an address the message will not go to, and the
 * form submits the underlying <select>, not what is on screen. So every
 * assertion here reads the committed chips rather than the text in the box.
 *
 * Suggestions come from the contact book, which the suite seeds none of —
 * contacts are learned from mail. They are made the way the application makes
 * them, by addressing a draft, which is also the round trip worth having.
 */

const DOCK = "#compose_dock";
const KNOWN = "recipients-known@example.test";
const OTHER = "recipients-other@example.test";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
}

/** The "To" field's committed chips. */
function chips(page: Page) {
    return page.locator(`${DOCK} .ts-control`).first().locator(".item");
}

async function addRecipient(page: Page, address: string): Promise<void> {
    const input = page.locator(`${DOCK} .ts-control input`).first();

    await input.fill(address);
    await input.press("Enter");
}

test.describe("compose recipients", () => {
    test("a typed address becomes a chip, and several stay several", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);
        await addRecipient(page, OTHER);

        await expect(chips(page)).toHaveCount(2);
        await expect(chips(page).first()).toContainText(KNOWN);
    });

    /**
     * Removing one must remove that one. Tom Select renders chips and the
     * <select> separately, so a remove that only takes the chip away leaves
     * the address on the message.
     */
    test("removing a chip removes the address, not just the chip", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);
        await addRecipient(page, OTHER);

        await chips(page).first().locator(".remove").click();

        await expect(chips(page)).toHaveCount(1);
        await expect(chips(page).first()).toContainText(OTHER);

        // Read as values, not as text: Tom Select mints these options itself
        // and gives them no label, so their text content is empty while the
        // value is the address the form would submit.
        // By id, not position: the first <select> in the dock is the account
        // to send from, and Tom Select leaves the real recipient field hidden
        // beside the chips it renders.
        const selected = await page
            .locator(`${DOCK} select[id$="toAddresses"]`)
            .evaluate((node) => Array.from(node.selectedOptions).map((option) => option.value));

        expect(selected).toContain(OTHER);
        expect(selected).not.toContain(KNOWN);
    });

    /** Cc and Bcc are their own fields, and must not collect the To address. */
    test("Cc and Bcc address separately", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);

        await page.locator(DOCK).getByRole("button", { name: /^Cc$/ }).click();

        const cc = page.locator(`${DOCK} .ts-control`).nth(1);
        await cc.locator("input").fill(OTHER);
        await cc.locator("input").press("Enter");

        await expect(chips(page)).toHaveCount(1);
        await expect(cc.locator(".item")).toHaveCount(1);
        await expect(cc.locator(".item")).toContainText(OTHER);
    });

    /**
     * Autocomplete over the contact book — which is the point of it, and the
     * reason a draft is saved first: contacts are learned, not seeded.
     */
    test("a known correspondent is suggested and committed by Tab", async ({ page }) => {
        await openCompose(page);
        await addRecipient(page, KNOWN);

        const saved = page.waitForResponse(
            (r) => r.url().includes("/compose/draft") && r.request().method() === "POST",
        );
        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`).fill("Learning a contact");
        await saved;

        await openCompose(page);

        const input = page.locator(`${DOCK} .ts-control input`).first();
        await input.fill("recipients-known");

        await expect(page.locator(".ts-dropdown .active")).toContainText(KNOWN);
        await input.press("Tab");

        await expect(chips(page)).toContainText(KNOWN);
    });

    /**
     * Enter in the recipient field commits a chip. It must not submit the
     * form — pressing it while addressing would send a message with no body,
     * and there is no taking that back beyond the undo window.
     */
    test("Enter commits a recipient rather than sending", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);

        await expect(chips(page)).toHaveCount(1);
        await expect(page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)).toBeVisible();
    });
});
