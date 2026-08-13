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

const viewports = [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'mobile', width: 390, height: 844 },
];

const roleRoute = {
    'super-admin-navigation': '/admin/procurement/platform/status-mappings',
    'operator-navigation': '/admin/procurement/crawler/proxies/create',
    'analyst-navigation': '/admin/procurement/procurements',
};

function collectFailures(page: Page): string[] {
    const failures: string[] = [];
    const baseUrl = process.env.E2E_BASE_URL;

    if (!baseUrl) {
        throw new Error('E2E_BASE_URL is required.');
    }

    const applicationOrigin = new URL(baseUrl).origin;

    page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400 && new URL(response.url()).origin === applicationOrigin) {
            failures.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return failures;
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
    await expect.poll(() => page.locator('html').evaluate(
        (element) => element.scrollWidth <= element.clientWidth,
    )).toBeTruthy();
}

async function expectNavigationGroupOrder(page: Page): Promise<void> {
    const positions: number[] = [];

    for (const group of navigationGroups) {
        const label = page.getByText(group, { exact: true }).first();
        await expect(label).toBeVisible();

        const box = await label.boundingBox();
        expect(box, `navigation group ${group} has no visible position`).not.toBeNull();
        positions.push(box!.y);
    }

    expect(positions).toEqual([...positions].sort((left, right) => left - right));
}

test('проверяет navigation и responsive route для каждой назначенной роли', async ({ page }, testInfo) => {
    const failures = collectFailures(page);
    const route = roleRoute[testInfo.project.name as keyof typeof roleRoute];

    expect(route, `unexpected browser role project ${testInfo.project.name}`).toBeTruthy();

    await page.setViewportSize(viewports[0]);
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('main')).toBeVisible();

    if (testInfo.project.name === 'super-admin-navigation') {
        await expectNavigationGroupOrder(page);
    }

    for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        const response = await page.goto(route);

        expect(response?.ok(), `${testInfo.project.name} ${viewport.name} ${route}`).toBeTruthy();
        await expect(page.getByRole('main')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    }

    expect(failures).toEqual([]);
});

test('доказывает exact owner routes, Sections и mobile table actions', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'super-admin-navigation');

    const failures = collectFailures(page);

    await page.setViewportSize(viewports[1]);
    await page.goto('/admin/procurement/platform/status-mappings');
    await expect(page.getByRole('heading', { name: 'Соответствие статусов' })).toBeVisible();
    await expect(page.getByText('Этап площадки', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Канонический статус', { exact: true }).first()).toBeAttached();
    await expect(page.getByRole('link', { name: /Редактировать/i }).first()).toBeVisible();
    await expectNoHorizontalOverflow(page);

    for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        await page.goto('/admin/procurement/crawler/proxies/create');
        await expect(page.getByText('Подключение прокси', { exact: true })).toBeVisible();
        await expect(page.getByText('Укажите технические параметры узла. Учётные данные не выводятся после сохранения.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Сервис')).toBeVisible();
        await expect(page.getByLabel('Хост')).toBeVisible();
        await expect(page.getByLabel('Порт')).toBeVisible();
        await expect(page.getByText('Разрешённое использование', { exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);

        const pageCopy = await page.getByRole('main').innerText();
        expect(pageCopy).not.toMatch(/\b(?:Service|Host|Port|Username|Password|Country)\b/);

        await page.goto('/admin/procurement/ai/prompts');
        await page.getByRole('link', { name: /Просмотр/i }).first().click();
        await expect(page).toHaveURL(/\/admin\/procurement\/ai\/prompts\/[^/]+$/);
        await expect(page.getByText('Промт', { exact: true })).toBeVisible();
        await expect(page.getByText('Тело промта', { exact: true })).toBeVisible();
        await expect(page.getByText('Все версии этого промта', { exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
    }

    expect(failures).toEqual([]);
});

test('различает role denial и unauthenticated redirect для прямых URL', async ({ page, browser }, testInfo) => {
    const deniedRoutes = {
        'super-admin-navigation': null,
        'operator-navigation': '/admin/roles',
        'analyst-navigation': '/admin/procurement/platform/platforms',
    };
    const deniedRoute = deniedRoutes[testInfo.project.name as keyof typeof deniedRoutes];

    if (deniedRoute) {
        const response = await page.goto(deniedRoute);

        expect(response?.status()).toBe(403);
        await expect(page).toHaveURL(new RegExp(`${deniedRoute}$`));
    }

    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const unauthenticatedPage = await context.newPage();

    await unauthenticatedPage.goto('/admin');
    await expect(unauthenticatedPage).toHaveURL(/\/admin\/login/);
    await context.close();
});
