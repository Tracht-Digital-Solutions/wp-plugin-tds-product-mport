<?php
/**
 * Secret encryption.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use RuntimeException;

/**
 * Authenticated encryption using sodium or OpenSSL.
 */
final class SecretBox {
	/**
	 * Encrypt a secret.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key = $this->key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			return 's1:' . base64_encode( $nonce . $cipher );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new RuntimeException( 'Unable to encrypt source credentials.' );
		}
		return 'o1:' . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a stored secret.
	 */
	public function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, 3 ), true );
		if ( false === $raw ) {
			throw new RuntimeException( 'Invalid encrypted credential.' );
		}
		if ( str_starts_with( $encoded, 's1:' ) ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key() );
		} elseif ( str_starts_with( $encoded, 'o1:' ) ) {
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		} else {
			throw new RuntimeException( 'Unknown credential format.' );
		}
		if ( false === $plain ) {
			throw new RuntimeException( 'Unable to decrypt source credentials. WordPress salts may have changed.' );
		}
		return $plain;
	}

	/**
	 * Derive a site-specific encryption key.
	 */
	private function key(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . home_url( '/' );
		return hash( 'sha256', $material, true );
	}
}
