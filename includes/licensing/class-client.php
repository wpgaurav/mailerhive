<?php
/**
 * FluentCart license server client. The product ID is the FluentCart product post on
 * the MailerHive store, never a variation.
 */

namespace MailerHive\Licensing;

defined( 'ABSPATH' ) || exit;

final class Client {
	const PRODUCT_ID = 1182799;
	const STORE      = 'https://gauravtiwari.org/';
	const PAGE       = self::STORE . 'product/mailerhive/';

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function request( string $action, array $credentials ) {
		if ( ! in_array( $action, array( 'activate_license', 'check_license', 'deactivate_license', 'get_license_version' ), true ) ) {
			return new \WP_Error( 'mailerhive_license_action', __( 'Unsupported license action.', 'mailerhive' ) );
		}
		$body     = array_merge(
			$credentials,
			array(
				'item_id'          => self::PRODUCT_ID,
				'site_url'         => home_url( '/' ),
				'current_version'  => MAILERHIVE_VERSION,
				'platform_version' => get_bloginfo( 'version' ),
				'server_version'   => PHP_VERSION,
			)
		);
		$response = wp_remote_post(
			self::STORE . '?fluent-cart=' . $action,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 262144,
				'headers'             => array( 'Accept' => 'application/json' ),
				'body'                => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mailerhive_license_connection', __( 'The license server could not be reached. Try again.', 'mailerhive' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'mailerhive_license_response', __( 'The license server returned an unreadable response.', 'mailerhive' ) );
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = array_merge( $data['data'], array( 'success' => $data['success'] ?? true ) );
		}
		if ( $code < 200 || $code >= 300 || false === ( $data['success'] ?? true ) ) {
			// Never surface raw server messages: they can contain a key or a signed URL.
			return new \WP_Error( 'mailerhive_license_rejected', __( 'The license request was rejected. Check the key and that it is still active.', 'mailerhive' ) );
		}
		return $data;
	}
}
