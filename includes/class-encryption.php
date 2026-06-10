<?php
defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption for sensitive option values (password, API keys).
 * Uses libsodium (bundled in PHP 8.1+) with a key derived from WP's AUTH_KEY.
 */
class SchemaForge_WP_Encryption {

	private string $key;

	public function __construct() {
		// Derive a 256-bit key from WordPress's AUTH_KEY constant.
		$salt      = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'schemaforge-wp-salt';
		$master    = defined( 'AUTH_KEY' )  ? AUTH_KEY  : 'schemaforge-wp-key';
		$this->key = hash_hkdf( 'sha256', $master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $salt );
	}

	public function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
		return base64_encode( $nonce . $ciphertext );
	}

	public function decrypt( string $encrypted ): string {
		if ( $encrypted === '' ) {
			return '';
		}
		$decoded = base64_decode( $encrypted, true );
		if ( $decoded === false || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );
		return $plaintext === false ? '' : $plaintext;
	}

	/** Store an encrypted value in wp_options, only if it changed. */
	public function save_option( string $option_name, string $plaintext ): void {
		// If empty, store empty (clear the secret).
		if ( $plaintext === '' ) {
			update_option( $option_name, '' );
			return;
		}
		update_option( $option_name, $this->encrypt( $plaintext ) );
	}

	/** Read and decrypt a value from wp_options. */
	public function get_option( string $option_name ): string {
		$stored = (string) get_option( $option_name, '' );
		if ( $stored === '' ) {
			return '';
		}
		return $this->decrypt( $stored );
	}
}
