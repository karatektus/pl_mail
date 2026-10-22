import { expect, type Page } from "@playwright/test";
import { consoleCommand, TEST_USER } from "./support/config";
import { test } from "./support/test";

/**
 * The contact editor and quick add, in a browser — the parts that are the
 * page's own: rows that make themselves, types that can be fixed with one
 * click, the save bar, and pasted details compared with the form before
 * anything is applied. The reading of the text is the server's
 * (ContactTextParserTest); here it only has to arrive and be usable.
 *
 * Each test makes its own contact on the worker's user, with a name no other
 * test uses.
 */
const SIGNATURE = [
    "Mit freundlichen Grüßen",
    "",
    "Dr. Anna-Lena van der Berg",
    "Leiterin Einkauf",
    "BERG & SÖHNE Feinkost",
    "Fon 0221 98765-12 | Fax -99",
    "a.vanderberg@berg-soehne.example",
].join("\n");

const quickRows = (page: Page) => page.locator("[data-contacts--quick-add-target='rows'] [data-row]");

async function quickAdd(page: Page, text: string): Promise<void> {
    await page.goto("/contacts/new");
    await page.getByLabel("Type or paste anything about them").fill(text);
}

test.describe("quick add", () => {
    test("reads a signature, asks about the line it cannot place, and saves what is ticked", async ({ page }) => {
        await quickAdd(page, SIGNATURE);

        await expect(page.locator("[name='displayName']")).toHaveValue("Dr. Anna-Lena van der Berg");
        await expect(page.locator("[name='familyName']")).toHaveValue("van der Berg");
        await expect(page.getByText("Left out as a greeting")).toBeVisible();
        await expect(quickRows(page)).toHaveCount(4);

        // The line the rules could not place is a question, and answering it
        // makes a row.
        await expect(page.getByText("Where does this line go?")).toBeVisible();
        await page.getByRole("combobox", { name: "Use as…: BERG & SÖHNE Feinkost" }).selectOption("company");
        await expect(page.getByText("Where does this line go?")).toBeHidden();
        await expect(quickRows(page)).toHaveCount(5);

        // A type fixed in one click; a row left out by unticking it.
        await quickRows(page).nth(2).locator("[data-kind]").selectOption("phone:home");
        await quickRows(page).nth(3).locator("[data-keep]").uncheck();
        await expect(page.getByText("Dr. Anna-Lena van der Berg with 4 details")).toBeVisible();

        await page.getByRole("button", { name: "Create contact" }).click();

        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        const details = page.locator("main, body").first();
        await expect(details).toContainText("BERG & SÖHNE Feinkost");
        await expect(details).toContainText("0221 98765-12");
        await expect(details).not.toContainText("98765-99");
    });

    test("hands everything found to the full editor", async ({ page }) => {
        await quickAdd(page, "Mia Handover <mia.handover@example.net>\nwww.handover.example");
        await expect(page.locator("[name='displayName']")).toHaveValue("Mia Handover");

        await page.getByRole("button", { name: "All fields" }).click();

        await expect(page.getByRole("heading", { name: "New contact" })).toBeVisible();
        await expect(page.locator("#f-displayName")).toHaveValue("Mia Handover");
        await expect(page.locator("[name='emails[0][value]']")).toHaveValue("mia.handover@example.net");
        await expect(page.locator("[name='urls[0][url]']")).toHaveValue("https://www.handover.example");
    });
});

test.describe("contact editor", () => {
    test("a row makes itself, the save bar counts it, and pasted details are compared first", async ({ page }) => {
        await quickAdd(page, "Paste Tester <paste.tester@example.org>");
        await expect(page.locator("[name='displayName']")).toHaveValue("Paste Tester");
        await page.getByRole("button", { name: "Create contact" }).click();
        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        await page.getByRole("link", { name: "Edit contact" }).click();

        const saveBar = page.locator("[data-contacts--editor-target='saveBar']");
        await expect(saveBar).toBeHidden();

        // Typing into the dashed row makes a real one, typed as a mobile.
        await page.getByPlaceholder("Add a phone number").pressSequentially("0171 2345678");
        await expect(page.locator("[name='phones[0][value]']")).toHaveValue("0171 2345678");
        await expect(page.locator("[name='phones[0][label]']")).toHaveValue("mobile");
        await expect(page.locator("[name='phones[0][value]']")).toBeFocused();
        await expect(saveBar).toBeVisible();
        await expect(saveBar).toContainText("1 unsaved change");

        // An address that is not one stops the save.
        await page.getByPlaceholder("Add an email address").pressSequentially("not-an-address");
        await expect(page.locator("[data-row-error]:visible")).toHaveText("That doesn't look like an email address yet.");
        await expect(page.getByRole("button", { name: "Save" })).toBeDisabled();
        await page.getByRole("button", { name: "Remove Email" }).nth(1).click();
        await expect(page.getByRole("button", { name: "Save" })).toBeEnabled();

        await page.getByRole("button", { name: "Paste details" }).click();
        await page.getByRole("textbox", { name: "Paste a signature, vCard or address" }).fill("Paste Tester\nwww.tester.example\nTorstraße 140, 10119 Berlin");

        const suggestions = page.locator("[data-suggestion]");
        await expect(suggestions).toHaveCount(3);
        await expect(suggestions.nth(0)).toContainText("Already there");
        await page.getByRole("button", { name: "Apply 2 details" }).click();

        await expect(page.getByText("Added to the form and highlighted: 2.")).toBeVisible();
        await expect(page.locator("[name='addresses[0][street]']")).toHaveValue("Torstraße 140");
        await expect(page.locator("[name='addresses[0][city]']")).toHaveValue("Berlin");

        await page.getByRole("button", { name: "Save" }).click();

        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        await expect(page.locator("body")).toContainText("Torstraße 140");
        await expect(page.locator("body")).toContainText("https://www.tester.example");
        await expect(page.locator("body")).toContainText("0171 2345678");
    });

    test("the name's parts follow the name until one is edited by hand", async ({ page }) => {
        await page.goto("/contacts/new/full");

        // The same rules as ContactTextParser::splitName().
        await page.locator("#f-displayName").fill("Dr. Hon. Med. Sergej Gil, PhD");
        await expect(page.locator("#f-namePrefix")).toHaveValue("Dr. Hon. Med.");
        await expect(page.locator("#f-givenName")).toHaveValue("Sergej");
        await expect(page.locator("#f-familyName")).toHaveValue("Gil");
        await expect(page.locator("#f-nameSuffix")).toHaveValue("PhD");

        await page.locator("#f-displayName").fill("Ursula von der Leyen");
        await expect(page.locator("#f-givenName")).toHaveValue("Ursula");
        await expect(page.locator("#f-familyName")).toHaveValue("von der Leyen");

        await page.getByRole("button", { name: "Edit parts" }).click();
        await page.locator("#f-givenName").fill("Ulla");
        await expect(page.locator("#f-displayName")).toHaveValue("Ulla von der Leyen");

        // Hand-edited parts stay put; the note says the two now disagree.
        await page.locator("#f-displayName").fill("Ursula von der Leyen-Albrecht");
        await expect(page.locator("#f-givenName")).toHaveValue("Ulla");
        await expect(page.getByText("The name and its parts no longer match.")).toBeVisible();
        await page.getByRole("button", { name: "Split the name again" }).click();
        await expect(page.locator("#f-givenName")).toHaveValue("Ursula");
        await expect(page.locator("#f-familyName")).toHaveValue("von der Leyen-Albrecht");
    });
});

test.describe("save to", () => {
    // The books are this worker's user's; take them away again so the other
    // contacts specs on this worker see a user with none.
    test.afterEach(() => {
        consoleCommand(`app:test:seed-address-books --books=0 --email=${TEST_USER.email}`);
    });

    test("up to four books are buttons, and the contact goes to the one picked", async ({ page }) => {
        consoleCommand(`app:test:seed-address-books --books=2 --email=${TEST_USER.email}`);
        await quickAdd(page, "Book Tester <book.tester@example.org>");
        await expect(page.locator("[name='displayName']")).toHaveValue("Book Tester");

        const group = page.getByRole("radiogroup", { name: "Save to" });
        await expect(group.getByRole("radio")).toHaveCount(3);
        await expect(group).not.toContainText("Company directory");
        await expect(page.getByText("Kept in plMail only.")).toBeVisible();

        await group.getByText("Family").click();
        await expect(page.getByText("Goes to Family on Nextcloud (test)")).toBeVisible();

        await page.getByRole("button", { name: "Create contact" }).click();

        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        await expect(page.locator("[data-contact-book]")).toContainText("Family");
    });

    test("past four books it is a dropdown", async ({ page }) => {
        consoleCommand(`app:test:seed-address-books --books=5 --email=${TEST_USER.email}`);
        await page.goto("/contacts/new");

        await expect(page.getByRole("radiogroup", { name: "Save to" })).toHaveCount(0);
        await expect(page.locator("select[name='addressBook'] option")).toHaveCount(6);
    });
});
