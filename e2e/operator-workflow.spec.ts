import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { dirname, join } from 'node:path';

const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

test.use({ storageState: join(dirname(authFile), 'operator.json') });

test('оператор находит соответствие по этапу площадки и открывает его для правки', async ({ page }) => {
    await page.goto('/admin/procurement/platform/status-mappings');

    const main = page.getByRole('main');
    const search = main.getByPlaceholder('Поиск', { exact: true });
    const matchingRow = main.getByRole('row', { name: /Определение поставщика завершено/ });
    const unrelatedRow = main.getByRole('row', { name: /Подача заявок/ });

    await expect(matchingRow).toBeVisible();
    await expect(unrelatedRow).toBeVisible();
    await search.fill('Определение поставщика завершено');
    await expect(unrelatedRow).toHaveCount(0);
    await expect(matchingRow).toBeVisible();
    await matchingRow.getByRole('link', { name: 'Изменить' }).click();

    await expect(page).toHaveURL(/\/status-mappings\/[^/]+\/edit$/);
    await expect(main.getByLabel('Канонический статус', { exact: false })).toBeVisible();
});

test('оператор исправляет некорректный адрес провайдера и сохраняет подключение', async ({ page }) => {
    const providers = '/admin/procurement/ai/providers';
    const code = `e2e-${randomUUID()}`;
    const endpoint = 'https://provider.e2e.test';

    await page.goto(`${providers}/create`);

    const main = page.getByRole('main');
    const address = main.getByRole('textbox', { name: 'Базовый адрес' });
    const create = main.getByRole('button', { name: 'Создать', exact: true });

    await main.getByRole('textbox', { name: /^Код/ }).fill(code);
    await main.getByRole('textbox', { name: /^Драйвер/ }).fill('gemini');
    await address.fill('некорректный-адрес');
    await create.click();

    await expect(address).toHaveJSProperty('type', 'url');
    await expect(address.locator(':scope:invalid')).toBeVisible();
    await expect(page).toHaveURL(`${providers}/create`);

    await address.fill(endpoint);
    await create.click();
    await expect(page).toHaveURL(/\/providers\/[^/]+\/edit$/);
    await expect(main.getByRole('textbox', { name: /^Код/ })).toHaveValue(code);
    await expect(address).toHaveValue(endpoint);
});
