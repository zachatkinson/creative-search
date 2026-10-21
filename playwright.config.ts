import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

/**
 * Three engines at desktop and phone widths, as the spec's browser-test gate asks.
 * CI runs every project; locally `npx playwright test --project=chromium-desktop` is enough.
 *
 * macOS 27 protects ~/Library/Application Support/Firefox, which Playwright's Firefox build
 * still reads for profiles.ini even when given its own profile, so every launch died with
 * "Could not find profile folder" (microsoft/playwright#42768). Pointing CFFIXED_USER_HOME at a
 * scratch directory makes Core Foundation resolve the home, and that lookup, inside a folder the
 * test run owns. Linux and Windows are unaffected and get the default launch (docs/DECISIONS.md D-022).
 */
const firefoxLaunchOptions =
	process.platform === 'darwin'
		? {
				env: {
					...process.env,
					CFFIXED_USER_HOME: fs.mkdtempSync(
						path.join( os.tmpdir(), 'csearch-firefox-home-' )
					),
				},
			}
		: {};

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
		{
			name: 'firefox-desktop',
			use: {
				...devices[ 'Desktop Firefox' ],
				launchOptions: firefoxLaunchOptions,
			},
		},
		{ name: 'webkit-desktop', use: { ...devices[ 'Desktop Safari' ] } },
		{ name: 'chromium-phone', use: { ...devices[ 'Pixel 7' ] } },
		{ name: 'webkit-phone', use: { ...devices[ 'iPhone 14' ] } },
	],
} );
