import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { consoleCommand, login, seedUser } from "./support/config";

/**
 * The fields of Settings → Encryption look like every other field in
 * Settings, in both themes: the text inputs, the textareas, the file inputs,
 * the unlock dialog's field and the decrypted-mail checkboxes.
 *
 * Reported from the milestone-v0.4.0 build (#28, comment 6216080577): the key
 * import, the S/MIME password and the rest were hand-written with a copy of
 * the field classes that had drifted from the form theme — no shadow, no
 * disabled state, a different height, smaller labels. Both now draw from
 * templates/_partials/_field_classes.html.twig, and this compares what the
 * browser computes rather than which classes are written, so a third copy
 * would fail here however it is spelled.
 *
 * The reference is a field the form theme rendered: the name on Settings →
 * Profile. Measured, not snapshotted: border, radius, fill, shadow, inset and
 * height, the focus ring and the disabled state.
 *
 * In a person of its own with an open keyring, as encryption.spec.ts does:
 * most of these fields are only shown once the keyring is open.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PASSWORD = "a-password-for-the-fields";

/** What a field looks like, as far as the theme decides it. */
type Look = Record<string, string>;

const PROPERTIES = [
    "borderTopLeftRadius",
    "borderTopWidth",
    "borderTopColor",
    "backgroundColor",
    "boxShadow",
    "paddingLeft",
    "color",
] as const;

for (const theme of ["light", "dark"] as const) {
    test(`the encryption fields are the theme's fields, in the ${theme} theme`, async ({ page }) => {
        test.setTimeout(90_000);

        const email = await person(page);

        await page.goto("/settings?section=profile");
        await setTheme(page, theme);

        const reference = await look(page, page.locator('.min-w-0.-mx-6 input[type="text"]:visible').first());
        const referenceLabel = await page.locator(".min-w-0.-mx-6 label:visible").first().evaluate((el) => getComputedStyle(el).fontSize);
        const referenceInput = page.locator('.min-w-0.-mx-6 input[type="text"]:visible').first();

        await referenceInput.focus();
        const referenceFocus = await referenceInput.evaluate((el) => getComputedStyle(el).boxShadow);

        await page.goto("/settings?section=encryption");
        await expect(page.getByText("Set up, and unlocked in this browser.")).toBeVisible({ timeout: 15000 });
        await expect(page.locator("turbo-frame#contact-keys")).toHaveAttribute("complete", "");
        await setTheme(page, theme);

        const fields = page.locator(
            [
                "[data-controller=settings--encryption] input:not([type=hidden]):not([type=radio]):not([type=checkbox]):not([type=file]):visible",
                "[data-controller=settings--encryption] textarea:visible",
            ].join(", "),
        );
        const count = await fields.count();

        // Contacts' keys (two), key import and export (three), S/MIME (two).
        // The recovery-phrase entry is shown only for keys the password no
        // longer opens; it is the same textarea class as the key import, and
        // the unlock dialog is checked on its own below. A card that stopped
        // rendering its form would otherwise leave fewer fields and still pass.
        expect(count, `found ${count} fields on Settings → Encryption for ${email}`).toBeGreaterThanOrEqual(7);

        const offences: string[] = [];

        for (let index = 0; index < count; index++) {
            const field = fields.nth(index);
            const name = await field.evaluate((el) => el.id || el.getAttribute("name") || el.getAttribute("data-settings--encryption-target") || el.tagName);
            const measured = await look(page, field);

            for (const property of PROPERTIES) {
                if (measured[property] !== reference[property]) {
                    offences.push(`${name}: ${property} is ${measured[property]}, a theme field's is ${reference[property]}`);
                }
            }

            if ("INPUT" === measured.tag && measured.height !== reference.height) {
                offences.push(`${name}: height is ${measured.height}, a theme field's is ${reference.height}`);
            }
        }

        // The labels are the theme's labels.
        for (const label of await page.locator("[data-controller=settings--encryption] label[for]:visible").all()) {
            const size = await label.evaluate((el) => getComputedStyle(el).fontSize);

            if (size !== referenceLabel) {
                offences.push(`label for ${await label.getAttribute("for")}: ${size}, a theme label's is ${referenceLabel}`);
            }
        }

        expect(offences.join("\n"), "encryption fields differ from the form theme").toBe("");

        // The file inputs keep the button app.css gives every file input.
        for (const file of await page.locator("[data-controller=settings--encryption] input[type=file]:visible").all()) {
            const button = await file.evaluate((el) => getComputedStyle(el, "::file-selector-button").borderTopWidth);

            expect(button).toBe("1px");
        }

        // The focus ring and the disabled state, on one field of each kind:
        // the same computed box-shadow a focused theme field has, and the
        // theme's half opacity when disabled.
        const lookup = page.locator("#contact-keys-lookup");

        await lookup.focus();
        expect(await lookup.evaluate((el) => getComputedStyle(el).boxShadow), "a focused field's ring").toBe(referenceFocus);
        expect(referenceFocus).not.toBe(reference.boxShadow);

        const recover = page.locator("[data-settings--encryption-target=importText]");

        await recover.evaluate((el) => ((el as HTMLTextAreaElement).disabled = true));
        expect(await recover.evaluate((el) => getComputedStyle(el).opacity)).toBe("0.5");

        // The unlock dialog's field, which the page shows on every page.
        const unlock = await look(page, page.locator("#keyring-unlock-secret"));

        for (const property of PROPERTIES) {
            expect(unlock[property], `the unlock dialog's field: ${property}`).toBe(reference[property]);
        }

        // The decrypted-mail checkboxes are the theme's checkbox.
        const checkbox = page.locator("[data-controller=settings--encryption] input[type=checkbox][name=confirm]").first();

        await expect(checkbox).toHaveCount(1);
        expect(await checkbox.evaluate((el) => getComputedStyle(el).width)).toBe("16px");
    });
}

/** The person's focus moves the ring; measured unfocused, so blur first. */
async function look(page: Page, field: ReturnType<Page["locator"]>): Promise<Look> {
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());

    return field.evaluate((el, properties) => {
        const style = getComputedStyle(el);
        const result: Record<string, string> = { tag: el.tagName, height: style.height };

        for (const property of properties) {
            result[property] = style[property as keyof CSSStyleDeclaration] as string;
        }

        return result;
    }, [...PROPERTIES]);
}

/**
 * The theme is an attribute on <html> — see account-health.spec.ts. The
 * fields fade their colours (`transition-colors duration-100`), so a field
 * measured the instant the theme flips is caught halfway between the two:
 * waited out here rather than tolerated in the comparison.
 */
async function setTheme(page: Page, theme: "light" | "dark"): Promise<void> {
    await page.evaluate((value) => document.documentElement.setAttribute("data-theme", value), theme);
    await page.waitForTimeout(400);
}

async function person(page: Page): Promise<string> {
    const email = `fields-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;

    seedUser({ email, password: PASSWORD, stretched: true });
    consoleCommand(`app:test:seed-mail --email=${email}`);

    await login(page, email, PASSWORD);

    return email;
}
