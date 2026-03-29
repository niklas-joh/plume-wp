<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Settings;

class ProviderSettings {

	private const OPTION_KEY      = 'wp_ai_mind_provider_keys';
	private const CIPHER          = 'AES-256-CBC';
	private const VALID_PROVIDERS = [ 'claude', 'openai', 'gemini', 'ollama' ];

	private array $keys;

	public function __construct() {
		$raw        = get_option( self::OPTION_KEY, [] );
		$this->keys = is_array( $raw ) ? $raw : [];
	}

	public function get_api_key( string $provider ): string {
		if ( ! isset( $this->keys[ $provider ] ) ) {
			return '';
		}
		return self::decrypt( $this->keys[ $provider ] );
	}

	public function set_api_key( string $provider, string $key ): void {
		if ( ! in_array( $provider, self::VALID_PROVIDERS, true ) ) {
			return;
		}
		$this->keys[ $provider ] = self::encrypt( $key );
		update_option( self::OPTION_KEY, $this->keys );
	}

	public function has_key( string $provider ): bool {
		return '' !== $this->get_api_key( $provider );
	}

	// ── Encryption helpers ─────────────────────────────────────────────────────

	private static function secret(): string {
		return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
	}

	private static function encrypt( string $plain ): string {
		$iv         = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( $plain, self::CIPHER, self::secret(), 0, $iv );
		return base64_encode( $iv . '::' . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding encrypted API key, not obfuscation.
	}

	private static function decrypt( string $encoded ): string {
		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted API key, not obfuscation.
		if ( false === $decoded || ! str_contains( $decoded, '::' ) ) {
			return '';
		}
		[ $iv, $ciphertext ] = explode( '::', $decoded, 2 );
		$plain               = openssl_decrypt( $ciphertext, self::CIPHER, self::secret(), 0, $iv );
		return false === $plain ? '' : $plain;
	}
}
