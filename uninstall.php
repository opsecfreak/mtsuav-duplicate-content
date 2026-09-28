<?php
/**
 * Uninstall handler for MTSUAV Duplicate Content.
 *
 * Removes the plugin option, per-user tip-box dismissal flags, and any
 * cached updater data.
 *
 * @package MTSUAV_Duplicate_Content
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mtsuav_dc_options' );

// Per-user tip-box dismissal flags set by the shared tip box drop-in.
delete_metadata( 'user', 0, 'mtsuav_tip_dismissed_mtsuav-duplicate-content', '', true );

// Cached GitHub release data from the shared updater drop-in.
delete_site_transient( 'mtsuav_upd_mtsuav-duplicate-content' );
