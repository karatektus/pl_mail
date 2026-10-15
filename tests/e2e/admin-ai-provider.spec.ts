import { test, expect, type Locator, type Page } from "./support/test";
import { TEST_ADMIN, login, seedUser } from "./support/config";

/**
 * Admin → AI puts away the controls the chosen provider cannot use.
 *
 * The keep-alive fields and "Warm up now" are about Ollama's memory. For an
 * OpenAI-compatible endpoint they are saved, shown back and sent nowhere, so the
 * page hides them — the moment the provider is chosen, not after a save.
 *
 * Checked in a browser because it is one attribute against the cascade: the
 * blocks are hidden with `hidden`, which loses to any display class on the same
 * element. A wrapper that picked up a `flex` would stay on screen while every
 * server-side test went on passing.
 *
 * The same goes for the second model host search can have: its fields appear
 * with its tick box, and the search model's keep-alive follows whichever host
 * search ends up on.
 *
 * NOTHING IS SAVED. The provider is installation-wide, and this spec runs
 * beside others; it changes the controls and reads the page.
 */
const ADMIN = TEST_ADMIN;

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: ADMIN.email, password: ADMIN.password, admin: true });
});

/**
 * Choose an option the way a person does: open the control, click the option.
 *
 * The form theme puts a Tom Select over every select, so what is clicked is not
 * the native element. `selectOption()` sets the native one and fires `change`
 * on it directly, which proves the page reacts to the event and not that the
 * control on screen ever sends it.
 */
async function pick(page: Page, select: Locator, label: string): Promise<void> {
    const wrapper = select.locator('xpath=following-sibling::div[contains(@class, "ts-wrapper")]');

    await wrapper.locator(".ts-control").click();
    await page.locator('.ts-dropdown [role="option"]').filter({ hasText: label }).locator("visible=true").click();
}

test("the Ollama-only controls follow the provider select", async ({ page }) => {
    await login(page, ADMIN.email, ADMIN.password);
    await page.goto("/admin?section=ai");

    const provider = page.locator("#ai_settings_provider");

    // The card folds itself away once a host is on file; whichever state this
    // installation is in, the fields have to be on screen to be looked at.
    const card = page.locator("details").filter({ has: provider });

    if (null === (await card.getAttribute("open"))) {
        await card.locator("summary").first().click();
    }

    await expect(provider).toBeVisible();

    const chatKeepAlive = page.locator("#ai_settings_chatKeepAlive");
    const searchKeepAlive = page.locator("#ai_settings_embeddingKeepAlive");
    const warmUp = page.locator('[data-controller="admin--ai-warmup"] button');

    await provider.selectOption("ollama");

    await expect(chatKeepAlive).toBeVisible();
    await expect(searchKeepAlive).toBeVisible();
    await expect(warmUp).toBeVisible();

    await pick(page, provider, "OpenAI-compatible");

    await expect(provider).toHaveValue("openai");
    await expect(chatKeepAlive).toBeHidden();
    await expect(searchKeepAlive).toBeHidden();
    await expect(warmUp).toBeHidden();

    // Hidden and still part of the form: a disabled field is left out of the
    // submission, and the server would read that as the value being cleared.
    await expect(chatKeepAlive).toBeEnabled();

    // The fields that mean the same for both stay where they are.
    await expect(page.locator("#ai_settings_baseUrl")).toBeVisible();
    await expect(page.locator("#ai_settings_chatModel")).toBeVisible();
    await expect(page.locator("#ai_settings_embeddingModel")).toBeVisible();

    await provider.selectOption("ollama");

    await expect(chatKeepAlive).toBeVisible();
    await expect(warmUp).toBeVisible();
});

test("a second host for search brings its own fields, and its keep-alive follows it", async ({ page }) => {
    await login(page, ADMIN.email, ADMIN.password);
    await page.goto("/admin?section=ai");

    const provider = page.locator("#ai_settings_provider");
    const card = page.locator("details").filter({ has: provider });

    if (null === (await card.getAttribute("open"))) {
        await card.locator("summary").first().click();
    }

    const own = page.locator("#ai_settings_embeddingOwnConnection");
    const searchProvider = page.locator("#ai_settings_embeddingProvider");
    const searchAddress = page.locator("#ai_settings_embeddingBaseUrl");
    const searchKeepAlive = page.locator("#ai_settings_embeddingKeepAlive");

    // Whatever this installation has stored, start from one host.
    await own.uncheck();

    await expect(searchProvider).toBeHidden();
    await expect(searchAddress).toBeHidden();

    await own.check();

    await expect(searchProvider).toBeVisible();
    await expect(searchAddress).toBeVisible();
    await expect(page.locator("#ai_settings_embeddingApiToken")).toBeVisible();

    // Writing rented, search at home: the one Ollama setting that still means
    // something is the search model's keep-alive, and it must stay settable.
    await provider.selectOption("openai");
    await searchProvider.selectOption("ollama");

    await expect(page.locator("#ai_settings_chatKeepAlive")).toBeHidden();
    await expect(searchKeepAlive).toBeVisible();

    // And the reverse: an Ollama that writes, a search host that is not one.
    await provider.selectOption("ollama");
    await searchProvider.selectOption("openai");

    await expect(page.locator("#ai_settings_chatKeepAlive")).toBeVisible();
    await expect(searchKeepAlive).toBeHidden();

    // Back to one host: search runs where writing does, and follows it again.
    await own.uncheck();

    await expect(searchAddress).toBeHidden();
    await expect(searchKeepAlive).toBeVisible();
});
