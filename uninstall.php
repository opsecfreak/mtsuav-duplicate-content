<?php
/**
 * Uninstall handler for One-Click Duplicate.
 *
 * Removes the plugin option, per-user tip-box dismissal flags, and any
 * cached updater data.
 *
 * @package OCD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ocd_options' );

// Per-user tip-box dismissal flags set by the shared tip box drop-in.
delete_metadata( 'user', 0, 'mtsuav_tip_dismissed_one-click-duplicate', '', true );

// Cached GitHub release data from the shared updater drop-in.
delete_site_transient( 'mtsuav_upd_one-click-duplicate' );
