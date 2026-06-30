// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'Tier gating', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'generator page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-generator' );
		// #plume-generator is the PHP-rendered mount point — always present when
		// the plugin is active and the page loads without a fatal error.
		// .first() avoids a strict-mode violation if the fallback selector also matches.
		await expect(
			page.locator( '#plume-generator, .plume-generator-app' ).first()
		).toBeVisible();
	} );

	test( 'images page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-images' );
		await expect(
			page.locator( '#plume-images, .plume-images-app' ).first()
		).toBeVisible();
	} );

	test( 'SEO page renders full UI for free-tier users, no Pro-gate', async ( { page } ) => {
		// Credits-based redesign: every tier reaches the full SEO screen now —
		// credit exhaustion is surfaced inline by SeoWorkArea via
		// OutOfCreditsNotice on a failed generate call, not by gating the
		// whole page up front (SeoApp.jsx docblock). Override window.plumeData
		// to simulate free tier before React boots, via a getter/setter proxy
		// so PHP's inline `var plumeData = {...}` still supplies the real
		// restUrl/nonce while we force isPaid: false.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'plumeData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPaid: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=plume-seo' );
		await page.waitForSelector( '#plume-seo', { timeout: 10000 } );

		// Full page header and post list render for free tier — no gate.
		await expect( page.locator( '.plume-page-header h1' ) ).toHaveText( 'SEO' );
		await expect( page.locator( '.plume-post-list' ) ).toBeVisible();
		await expect( page.locator( '.plume-pro-gate' ) ).toHaveCount( 0 );
		await expect( page.locator( '.plume-pro-badge' ) ).toHaveCount( 0 );
	} );

	test( 'settings page shows provider configuration options', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-settings' );
		// .plume-settings-shell hydrates after React boots (confirmed in p3-chat.spec.js line 35).
		await page.waitForSelector( '.plume-settings-shell', { timeout: 10000 } );
		await expect( page.locator( '.plume-settings-shell' ) ).toBeVisible();
		// The settings page renders tabs via .plume-settings-tabpanel (p3-chat.spec.js line 37).
		await expect( page.locator( '.plume-settings-tabpanel' ) ).toBeVisible();
	} );

	test( 'chat page remains accessible for free-tier users', async ( { page } ) => {
		// Chat is not Pro-gated — ensure it still renders .plume-shell regardless of tier.
		// Getter/setter proxy forces isPro: false while preserving restUrl, nonce, etc.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'plumeindData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPro: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=plume-chat' );
		// .plume-shell is the root chat element (ChatApp.jsx line 297).
		await page.waitForSelector( '.plume-shell', { timeout: 10000 } );
		await expect( page.locator( '.plume-shell' ) ).toBeVisible();
		// Sidebar and composer must also be present for free-tier users.
		await expect( page.locator( '.plume-sidebar' ) ).toBeVisible();
		await expect( page.locator( '.plume-composer' ) ).toBeVisible();
	} );
} );
