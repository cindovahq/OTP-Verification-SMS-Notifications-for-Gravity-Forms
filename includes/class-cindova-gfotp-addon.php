<?php
/**
 * Phone OTP add-on: Firebase and App Check settings (Forms > Settings > Phone OTP)
 * and the per-form OTP settings tab.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gravity Forms add-on for phone OTP verification.
 */
class Cindova_GFOTP_Addon extends GFAddOn {

	use Cindova_GFOTP_Addon_Common;

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = CINDOVA_GFOTP_VERSION; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Property name defined by the Gravity Forms framework.

	/**
	 * Minimum Gravity Forms version.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Add-on slug.
	 *
	 * @var string
	 */
	protected $_slug = CINDOVA_GFOTP_SLUG; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Plugin path relative to the plugins folder (set in the constructor).
	 *
	 * @var string
	 */
	protected $_path = ''; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Full path of the main plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = CINDOVA_GFOTP_FILE; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Add-on title.
	 *
	 * @var string
	 */
	protected $_title = 'Phone OTP Verification'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Short title used in menus.
	 *
	 * @var string
	 */
	protected $_short_title = 'Phone OTP'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Capability required for the plugin settings page.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_edit_settings'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Capability required for the form settings tab.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_edit_forms'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Capability required to uninstall the add-on.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_uninstall'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Singleton instance.
	 *
	 * @var Cindova_GFOTP_Addon|null
	 */
	private static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Matches the Gravity Forms add-on convention.

	/**
	 * Get the singleton instance.
	 *
	 * @return Cindova_GFOTP_Addon
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Set the plugin path, then let the framework bootstrap.
	 */
	public function __construct() {
		$this->_path = plugin_basename( CINDOVA_GFOTP_FILE );
		parent::__construct();
	}

	/**
	 * Register the OTP runtime hooks. They live in init() (not init_frontend()) so that
	 * submissions through the REST API and AJAX are verified too.
	 *
	 * @return void
	 */
	public function init() {
		// Translate the titles here: property defaults cannot call __() and the constructor runs before init.
		$this->_title       = __( 'Phone OTP Verification', 'otp-verification-sms-for-gravity-forms' );
		$this->_short_title = __( 'Phone OTP', 'otp-verification-sms-for-gravity-forms' );

		parent::init();

		add_filter( 'gform_form_tag', 'cindova_gfotp_form_tag', 10, 2 );
		add_filter( 'gform_field_validation', 'cindova_gfotp_validate_otp_field', 10, 4 );
		add_filter( 'gform_validation', 'cindova_gfotp_validate_final_submission', 20 );
		add_action( 'gform_entry_created', 'cindova_gfotp_mark_token_used', 10, 2 );
		add_action( 'gform_enqueue_scripts', 'cindova_gfotp_enqueue_frontend', 10, 2 );
	}

	/**
	 * Icon of the settings tabs.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return 'gform-icon--phone';
	}

	/**
	 * The Firebase config is stored as already normalized JSON.
	 *
	 * @return string[]
	 */
	protected function passthrough_setting_names() {
		return array( 'firebase_config' );
	}

	// # PLUGIN SETTINGS -----------------------------------------------------------------------------------------------

	/**
	 * Plugin settings page title.
	 *
	 * @return string
	 */
	public function plugin_settings_title() {
		return esc_html__( 'Phone OTP Verification', 'otp-verification-sms-for-gravity-forms' );
	}

	/**
	 * Plugin settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'id'     => 'firebase',
				'title'  => esc_html__( 'Firebase', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name'                => 'firebase_config',
						'type'                => 'cindova_json',
						'label'               => esc_html__( 'Firebase web config (JSON)', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'Copy the config object from Firebase console, Project settings, Your apps. Keys must be double-quoted JSON strings; apiKey, authDomain and projectId are required. If empty, OTP verification stays disabled.', 'otp-verification-sms-for-gravity-forms' ),
						'validation_callback' => array( $this, 'validate_firebase_config' ),
						'save_callback'       => array( $this, 'save_firebase_config' ),
					),
					array(
						'name' => 'firebase_help',
						'type' => 'html',
						'html' => $this->get_firebase_help_html(),
					),
				),
			),
			array(
				'id'     => 'app-check',
				'title'  => esc_html__( 'App Check (optional)', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name'         => 'app_check_site_key',
						'type'         => 'text',
						'label'        => esc_html__( 'reCAPTCHA site key', 'otp-verification-sms-for-gravity-forms' ),
						'autocomplete' => 'off',
						'description'  => '<strong>' . esc_html__( 'WARNING:', 'otp-verification-sms-for-gravity-forms' ) . '</strong> ' . esc_html__( "Leave blank to disable. App Check enforcement for Firebase Authentication is a Preview feature. If you enable enforcement in the Firebase console and this key is wrong or missing, every OTP request will be rejected. Register the key under Firebase console, App Check first, and test with enforcement in 'monitor' mode.", 'otp-verification-sms-for-gravity-forms' ),
					),
					array(
						'name'          => 'app_check_provider',
						'type'          => 'radio',
						'label'         => esc_html__( 'Provider', 'otp-verification-sms-for-gravity-forms' ),
						'default_value' => 'v3',
						'horizontal'    => true,
						'choices'       => array(
							array(
								'label' => esc_html__( 'reCAPTCHA v3', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'v3',
							),
							array(
								'label' => esc_html__( 'reCAPTCHA Enterprise', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'enterprise',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Firebase setup checklist shown on the settings page.
	 *
	 * @return string
	 */
	private function get_firebase_help_html() {
		$items = array(
			esc_html__( 'Firebase phone authentication requires the Blaze (pay-as-you-go) plan.', 'otp-verification-sms-for-gravity-forms' ),
			esc_html__( 'Enable the Phone provider under Authentication, Sign-in method.', 'otp-verification-sms-for-gravity-forms' ),
			esc_html__( 'Add your site domain under Authentication, Settings, Authorized domains.', 'otp-verification-sms-for-gravity-forms' ),
			esc_html__( 'Configure the SMS region policy under Authentication, Settings, SMS region policy.', 'otp-verification-sms-for-gravity-forms' ),
			esc_html__( 'Copy the web app config object from Project settings, Your apps, and paste it above.', 'otp-verification-sms-for-gravity-forms' ),
			esc_html__( 'Gravity Forms stores plugin settings as plain text in the database. To encrypt them at rest, define the GF_ENCRYPTION_KEY constant in wp-config.php (see the Gravity Forms documentation).', 'otp-verification-sms-for-gravity-forms' ),
		);
		return '<div class="gform-settings-description"><strong>' . esc_html__( 'Firebase setup checklist', 'otp-verification-sms-for-gravity-forms' ) . '</strong><ul class="ul-disc"><li>' . implode( '</li><li>', $items ) . '</li></ul></div>';
	}

	/**
	 * Render the Firebase config textarea (callback of the "cindova_json" field type).
	 *
	 * Gravity Forms decodes posted text that is valid JSON into an array, so the value is
	 * re-encoded here for display; the stored value is a normalized JSON string.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param bool                                              $should_echo Whether to output the markup.
	 * @return string
	 */
	public function settings_cindova_json( $field, $should_echo = true ) {
		$value = $field->get_value();
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : $value;
		}
		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		$html  = $field->get_description();
		$html .= sprintf(
			'<span class="%1$s"><textarea name="%2$s" id="%3$s" rows="10" class="large code" spellcheck="false">%4$s</textarea>%5$s</span>',
			esc_attr( $field->get_container_classes() ),
			esc_attr( $field->settings->get_input_name_prefix() . '_' . $field->name ),
			esc_attr( $field->name ),
			esc_textarea( is_string( $value ) ? $value : '' ),
			$field->get_error_icon()
		);

		if ( $should_echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above and Gravity Forms markup.
		}
		return $html;
	}

	/**
	 * Validate the posted Firebase config.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param string|array                                      $value Posted value.
	 * @return void
	 */
	public function validate_firebase_config( $field, $value ) {
		if ( ( is_string( $value ) && '' === trim( $value ) ) || null === $value ) {
			return; // Empty disables OTP verification.
		}
		if ( false === cindova_gfotp_sanitize_firebase_config( $value ) ) {
			$field->set_error( esc_html__( 'Invalid Firebase config. Paste a JSON object with double-quoted keys and string values that includes apiKey, authDomain and projectId.', 'otp-verification-sms-for-gravity-forms' ) );
		}
	}

	/**
	 * Store the normalized Firebase config.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param string|array                                      $value Posted value.
	 * @return string
	 */
	public function save_firebase_config( $field, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature defined by the framework.
		$normalized = cindova_gfotp_sanitize_firebase_config( $value );
		return false === $normalized ? '' : $normalized;
	}

	// # FORM SETTINGS -------------------------------------------------------------------------------------------------

	/**
	 * Per-form settings fields.
	 *
	 * @param array $form Form array.
	 * @return array
	 */
	public function form_settings_fields( $form ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature defined by the framework.
		$live_on_enabled = array(
			'live'   => true,
			'fields' => array(
				array(
					'field' => 'enabled',
				),
			),
		);

		return array(
			array(
				'title'       => esc_html__( 'Phone OTP Verification', 'otp-verification-sms-for-gravity-forms' ),
				'description' => esc_html__( 'Visitors must verify their phone number with a one-time code sent by SMS (through Firebase) before the form can be submitted. Use a Phone field with the International format, and add a Single Line Text field for the code. SMS notifications are configured under "SMS & Slack Notifications" in the form settings.', 'otp-verification-sms-for-gravity-forms' ),
				'fields'      => array(
					array(
						'name'         => 'enabled',
						'type'         => 'toggle',
						'label'        => esc_html__( 'Enable phone verification', 'otp-verification-sms-for-gravity-forms' ),
						'toggle_label' => esc_html__( 'Enable phone verification', 'otp-verification-sms-for-gravity-forms' ),
					),
					array(
						'name'                => 'phone_field_id',
						'type'                => 'field_select',
						'label'               => esc_html__( 'Phone field', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'The phone field should use the International format so that the number includes the country code.', 'otp-verification-sms-for-gravity-forms' ),
						'auto_mapping'        => false,
						'args'                => array( 'input_types' => array( 'phone', 'text' ) ),
						'dependency'          => $live_on_enabled,
						'validation_callback' => array( $this, 'validate_phone_field_setting' ),
					),
					array(
						'name'                => 'otp_field_id',
						'type'                => 'field_select',
						'label'               => esc_html__( 'OTP code field', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'A Single Line Text field that holds the verification code entered by the visitor.', 'otp-verification-sms-for-gravity-forms' ),
						'auto_mapping'        => false,
						'args'                => array( 'input_types' => array( 'text' ) ),
						'dependency'          => $live_on_enabled,
						'validation_callback' => array( $this, 'validate_otp_field_setting' ),
					),
				),
			),
		);
	}

	/**
	 * Validate the phone field selection.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param mixed                                             $value Posted value.
	 * @return void
	 */
	public function validate_phone_field_setting( $field, $value ) {
		if ( rgblank( $value ) ) {
			$field->set_error( esc_html__( 'Select the phone field.', 'otp-verification-sms-for-gravity-forms' ) );
			return;
		}
		$field->do_validation( $value );
	}

	/**
	 * Validate the OTP field selection (required and different from the phone field).
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param mixed                                             $value Posted value.
	 * @return void
	 */
	public function validate_otp_field_setting( $field, $value ) {
		if ( rgblank( $value ) ) {
			$field->set_error( esc_html__( 'Select the OTP code field.', 'otp-verification-sms-for-gravity-forms' ) );
			return;
		}
		$posted = $field->settings->get_posted_values();
		if ( (string) rgar( $posted, 'phone_field_id' ) === (string) $value ) {
			$field->set_error( esc_html__( 'The OTP code field must be different from the phone field.', 'otp-verification-sms-for-gravity-forms' ) );
			return;
		}
		$field->do_validation( $value );
	}
}
