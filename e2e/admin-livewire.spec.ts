import { expect, test } from '@playwright/test';

test('открывает authenticated admin without Livewire or console errors', async ({ page }) => {
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

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('main')).toBeVisible();
    expect(failures).toEqual([]);
});
