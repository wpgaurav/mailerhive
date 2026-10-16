<?php
/**
 * Offers licensed MailerHive releases from the store through WordPress's normal
 * plugin updates. Packages are accepted only from the store or its R2 bucket, and
 * an update is installed only when the ZIP's plugin header matches the release.
 */

namespace MailerHive\Licensing;

defined( 'ABSPATH' ) || exit;

final class Updater {
	const SLUG          = 'mailerhive';
	const CACHE         = 'mailerhive_update_metadata';
	const CACHE_SECONDS = 10800; // three hours between store checks
	const ICON          = 'https://r2.gauravtiwari.org/wp-content/uploads/2026/10/mailerhive-product-icon-512-20261011.png';
	const BANNER        = 'https://r2.gauravtiwari.org/wp-content/uploads/2026/10/mailerhive-banner-1544x500-20261011.png';

	private static $fetching  = false;
	private static $injecting = false;
	private $license;
	private $client;

	public function __construct( ?License $license = null, ?Client $client = null ) {
		$this->license = $license ?? new License();
		$this->client  = $client ?? new Client();
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'updates' ) );
		add_filter( 'plugins_api', array( $this, 'information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'source' ), 10, 4 );
		add_action( 'delete_site_transient_update_plugins', array( self::class, 'clear_cache' ) );
	}

	public static function clear_cache(): void {
		delete_transient( self::CACHE );
	}

	public static function package_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 'gauravtiwari.org' === $host || (bool) preg_match( '/^gauravtiwari-org-fluentcart\.[a-f0-9]{32}\.r2\.cloudflarestorage\.com$/D', $host );
	}

	/** @return array<string,mixed>|\WP_Error|null */
	public function metadata( bool $force = false ) {
		$credentials = $this->license->credentials();
		if ( ! $credentials || self::$fetching ) {
			return null;
		}
		$identity = hash( 'sha256', home_url( '/' ) . wp_json_encode( $credentials ) . MAILERHIVE_VERSION );
		$cached   = get_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) && ( $cached['identity'] ?? '' ) === $identity && is_array( $cached['data'] ?? null ) ) {
			$data    = $cached['data'];
			$package = is_string( $data['package'] ?? null ) ? $data['package'] : '';
			if ( '' !== $package && ! self::package_allowed( $package ) ) {
				$data['package'] = '';
			}
			return $data;
		}
		self::$fetching = true;
		try {
			$data = $this->client->request( 'get_license_version', $credentials );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$version = is_scalar( $data['new_version'] ?? null ) ? (string) $data['new_version'] : '';
			if ( self::SLUG !== ( $data['slug'] ?? '' ) || ( isset( $data['product_id'] ) && (int) $data['product_id'] !== Client::PRODUCT_ID ) || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+.][0-9A-Za-z.-]+)?$/D', $version ) ) {
				return new \WP_Error( 'mailerhive_update_identity', __( 'The store returned incompatible update metadata.', 'mailerhive' ) );
			}
			$package             = $data['package'] ?? $data['download_link'] ?? '';
			$package             = is_string( $package ) ? $package : '';
			$data['new_version'] = $version;
			$data['package']     = 'valid' === ( $data['license_status'] ?? '' ) && self::package_allowed( $package ) ? $package : '';
			set_transient( self::CACHE, array( 'identity' => $identity, 'data' => $data ), self::CACHE_SECONDS );
			return $data;
		} finally {
			self::$fetching = false;
		}
	}

	public function updates( $transient ) {
		if ( ! ( $transient instanceof \stdClass ) || empty( $transient->checked ) || self::$injecting ) {
			return $transient;
		}
		self::$injecting = true;
		try {
			$file = plugin_basename( MAILERHIVE_FILE );
			$data = $this->metadata();
			if ( is_wp_error( $data ) ) {
				return $transient;
			}
			if ( ! $data ) {
				unset( $transient->response[ $file ], $transient->no_update[ $file ] );
				return $transient;
			}
			$entry = (object) array(
				'id'           => Client::PAGE,
				'slug'         => self::SLUG,
				'plugin'       => $file,
				'new_version'  => $data['new_version'],
				'url'          => Client::PAGE,
				'package'      => $data['package'],
				'requires'     => $data['requires'] ?? '6.6',
				'requires_php' => $data['requires_php'] ?? '8.1',
				'tested'       => $data['tested'] ?? '',
				'icons'        => array( '2x' => self::ICON, '1x' => self::ICON ),
				'banners'      => array( 'high' => self::BANNER, 'low' => self::BANNER ),
			);
			if ( '' !== $data['package'] && version_compare( (string) $data['new_version'], MAILERHIVE_VERSION, '>' ) ) {
				$transient->response[ $file ] = $entry;
				unset( $transient->no_update[ $file ] );
			} else {
				$transient->no_update[ $file ] = $entry;
				unset( $transient->response[ $file ] );
			}
			return $transient;
		} finally {
			self::$injecting = false;
		}
	}

	public function information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$data = $this->metadata();
		if ( ! $data || is_wp_error( $data ) ) {
			return $result;
		}
		$sections = array();
		foreach ( array( 'description', 'changelog' ) as $key ) {
			$section          = is_array( $data['sections'] ?? null ) ? ( $data['sections'][ $key ] ?? '' ) : '';
			$sections[ $key ] = wp_kses_post( is_string( $section ) ? $section : '' );
		}
		return (object) array(
			'name'          => 'MailerHive',
			'slug'          => self::SLUG,
			'version'       => $data['new_version'],
			'author'        => '<a href="https://gauravtiwari.org/">Gaurav Tiwari</a>',
			'homepage'      => Client::PAGE,
			'requires'      => $data['requires'] ?? '6.6',
			'requires_php'  => $data['requires_php'] ?? '8.1',
			'tested'        => $data['tested'] ?? '',
			'sections'      => $sections,
			'download_link' => $data['package'],
			'banners'       => array( 'high' => self::BANNER, 'low' => self::BANNER ),
			'icons'         => array( '2x' => self::ICON, '1x' => self::ICON ),
		);
	}

	public function download( $reply, $package, $upgrader, $extra ) {
		if ( plugin_basename( MAILERHIVE_FILE ) !== ( $extra['plugin'] ?? '' ) || false !== $reply ) {
			return $reply;
		}
		// WordPress takes the same-site store offline during bulk updates. Use the identity-bound cache there.
		$same_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST ) === wp_parse_url( Client::STORE, PHP_URL_HOST );
		$data      = $this->metadata( ! ( $same_host && file_exists( ABSPATH . '.maintenance' ) ) );
		if ( ! $data || is_wp_error( $data ) || empty( $data['package'] ) || ! is_string( $data['package'] ) || ! version_compare( (string) $data['new_version'], MAILERHIVE_VERSION, '>' ) ) {
			return new \WP_Error( 'mailerhive_update_unavailable', __( 'A licensed MailerHive update is not available. Check the license and try again.', 'mailerhive' ) );
		}
		if ( ! self::package_allowed( $data['package'] ) ) {
			return new \WP_Error( 'mailerhive_update_unavailable', __( 'A licensed MailerHive update is not available. Check the license and try again.', 'mailerhive' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $data['package'], 120 ); // a fresh signed URL; never logged
		return is_wp_error( $file ) ? new \WP_Error( 'mailerhive_update_download', __( 'The MailerHive update could not be downloaded. Try again.', 'mailerhive' ) ) : $file;
	}

	public function source( $source, $remote, $upgrader, $extra ) {
		if ( plugin_basename( MAILERHIVE_FILE ) !== ( $extra['plugin'] ?? '' ) || ! is_string( $source ) ) {
			return $source;
		}
		$main = trailingslashit( $source ) . 'mailerhive.php';
		$data = $this->metadata();
		if ( ! is_file( $main ) || ! $data || is_wp_error( $data ) ) {
			return new \WP_Error( 'mailerhive_update_package', __( 'The update does not contain a valid MailerHive plugin.', 'mailerhive' ) );
		}
		$header = get_file_data( $main, array( 'name' => 'Plugin Name', 'version' => 'Version' ) );
		if ( 'MailerHive' !== $header['name'] || $header['version'] !== $data['new_version'] || ! version_compare( $header['version'], MAILERHIVE_VERSION, '>' ) ) {
			return new \WP_Error( 'mailerhive_update_package', __( 'The update package does not match the MailerHive release.', 'mailerhive' ) );
		}
		return $source;
	}
}
