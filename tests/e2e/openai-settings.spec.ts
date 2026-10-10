import { test, expect } from './support/test';
import { seedUser, TEST_ADMIN, login } from './support/config';

test.use({storageState:{cookies:[],origins:[]}});

const mockBaseUrl = process.env.E2E_OPENAI_MOCK_BASE_URL;
const mockLogUrl = process.env.E2E_OPENAI_MOCK_LOG_URL;
test.skip(!mockBaseUrl || !mockLogUrl, 'Requires the synthetic OpenAI mock; never use a real provider.');

test('compatible settings probe, save and endpoint change keep keys private', async ({ page }) => {
    seedUser({ email: TEST_ADMIN.email, password: TEST_ADMIN.password, admin: true });
    await login(page, TEST_ADMIN.email, TEST_ADMIN.password);
    const before = (await (await page.request.get(mockLogUrl!)).json()).length;
    const openSettings = async () => {
        const card = page.locator('details').filter({has:page.locator('#ai_settings_openAiBaseUrl')});
        if (await card.getAttribute('open') === null) { await card.locator('summary').first().click(); }
    };
    await page.goto('/admin/ai');
    await openSettings();
    await page.locator('#ai_settings_chatProvider').selectOption('openai');
    await page.locator('#ai_settings_openAiBaseUrl').fill(mockBaseUrl!);
    await page.locator('#ai_settings_openAiModel').fill('synthetic-browser-model');
    await page.locator('#ai_settings_openAiApiToken').fill('synthetic-browser-secret');
    const probe = page.waitForResponse(r => r.url().endsWith('/admin/ai/test') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Test', exact: true }).click();
    const response = await probe;
    expect(response.status()).toBe(200);
    expect(await response.text()).not.toContain('synthetic-browser-secret');
    await expect(page.locator('#ai_settings_openAiApiToken')).toHaveValue('');
    await openSettings();
    await page.locator('#ai_settings_openAiApiToken').fill('synthetic-browser-secret');
    const save = page.waitForResponse(r => r.url().endsWith('/admin/ai') && r.request().method() === 'POST');
    await page.locator('form[name="ai_settings"]').getByRole('button', { name: 'Save', exact: true }).click();
    expect((await save).status()).toBe(200);
    await page.reload();
    await expect(page.locator('#ai_settings_chatProvider')).toHaveValue('openai');
    await expect(page.locator('#ai_settings_openAiModel')).toHaveValue('synthetic-browser-model');
    await expect(page.locator('#ai_settings_openAiApiToken')).toHaveValue('');
    await openSettings();
    await page.locator('#ai_settings_openAiBaseUrl').fill(mockBaseUrl!.replace(/\/v1\/?$/, '/alternate'));
    const changed = page.waitForResponse(r => r.url().endsWith('/admin/ai/test') && r.request().method() === 'POST');
    await page.getByRole('button', {name:'Test',exact:true}).click();
    expect((await changed).status()).toBe(200);
    const calls = (await (await page.request.get(mockLogUrl!)).json()).slice(before);
    expect(calls.some((call: {url:string; authorized:boolean}) => call.url === '/v1/models' && call.authorized)).toBeTruthy();
    expect(calls.filter((call:{url:string}) => call.url === '/alternate/models').every((call:{authorized:boolean}) => !call.authorized)).toBeTruthy();
    expect(calls.some((call:{url:string}) => call.url === '/alternate/models')).toBeTruthy();
});
