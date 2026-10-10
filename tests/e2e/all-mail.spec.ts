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
    const geometry = await allLink.evaluate(link => {
        const siblings = Array.from(link.closest('nav')!.querySelectorAll<HTMLElement>(':scope > [style="--label-depth: 12px"] > a'));
        return siblings.map(item => ({ text: item.textContent?.trim(), left: item.getBoundingClientRect().left, top: item.getBoundingClientRect().top, height: item.getBoundingClientRect().height }));
    });
    expect(geometry.length).toBeGreaterThan(1);
    for (const sibling of geometry) {
        expect(Math.abs(sibling.left - geometry[0].left), `Account folder alignment: ${sibling.text}`).toBeLessThanOrEqual(1);
    }
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

test('Unified All Mail stays in the primary navigation and preserves archive and bulk', async ({ page }) => {
    await page.goto('/settings');
    const link = page.locator('a[href="/mail/all"]:visible').first();
    await expect(link).toBeVisible();
    expect(await link.evaluate(el => el.closest('details') === null)).toBe(true);
    await link.hover();
    await page.waitForTimeout(200);
    await link.click();
    await expect(page.locator('#account-list')).toBeVisible();
    await expect(page.locator('#inbox-list-frame')).toHaveAttribute('data-sync-scope', 'all_mail_unified');
    await expect(page.locator('#inbox-list-frame')).toHaveAttribute('data-list-scope-value', '');
    await expect(link).toHaveClass(/is-active/);
    const refreshed = page.waitForResponse(r => r.request().headers()['x-list-fragment'] === 'inbox-list-frame');
    await page.evaluate(() => document.body.dispatchEvent(new CustomEvent('core--mercure:mailbox-synced', { bubbles: true, detail: { specialUse: 'archive' } })));
    expect((await refreshed).status()).toBe(200);
    const row = mailRow(page, INBOX_SUBJECTS.archive);
    const archived = page.waitForResponse(r => r.url().includes('/status/thread/') && r.request().method() === 'POST');
    await rowAction(row, 'Archive');
    const response = await archived;
    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON()).toMatchObject({ scope: 'all_mail_unified', value: '' });
    await expect(row).toBeVisible();
    await page.reload();
    await expect(row).toBeVisible();
    await expect(link).toHaveClass(/is-active/);
    await page.getByRole('checkbox', { name: 'Select all conversations' }).click();
    const bulk = page.waitForResponse(r => r.url().includes('/status/bulk/archive') && r.request().method() === 'POST');
    await page.locator('[data-mail--list-toolbar-target="actions"]').getByRole('button', { name: 'Archive', exact: true }).click();
    expect((await bulk).status()).toBe(200);
    await expect(row).toBeVisible();
    await page.goto('/mail/inbox');
    await expect(link).not.toHaveClass(/is-active/);
    await link.click();
    await expect(row).toBeVisible();
});
