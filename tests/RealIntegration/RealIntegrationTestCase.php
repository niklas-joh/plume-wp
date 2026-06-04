<?php
/**
 * Base class for all real integration tests.
 *
 * Extends IntegrationTestCase so every test has a real WordPress environment,
 * including a live database and functional REST server. Adds skip-if-no-key
 * helpers and tier-configuration shortcuts needed for real API calls.
 *
 * @package Stilus\Tests\RealIntegration
 */

declare( strict_types=1 );

namespace Stilus\Tests\RealIntegration;

use Stilus\Tests\Integration\IntegrationTestCase;
use Stilus\Tiers\TierManager;
use Stilus\Proxy\SiteRegistration;
use Stilus\Settings\ProviderSettings;

/**
 * @since 1.8.0
 */
abstract class RealIntegrationTestCase extends IntegrationTestCase {

	/**
	 * Skip if CLAUDE_API_KEY env var is absent.
	 *
	 * Call from setUpBeforeClass() in test classes that need real Anthropic calls.
	 *
	 * @since 1.8.0
	 */
	protected static function skip_without_api_key(): void {
		if ( '' === ( getenv( 'CLAUDE_API_KEY' ) ?: '' ) ) {
			self::markTestSkipped( 'CLAUDE_API_KEY not set — skipping real API tests.' );
		}
	}

	/**
	 * Skip if STILUS_CI_SITE_TOKEN env var is absent.
	 *
	 * @since 1.8.0
	 */
	protected static function skip_without_proxy_token(): void {
		if ( '' === ( getenv( 'STILUS_CI_SITE_TOKEN' ) ?: '' ) ) {
			self::markTestSkipped( 'STILUS_CI_SITE_TOKEN not set — skipping real proxy tests.' );
		}
	}

	/**
	 * Configure the site for the Pro-BYOK tier with a real Anthropic API key.
	 *
	 * Sets the site-level tier option to pro_byok, removes the HMAC secret so
	 * is_site_tier_verified() passes for this unregistered test install, and
	 * stores the CLAUDE_API_KEY env var via ProviderSettings so the Claude
	 * provider can make live API calls.
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID (currently active user).
	 */
	protected function activate_byok_tier( int $user_id ): void {
		// Site-level paid tier takes priority over user meta for pro_byok.
		update_option( TierManager::SITE_OPTION, 'pro_byok', false );
		// No HMAC secret present → is_site_tier_verified() returns true.
		delete_option( SiteRegistration::OPTION_SECRET );
		// Inject the real API key so the Claude provider can call the Anthropic API.
		( new ProviderSettings() )->set_api_key( 'claude', getenv( 'CLAUDE_API_KEY' ) ?: '' );
	}

	/**
	 * Configure a user for the trial tier (routes AI calls through the proxy).
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID.
	 */
	protected function activate_trial_tier( int $user_id ): void {
		$this->set_user_tier( $user_id, 'trial' );
	}

	/**
	 * Configure a user for the free tier (chat only; all other features blocked).
	 *
	 * @since 1.8.0
	 * @param int $user_id WordPress user ID.
	 */
	protected function activate_free_tier( int $user_id ): void {
		$this->set_user_tier( $user_id, 'free' );
	}
}
