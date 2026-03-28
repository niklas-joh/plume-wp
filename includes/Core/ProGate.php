<?php
declare( strict_types=1 );

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden
namespace WP_AI_Mind\Core {
	// phpcs:enable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden

	/**
	 * Free/Pro gate. Single abstraction — swap the backend without touching callers.
	 *
	 * Backend: Freemius SDK (wam_fs()) when loaded; wp_options flag as fallback.
	 */
	class ProGate {

		private const OPTION_KEY = 'wp_ai_mind_licence_status';

		public static function is_pro(): bool {
			// Dev override: define WP_AI_MIND_PRO in wp-config.php or an mu-plugin.
			if ( defined( 'WP_AI_MIND_PRO' ) ) {
				return (bool) WP_AI_MIND_PRO;
			}
			// When Freemius SDK is loaded, delegate to it.
			// wam_fs() is the Freemius bootstrap function defined in wp-ai-mind.php.
			if ( function_exists( 'wam_fs' ) ) {
				try {
					return \wam_fs()->can_use_premium_code__premium_only(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Freemius not yet initialised — fall through to option check.
				}
			}
			// Fallback: manual licence flag set during activation.
			return 'active' === \get_option( self::OPTION_KEY, '' );
		}

		/** Called from Freemius webhook / activation in P6. */
		public static function activate( string $licence_key ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			// Stub — full Freemius activation in P6.
			update_option( self::OPTION_KEY, 'active' );
			return true;
		}

		public static function deactivate(): void {
			delete_option( self::OPTION_KEY );
		}
	}
}

// phpcs:disable
namespace {
	// Global helper — all callers use this, never the class directly.
	if ( ! function_exists( 'wp_ai_mind_is_pro' ) ) {
		function wp_ai_mind_is_pro(): bool {
			return \WP_AI_Mind\Core\ProGate::is_pro();
		}
	}
}
// phpcs:enable
