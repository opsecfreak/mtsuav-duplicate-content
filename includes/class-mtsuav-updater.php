<?php
/**
 * MTSUAV GitHub Releases updater (shared drop-in, v2).
 *
 * Gives any MTSUAV free plugin automatic updates from its public GitHub
 * repository's releases, with zero manifest files to maintain. The GitHub
 * release itself is the source of truth: tag vX.Y.Z plus a zip asset named
 * <slug>-X.Y.Z.zip. No version list can lag because there is no version list.
 *
 * v2 redesign: the v1 drop-in used shared global constants
 * (MTSUAV_UPDATER_SLUG etc.) plus a single static init, so only the first
 * MTSUAV plugin loaded ever got an updater. v2 keeps one class but creates
 * one INSTANCE PER PLUGIN, registered in a static registry keyed by plugin
 * basename. Any number of MTSUAV plugins can be active together; each gets
 * its own update checks against its own repo. No global constants are used.
 *
 * IMPORTANT: this file must be byte-identical in every plugin that ships
 * it. The class_exists() guard means the first-loaded copy wins; identical
 * copies make that harmless. Always copy from
 * ~/workspace/plugin-business/tools/drop-ins/class-mtsuav-updater.php.
 *
 * Wiring (in the main plugin file):
 *   require_once __DIR__ . '/includes/class-mtsuav-updater.php';
 *   MTSUAV_Updater::register(
 *       'mtsuav-example',              // plugin slug
 *       'opsecfreak/mtsuav-example',   // public GitHub repo (owner/repo)
 *       '1.0.0',                       // current plugin version (match the header)
 *       __FILE__                       // main plugin file
 *   );
 *
 * Behavior:
 * - Polls https://api.github.com/repos/<repo>/releases/latest, cached in a
 *   site transient for 12 hours (1 hour after a failure, to avoid hammering).
 * - If the release tag is newer than the registered version, injects the
 *   update into the WordPress update transient with the release zip asset
 *   as the package URL.
 * - Answers the plugin-information API so the "View details" popup works.
 * - Fail-open and silent: any HTTP or parse failure simply offers no update.
 * - No license key, no tracking, no personal data sent. The only outbound
 *   request is the public GitHub releases API poll, with a generic
 *   User-Agent that carries no site identifier.
 *
 * The wp.org build variant of a plugin must NOT ship this file: strip the
 * require_once/register lines and the Update URI header so wordpress.org
 * serves updates for the directory copy.
 *
 * @package MTSUAV_Updater
 * @version 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MTSUAV_Updater' ) ) {

	class MTSUAV_Updater {

		const DROPIN_VERSION = '2.0.0';
		const CACHE_OK       = 12 * HOUR_IN_SECONDS;
		const CACHE_FAIL     = HOUR_IN_SECONDS;
		const TIMEOUT        = 10;

		/** @var MTSUAV_Updater[] Registry of instances, keyed by plugin basename. */
		protected static $instances = array();

		/** @var string Plugin slug. */
		protected $slug;

		/** @var string GitHub repo in owner/name form. */
		protected $repo;

		/** @var string Currently installed version. */
		protected $version;

		/** @var string Basename of the main plugin file (dir/file.php). */
		protected $basename;

		/**
		 * @param string $slug        Plugin slug.
		 * @param string $repo        GitHub repo, owner/name.
		 * @param string $version     Installed version.
		 * @param string $plugin_file Absolute path to the main plugin file.
		 */
		protected function __construct( $slug, $repo, $version, $plugin_file ) {
			$this->slug     = (string) $slug;
			$this->repo     = (string) $repo;
			$this->version  = (string) $version;
			$this->basename = plugin_basename( $plugin_file );
		}

		/**
		 * Register an updater instance for one plugin. Safe to call once per
		 * plugin; repeat calls for the same plugin basename are ignored.
		 *
		 * @param string $slug        Plugin slug.
		 * @param string $repo        GitHub repo, owner/name.
		 * @param string $version     Installed version.
		 * @param string $plugin_file Absolute path to the main plugin file.
		 * @return MTSUAV_Updater|null The instance, or null on bad arguments.
		 */
		public static function register( $slug, $repo, $version, $plugin_file ) {
			if ( ! is_string( $slug ) || '' === $slug
				|| ! is_string( $repo ) || '' === $repo
				|| ! is_string( $version ) || '' === $version
				|| ! is_string( $plugin_file ) || '' === $plugin_file ) {
				return null;
			}
			$basename = plugin_basename( $plugin_file );
			if ( isset( self::$instances[ $basename ] ) ) {
				return self::$instances[ $basename ];
			}
			$instance = new self( $slug, $repo, $version, $plugin_file );
			self::$instances[ $basename ] = $instance;
			add_filter( 'pre_set_site_transient_update_plugins', array( $instance, 'check_for_update' ) );
			add_filter( 'plugins_api', array( $instance, 'plugin_information' ), 10, 3 );
			return $instance;
		}

		/**
		 * All registered instances (for tests/diagnostics).
		 *
		 * @return MTSUAV_Updater[]
		 */
		public static function registered() {
			return self::$instances;
		}

		/**
		 * Transient key for this plugin's cached release payload.
		 *
		 * @return string
		 */
		protected function cache_key() {
			return 'mtsuav_upd_' . $this->slug;
		}

		/**
		 * Fetch (and cache) the latest GitHub release for this plugin's repo.
		 *
		 * @return array|null Array with tag, version, zip_url, html_url, notes; null on failure.
		 */
		protected function latest_release() {
			$cached = get_site_transient( $this->cache_key() );
			if ( is_array( $cached ) ) {
				return isset( $cached['failed'] ) ? null : $cached;
			}

			$url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'MTSUAV-Updater/' . self::DROPIN_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_site_transient( $this->cache_key(), array( 'failed' => time() ), self::CACHE_FAIL );
				return null;
			}

			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
				set_site_transient( $this->cache_key(), array( 'failed' => time() ), self::CACHE_FAIL );
				return null;
			}

			$version = ltrim( (string) $data['tag_name'], 'vV' );
			$zip_url = '';
			if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
				$preferred = $this->slug . '-' . $version . '.zip';
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
				set_site_transient( $this->cache_key(), array( 'failed' => time() ), self::CACHE_FAIL );
				return null;
			}

			$release = array(
				'tag'      => (string) $data['tag_name'],
				'version'  => $version,
				'zip_url'  => $zip_url,
				'html_url' => isset( $data['html_url'] ) ? (string) $data['html_url'] : ( 'https://github.com/' . $this->repo ),
				'notes'    => isset( $data['body'] ) ? (string) $data['body'] : '',
			);
			set_site_transient( $this->cache_key(), $release, self::CACHE_OK );
			return $release;
		}

		/**
		 * Inject the update into the core update transient when a newer
		 * GitHub release exists for THIS plugin.
		 *
		 * @param object $transient The update_plugins site transient.
		 * @return object
		 */
		public function check_for_update( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}

			$release = $this->latest_release();
			if ( null === $release ) {
				return $transient;
			}

			if ( ! version_compare( $release['version'], $this->version, '>' ) ) {
				return $transient;
			}

			$update              = new stdClass();
			$update->slug        = $this->slug;
			$update->plugin      = $this->basename;
			$update->new_version = $release['version'];
			$update->url         = $release['html_url'];
			$update->package     = $release['zip_url'];
			$update->tested      = get_bloginfo( 'version' );

			$transient->response[ $this->basename ] = $update;
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
		public function plugin_information( $res, $action, $args ) {
			if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
				return $res;
			}

			$release = $this->latest_release();
			$info    = new stdClass();
			$info->name          = $this->slug;
			$info->slug          = $this->slug;
			$info->version       = $release ? $release['version'] : $this->version;
			$info->author        = '<a href="https://mtsuav.com/">MTSUAV</a>';
			$info->homepage      = 'https://github.com/' . $this->repo;
			$info->download_link = $release ? $release['zip_url'] : '';
			$info->requires      = '6.0';
			$info->requires_php  = '8.0';
			$info->sections      = array(
				'description' => 'Free plugin by MTSUAV. See the GitHub repository for full documentation.',
				'changelog'   => $release && '' !== $release['notes']
					? nl2br( esc_html( $release['notes'] ) )
					: 'See the GitHub releases page for changelog entries.',
			);
			return $info;
		}
	}
}
