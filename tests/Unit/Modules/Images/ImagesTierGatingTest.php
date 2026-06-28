<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Images;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Images\ImagesModule;
use PHPUnit\Framework\TestCase;

/**
 * Tier-gating unit tests for the Images module REST route.
 *
 * The permission_callback no longer checks tier or quota — credit enforcement
 * happens entirely on the Worker side. It now collapses to a single
 * current_user_can('edit_posts') check, identical across every tier.
 */
class ImagesTierGatingTest extends TestCase {

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

		ImagesModule::register_routes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_images_generate_returns_200_for_free_tier_user(): void {
		$this->assertArrayHasKey( '/images/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/images/generate']['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( (bool) $permission_callback() );
	}

	public function test_images_generate_permission_callback_ignores_tier_entirely(): void {
		$this->assertArrayHasKey( '/images/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/images/generate']['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		foreach ( [ 'free', 'pro_managed', 'pro_byok' ] as $tier ) {
			Functions\when( 'get_option' )->justReturn( $tier );
			$this->assertTrue( (bool) $permission_callback(), "permission_callback() must return true for tier '{$tier}'." );
		}
	}

	public function test_images_generate_returns_403_without_edit_posts_capability(): void {
		$this->assertArrayHasKey( '/images/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/images/generate']['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( (bool) $permission_callback() );
	}
}
