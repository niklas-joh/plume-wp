<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Core;

class Autoloader {

	private static bool $registered = false;

	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}
		self::$registered = spl_autoload_register( [ self::class, 'load' ] );
		return (bool) self::$registered;
	}

	public static function load( string $class_name ): void {
		$prefix = 'WP_AI_Mind\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		// WP_AI_MIND_DIR is set in the WordPress bootstrap (wp-ai-mind.php).
		// In unit test context it is not defined, so fall back to plugin root.
		$base = defined( 'WP_AI_MIND_DIR' ) ? WP_AI_MIND_DIR : dirname( __DIR__, 2 ) . '/';
		$file = $base . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
