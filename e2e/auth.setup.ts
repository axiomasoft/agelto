import { expect, test } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname } from 'node:path';

const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

test('аутентифицирует initial administrator через Livewire', async ({ page }) => {
    const email = process.env.E2E_ADMIN_EMAIL;
    const passwordFile = process.env.E2E_ADMIN_PASSWORD_FILE;

    if (!email || !passwordFile) {
        throw new Error('E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD_FILE are required.');
    }

    const password = readFileSync(passwordFile, 'utf8').trim();

    await page.goto('/admin/login');
    await page.getByRole('textbox', { name: /^email address/i }).fill(email);
    await page.getByRole('textbox', { name: /^password/i }).fill(password);

    const livewireUpdate = page.waitForResponse((response) => (
        response.url().includes('/livewire/update')
        && response.request().method() === 'POST'
    ));

    await page.getByRole('button', { name: /sign in|войти/i }).click();

    expect((await livewireUpdate).ok()).toBeTruthy();
    await expect(page).toHaveURL(/\/admin$/);

    mkdirSync(dirname(authFile), { recursive: true });
    await page.context().storageState({ path: authFile });
});
