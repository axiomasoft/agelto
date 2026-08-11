import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const documentationPrefix = '/admin/documentation/';
const representativeExtensions = ['.html', '.css', '.js', '.json', '.woff2'];

test('загружает закрытую документацию без browser или cache bypass', async ({ page }) => {
    const failures: string[] = [];
    const documentationResponses: string[] = [];
    let authenticatedSession = true;

    page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        const url = new URL(response.url());

        if (!url.pathname.startsWith(documentationPrefix)) {
            return;
        }

        documentationResponses.push(`${response.status()} ${url.pathname}`);

        if (!authenticatedSession) {
            return;
        }

        expect(response.status(), `documentation response: ${url.pathname}`).toBeGreaterThanOrEqual(200);
        expect(response.status(), `documentation response: ${url.pathname}`).toBeLessThan(300);
        expect(response.headers()['cache-control']).toContain('private');
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(url.pathname).not.toContain('/public/');
    });

    await page.goto('/admin/documentation/');
    await expect(page).toHaveURL(/\/admin\/documentation\/?$/);
    await expect(page.locator('body')).toBeVisible();

    const entries = JSON.parse(readFileSync(resolve('var/private-documentation/manifest.json'), 'utf8')) as Array<{ path: string }>;
    const representativePaths = representativeExtensions.map((extension) => {
        const entry = entries.find(({ path }) => path.endsWith(extension));

        if (!entry) {
            throw new Error(`Missing representative ${extension} artifact`);
        }

        return entry.path;
    });

    for (const path of representativePaths) {
        const result = await page.evaluate(async (artifactPath) => {
            const response = await fetch(`/admin/documentation/${artifactPath}`, { cache: 'no-store' });

            return {
                cacheControl: response.headers.get('cache-control'),
                contentType: response.headers.get('content-type'),
                status: response.status,
            };
        }, path);

        expect(result.status, path).toBe(200);
        expect(result.cacheControl, path).toContain('private');
        expect(result.cacheControl, path).toContain('no-store');
        expect(result.contentType, path).toBeTruthy();
    }

    authenticatedSession = false;
    await page.context().clearCookies();
    await page.goto('/admin/documentation/');
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.locator('body')).not.toContainText('Agelto');

    expect(documentationResponses).not.toEqual([]);
    expect(failures).toEqual([]);
});
