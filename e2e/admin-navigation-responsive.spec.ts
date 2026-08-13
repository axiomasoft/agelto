import { expect, test, type Page } from '@playwright/test';

const navigationGroups = [
    'Закупки',
    'Площадки',
    'Сбор данных',
    'Качество анализа',
    'Настройка AI',
    'Доступ и безопасность',
    'Система',
];

function collectFailures(page: Page): string[] {
    const failures: string[] = [];

    page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400 && /livewire|filament/.test(response.url())) {
            failures.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return failures;
}

test('проверяет domain navigation, responsive viewport и browser health для назначенной роли', async ({ page }, testInfo) => {
    const failures = collectFailures(page);

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('main')).toBeVisible();

    if (testInfo.project.name === 'super-admin-navigation') {
        for (const group of navigationGroups) {
            await expect(page.getByText(group, { exact: true }).first()).toBeVisible();
        }
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/procurement/procurements');
    await expect(page.getByRole('main')).toBeVisible();
    await expect.poll(() => page.locator('html').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBeTruthy();
    expect(failures).toEqual([]);
});

test('отклоняет прямой URL без storage state', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('/admin/roles');
    await expect(page).toHaveURL(/\/admin\/login/);
    await context.close();
});
