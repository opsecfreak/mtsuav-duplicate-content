<?php
/**
 * MTSUAV quiet tip box (shared drop-in).
 *
 * A small, dismissible tip box shown ONLY on the plugin's own admin screens.
 * Never a nag: one quiet box, dismissible per user, no repeat prompts.
 *
 * Usage (inside the plugin's own settings/admin page output):
 *   if ( function_exists( 'mtsuav_tip_box' ) ) {
 *       mtsuav_tip_box( 'mtsuav-example', 'MTSUAV Example' );
 *   }
 *
 * Dismissal is handled on admin_init via ?mtsuav_tip_dismiss=<slug>&_wpnonce=...
 * and stored per user, so each user dismisses it once.
 *
 * @package MTSUAV_Tip_Box
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mtsuav_tip_box' ) ) {

	/**
	 * Render the quiet tip box for a plugin's own admin screen.
	 *
	 * @param string $slug       Plugin slug, e.g. 'mtsuav-example'.
	 * @param string $name       Human-readable plugin name.
	 * @param string $store_url  Optional store URL. Defaults to the MTSUAV shop.
	 * @return void
	 */
	function mtsuav_tip_box( $slug, $name, $store_url = 'https://mtsuav.com/shop/' ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id && get_user_meta( $user_id, 'mtsuav_tip_dismissed_' . $slug, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'mtsuav_tip_dismiss', $slug ),
			'mtsuav_tip_dismiss_' . $slug
		);
		?>
		<div class="notice notice-info mtsuav-tip-box" style="display:flex;gap:12px;align-items:center;justify-content:space-between;">
			<p style="margin:0.6em 0;">
				<strong><?php echo esc_html( $name ); ?></strong> is free, and stays free.
				If it saves you time, a tip helps fund new free plugins.
				<a class="button button-small" style="margin-left:8px;" href="<?php echo esc_url( $store_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Tip the developer', 'mtsuav-tip-box' ); ?></a>
			</p>
			<p style="margin:0.6em 0;white-space:nowrap;">
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="color:#787c82;text-decoration:none;"><?php esc_html_e( 'Dismiss', 'mtsuav-tip-box' ); ?></a>
			</p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'mtsuav_tip_box_handle_dismiss' ) ) {

	/**
	 * Handle tip-box dismissal. Hooked to admin_init by mtsuav_tip_box_init().
	 *
	 * @return void
	 */
	function mtsuav_tip_box_handle_dismiss() {
		if ( ! isset( $_GET['mtsuav_tip_dismiss'] ) ) {
			return;
		}
		$slug = sanitize_key( wp_unslash( $_GET['mtsuav_tip_dismiss'] ) );
		if ( '' === $slug || ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mtsuav_tip_dismiss_' . $slug ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, 'mtsuav_tip_dismissed_' . $slug, '1' );
		}
		wp_safe_redirect( remove_query_arg( array( 'mtsuav_tip_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Wire up the dismissal handler. Call once from the main plugin file.
	 *
	 * @return void
	 */
	function mtsuav_tip_box_init() {
		add_action( 'admin_init', 'mtsuav_tip_box_handle_dismiss' );
	}
}
