import { expect } from "@playwright/test";
import { consoleCommand } from "./support/config";
import { test } from "./support/test";

/**
 * "Improve with AI" on quick add and on the contact editor: what the page
 * does with an answer — marks what the AI changed and why, lets it be
 * undone or applied, and never applies anything by itself.
 *
 * No model: the page's own AI routes are answered in the browser with
 * page.route(), the way ai-compose.spec.ts answers the composer's. The host
 * app:test:ai-contact-help names is one nothing answers on, so a request
 * that slipped past the route would fail rather than reach anything.
 *
 * In the chromium-exclusive project: switching contact help on is
 * install-wide (AiSettings has no user column).
 */
test.beforeAll(() => {
    consoleCommand("app:test:ai-contact-help on");
});

test.afterAll(() => {
    consoleCommand("app:test:ai-contact-help off");
});

test("quick add swaps in the AI's reading, marked, and back again", async ({ page }) => {
    await page.route("**/contacts/ai/parse", (route) => route.fulfill({
        json: {
            source: "ai",
            name: "Dr. Anna-Lena van der Berg",
            parts: { namePrefix: "Dr.", givenName: "Anna-Lena", familyName: "van der Berg" },
            fields: [
                { type: "jobTitle", label: "", value: "Leiterin Einkauf" },
                { type: "company", label: "", value: "Berg & Söhne Feinkost", reason: "Was not placed by the rules" },
                { type: "phone", label: "other", value: "+49 221 98765-99", reason: "Completed from “Fax -99”" },
            ],
            unsure: [],
            skipped: [],
            existing: [],
        },
    }));

    await page.goto("/contacts/new");
    await page.getByLabel("Type or paste anything about them").fill("Dr. Anna-Lena van der Berg\nLeiterin Einkauf\nBERG & SÖHNE Feinkost");
    await expect(page.getByText("Where does this line go?")).toBeVisible();

    await page.getByRole("button", { name: "Improve with AI" }).click();

    await expect(page.getByText("Improved by AI")).toBeVisible();
    await expect(page.getByText("Was not placed by the rules")).toBeVisible();
    await expect(page.getByText("Where does this line go?")).toBeHidden();
    await expect(page.locator("[data-contacts--quick-add-target='rows'] [data-ai]:not([hidden])")).toHaveCount(2);

    await page.getByRole("button", { name: "Undo AI changes" }).click();

    await expect(page.getByText("Read by rules")).toBeVisible();
    await expect(page.getByText("Where does this line go?")).toBeVisible();
});

test("the editor shows the AI's suggestions with their reasons, and applies only what is ticked", async ({ page }) => {
    await page.goto("/contacts/new");
    await page.getByLabel("Type or paste anything about them").fill("Lena Vogt <lena.vogt@nordlicht.example>\nArt Director | Nordlicht Design GmbH\nTel. +49 30 5550123");
    await expect(page.locator("[name='displayName']")).toHaveValue("Lena Vogt");
    await page.getByRole("button", { name: "Create contact" }).click();
    await expect(page).toHaveURL(/\/contacts\/\d+$/);
    await page.getByRole("link", { name: "Edit contact" }).click();

    await page.route("**/contacts/*/ai/suggest", (route) => route.fulfill({
        json: {
            name: null,
            fields: [
                { type: "jobTitle", label: "", value: "Creative Director", reason: "Her signature says so since September" },
                { type: "phone", label: "home", value: "+49 30 5550123", reason: "A Berlin landline at home" },
                { type: "url", label: "work", value: "https://nordlicht.example", reason: "From her work address" },
            ],
            signature: { receivedAt: "2026-10-14T09:00:00+00:00", subject: "Proofs" },
        },
    }));

    await page.getByRole("button", { name: "Improve with AI" }).click();

    const suggestions = page.locator("[data-suggestion]");
    await expect(suggestions).toHaveCount(3);
    await expect(page.getByText("Her signature says so since September")).toBeVisible();
    await expect(page.getByText(/Sent this contact and the signature of their mail of/)).toBeVisible();

    // A different job title is a question, unticked; the type fix the AI
    // was asked for, and the new website, start ticked.
    await expect(suggestions.nth(0).locator("[data-check]")).not.toBeChecked();
    await expect(suggestions.nth(1)).toContainText("Changes the type from Work to Home");
    await expect(suggestions.nth(1).locator("[data-check]")).toBeChecked();

    await page.getByRole("button", { name: "Apply 2 details" }).click();

    await expect(page.locator("#f-jobTitle")).toHaveValue("Art Director");
    await expect(page.locator("[name='phones[0][label]']")).toHaveValue("home");
    await expect(page.locator("[name='urls[0][url]']")).toHaveValue("https://nordlicht.example");
});
