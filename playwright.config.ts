import { defineConfig, devices } from '@playwright/test';

/**
 * Three engines at desktop and phone widths, as the spec's browser-test gate asks.
 * CI runs every project; locally `npx playwright test --project=chromium-desktop` is enough.
 */
export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [ [ 'list' ] ],
	outputDir: 'test-results',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8890',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'chromium-desktop', use: { ...devices[ 'Desktop Chrome' ] } },
		{ name: 'firefox-desktop', use: { ...devices[ 'Desktop Firefox' ] } },
		{ name: 'webkit-desktop', use: { ...devices[ 'Desktop Safari' ] } },
		{ name: 'chromium-phone', use: { ...devices[ 'Pixel 7' ] } },
		{ name: 'webkit-phone', use: { ...devices[ 'iPhone 14' ] } },
	],
} );
