import { expect, type Locator, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * Always Bcc (#692), from the browser's side.
 *
 * Two things PHPUnit cannot see. The settings card: the list is a chip field
 * that saves on every chip without being rebuilt under the cursor, and an
 * address appears only once it is made an exception. And the compose window:
 * the From switch trades the copies the old address asked for for the new
 * address's, without touching an address typed into Bcc by hand.
 */

const DOCK = "#compose_dock";
const BCC_ROW = `${DOCK} [data-compose--compose-target="bccField"]`;

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

/** The first account on the card, opened. */
async function openAccount(page: Page): Promise<Locator> {
    await page.goto("/settings/compose");

    const account = page.locator("[data-compose-defaults-account]").first();

    if (null === (await account.getAttribute("open"))) {
        await account.locator("summary").first().click();
    }

    return account;
}

/** The account's own chip field. */
function accountChips(account: Locator): Locator {
    return account.locator("form[action$='/compose-defaults/bcc'] .ts-wrapper");
}

/**
 * The save a chip change makes, waited for by its request rather than by the
 * "Saved." toast: an earlier save's toast can still be on screen, and leaving
 * the page before the debounced post has gone out would drop it.
 */
function saved(page: Page): Promise<unknown> {
    return page.waitForResponse((response) =>
        response.url().includes("/compose-defaults/")
        && response.url().endsWith("/bcc")
        && "POST" === response.request().method());
}

async function addChip(field: Locator, address: string): Promise<void> {
    const before = await field.locator(".item").count();
    const save = saved(field.page());

    await field.locator("input").fill(address);
    await field.locator("input").press("Enter");
    await expect(field.locator(".item")).toHaveCount(before + 1);
    await save;
}

async function clearChips(field: Locator): Promise<void> {
    while ((await field.locator(".item").count()) > 0) {
        const before = await field.locator(".item").count();
        const save = saved(field.page());

        await field.locator(".item .remove").first().click();
        await expect(field.locator(".item")).toHaveCount(before - 1);
        await save;
    }
}

async function addAlias(page: Page, address: string): Promise<string> {
    await page.goto("/settings/aliases");

    const account = page.locator("#settings-alias-list > div > div").first();
    await account.getByPlaceholder(/Add another address/i).fill(address);
    await account.getByRole("button", { name: "Add", exact: true }).click();
    await expect(page.getByText(address)).toBeVisible();

    return address;
}

async function removeAlias(page: Page, address: string): Promise<void> {
    await page.goto("/settings/aliases");

    const row = page.locator("#settings-alias-list li").filter({ hasText: address });

    if (0 === (await row.count())) {
        return;
    }

    await row.getByRole("button", { name: "Remove" }).first().click();
    await expect(row).toHaveCount(0);
}

/** The addresses on the compose window's Bcc chips, lowercased. */
async function bccChips(page: Page): Promise<string> {
    const texts = await page.locator(`${BCC_ROW} .ts-control .item`).allTextContents();

    return texts.join(" ").toLowerCase();
}

async function pickFrom(page: Page, value: string): Promise<void> {
    await page.locator(`${DOCK} [data-compose--compose-target="fromBtn"]`).click({ timeout: 10_000 });
    await page
        .locator(`${DOCK} [data-compose--compose-target="fromDropdown"] button[data-value="${value}"]`)
        .click({ timeout: 10_000 });
}

test.describe("always Bcc", () => {
    /**
     * Two aliases rather than the account address and one alias: the seeded
     * account has no alias row of its own, so once one is added the From
     * menu offers aliases only. One alias follows the account's list, the
     * other is made an exception with its own, and From goes back and forth
     * between them.
     */
    test("chips save, an exception gets its own list, and From trades them", async ({ page }) => {
        const stamp = Date.now();
        const backup = `bcc-backup-${stamp}@example.test`;
        const aliasCopy = `bcc-alias-copy-${stamp}@example.test`;
        const typed = `bcc-typed-${stamp}@example.test`;

        const inheriting = await addAlias(page, `bcc-alias-a-${stamp}@example.test`);
        const overriding = await addAlias(page, `bcc-alias-b-${stamp}@example.test`);

        try {
            let account = await openAccount(page);

            // Neither alias differs yet, so neither is shown.
            await expect(account.locator("[data-compose-defaults-exception]").filter({ hasText: inheriting })).toBeHidden();

            const field = accountChips(account);
            await addChip(field, backup);

            // Saved without rebuilding the field: the caret is still in it,
            // and the folded summary already says what the account does.
            await expect(field.locator("input")).toBeFocused();
            await expect(account.locator("[id^='compose-defaults-summary-']")).toContainText(backup);

            // Make the second alias an exception with a list of its own.
            await account.locator("select[id^='exception-add-']").selectOption({ label: overriding });

            const exception = () => page
                .locator("[data-compose-defaults-account]").first()
                .locator("[data-compose-defaults-exception]").filter({ hasText: overriding });

            await expect(exception()).toBeVisible();
            await exception().getByRole("button", { name: "Its own list" }).click();

            // Seeded with the account's list, so it opens on what was in force.
            const own = exception().locator(".ts-wrapper");
            await expect(own.locator(".item")).toHaveCount(1);
            await clearChips(own);
            await addChip(own, aliasCopy);

            account = await openAccount(page);
            await expect(account.locator("[data-compose-defaults-exception]").filter({ hasText: overriding })).toContainText(`Bcc ${aliasCopy}`);

            // A new window: the account's copy is there, and visible.
            await page.goto("/mail/inbox");
            await page.getByRole("link", { name: "Compose" }).first().click({ timeout: 10_000 });
            await expect(page.locator(BCC_ROW)).toBeVisible();
            await expect.poll(() => bccChips(page)).toContain(backup);

            const accountId = await page
                .locator(`${DOCK} [data-compose--compose-target="fromDropdown"] button`)
                .first()
                .evaluate((node) => String((node as HTMLElement).dataset.value).split("|")[0]);

            await pickFrom(page, `${accountId}|${inheriting}`);

            // An address typed into Bcc by hand is the sender's own.
            const input = page.locator(`${BCC_ROW} .ts-control input`);
            await input.fill(typed, { timeout: 10_000 });
            await input.press("Enter");
            await expect.poll(() => bccChips(page)).toContain(typed);

            // To the exception: its copy replaces the account's; the typed one stays.
            await pickFrom(page, `${accountId}|${overriding}`);
            await expect.poll(() => bccChips(page)).toContain(aliasCopy);
            expect(await bccChips(page)).toContain(typed);
            expect(await bccChips(page)).not.toContain(backup);

            // And back.
            await pickFrom(page, `${accountId}|${inheriting}`);
            await expect.poll(() => bccChips(page)).toContain(backup);
            expect(await bccChips(page)).toContain(typed);
            expect(await bccChips(page)).not.toContain(aliasCopy);
        } finally {
            await clearChips(accountChips(await openAccount(page)));
            await removeAlias(page, inheriting);
            await removeAlias(page, overriding);
        }
    });

    test("a half-typed address does not become a chip", async ({ page }) => {
        const field = accountChips(await openAccount(page));
        const input = field.locator("input");

        await input.fill("broken@");
        await input.press("Enter");

        await expect(field.locator(".item")).toHaveCount(0);
        await expect(page.locator(".ts-dropdown .create")).toHaveCount(0);
    });
});
