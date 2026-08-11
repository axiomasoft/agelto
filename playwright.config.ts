import { defineConfig, devices } from '@playwright/test';

const outputDir = process.env.E2E_OUTPUT_DIR ?? 'test-results';
const authFile = process.env.E2E_AUTH_FILE ?? 'playwright/.auth/admin.json';

export default defineConfig({
    testDir: './e2e',
    outputDir,
    forbidOnly: Boolean(process.env.CI),
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['html', { outputFolder: 'playwright-report' }], ['line']] : 'line',
    use: {
        baseURL: process.env.E2E_BASE_URL,
        testIdAttribute: 'data-testid',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            testMatch: /.*\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
                storageState: authFile,
            },
        },
    ],
});
