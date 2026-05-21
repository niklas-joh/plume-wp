<?php
/**
 * Unit tests for NJ_Tier_Config::get_proxy_url() option-based lookup path.
 *
 * Brain Monkey is used to mock get_option() so these tests do not require a
 * live database or WordPress bootstrap.
 *
 * @package WP_AI_Mind\Tests\Unit\Tiers
 * @since   1.8.0
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tiers\NJ_Tier_Config;

/**
 * Tests for the three-tier URL resolution in NJ_Tier_Config::get_proxy_url().
 *
 * @since 1.8.0
 */
class GetProxyUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Option path (no constant defined) ────────────────────────────────────

	/**
	 * When only the WP option is set (no constant), returns the sanitised URL
	 * without a trailing slash.
	 *
	 * @since 1.8.0
	 */
	public function test_returns_option_url_when_no_constant(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_ai_mind_proxy_url', '' )
			->andReturn( 'https://my-proxy.example.com/' );

		Functions\expect( 'esc_url_raw' )
			->once()
			->andReturnFirstArg();

		$this->assertSame(
			'https://my-proxy.example.com',
			NJ_Tier_Config::get_proxy_url()
		);
	}

	/**
	 * When the WP option is set and the constant is also defined, the constant
	 * takes priority and get_option() is never called.
	 *
	 * @since 1.8.0
	 */
	public function test_constant_wins_over_option(): void {
		if ( ! defined( 'WP_AI_MIND_PROXY_URL' ) ) {
			define( 'WP_AI_MIND_PROXY_URL', 'https://constant-proxy.example.com/' );
		}

		// get_option must not be called when the constant is present.
		Functions\expect( 'get_option' )->never();

		$this->assertSame(
			'https://constant-proxy.example.com',
			NJ_Tier_Config::get_proxy_url()
		);
	}

	/**
	 * When the WP option is an empty string, falls through to the hard-coded
	 * default URL.
	 *
	 * @since 1.8.0
	 */
	public function test_falls_through_to_default_when_option_empty(): void {
		// Only run when WP_AI_MIND_PROXY_URL is not defined.
		if ( defined( 'WP_AI_MIND_PROXY_URL' ) ) {
			$this->markTestSkipped( 'WP_AI_MIND_PROXY_URL constant is defined in this process.' );
		}

		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_ai_mind_proxy_url', '' )
			->andReturn( '' );

		Functions\expect( 'esc_url_raw' )
			->once()
			->andReturn( '' );

		$this->assertSame(
			'https://wp-ai-mind-proxy.wp-ai-mind.workers.dev',
			NJ_Tier_Config::get_proxy_url()
		);
	}

	/**
	 * When the WP option contains a non-HTTPS URL, esc_url_raw keeps it but
	 * the HTTPS-only guard rejects it and falls through to the hard-coded default.
	 *
	 * @since 1.8.0
	 */
	public function test_rejects_non_https_option_url(): void {
		if ( defined( 'WP_AI_MIND_PROXY_URL' ) ) {
			$this->markTestSkipped( 'WP_AI_MIND_PROXY_URL constant is defined in this process.' );
		}

		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_ai_mind_proxy_url', '' )
			->andReturn( 'http://insecure-proxy.example.com/' );

		// esc_url_raw preserves http:// URLs; the HTTPS guard must then reject it.
		Functions\expect( 'esc_url_raw' )
			->once()
			->andReturn( 'http://insecure-proxy.example.com/' );

		$this->assertSame(
			'https://wp-ai-mind-proxy.wp-ai-mind.workers.dev',
			NJ_Tier_Config::get_proxy_url()
		);
	}
}
