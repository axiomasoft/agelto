import { expect, test } from '@playwright/test';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const documentationPrefix = '/admin/documentation/';
const representativeExtensions = ['.html', '.css', '.js', '.json', '.woff2'];
const htmlCsp = "default-src 'self'; base-uri 'none'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:";
const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

test('загружает закрытую документацию без browser или cache bypass', async ({ browser, page }) => {
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
    await expect(page).toHaveURL(/\/admin\/documentation\/$/);
    await expect(page.locator('body')).toBeVisible();

    const entries = JSON.parse(readFileSync(resolve('var/private-documentation/manifest.json'), 'utf8')) as Array<{ mime: string; path: string; sha256: string }>;
    expect(entries.some(({ path }) => path.endsWith('.svg'))).toBeFalsy();

    const representativeEntries = representativeExtensions.map((extension) => {
        const entry = entries.find(({ path }) => path.endsWith(extension));

        if (!entry) {
            throw new Error(`Missing representative ${extension} artifact`);
        }

        return entry;
    });

    for (const entry of representativeEntries) {
        const result = await page.evaluate(async (artifactPath) => {
            const response = await fetch(`/admin/documentation/${artifactPath}`, { cache: 'reload' });

            return {
                body: Array.from(new Uint8Array(await response.arrayBuffer())),
                headers: Object.fromEntries(response.headers.entries()),
                status: response.status,
            };
        }, entry.path);

        expect(result.status, entry.path).toBe(200);
        expect(createHash('sha256').update(Buffer.from(result.body)).digest('hex'), entry.path).toBe(entry.sha256);
        expect(result.headers['content-type'], entry.path).toBe(entry.mime);
        expect(result.headers['cache-control'].split(',').map((directive) => directive.trim()).sort(), entry.path)
            .toEqual(['no-store', 'private']);
        expect(result.headers['x-content-type-options'], entry.path).toBe('nosniff');
        expect(result.headers['referrer-policy'], entry.path).toBe('no-referrer');
        expect(result.headers['cross-origin-resource-policy'], entry.path).toBe('same-origin');
        expect(result.headers['content-security-policy'] ?? null, entry.path).toBe(entry.mime === 'text/html; charset=utf-8' ? htmlCsp : null);
    }

    const directAssetPath = representativeEntries.find(({ path }) => path.endsWith('.css'))?.path;
    expect(directAssetPath).toBeTruthy();

    authenticatedSession = false;
    await page.context().clearCookies();
    await page.goto(`/admin/documentation/${directAssetPath}`);
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.locator('body')).not.toContainText('Agelto');

    const composeProject = process.env.E2E_COMPOSE_PROJECT;
    const composeEnvFile = process.env.E2E_COMPOSE_ENV_FILE;
    const composeFile = process.env.E2E_COMPOSE_FILE;
    const adminEmail = process.env.E2E_ADMIN_EMAIL;

    if (!composeProject || !composeEnvFile || !composeFile || !adminEmail) {
        throw new Error('E2E_COMPOSE_PROJECT, E2E_COMPOSE_ENV_FILE, E2E_COMPOSE_FILE and E2E_ADMIN_EMAIL are required.');
    }

    const revokedContext = await browser.newContext({ storageState: authFile });
    const revokedPage = await revokedContext.newPage();
    const beforeRevocation = await revokedPage.goto(`/admin/documentation/${directAssetPath}`);
    expect(beforeRevocation?.status()).toBe(200);

    const composeArguments = [
        'compose',
        '--project-directory', resolve('.'),
        '--project-name', composeProject,
        '--env-file', composeEnvFile,
        '-f', composeFile,
        'exec', '-T', 'app', 'php', 'artisan',
    ];
    execFileSync('docker', [...composeArguments, 'guard:role', 'detach', adminEmail, 'App\\Guards\\Admin\\Roles\\SuperAdminRole'], { stdio: 'pipe' });
    execFileSync('docker', [...composeArguments, 'guard:cache-reset', '--force'], { stdio: 'pipe' });

    const afterRevocation = await revokedPage.goto(`/admin/documentation/${directAssetPath}`);
    expect(afterRevocation?.status()).toBe(403);
    await expect(revokedPage.locator('body')).not.toContainText('body {');
    await revokedContext.close();

    expect(documentationResponses).not.toEqual([]);
    expect(failures).toEqual([]);
});
