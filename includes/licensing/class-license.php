<?php
/**
 * This site's MailerHive license. It unlocks automatic updates only; every feature
 * works without it.
 */

namespace MailerHive\Licensing;

defined( 'ABSPATH' ) || exit;

final class License {
	const OPTION = 'mailerhive_license';

	private $client;

	public function __construct( ?Client $client = null ) {
		$this->client = $client ?? new Client();
	}

	public function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** @return array{license_key:string,activation_hash:string}|array{} */
	public function credentials(): array {
		$state = $this->state();
		// A copied site must activate itself, never act as the source installation.
		if ( empty( $state['secret'] ) || ! is_string( $state['secret'] ) || ( $state['site'] ?? '' ) !== home_url( '/' ) ) {
			return array();
		}
		try {
			$secret = json_decode( Secrets::decrypt( $state['secret'] ), true );
			return is_array( $secret ) && is_string( $secret['license_key'] ?? null ) && is_string( $secret['activation_hash'] ?? null )
				? array( 'license_key' => $secret['license_key'], 'activation_hash' => $secret['activation_hash'] ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** @return bool|\WP_Error */
	public function activate( string $key ) {
		if ( $this->state() ) {
			return new \WP_Error( 'mailerhive_license_existing', __( 'Disconnect the current license before activating another.', 'mailerhive' ) );
		}
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 256 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			return new \WP_Error( 'mailerhive_license_key', __( 'Enter a valid license key.', 'mailerhive' ) );
		}
		try {
			Secrets::encrypt( $key ); // establish encryption before consuming a remote activation
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'mailerhive_license_encryption', __( 'Set strong AUTH_KEY and SECURE_AUTH_KEY values in wp-config.php and enable Sodium before activating.', 'mailerhive' ) );
		}
		$data = $this->client->request( 'activate_license', array( 'license_key' => $key ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $this->valid( $data ) || empty( $data['activation_hash'] ) || ! is_scalar( $data['activation_hash'] ) ) {
			return new \WP_Error( 'mailerhive_license_invalid', __( 'This license is not valid for MailerHive.', 'mailerhive' ) );
		}
		$this->save( $data, array( 'license_key' => $key, 'activation_hash' => (string) $data['activation_hash'] ) );
		return true;
	}

	/** @return bool|\WP_Error */
	public function check() {
		$credentials = $this->credentials();
		if ( ! $credentials ) {
			return new \WP_Error( 'mailerhive_license_missing', __( 'Activate a license for this site first.', 'mailerhive' ) );
		}
		$data = $this->client->request( 'check_license', $credentials );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $this->valid( $data ) ) {
			$data = array( 'status' => 'invalid' );
		}
		$this->save( $data, $credentials );
		return 'valid' === ( $data['status'] ?? '' ) ? true : new \WP_Error( 'mailerhive_license_invalid', __( 'The license is inactive. MailerHive keeps working, but updates are paused.', 'mailerhive' ) );
	}

	/** @return bool|\WP_Error */
	public function disconnect() {
		$credentials = $this->credentials();
		if ( $credentials ) {
			$data = $this->client->request( 'deactivate_license', $credentials );
			if ( is_wp_error( $data ) ) {
				if ( 'mailerhive_license_connection' === $data->get_error_code() ) {
					delete_option( self::OPTION );
					Updater::clear_cache();
					delete_site_transient( 'update_plugins' );
					return new \WP_Error( 'mailerhive_license_disconnected_local', __( 'The license key was removed from this site, but the store could not be reached, so the seat may still be in use.', 'mailerhive' ) );
				}
				return $data;
			}
			if ( 'deactivated' !== ( $data['status'] ?? '' ) ) {
				return new \WP_Error( 'mailerhive_license_disconnect', __( 'The license could not be disconnected. Try again.', 'mailerhive' ) );
			}
		}
		delete_option( self::OPTION );
		Updater::clear_cache();
		delete_site_transient( 'update_plugins' );
		return true;
	}

	private function valid( array $data ): bool {
		return 'valid' === ( $data['status'] ?? '' ) && (int) ( $data['product_id'] ?? 0 ) === Client::PRODUCT_ID;
	}

	private function save( array $data, array $credentials ): void {
		$old     = $this->state();
		$encoded = wp_json_encode( $credentials );
		if ( false === $encoded ) {
			throw new \RuntimeException( 'License could not be encoded.' );
		}
		$expires = $data['expiration_date'] ?? $old['expires'] ?? '';
		$plan    = $data['variation_title'] ?? $old['plan'] ?? '';
		update_option(
			self::OPTION,
			array(
				'secret'     => Secrets::encrypt( $encoded ),
				'site'       => home_url( '/' ),
				'status'     => 'valid' === ( $data['status'] ?? '' ) ? 'valid' : 'invalid',
				'suffix'     => substr( $credentials['license_key'], -4 ),
				'expires'    => sanitize_text_field( is_scalar( $expires ) ? (string) $expires : '' ),
				'plan'       => sanitize_text_field( is_scalar( $plan ) ? (string) $plan : '' ),
				'checked_at' => time(),
			),
			false
		);
		Updater::clear_cache();
		delete_site_transient( 'update_plugins' );
	}
}
