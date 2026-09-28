<?php
/**
 * Settings → Translation Drift.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Capabilities;
use TranslationDrift\Container;
use TranslationDrift\Providers\ProviderDetector;
use TranslationDrift\Settings;

/**
 * The settings screen, built on the Settings API.
 *
 * @since 0.1.0
 */
class SettingsPage {

	/**
	 * Menu slug.
	 *
	 * @since 0.1.0
	 */
	public const SLUG = 'translation-drift-settings';

	/**
	 * Settings API group.
	 *
	 * @since 0.1.0
	 */
	public const GROUP = 'tdrift_settings_group';

	/**
	 * Settings whose change needs every status recalculated.
	 *
	 * @since 0.1.0
	 */
	private const TRACKING_KEYS = array( 'post_types', 'fields', 'meta_keys', 'acf', 'elementor', 'source_language', 'strict' );

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container Services; the provider may be missing.
	 */
	public function __construct( private Container $container ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_update' ), 10, 2 );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
	}

	/**
	 * Capability needed to save the settings.
	 *
	 * @since 0.1.0
	 */
	public function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Adds the page under Settings.
	 *
	 * @since 0.1.0
	 */
	public function add_page(): void {
		$hook = add_options_page(
			__( 'Translation Drift settings', 'translation-drift' ),
			__( 'Translation Drift', 'translation-drift' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			add_action( 'admin_print_styles-' . $hook, array( Assets::class, 'enqueue_admin_style' ) );
		}
	}

	/**
	 * Registers the option, sections and fields.
	 *
	 * @since 0.1.0
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		$sections = array(
			'tracking'      => __( 'What to track', 'translation-drift' ),
			'workflow'      => __( 'Workflow', 'translation-drift' ),
			'notifications' => __( 'Notifications', 'translation-drift' ),
			'data'          => __( 'Data', 'translation-drift' ),
		);
		foreach ( $sections as $id => $title ) {
			add_settings_section( "tdrift_{$id}", $title, '__return_null', self::SLUG );
		}

		$fields = array(
			'post_types'           => array( 'tracking', __( 'Post types', 'translation-drift' ) ),
			'fields'               => array( 'tracking', __( 'Fields', 'translation-drift' ) ),
			'meta_keys'            => array( 'tracking', __( 'Extra custom fields', 'translation-drift' ) ),
			'integrations'         => array( 'tracking', __( 'Page builders and fields', 'translation-drift' ) ),
			'source_language'      => array( 'tracking', __( 'Source language', 'translation-drift' ) ),
			'strict'               => array( 'tracking', __( 'Strict mode', 'translation-drift' ) ),
			'auto_clear'           => array( 'workflow', __( 'Saving a translation', 'translation-drift' ) ),
			'translators'          => array( 'workflow', __( 'Translators', 'translation-drift' ) ),
			'digest_frequency'     => array( 'notifications', __( 'Email digest', 'translation-drift' ) ),
			'digest_recipients'    => array( 'notifications', __( 'Also send the digest to', 'translation-drift' ) ),
			'immediate_post_types' => array( 'notifications', __( 'Immediate emails', 'translation-drift' ) ),
			'delete_data'          => array( 'data', __( 'Uninstall', 'translation-drift' ) ),
		);
		// Single controls get a real <label> (the row heading) through label_for; groups use fieldsets.
		$label_for = array(
			'meta_keys'         => 'tdrift-meta-keys',
			'digest_frequency'  => 'tdrift-digest-frequency',
			'digest_recipients' => 'tdrift-digest-recipients',
		);
		$provider  = $this->container->provider();
		if ( null === $provider || ProviderDetector::WPML !== $provider->id() ) {
			$label_for['source_language'] = 'tdrift-source-language';
		}
		foreach ( $fields as $key => list( $section, $title ) ) {
			$args = isset( $label_for[ $key ] ) ? array( 'label_for' => $label_for[ $key ] ) : array();
			add_settings_field( "tdrift_{$key}", $title, array( $this, 'field_' . $key ), self::SLUG, "tdrift_{$section}", $args );
		}
	}

	/**
	 * Sanitizes submitted settings. Unknown keys are dropped; invalid values fall back to defaults.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Settings::defaults();
		$types    = array_keys( $this->translated_post_types() );
		$langs    = $this->languages();

		$post_types = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ), $types ) );
		$fields     = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['fields'] ?? array() ) ), Settings::POST_FIELDS ) );

		// WordPress may run this twice on one save (update_option() then add_option()), so accept
		// already-sanitized lists as well as the form's text.
		$meta_keys = array();
		foreach ( (array) preg_split( '/[\r\n,]+/', $this->as_text( $input['meta_keys'] ?? '' ) ) as $key ) {
			$key = trim( sanitize_text_field( (string) $key ) );
			if ( '' !== $key ) {
				$meta_keys[] = $key;
			}
		}

		$source = sanitize_key( (string) ( $input['source_language'] ?? '' ) );

		$recipients = array();
		foreach ( (array) preg_split( '/[\s,;]+/', $this->as_text( $input['digest_recipients'] ?? '' ) ) as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		$translators = array();
		foreach ( (array) ( $input['translators'] ?? array() ) as $lang => $users ) {
			$lang = sanitize_key( (string) $lang );
			if ( in_array( $lang, $langs, true ) ) {
				$translators[ $lang ] = array_values( array_filter( array_map( 'absint', (array) $users ) ) );
			}
		}

		$frequency = sanitize_key( (string) ( $input['digest_frequency'] ?? 'off' ) );

		return array(
			'post_types'           => $post_types,
			'fields'               => array() === $fields ? $defaults['fields'] : $fields,
			'meta_keys'            => array_values( array_unique( $meta_keys ) ),
			'acf'                  => ! empty( $input['acf'] ),
			'elementor'            => ! empty( $input['elementor'] ),
			'source_language'      => in_array( $source, $langs, true ) ? $source : '',
			'strict'               => ! empty( $input['strict'] ),
			'auto_clear'           => ! empty( $input['auto_clear'] ),
			'digest_frequency'     => in_array( $frequency, array( 'off', 'daily', 'weekly' ), true ) ? $frequency : 'off',
			'digest_recipients'    => array_values( array_unique( $recipients ) ),
			'immediate_post_types' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['immediate_post_types'] ?? array() ) ), $types ) ),
			'translators'          => $translators,
			'delete_data'          => ! empty( $input['delete_data'] ),
		);
	}

	/**
	 * Recalculates every status when what's tracked changes.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 */
	public function on_update( $old_value, $new_value ): void {
		$old = array_merge( Settings::defaults(), is_array( $old_value ) ? $old_value : array() );
		$new = array_merge( Settings::defaults(), is_array( $new_value ) ? $new_value : array() );

		foreach ( self::TRACKING_KEYS as $key ) {
			if ( $old[ $key ] !== $new[ $key ] ) {
				if ( $this->container->has_provider() ) {
					$this->container->baseline()->start_recalculation();
				}
				return;
			}
		}
	}

	/**
	 * Prints the page.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'translation-drift' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translation Drift settings', 'translation-drift' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
			<?php $this->render_tools(); ?>
		</div>
		<?php
	}

	/**
	 * The "Build baseline" tool.
	 *
	 * @since 0.1.0
	 */
	private function render_tools(): void {
		if ( ! $this->container->has_provider() ) {
			return;
		}

		$state  = $this->container->baseline()->state();
		$labels = array(
			'none'    => __( 'Not built yet.', 'translation-drift' ),
			'queued'  => __( 'Queued; it runs in the background shortly.', 'translation-drift' ),
			'running' => __( 'Running in the background.', 'translation-drift' ),
			/* translators: %s: number of posts. */
			'done'    => sprintf( _n( 'Built (%s post checked).', 'Built (%s posts checked).', $state['processed'], 'translation-drift' ), number_format_i18n( $state['processed'] ) ),
		);
		?>
		<h2><?php esc_html_e( 'Baseline', 'translation-drift' ); ?></h2>
		<p><?php esc_html_e( 'The baseline treats every translation that has no sync point yet as up to date with its source. It runs in the background, in batches.', 'translation-drift' ); ?></p>
		<p><strong><?php esc_html_e( 'Status:', 'translation-drift' ); ?></strong> <?php echo esc_html( $labels[ $state['status'] ] ?? $state['status'] ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( Actions::BUILD_BASELINE ); ?>">
			<?php wp_nonce_field( Actions::BUILD_BASELINE ); ?>
			<p>
				<label>
					<input type="checkbox" name="tdrift_force" value="1">
					<?php esc_html_e( 'Also mark translations that are currently outdated as up to date', 'translation-drift' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Build baseline', 'translation-drift' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Post types field.
	 *
	 * @since 0.1.0
	 */
	public function field_post_types(): void {
		$chosen = (array) $this->settings()->get( 'post_types' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Post types', 'translation-drift' ) . '</legend>';
		foreach ( $this->translated_post_types() as $type => $label ) {
			$this->checkbox( 'post_types][', $type, $label, array() === $chosen || in_array( $type, $chosen, true ) );
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Only post types your multilingual plugin translates are listed. Clear every box to track all of them.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Tracked fields field.
	 *
	 * @since 0.1.0
	 */
	public function field_fields(): void {
		$chosen = (array) $this->settings()->get( 'fields' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Fields', 'translation-drift' ) . '</legend>';
		foreach ( Settings::POST_FIELDS as $field ) {
			$this->checkbox( 'fields][', $field, StatusView::field_label( $field ), in_array( $field, $chosen, true ) );
		}
		echo '</fieldset>';
	}

	/**
	 * Extra meta keys field.
	 *
	 * @since 0.1.0
	 */
	public function field_meta_keys(): void {
		printf(
			'<textarea id="tdrift-meta-keys" name="%1$s[meta_keys]" rows="3" class="large-text code" aria-describedby="tdrift-meta-keys-help">%2$s</textarea><p class="description" id="tdrift-meta-keys-help">%3$s</p>',
			esc_attr( Settings::OPTION ),
			esc_textarea( implode( "\n", (array) $this->settings()->get( 'meta_keys' ) ) ),
			esc_html__( 'Custom field (post meta) keys to track, one per line.', 'translation-drift' )
		);
	}

	/**
	 * ACF and Elementor toggles.
	 *
	 * @since 0.1.0
	 */
	public function field_integrations(): void {
		$acf       = function_exists( 'acf_get_field_groups' );
		$elementor = defined( 'ELEMENTOR_VERSION' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Page builders and fields', 'translation-drift' ) . '</legend>';
		$this->toggle( 'acf', __( 'Track Advanced Custom Fields fields and ACF blocks', 'translation-drift' ) . ( $acf ? '' : ' ' . __( '(ACF isn’t active)', 'translation-drift' ) ) );
		echo '<br>';
		$this->toggle( 'elementor', __( 'Track Elementor text', 'translation-drift' ) . ( $elementor ? '' : ' ' . __( '(Elementor isn’t active)', 'translation-drift' ) ) );
		echo '</fieldset><p class="description">' . esc_html__( 'ACF fields set to be translated (or copied once) are tracked; other text fields are tracked when no translation setting exists. Layout values and styling are ignored.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Source language field.
	 *
	 * @since 0.1.0
	 */
	public function field_source_language(): void {
		$provider = $this->container->provider();
		if ( null !== $provider && ProviderDetector::WPML === $provider->id() ) {
			echo '<p>' . esc_html__( 'With WPML, each translation is compared with its original post.', 'translation-drift' ) . '</p>';
			return;
		}

		$current = (string) $this->settings()->get( 'source_language' );
		printf( '<select id="tdrift-source-language" name="%s[source_language]">', esc_attr( Settings::OPTION ) );
		printf( '<option value="">%s</option>', esc_html__( 'Default language of the site', 'translation-drift' ) );
		foreach ( $this->languages() as $lang ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $lang ), selected( $current, $lang, false ), esc_html( strtoupper( $lang ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Translations are compared with the post in this language.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Strict mode field.
	 *
	 * @since 0.1.0
	 */
	public function field_strict(): void {
		$this->toggle( 'strict', __( 'Count formatting-only changes (markup, block settings, whitespace in HTML) as changes', 'translation-drift' ) );
	}

	/**
	 * Auto-clear field.
	 *
	 * @since 0.1.0
	 */
	public function field_auto_clear(): void {
		$this->toggle( 'auto_clear', __( 'Mark an outdated translation as up to date when it is saved', 'translation-drift' ) );
		echo '<p class="description">' . esc_html__( 'Off by default: translators mark translations as up to date themselves after reviewing the changes.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Translator assignment per language.
	 *
	 * @since 0.1.0
	 */
	public function field_translators(): void {
		$assigned = (array) $this->settings()->get( 'translators' );
		$users    = get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'display_name' ),
				'orderby'    => 'display_name',
				'number'     => 500,
			)
		);

		foreach ( $this->languages() as $lang ) {
			$id       = 'tdrift-translators-' . $lang;
			$selected = array_map( 'intval', (array) ( $assigned[ $lang ] ?? array() ) );
			printf(
				'<p><label for="%1$s">%2$s</label><br><select id="%1$s" name="%3$s[translators][%4$s][]" multiple size="4" class="regular-text">',
				esc_attr( $id ),
				/* translators: %s: language code, e.g. FR. */
				esc_html( sprintf( __( 'Translators for %s', 'translation-drift' ), strtoupper( $lang ) ) ),
				esc_attr( Settings::OPTION ),
				esc_attr( $lang )
			);
			foreach ( $users as $user ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $user->ID, selected( in_array( (int) $user->ID, $selected, true ), true, false ), esc_html( $user->display_name ) );
			}
			echo '</select></p>';
		}
		echo '<p class="description">' . esc_html__( 'Translators receive the digest for their languages.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Digest frequency field.
	 *
	 * @since 0.1.0
	 */
	public function field_digest_frequency(): void {
		$current = (string) $this->settings()->get( 'digest_frequency' );
		$options = array(
			'off'    => __( 'Off', 'translation-drift' ),
			'daily'  => __( 'Daily', 'translation-drift' ),
			'weekly' => __( 'Weekly', 'translation-drift' ),
		);
		printf( '<select id="tdrift-digest-frequency" name="%s[digest_frequency]">', esc_attr( Settings::OPTION ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Each translator gets a list of outdated translations in their languages.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Extra digest recipients field.
	 *
	 * @since 0.1.0
	 */
	public function field_digest_recipients(): void {
		printf(
			'<textarea id="tdrift-digest-recipients" name="%1$s[digest_recipients]" rows="2" class="large-text" aria-describedby="tdrift-digest-recipients-help">%2$s</textarea><p class="description" id="tdrift-digest-recipients-help">%3$s</p>',
			esc_attr( Settings::OPTION ),
			esc_textarea( implode( "\n", (array) $this->settings()->get( 'digest_recipients' ) ) ),
			esc_html__( 'Email addresses, one per line. They receive every language.', 'translation-drift' )
		);
	}

	/**
	 * High-priority post types field.
	 *
	 * @since 0.1.0
	 */
	public function field_immediate_post_types(): void {
		$chosen = (array) $this->settings()->get( 'immediate_post_types' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Immediate emails', 'translation-drift' ) . '</legend>';
		foreach ( $this->translated_post_types() as $type => $label ) {
			$this->checkbox( 'immediate_post_types][', $type, $label, in_array( $type, $chosen, true ) );
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Email translators as soon as a translation of these post types goes out of date.', 'translation-drift' ) . '</p>';
	}

	/**
	 * Delete-data field.
	 *
	 * @since 0.1.0
	 */
	public function field_delete_data(): void {
		$this->toggle( 'delete_data', __( 'Delete all Translation Drift data when the plugin is deleted', 'translation-drift' ) );
	}

	/**
	 * A textarea value, or a list (already sanitized) joined by newlines.
	 *
	 * @param mixed $value Value.
	 */
	private function as_text( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( "\n", array_filter( $value, 'is_string' ) );
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Settings.
	 */
	private function settings(): Settings {
		return $this->container->settings();
	}

	/**
	 * Language codes of the active provider.
	 *
	 * @return list<string>
	 */
	private function languages(): array {
		return $this->container->provider()?->get_languages() ?? array();
	}

	/**
	 * Public, translated post types except attachments, keyed by name.
	 *
	 * @return array<string, string> Labels keyed by post type.
	 */
	private function translated_post_types(): array {
		$provider = $this->container->provider();
		$types    = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && null !== $provider && $provider->is_translated_post_type( $type->name ) ) {
				$types[ $type->name ] = (string) $type->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Prints a checkbox for a list setting.
	 *
	 * @param string $name    Setting key followed by `][`, for array values.
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 */
	private function checkbox( string $name, string $value, string $label, bool $checked ): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="%3$s"%4$s> %5$s</label><br>',
			esc_attr( Settings::OPTION ),
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Prints a checkbox for a boolean setting.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function toggle( string $key, string $label ): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->settings()->get( $key ), true, false ),
			esc_html( $label )
		);
	}
}
