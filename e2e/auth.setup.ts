import { expect, test, type Page } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

async function authenticate(page: Page, email: string, password: string, output: string): Promise<void> {
    await page.goto('/admin/login');
    await page.getByRole('textbox', { name: /^(?:email address|адрес электронной почты)/i }).fill(email);
    await page.getByRole('textbox', { name: /^(?:password|пароль)/i }).fill(password);

    const livewireUpdate = page.waitForResponse((response) => (
        /\/livewire-[^/]+\/update(?:\?|$)/.test(response.url())
        && response.request().method() === 'POST'
    ));

    await page.getByRole('button', { name: /sign in|войти/i }).click();

    expect((await livewireUpdate).ok()).toBeTruthy();
    await expect(page).toHaveURL(/\/admin$/);
    await page.context().storageState({ path: output });
}

test('аутентифицирует роли browser matrix через Livewire', async ({ browser }) => {
    const adminEmail = process.env.E2E_ADMIN_EMAIL;
    const passwordFile = process.env.E2E_ADMIN_PASSWORD_FILE;

    if (!adminEmail || !passwordFile) {
        throw new Error('E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD_FILE are required.');
    }

    const password = readFileSync(passwordFile, 'utf8').trim();
    const authDirectory = dirname(authFile);
    mkdirSync(authDirectory, { recursive: true });

    for (const [email, filename] of [
        [adminEmail, 'admin.json'],
        ['operator@agelto.e2e.test', 'operator.json'],
        ['analyst@agelto.e2e.test', 'analyst.json'],
    ]) {
        const context = await browser.newContext();
        await authenticate(await context.newPage(), email, password, join(authDirectory, filename));
        await context.close();
    }
});
