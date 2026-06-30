<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

/**
 * Tier-gating unit tests for the SEO module REST routes.
 *
 * Both /seo/generate and /seo/apply permission_callbacks no longer check tier
 * or quota — credit enforcement happens entirely on the Worker side. They now
 * collapse to a single current_user_can('edit_posts') check, identical across
 * every tier.
 */
class SeoTierGatingTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $captured_routes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( 'free' );

		// Capture the registered routes so we can invoke permission_callback directly.
		$this->captured_routes = [];
		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ): void {
				$this->captured_routes[ $route ] = $args;
			}
		);

		SeoModule::register_routes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function gated_routes(): array {
		return [
			'/seo/generate' => [ '/seo/generate' ],
			'/seo/apply'    => [ '/seo/apply' ],
		];
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_returns_200_for_free_tier_user( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( (bool) $permission_callback() );
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_permission_callback_ignores_tier_entirely( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		foreach ( [ 'free', 'pro_managed', 'pro_byok' ] as $tier ) {
			Functions\when( 'get_option' )->justReturn( $tier );
			$this->assertTrue( (bool) $permission_callback(), "permission_callback() must return true for tier '{$tier}'." );
		}
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_returns_403_without_edit_posts_capability( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( (bool) $permission_callback() );
	}
}
