import { expect, test } from '@playwright/test';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

const documentationPrefix = '/documentation/';
const representativeExtensions = ['.html', '.css', '.js', '.json', '.woff2'];
const htmlCsp = "default-src 'self'; base-uri 'none'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:";
const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

function mermaidDocumentationPaths(): string[] {
    return readdirSync(resolve('docs'), { recursive: true })
        .filter((entry): entry is string => typeof entry === 'string' && entry.endsWith('.md'))
        .filter((entry) => readFileSync(resolve('docs', entry), 'utf8').includes('```mermaid'))
        .map((entry) => entry.replaceAll('\\', '/').replace(/\.md$/, ''))
        .map((entry) => entry === 'index' ? '' : entry.replace(/\/_index$/, '/'))
        .map((entry) => `/documentation/${entry}`)
        .sort();
}

test('загружает закрытую документацию без browser или cache bypass', async ({ browser, page }) => {
    // This scenario visits every Mermaid page, verifies representative asset bytes and
    // tests revocation in a second browser context; CI can exceed the default 30 seconds.
    test.setTimeout(120_000);
    const failures: string[] = [];
    const documentationResponses: string[] = [];
    const composeProject = process.env.E2E_COMPOSE_PROJECT;
    const composeEnvFile = process.env.E2E_COMPOSE_ENV_FILE;
    const composeFile = process.env.E2E_COMPOSE_FILE;
    const adminEmail = process.env.E2E_ADMIN_EMAIL;

    if (!composeProject || !composeEnvFile || !composeFile || !adminEmail) {
        throw new Error('E2E_COMPOSE_PROJECT, E2E_COMPOSE_ENV_FILE, E2E_COMPOSE_FILE and E2E_ADMIN_EMAIL are required.');
    }

    const composeArguments = [
        'compose',
        '--project-directory', resolve('.'),
        '--project-name', composeProject,
        '--env-file', composeEnvFile,
        '-f', composeFile,
        'exec', '-T', '-e', `E2E_ROLE_USER=${adminEmail}`, 'app',
    ];
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

    await page.goto('/documentation/');
    await expect(page).toHaveURL(/\/documentation\/$/);
    await expect(page.locator('body')).toBeVisible();

    for (const directUrl of [
        '/documentation/business/',
        '/documentation/operators/production/server-setup',
        '/documentation/operators/production/deployment',
    ]) {
        const directResponse = await page.goto(directUrl);

        expect(directResponse?.status(), `direct documentation response: ${directUrl}`).toBe(200);
        await expect(page).toHaveURL(new RegExp(`${directUrl.replaceAll('/', '\\/')}$`));
    }

    await expect(page.locator('.language-mermaid pre code').first()).toBeVisible();

    const refreshedResponse = await page.reload();
    expect(refreshedResponse?.status(), 'refreshed Mermaid page response').toBe(200);
    await expect(page.locator('.language-mermaid pre code').first()).toBeVisible();

    for (const directUrl of mermaidDocumentationPaths()) {
        const diagramResponse = await page.goto(directUrl);

        expect(diagramResponse?.status(), `Mermaid documentation response: ${directUrl}`).toBe(200);
        await expect(page.locator('.language-mermaid pre code').first(), `Mermaid source block: ${directUrl}`).toBeVisible();
    }

    const serverSetupResponse = await page.goto('/documentation/operators/production/server-setup');
    expect(serverSetupResponse?.status(), 'direct server-setup documentation response').toBe(200);
    await expect(page.locator('.language-mermaid')).toHaveCount(0);

    const reloadedServerSetupResponse = await page.reload();
    expect(reloadedServerSetupResponse?.status(), 'reloaded server-setup documentation response').toBe(200);
    await expect(page.locator('.language-mermaid')).toHaveCount(0);

    const manifest = execFileSync('docker', [...composeArguments, 'cat', '/var/private-documentation/manifest.json'], { encoding: 'utf8' });
    const entries = JSON.parse(manifest) as Array<{ mime: string; path: string; sha256: string }>;
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
            const response = await fetch(`/documentation/${artifactPath}`, { cache: 'reload' });

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
    await page.goto(`/documentation/${directAssetPath}`);
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.locator('body')).not.toContainText('body {');

    const revokedContext = await browser.newContext({ storageState: authFile });
    const revokedPage = await revokedContext.newPage();
    const beforeRevocation = await revokedPage.goto(`/documentation/${directAssetPath}`);
    expect(beforeRevocation?.status()).toBe(200);

    const setRole = (operation: 'removeRole' | 'assignRole') => {
        const code = `$user = \\App\\Models\\User::where('email', getenv('E2E_ROLE_USER'))->firstOrFail(); $user->${operation}(\\App\\Guards\\Admin\\Roles\\SuperAdminRole::class); echo $user->fresh()->isSuperAdmin() ? 'ROLE_PRESENT' : 'ROLE_ABSENT';`;
        return execFileSync('docker', [...composeArguments, 'php', 'artisan', 'tinker', `--execute=${code}`], { encoding: 'utf8' }).trim();
    };
    try {
        expect(setRole('removeRole')).toContain('ROLE_ABSENT');

        const afterRevocation = await revokedPage.goto(`/documentation/${directAssetPath}`);
        expect(afterRevocation?.status()).toBe(403);
        await expect(revokedPage.locator('body')).not.toContainText('body {');
    } finally {
        expect(setRole('assignRole')).toContain('ROLE_PRESENT');
        await revokedContext.close();
    }

    expect(documentationResponses).not.toEqual([]);
    expect(failures).toEqual([]);
});
