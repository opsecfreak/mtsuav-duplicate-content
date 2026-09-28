<?php
/**
 * Plugin Name:       One-Click Duplicate
 * Plugin URI:        https://mtsuav.com/
 * Description:       Duplicate posts, pages, products, and custom post types in one click. Row action, bulk action, and admin bar triggers with full control over status, taxonomies, meta, featured image, and comments.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MTSUAV
 * Author URI:        https://mtsuav.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       one-click-duplicate
 * Update URI:        https://github.com/opsecfreak/one-click-duplicate
 *
 * @package OCD
 */

defined( 'ABSPATH' ) || exit;

define( 'OCD_VERSION', '1.0.0' );
define( 'OCD_SLUG', 'one-click-duplicate' );
define( 'OCD_OPTION', 'ocd_options' );
define( 'OCD_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/class-mtsuav-updater.php';
require_once __DIR__ . '/includes/class-mtsuav-tip-box.php';
require_once __DIR__ . '/includes/class-ocd-settings.php';
require_once __DIR__ . '/includes/class-ocd-duplicator.php';

MTSUAV_Updater::register( 'one-click-duplicate', 'opsecfreak/one-click-duplicate', OCD_VERSION, __FILE__ );
mtsuav_tip_box_init();

/**
 * Default plugin options.
 *
 * @return array
 */
function ocd_defaults() {
	return array(
		'post_types'      => array( 'post', 'page' ),
		'status'          => 'draft',
		'title_prefix'    => '',
		'title_suffix'    => ' (Copy)',
		'copy_taxonomies' => 1,
		'copy_meta'       => 1,
		'meta_exclude'    => "_edit_lock\n_edit_last\n_wp_old_slug",
		'copy_thumbnail'  => 1,
		'copy_comments'   => 0,
		'redirect'        => 'edit',
		'capability'      => 'edit_posts',
	);
}

/**
 * Get the plugin options merged over defaults.
 *
 * @return array
 */
function ocd_get_options() {
	$saved   = get_option( OCD_OPTION, array() );
	$saved   = is_array( $saved ) ? $saved : array();
	$options = array_merge( ocd_defaults(), $saved );

	// Normalize types so callers can rely on them.
	$options['post_types']      = array_values( array_filter( array_map( 'sanitize_key', (array) $options['post_types'] ) ) );
	$options['copy_taxonomies'] = ! empty( $options['copy_taxonomies'] ) ? 1 : 0;
	$options['copy_meta']       = ! empty( $options['copy_meta'] ) ? 1 : 0;
	$options['copy_thumbnail']  = ! empty( $options['copy_thumbnail'] ) ? 1 : 0;
	$options['copy_comments']   = ! empty( $options['copy_comments'] ) ? 1 : 0;

	return $options;
}

/**
 * Minimum capability required to duplicate content.
 *
 * @return string
 */
function ocd_capability() {
	$options = ocd_get_options();
	$allowed = array( 'edit_posts', 'publish_posts', 'manage_options' );
	return in_array( $options['capability'], $allowed, true ) ? $options['capability'] : 'edit_posts';
}

/**
 * Whether the current user may duplicate the given post.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function ocd_user_can_duplicate( $post_id = 0 ) {
	$cap = ocd_capability();
	if ( $post_id ) {
		return current_user_can( $cap, $post_id ) && current_user_can( 'edit_post', $post_id );
	}
	return current_user_can( $cap );
}

/**
 * Build the duplication URL for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ocd_duplicate_url( $post_id ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'ocd_duplicate',
				'post'   => absint( $post_id ),
			),
			admin_url( 'admin-post.php' )
		),
		'ocd_duplicate_' . absint( $post_id )
	);
}

OCD_Settings::init();
OCD_Duplicator::init();
