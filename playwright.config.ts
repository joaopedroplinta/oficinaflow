import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8012',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8012',
        url: 'http://127.0.0.1:8012',
        reuseExistingServer: false,
        env: {
            APP_ENV: 'testing',
            APP_URL: 'http://127.0.0.1:8012',
            DB_DATABASE: 'oficinaflow_testing',
            DB_URL: '',
            SESSION_DRIVER: 'file',
            CACHE_STORE: 'array',
            SANCTUM_STATEFUL_DOMAINS: '127.0.0.1:8012',
            MAIL_MAILER: 'array',
        },
    },
});
