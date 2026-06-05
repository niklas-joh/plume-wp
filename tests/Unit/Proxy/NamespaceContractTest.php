<?php
/**
 * Structural contract test: Worker TypeScript source and PHP controllers
 * must both use the stilus/v1 REST namespace.
 *
 * Runs in the standard phpunit (unit) job — no wp-env, no secrets, ~0 ms.
 * If this fails it means the Worker callback URL and the WordPress REST route
 * have diverged — the same class of bug that caused PR #596.
 *
 * @package Stilus\Tests\Unit\Proxy
 */

declare( strict_types=1 );

namespace Stilus\Tests\Unit\Proxy;

use PHPUnit\Framework\TestCase;

/**
 * @since 1.8.0
 */
class NamespaceContractTest extends TestCase {

	private const WORKER_SRC = __DIR__ . '/../../../stilus-proxy/src';

	/**
	 * Worker registration.ts callback URL must use the stilus/v1 namespace.
	 *
	 * @since 1.8.0
	 */
	public function test_worker_registration_callback_uses_stilus_v1(): void {
		$source = file_get_contents( self::WORKER_SRC . '/registration.ts' );
		$this->assertIsString( $source );
		$this->assertStringContainsString(
			'/wp-json/stilus/v1/activation-verify',
			$source,
			'Worker registration.ts callback URL must match the stilus/v1 REST namespace.'
		);
	}

	/**
	 * No Worker source file may contain a legacy /wp-ai-mind/ namespace URL.
	 *
	 * @since 1.8.0
	 */
	public function test_all_worker_source_files_free_of_legacy_namespace(): void {
		$ts_files = glob( self::WORKER_SRC . '/*.ts' );
		$this->assertNotEmpty( $ts_files, 'Expected at least one .ts file in stilus-proxy/src/.' );
		foreach ( $ts_files as $path ) {
			$this->assertStringNotContainsString(
				'/wp-json/wp-ai-mind/',
				file_get_contents( $path ),
				basename( $path ) . ' contains a legacy /wp-json/wp-ai-mind/ reference.'
			);
		}
	}

	/**
	 * Core PHP REST controllers must declare the stilus/v1 namespace, not the legacy one.
	 *
	 * @since 1.8.0
	 */
	public function test_php_rest_controllers_use_stilus_v1_namespace(): void {
		$controllers = [
			__DIR__ . '/../../../includes/Modules/Chat/ChatRestController.php',
			__DIR__ . '/../../../includes/Admin/ActivationVerifyRestController.php',
			__DIR__ . '/../../../includes/Payments/TierUpdateWebhookController.php',
		];
		foreach ( $controllers as $file_path ) {
			$source = file_get_contents( $file_path );
			$this->assertNotFalse( $source, "Could not read: $file_path" );
			$this->assertStringContainsString(
				"'stilus/v1'",
				$source,
				basename( $file_path ) . " must use 'stilus/v1'."
			);
			$this->assertStringNotContainsString(
				"'wp-ai-mind/v1'",
				$source,
				basename( $file_path ) . " must not use the legacy 'wp-ai-mind/v1' namespace."
			);
		}
	}
}
