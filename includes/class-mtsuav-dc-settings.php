<?php
/**
 * Settings page for MTSUAV Duplicate Content.
 *
 * Registers a single option array (mtsuav_dc_options) via the Settings API
 * and renders the page under Settings > Duplicate Content.
 *
 * @package MTSUAV_Duplicate_Content
 */

defined( 'ABSPATH' ) || exit;

class MTSUAV_DC_Settings {

	const PAGE_SLUG   = 'mtsuav-duplicate-content';
	const GROUP       = 'mtsuav_dc_settings_group';
	const SECTION_MAIN = 'mtsuav_dc_section_main';

	/**
	 * Wire up hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Duplicate Content', 'mtsuav-duplicate-content' ),
			__( 'Duplicate Content', 'mtsuav-duplicate-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option, sections, and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting( self::GROUP, MTSUAV_DC_OPTION, array( __CLASS__, 'sanitize' ) );

		add_settings_section(
			self::SECTION_MAIN,
			__( 'Duplication settings', 'mtsuav-duplicate-content' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$fields = array(
			'post_types'      => __( 'Content types', 'mtsuav-duplicate-content' ),
			'status'          => __( 'New item status', 'mtsuav-duplicate-content' ),
			'title_affixes'   => __( 'Title prefix and suffix', 'mtsuav-duplicate-content' ),
			'copy_taxonomies' => __( 'Copy taxonomies', 'mtsuav-duplicate-content' ),
			'copy_meta'       => __( 'Copy custom fields', 'mtsuav-duplicate-content' ),
			'meta_exclude'    => __( 'Excluded custom field keys', 'mtsuav-duplicate-content' ),
			'copy_thumbnail'  => __( 'Copy featured image', 'mtsuav-duplicate-content' ),
			'copy_comments'   => __( 'Copy comments', 'mtsuav-duplicate-content' ),
			'redirect'        => __( 'After duplicating', 'mtsuav-duplicate-content' ),
			'capability'      => __( 'Minimum capability', 'mtsuav-duplicate-content' ),
		);

		foreach ( $fields as $id => $title ) {
			add_settings_field(
				'mtsuav_dc_' . $id,
				$title,
				array( __CLASS__, 'field_' . $id ),
				self::PAGE_SLUG,
				self::SECTION_MAIN
			);
		}
	}

	/**
	 * Sanitize the option array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = mtsuav_dc_defaults();
		$clean    = $defaults;

		// Post types: keep only known public post types.
		$known = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$types = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$types = array_map( 'sanitize_key', $types );
		$clean['post_types'] = array_values( array_intersect( $types, $known ) );

		// Status.
		$statuses = array( 'draft', 'pending', 'publish', 'same' );
		$clean['status'] = isset( $input['status'] ) && in_array( $input['status'], $statuses, true )
			? $input['status']
			: $defaults['status'];

		// Title affixes.
		$clean['title_prefix'] = isset( $input['title_prefix'] ) ? sanitize_text_field( wp_unslash( $input['title_prefix'] ) ) : '';
		$clean['title_suffix'] = isset( $input['title_suffix'] ) ? sanitize_text_field( wp_unslash( $input['title_suffix'] ) ) : '';

		// Toggles.
		foreach ( array( 'copy_taxonomies', 'copy_meta', 'copy_thumbnail', 'copy_comments' ) as $toggle ) {
			$clean[ $toggle ] = ! empty( $input[ $toggle ] ) ? 1 : 0;
		}

		// Meta exclusion list: one key per line.
		$raw_lines = isset( $input['meta_exclude'] ) ? sanitize_textarea_field( wp_unslash( $input['meta_exclude'] ) ) : '';
		$lines     = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw_lines ) ) );
		$lines     = array_map( 'sanitize_key', $lines );
		$clean['meta_exclude'] = implode( "\n", array_unique( $lines ) );

		// Redirect.
		$redirects = array( 'edit', 'list', 'stay' );
		$clean['redirect'] = isset( $input['redirect'] ) && in_array( $input['redirect'], $redirects, true )
			? $input['redirect']
			: $defaults['redirect'];

		// Capability.
		$caps = array( 'edit_posts', 'publish_posts', 'manage_options' );
		$clean['capability'] = isset( $input['capability'] ) && in_array( $input['capability'], $caps, true )
			? $input['capability']
			: $defaults['capability'];

		return $clean;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mtsuav-duplicate-content' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Duplicate Content', 'mtsuav-duplicate-content' ); ?></h1>
			<?php
			if ( function_exists( 'mtsuav_tip_box' ) ) {
				mtsuav_tip_box( 'mtsuav-duplicate-content', 'MTSUAV Duplicate Content' );
			}
			?>
			<p><?php esc_html_e( 'Choose what gets copied when you duplicate an item, and who is allowed to do it.', 'mtsuav-duplicate-content' ); ?></p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field name helper.
	 *
	 * @param string $key Option key.
	 * @return string
	 */
	protected static function name( $key ) {
		return MTSUAV_DC_OPTION . '[' . $key . ']';
	}

	/**
	 * Post types multiselect (checkbox list).
	 *
	 * @return void
	 */
	public static function field_post_types() {
		$options = mtsuav_dc_get_options();
		$types   = get_post_types( array( 'public' => true ), 'objects' );
		if ( isset( $types['attachment'] ) ) {
			unset( $types['attachment'] );
		}
		foreach ( $types as $type ) {
			$checked = in_array( $type->name, $options['post_types'], true ) ? 'checked' : '';
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $type->name ),
				esc_attr( $checked ),
				esc_html( $type->labels->singular_name ),
				esc_html( $type->name )
			);
		}
		echo '<p class="description">' . esc_html__( 'Duplication triggers appear only for the selected types.', 'mtsuav-duplicate-content' ) . '</p>';
	}

	/**
	 * New post status select.
	 *
	 * @return void
	 */
	public static function field_status() {
		$options = mtsuav_dc_get_options();
		$choices = array(
			'draft'   => __( 'Draft', 'mtsuav-duplicate-content' ),
			'pending' => __( 'Pending review', 'mtsuav-duplicate-content' ),
			'publish' => __( 'Published', 'mtsuav-duplicate-content' ),
			'same'    => __( 'Same as original', 'mtsuav-duplicate-content' ),
		);
		echo '<select name="' . esc_attr( self::name( 'status' ) ) . '">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $options['status'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Status assigned to the new copy. Draft is the safe default.', 'mtsuav-duplicate-content' ) . '</p>';
	}

	/**
	 * Title prefix and suffix inputs.
	 *
	 * @return void
	 */
	public static function field_title_affixes() {
		$options = mtsuav_dc_get_options();
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" /> ',
			esc_attr( self::name( 'title_prefix' ) ),
			esc_attr( $options['title_prefix'] ),
			esc_attr__( 'Prefix', 'mtsuav-duplicate-content' )
		);
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( self::name( 'title_suffix' ) ),
			esc_attr( $options['title_suffix'] ),
			esc_attr__( 'Suffix', 'mtsuav-duplicate-content' )
		);
		echo '<p class="description">' . esc_html__( 'Added around the original title, for example "My Post (Copy)".', 'mtsuav-duplicate-content' ) . '</p>';
	}

	/**
	 * Copy taxonomies checkbox.
	 *
	 * @return void
	 */
	public static function field_copy_taxonomies() {
		$options = mtsuav_dc_get_options();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::name( 'copy_taxonomies' ) ),
			checked( $options['copy_taxonomies'], 1, false ),
			esc_html__( 'Copy categories, tags, and custom taxonomy terms to the new item.', 'mtsuav-duplicate-content' )
		);
	}

	/**
	 * Copy post meta checkbox.
	 *
	 * @return void
	 */
	public static function field_copy_meta() {
		$options = mtsuav_dc_get_options();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::name( 'copy_meta' ) ),
			checked( $options['copy_meta'], 1, false ),
			esc_html__( 'Copy custom fields (post meta) to the new item.', 'mtsuav-duplicate-content' )
		);
	}

	/**
	 * Meta exclusion textarea.
	 *
	 * @return void
	 */
	public static function field_meta_exclude() {
		$options = mtsuav_dc_get_options();
		printf(
			'<textarea name="%s" rows="5" cols="40" class="large-text code">%s</textarea>',
			esc_attr( self::name( 'meta_exclude' ) ),
			esc_textarea( $options['meta_exclude'] )
		);
		echo '<p class="description">' . esc_html__( 'One meta key per line. These keys are never copied, even when custom field copying is on.', 'mtsuav-duplicate-content' ) . '</p>';
	}

	/**
	 * Copy featured image checkbox.
	 *
	 * @return void
	 */
	public static function field_copy_thumbnail() {
		$options = mtsuav_dc_get_options();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::name( 'copy_thumbnail' ) ),
			checked( $options['copy_thumbnail'], 1, false ),
			esc_html__( 'Duplicate the featured image as a new, independent attachment on the copy.', 'mtsuav-duplicate-content' )
		);
	}

	/**
	 * Copy comments checkbox.
	 *
	 * @return void
	 */
	public static function field_copy_comments() {
		$options = mtsuav_dc_get_options();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::name( 'copy_comments' ) ),
			checked( $options['copy_comments'], 1, false ),
			esc_html__( 'Copy approved and pending comments to the new item, keeping reply threads intact.', 'mtsuav-duplicate-content' )
		);
	}

	/**
	 * After-duplicate redirect radios.
	 *
	 * @return void
	 */
	public static function field_redirect() {
		$options = mtsuav_dc_get_options();
		$choices = array(
			'edit' => __( 'Open the new item for editing', 'mtsuav-duplicate-content' ),
			'list' => __( 'Go back to the content list', 'mtsuav-duplicate-content' ),
			'stay' => __( 'Stay on the current page', 'mtsuav-duplicate-content' ),
		);
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%s" value="%s" %s /> %s</label>',
				esc_attr( self::name( 'redirect' ) ),
				esc_attr( $value ),
				checked( $options['redirect'], $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Minimum capability select.
	 *
	 * @return void
	 */
	public static function field_capability() {
		$options = mtsuav_dc_get_options();
		$choices = array(
			'edit_posts'     => __( 'Contributor and up (edit_posts)', 'mtsuav-duplicate-content' ),
			'publish_posts'  => __( 'Author and up (publish_posts)', 'mtsuav-duplicate-content' ),
			'manage_options' => __( 'Administrator only (manage_options)', 'mtsuav-duplicate-content' ),
		);
		echo '<select name="' . esc_attr( self::name( 'capability' ) ) . '">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $options['capability'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Enforced on every duplication trigger.', 'mtsuav-duplicate-content' ) . '</p>';
	}
}
