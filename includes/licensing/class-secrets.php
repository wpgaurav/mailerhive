<?php
/**
 * Site-bound authenticated encryption for the stored license key.
 */

namespace MailerHive\Licensing;

defined( 'ABSPATH' ) || exit;

final class Secrets {
	private static function key(): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && is_readable( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) || strlen( (string) AUTH_KEY ) < 32 || strlen( (string) SECURE_AUTH_KEY ) < 32 || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'License storage requires Sodium and strong AUTH_KEY and SECURE_AUTH_KEY values.' );
		}
		return hash( 'sha256', 'mailerhive:license:' . get_current_blog_id() . ':' . AUTH_KEY . SECURE_AUTH_KEY, true );
	}

	public static function encrypt( string $value ): string {
		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- authenticated ciphertext.
	}

	public static function decrypt( string $value ): string {
		$key   = self::key();
		$bytes = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- authenticated ciphertext.
		if ( false === $bytes || strlen( $bytes ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw new \RuntimeException( 'Invalid license record.' );
		}
		$plain = sodium_crypto_secretbox_open( substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		if ( false === $plain ) {
			throw new \RuntimeException( 'License storage key changed.' );
		}
		return $plain;
	}
}
