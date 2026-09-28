<?php
/**
 * Duplication engine for One-Click Duplicate.
 *
 * Triggers:
 * - "Duplicate" row action on post list tables.
 * - "Duplicate" bulk action on post list tables.
 * - "Duplicate this" item in the admin bar on singular frontend views.
 *
 * Every trigger enforces the configured minimum capability and uses nonces.
 *
 * @package OCD
 */

defined( 'ABSPATH' ) || exit;

class OCD_Duplicator {

	const ROW_ACTION   = 'ocd_duplicate';
	const BULK_ACTION  = 'ocd_duplicate';
	const ADMIN_ACTION = 'ocd_duplicate';

	/**
	 * Wire up hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'register_bulk_actions' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( __CLASS__, 'handle_duplicate_request' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_item' ), 100 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Whether duplication is enabled for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function type_enabled( $post_type ) {
		$options = ocd_get_options();
		return in_array( $post_type, $options['post_types'], true );
	}

	/**
	 * Add the "Duplicate" row action.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $actions;
		}
		if ( ! self::type_enabled( $post->post_type ) ) {
			return $actions;
		}
		if ( ! ocd_user_can_duplicate( $post->ID ) ) {
			return $actions;
		}
		if ( 'trash' === $post->post_status ) {
			return $actions;
		}

		$actions['ocd_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( ocd_duplicate_url( $post->ID ) ),
			esc_attr( sprintf( __( 'Duplicate "%s"', 'one-click-duplicate' ), $post->post_title ) ),
			esc_html__( 'Duplicate', 'one-click-duplicate' )
		);
		return $actions;
	}

	/**
	 * Register the bulk action for every enabled post type.
	 *
	 * @return void
	 */
	public static function register_bulk_actions() {
		$options = ocd_get_options();
		foreach ( $options['post_types'] as $post_type ) {
			$type_obj = get_post_type_object( $post_type );
			if ( ! $type_obj || ! $type_obj->show_ui ) {
				continue;
			}
			add_filter( "bulk_actions-edit-{$post_type}", array( __CLASS__, 'bulk_action_label' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Add the bulk action label.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public static function bulk_action_label( $actions ) {
		if ( ! ocd_user_can_duplicate() ) {
			return $actions;
		}
		$actions[ self::BULK_ACTION ] = __( 'Duplicate', 'one-click-duplicate' );
		return $actions;
	}

	/**
	 * Handle the bulk duplication action.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action name.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( self::BULK_ACTION !== $doaction ) {
			return $redirect_to;
		}
		if ( ! ocd_user_can_duplicate() ) {
			wp_die( esc_html__( 'You do not have permission to duplicate content.', 'one-click-duplicate' ) );
		}

		$count = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post || ! self::type_enabled( $post->post_type ) ) {
				continue;
			}
			if ( ! ocd_user_can_duplicate( $post_id ) ) {
				continue;
			}
			$new_id = self::duplicate_post( $post_id );
			if ( ! is_wp_error( $new_id ) && $new_id ) {
				++$count;
			}
		}

		return add_query_arg( 'ocd_duplicated', $count, $redirect_to );
	}

	/**
	 * Handle the admin-post duplication request (row action and admin bar).
	 *
	 * @return void
	 */
	public static function handle_duplicate_request() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Missing post ID.', 'one-click-duplicate' ) );
		}

		check_admin_referer( 'ocd_duplicate_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || ! self::type_enabled( $post->post_type ) ) {
			wp_die( esc_html__( 'This content type cannot be duplicated.', 'one-click-duplicate' ) );
		}
		if ( ! ocd_user_can_duplicate( $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this item.', 'one-click-duplicate' ) );
		}

		$new_id = self::duplicate_post( $post_id );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			wp_die( esc_html__( 'Duplication failed. Please try again.', 'one-click-duplicate' ) );
		}

		wp_safe_redirect( self::after_duplicate_url( $new_id ) );
		exit;
	}

	/**
	 * Add the admin bar item on singular frontend views.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function admin_bar_item( $wp_admin_bar ) {
		if ( is_admin() || ! is_singular() || ! is_admin_bar_showing() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! self::type_enabled( $post->post_type ) ) {
			return;
		}
		if ( ! ocd_user_can_duplicate( $post_id ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'ocd-duplicate',
				'title' => __( 'Duplicate this', 'one-click-duplicate' ),
				'href'  => ocd_duplicate_url( $post_id ),
				'meta'  => array( 'class' => 'ocd-duplicate' ),
			)
		);
	}

	/**
	 * Duplicate a post and everything the settings allow.
	 *
	 * @param int $post_id Source post ID.
	 * @return int|WP_Error New post ID on success.
	 */
	public static function duplicate_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ocd_missing', __( 'Source item not found.', 'one-click-duplicate' ) );
		}

		$options = ocd_get_options();

		$status = self::resolve_status( $post, $options['status'] );

		$new_post = array(
			'post_author'           => get_current_user_id(),
			'post_content'          => $post->post_content,
			'post_content_filtered' => $post->post_content_filtered,
			'post_title'            => $options['title_prefix'] . $post->post_title . $options['title_suffix'],
			'post_excerpt'          => $post->post_excerpt,
			'post_status'           => $status,
			'post_type'             => $post->post_type,
			'post_parent'           => $post->post_parent,
			'menu_order'            => $post->menu_order,
			'comment_status'        => $post->comment_status,
			'ping_status'           => $post->ping_status,
			'post_password'         => $post->post_password,
			'post_name'             => '',
			'to_ping'               => '',
			'pinged'                => '',
		);

		$new_id = wp_insert_post( $new_post, true );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'ocd_insert_failed', __( 'Could not create the copy.', 'one-click-duplicate' ) );
		}

		if ( $options['copy_taxonomies'] ) {
			self::copy_taxonomies( $post_id, $new_id, $post->post_type );
		}

		$excluded = self::exclusion_list( $options['meta_exclude'] );

		if ( $options['copy_meta'] ) {
			self::copy_meta( $post_id, $new_id, $excluded );
		} elseif ( is_post_type_hierarchical( $post->post_type ) ) {
			// Page template must survive even when meta copying is off.
			$template = get_post_meta( $post_id, '_wp_page_template', true );
			if ( '' !== $template ) {
				update_post_meta( $new_id, '_wp_page_template', $template );
			}
		}

		if ( $options['copy_thumbnail'] ) {
			self::copy_thumbnail( $post_id, $new_id, $excluded );
		}

		if ( $options['copy_comments'] ) {
			self::copy_comments( $post_id, $new_id );
		}

		/**
		 * Fires after an item has been duplicated.
		 *
		 * @param int $new_id  New post ID.
		 * @param int $post_id Source post ID.
		 */
		do_action( 'ocd_after_duplicate', $new_id, $post_id );

		return $new_id;
	}

	/**
	 * Resolve the status for the copy.
	 *
	 * @param WP_Post $post   Source post.
	 * @param string  $setting Status setting.
	 * @return string
	 */
	protected static function resolve_status( $post, $setting ) {
		if ( 'same' === $setting ) {
			$keep = array( 'publish', 'pending', 'draft', 'private', 'future' );
			return in_array( $post->post_status, $keep, true ) ? $post->post_status : 'draft';
		}
		return $setting;
	}

	/**
	 * Copy taxonomy terms by slug.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $new_id    New post ID.
	 * @param string $post_type Post type.
	 * @return void
	 */
	protected static function copy_taxonomies( $source_id, $new_id, $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			wp_set_object_terms( $new_id, $terms, $taxonomy, false );
		}
	}

	/**
	 * Parse the exclusion textarea into a list of meta keys.
	 *
	 * @param string $raw Raw textarea content.
	 * @return array
	 */
	protected static function exclusion_list( $raw ) {
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ) );
		$keys  = array();
		foreach ( $lines as $line ) {
			$key = sanitize_key( $line );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}
		// Never copy the featured image reference through generic meta copying.
		$keys[] = '_thumbnail_id';
		return array_unique( $keys );
	}

	/**
	 * Copy post meta, skipping excluded keys.
	 *
	 * @param int   $source_id Source post ID.
	 * @param int   $new_id    New post ID.
	 * @param array $excluded  Meta keys to skip.
	 * @return void
	 */
	protected static function copy_meta( $source_id, $new_id, $excluded ) {
		$all_meta = get_post_meta( $source_id );
		if ( ! is_array( $all_meta ) ) {
			return;
		}
		foreach ( $all_meta as $key => $values ) {
			if ( in_array( $key, $excluded, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Duplicate the featured image as an independent attachment.
	 *
	 * Creates a new attachment record pointing at the same file, with its own
	 * metadata, so deleting or editing one copy never affects the other.
	 *
	 * @param int   $source_id Source post ID.
	 * @param int   $new_id    New post ID.
	 * @param array $excluded  Meta keys to skip.
	 * @return void
	 */
	protected static function copy_thumbnail( $source_id, $new_id, $excluded ) {
		$thumb_id = get_post_thumbnail_id( $source_id );
		if ( ! $thumb_id ) {
			return;
		}
		$attachment = get_post( $thumb_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return;
		}

		$file = get_attached_file( $thumb_id );
		if ( ! $file ) {
			return;
		}

		$new_attachment = array(
			'post_title'     => $attachment->post_title,
			'post_content'   => $attachment->post_content,
			'post_excerpt'   => $attachment->post_excerpt,
			'post_status'    => 'inherit',
			'post_type'      => 'attachment',
			'post_parent'    => $new_id,
			'post_mime_type' => $attachment->post_mime_type,
			'guid'           => $attachment->guid,
		);

		$new_thumb_id = wp_insert_attachment( $new_attachment, $file, $new_id, true );
		if ( is_wp_error( $new_thumb_id ) || ! $new_thumb_id ) {
			return;
		}

		$all_meta = get_post_meta( $thumb_id );
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $key => $values ) {
				if ( in_array( $key, $excluded, true ) ) {
					continue;
				}
				foreach ( (array) $values as $value ) {
					add_post_meta( $new_thumb_id, $key, maybe_unserialize( $value ) );
				}
			}
		}

		set_post_thumbnail( $new_id, $new_thumb_id );
	}

	/**
	 * Copy comments, remapping reply parents so threads stay intact.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $new_id    New post ID.
	 * @return void
	 */
	protected static function copy_comments( $source_id, $new_id ) {
		$comments = get_comments(
			array(
				'post_id' => $source_id,
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);
		if ( empty( $comments ) ) {
			return;
		}

		$id_map = array( 0 => 0 );
		foreach ( $comments as $comment ) {
			$data = array(
				'comment_post_ID'      => $new_id,
				'comment_author'       => $comment->comment_author,
				'comment_author_email' => $comment->comment_author_email,
				'comment_author_url'   => $comment->comment_author_url,
				'comment_author_IP'    => $comment->comment_author_IP,
				'comment_date'         => $comment->comment_date,
				'comment_date_gmt'     => $comment->comment_date_gmt,
				'comment_content'      => $comment->comment_content,
				'comment_karma'        => $comment->comment_karma,
				'comment_approved'     => $comment->comment_approved,
				'comment_agent'        => $comment->comment_agent,
				'comment_type'         => $comment->comment_type,
				'comment_parent'       => isset( $id_map[ (int) $comment->comment_parent ] ) ? $id_map[ (int) $comment->comment_parent ] : 0,
				'user_id'              => $comment->user_id,
			);
			$new_comment_id = wp_insert_comment( $data );
			if ( $new_comment_id ) {
				$id_map[ (int) $comment->comment_ID ] = (int) $new_comment_id;
			}
		}

		// Refresh the comment count on the new post.
		wp_update_comment_count( $new_id );
	}

	/**
	 * Build the redirect URL after a single duplication.
	 *
	 * @param int $new_id New post ID.
	 * @return string
	 */
	protected static function after_duplicate_url( $new_id ) {
		$options = ocd_get_options();
		$post    = get_post( $new_id );

		switch ( $options['redirect'] ) {
			case 'list':
				$url = admin_url( 'edit.php' );
				if ( $post ) {
					$url = add_query_arg( 'post_type', $post->post_type, $url );
				}
				return add_query_arg( 'ocd_created', $new_id, $url );

			case 'stay':
				$referer = wp_get_referer();
				$base    = $referer ? $referer : admin_url( 'edit.php' );
				return add_query_arg( 'ocd_created', $new_id, $base );

			case 'edit':
			default:
				$edit = get_edit_post_link( $new_id, 'raw' );
				if ( ! $edit ) {
					$edit = admin_url( 'edit.php' );
				}
				return add_query_arg( 'ocd_created', $new_id, $edit );
		}
	}

	/**
	 * Show confirmation notices after duplication.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( isset( $_GET['ocd_created'] ) ) {
			$new_id = absint( $_GET['ocd_created'] );
			$post   = $new_id ? get_post( $new_id ) : null;
			if ( $post && current_user_can( 'edit_post', $new_id ) ) {
				$title = $post->post_title ? $post->post_title : sprintf( __( '(no title, ID %d)', 'one-click-duplicate' ), $new_id );
				$view  = get_permalink( $new_id );
				$edit  = get_edit_post_link( $new_id );
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %s: duplicated item title */
					esc_html__( 'Duplicated as "%s".', 'one-click-duplicate' ),
					esc_html( $title )
				);
				if ( $edit ) {
					echo ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit the copy', 'one-click-duplicate' ) . '</a>';
				}
				if ( $view ) {
					echo ' | <a href="' . esc_url( $view ) . '">' . esc_html__( 'View the copy', 'one-click-duplicate' ) . '</a>';
				}
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['ocd_duplicated'] ) ) {
			$count = absint( $_GET['ocd_duplicated'] );
			if ( $count > 0 && ocd_user_can_duplicate() ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %d: number of duplicated items */
					esc_html( _n( '%d item duplicated.', '%d items duplicated.', $count, 'one-click-duplicate' ) ),
					esc_html( number_format_i18n( $count ) )
				);
				echo '</p></div>';
			}
		}
	}
}
