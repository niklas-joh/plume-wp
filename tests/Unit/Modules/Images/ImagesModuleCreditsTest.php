<?php
/**
 * Unit tests pinning the IMAGE_CREDITS constant in the Images module.
 *
 * @package Plume\Tests\Unit\Modules\Images
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Images;

use PHPUnit\Framework\TestCase;
use Plume\Modules\Images\ImagesModule;

/**
 * Pins the IMAGE_CREDITS constant used for credit logging in ImagesModule.
 *
 * The constant mirrors IMAGE_CREDITS in plume-proxy/src/credits.ts. If the
 * Worker value changes, both sides must be updated together — this test
 * acts as the regression guard on the PHP side.
 *
 * @since NEXT_VERSION
 */
class ImagesModuleCreditsTest extends TestCase {

	/**
	 * IMAGE_CREDITS must equal 15 to match plume-proxy/src/credits.ts IMAGE_CREDITS.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_image_credits_constant_is_fifteen(): void {
		$ref = new \ReflectionClass( ImagesModule::class );
		$this->assertSame(
			15,
			$ref->getConstant( 'IMAGE_CREDITS' ),
			'ImagesModule::IMAGE_CREDITS must match plume-proxy/src/credits.ts IMAGE_CREDITS = 15.'
		);
	}

	/**
	 * IMAGE_CREDITS is private — external callers cannot bypass the formula.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_image_credits_constant_is_private(): void {
		$ref        = new \ReflectionClass( ImagesModule::class );
		$constants  = $ref->getReflectionConstants();
		$found      = null;
		foreach ( $constants as $constant ) {
			if ( 'IMAGE_CREDITS' === $constant->getName() ) {
				$found = $constant;
				break;
			}
		}
		$this->assertNotNull( $found, 'IMAGE_CREDITS constant must exist.' );
		$this->assertTrue( $found->isPrivate(), 'IMAGE_CREDITS must be private to enforce the formula centrally.' );
	}

	/**
	 * Credit cost for a batch of images equals count × 15.
	 *
	 * This verifies the arithmetic implied by the logging call
	 * `UsageTracker::log_usage( count( $images ) * self::IMAGE_CREDITS )`.
	 *
	 * @since NEXT_VERSION
	 * @dataProvider batch_size_provider
	 * @param int $count    Number of successfully generated images.
	 * @param int $expected Expected total credits to log.
	 */
	public function test_image_credit_formula( int $count, int $expected ): void {
		$ref           = new \ReflectionClass( ImagesModule::class );
		$image_credits = $ref->getConstant( 'IMAGE_CREDITS' );
		$this->assertSame( $expected, $count * $image_credits );
	}

	/**
	 * @since NEXT_VERSION
	 * @return array<array{int, int}>
	 */
	public static function batch_size_provider(): array {
		return [
			'single image'  => [ 1, 15 ],
			'two images'    => [ 2, 30 ],
			'three images'  => [ 3, 45 ],
		];
	}
}
