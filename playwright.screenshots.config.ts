/**
 * External dependencies
 */
import { defineConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import base from './playwright.config';

// Readme screenshots (make screenshots): same login and site as the e2e suite, its own folder.
export default defineConfig( {
	...base,
	testDir: './tests/screenshots',
	outputDir: './test-results/screenshots',
} );
