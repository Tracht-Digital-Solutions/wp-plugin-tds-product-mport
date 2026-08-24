import { defineConfig } from '@playwright/test';

const baseURL = process.env.TDS_E2E_BASE_URL || 'http://localhost:8888';

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 120_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['line'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL,
		locale: 'en-US',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'desktop-1440',
			use: { viewport: { width: 1440, height: 900 } },
		},
		{
			name: 'mobile-390',
			use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
		},
	],
});
