import { test, expect } from './support/test';
import { seed, mailRow, INBOX_SUBJECTS } from './support/config';
import { rowAction } from './support/rows';

test.beforeEach(() => seed('seed-mail'));

test('All Mail account navigation preserves archive, bulk actions and undo', async ({ page }) => {
    await page.goto('/mail/inbox');
    const accountHref = await page.locator('a[href]').evaluateAll(links => links.map(a => a.getAttribute('href')).find(href => /^\/mail\/account\/\d+$/.test(href ?? '')));
    expect(accountHref).toBeTruthy();
    await page.goto(accountHref!);
    const allLink = page.locator(`a[href="${accountHref}/all"]:visible`).first();
    if (await allLink.count() === 0) {
        await page.getByRole('button', { name: 'Show or hide folders in E2E Mailbox' }).click();
    }
    await expect(allLink).toBeVisible();
    await allLink.click();
    await expect(page.locator('#inbox-list-frame')).toHaveAttribute('data-sync-scope', 'all_mail');
    await expect(page.locator('#inbox-list-frame')).toHaveAttribute('data-list-scope-value', accountHref!.split('/').pop()!);
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
    const row = mailRow(page, INBOX_SUBJECTS.archive);
    const archived = page.waitForResponse(r => r.url().includes('/status/thread/') && r.request().method() === 'POST');
    await rowAction(row, 'Archive');
    const archiveResponse = await archived;
    expect(archiveResponse.status()).toBe(200);
    expect(archiveResponse.request().postDataJSON()).toMatchObject({scope:'all_mail',value:accountHref!.split('/').pop()});
    await expect(row).toBeVisible();
    await page.reload();
    await expect(row).toBeVisible();
    await page.goto(accountHref!);
    await expect(row).toHaveCount(0);
    await page.goBack();
    await expect(row).toBeVisible();
    await page.getByRole('checkbox', { name: 'Select all conversations' }).click();
    const bulk = page.waitForResponse(r => r.url().includes('/status/bulk/archive') && r.request().method() === 'POST');
    await page.locator('[data-mail--list-toolbar-target="actions"]').getByRole('button', { name: 'Archive', exact: true }).click();
    expect((await bulk).status()).toBe(200);
    await expect(row).toBeVisible();
    const undo = page.waitForResponse(r => r.url().includes('/status/undo/') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Undo', exact: true }).last().click();
    expect((await undo).status()).toBe(200);
    await page.goto(accountHref!);
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
});
