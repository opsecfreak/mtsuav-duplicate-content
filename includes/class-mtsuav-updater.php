<?php
/**
 * MTSUAV GitHub Releases updater (shared drop-in).
 *
 * Gives any MTSUAV free plugin automatic updates from its public GitHub
 * repository's releases, with zero manifest files to maintain. The GitHub
 * release itself is the source of truth: tag vX.Y.Z plus a zip asset named
 * <slug>-X.Y.Z.zip. No version list can lag because there is no version list.
 *
 * Wiring (in the main plugin file, before including this file):
 *   define( 'MTSUAV_UPDATER_SLUG',    'mtsuav-example' );          // plugin slug / text domain
 *   define( 'MTSUAV_UPDATER_REPO',    'opsecfreak/mtsuav-example' ); // public GitHub repo
 *   define( 'MTSUAV_UPDATER_VERSION',  '1.0.0' );                   // must match the plugin header
 *   define( 'MTSUAV_UPDATER_FILE',     __FILE__ );                  // main plugin file
 *   require_once __DIR__ . '/includes/class-mtsuav-updater.php';
 *   MTSUAV_Updater::init();
 *
 * Behavior:
 * - Polls https://api.github.com/repos/<repo>/releases/latest, cached in a
 *   site transient for 12 hours (1 hour after a failure, to avoid hammering).
 * - If the release tag is newer than MTSUAV_UPDATER_VERSION, injects the
 *   update into the WordPress update transient with the release zip asset
 *   as the package URL.
 * - Answers the plugin-information API so the "View details" popup works.
 * - Fail-open and silent: any HTTP or parse failure simply offers no update.
 * - No license key, no tracking, no personal data sent. The only outbound
 *   request is the public GitHub releases API poll.
 *
 * The wp.org build variant of a plugin must NOT ship this file: strip the
 * require_once/init lines and the Update URI header so wordpress.org serves
 * updates for the directory copy.
 *
 * @package MTSUAV_Updater
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MTSUAV_Updater' ) ) {

	class MTSUAV_Updater {

		const CACHE_OK   = 12 * HOUR_IN_SECONDS;
		const CACHE_FAIL = HOUR_IN_SECONDS;
		const TIMEOUT    = 10;

		/**
		 * Wire up the update hooks. Returns false when misconfigured.
		 *
		 * @return bool
		 */
		public static function init() {
			if ( ! defined( 'MTSUAV_UPDATER_SLUG' ) || ! defined( 'MTSUAV_UPDATER_REPO' )
				|| ! defined( 'MTSUAV_UPDATER_VERSION' ) || ! defined( 'MTSUAV_UPDATER_FILE' ) ) {
				return false;
			}
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
			return true;
		}

		/**
		 * Transient key for the cached release payload.
		 *
		 * @return string
		 */
		protected static function cache_key() {
			return 'mtsuav_upd_' . MTSUAV_UPDATER_SLUG;
		}

		/**
		 * Fetch (and cache) the latest GitHub release for the repo.
		 *
		 * @return array|null Array with tag, version, zip_url, html_url, notes; null on failure.
		 */
		protected static function latest_release() {
			$cached = get_site_transient( static::cache_key() );
			if ( is_array( $cached ) ) {
				return isset( $cached['failed'] ) ? null : $cached;
			}

			$url      = 'https://api.github.com/repos/' . MTSUAV_UPDATER_REPO . '/releases/latest';
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => static::TIMEOUT,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_site_transient( static::cache_key(), array( 'failed' => time() ), static::CACHE_FAIL );
				return null;
			}

			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
				set_site_transient( static::cache_key(), array( 'failed' => time() ), static::CACHE_FAIL );
				return null;
			}

			$version = ltrim( (string) $data['tag_name'], 'vV' );
			$zip_url = '';
			if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
				$preferred = MTSUAV_UPDATER_SLUG . '-' . $version . '.zip';
				foreach ( $data['assets'] as $asset ) {
					$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
					$dl   = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
					if ( '' === $name || '' === $dl ) {
						continue;
					}
					if ( $name === $preferred ) {
						$zip_url = $dl;
						break;
					}
					if ( '' === $zip_url && 'zip' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
						$zip_url = $dl;
					}
				}
			}

			if ( '' === $zip_url ) {
				set_site_transient( static::cache_key(), array( 'failed' => time() ), static::CACHE_FAIL );
				return null;
			}

			$release = array(
				'tag'      => (string) $data['tag_name'],
				'version'  => $version,
				'zip_url'  => $zip_url,
				'html_url' => isset( $data['html_url'] ) ? (string) $data['html_url'] : ( 'https://github.com/' . MTSUAV_UPDATER_REPO ),
				'notes'    => isset( $data['body'] ) ? (string) $data['body'] : '',
			);
			set_site_transient( static::cache_key(), $release, static::CACHE_OK );
			return $release;
		}

		/**
		 * Inject the update into the core update transient when a newer
		 * GitHub release exists.
		 *
		 * @param object $transient The update_plugins site transient.
		 * @return object
		 */
		public static function check_for_update( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}

			$basename = plugin_basename( MTSUAV_UPDATER_FILE );
			$release  = static::latest_release();
			if ( null === $release ) {
				return $transient;
			}

			if ( ! version_compare( $release['version'], MTSUAV_UPDATER_VERSION, '>' ) ) {
				return $transient;
			}

			$update             = new stdClass();
			$update->slug       = MTSUAV_UPDATER_SLUG;
			$update->plugin     = $basename;
			$update->new_version = $release['version'];
			$update->url        = $release['html_url'];
			$update->package    = $release['zip_url'];
			$update->tested     = get_bloginfo( 'version' );

			$transient->response[ $basename ] = $update;
			return $transient;
		}

		/**
		 * Serve the "View details" popup from the cached release data.
		 *
		 * @param mixed  $res    Default response.
		 * @param string $action The API action.
		 * @param object $args   API arguments.
		 * @return mixed
		 */
		public static function plugin_information( $res, $action, $args ) {
			if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== MTSUAV_UPDATER_SLUG ) {
				return $res;
			}

			$release = static::latest_release();
			$info    = new stdClass();
			$info->name          = MTSUAV_UPDATER_SLUG;
			$info->slug          = MTSUAV_UPDATER_SLUG;
			$info->version       = $release ? $release['version'] : MTSUAV_UPDATER_VERSION;
			$info->author        = '<a href="https://mtsuav.com/">MTSUAV</a>';
			$info->homepage     = 'https://github.com/' . MTSUAV_UPDATER_REPO;
			$info->download_link = $release ? $release['zip_url'] : '';
			$info->requires     = '6.0';
			$info->requires_php = '8.0';
			$info->sections     = array(
				'description' => 'Free plugin by MTSUAV. See the GitHub repository for full documentation.',
				'changelog'   => $release && '' !== $release['notes']
					? nl2br( esc_html( $release['notes'] ) )
					: 'See the GitHub releases page for changelog entries.',
			);
			return $info;
		}
	}
}
