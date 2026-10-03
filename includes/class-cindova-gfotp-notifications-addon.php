<?php
/**
 * SMS & Slack notifications add-on: provider settings (Forms > Settings > SMS & Slack)
 * and per-form notification feeds.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gravity Forms feed add-on that sends SMS (MSG91 or Twilio) and Slack notifications.
 */
class Cindova_GFOTP_Notifications_Addon extends GFFeedAddOn {

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
	protected $_slug = CINDOVA_GFOTP_NOTIFY_SLUG; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

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
	protected $_title = 'SMS & Slack Notifications'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Short title used in menus.
	 *
	 * @var string
	 */
	protected $_short_title = 'SMS & Slack'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Capability required for the plugin settings page.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_edit_settings'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Gravity Forms framework property.

	/**
	 * Capability required for the feed pages.
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
	 * @var Cindova_GFOTP_Notifications_Addon|null
	 */
	private static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Matches the Gravity Forms add-on convention.

	/**
	 * Get the singleton instance.
	 *
	 * @return Cindova_GFOTP_Notifications_Addon
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
	 * Translate the titles and register the "Send test" AJAX handler.
	 *
	 * @return void
	 */
	public function init() {
		// Translate the titles here: property defaults cannot call __() and the constructor runs before init.
		$this->_title       = __( 'SMS & Slack Notifications', 'otp-verification-sms-for-gravity-forms' );
		$this->_short_title = __( 'SMS & Slack', 'otp-verification-sms-for-gravity-forms' );

		parent::init();

		add_action( 'wp_ajax_cindova_gfotp_send_test', array( $this, 'ajax_send_test' ) );
	}

	/**
	 * Gravity Forms' own Uninstall screen: delete the feeds (framework) and the channel cache.
	 * The one-time migration markers are kept so that old 2.x data is not imported a second time.
	 *
	 * @return bool
	 */
	public function uninstall() {
		parent::uninstall();
		delete_option( 'cindova_gfotp_slack_channels' );
		return true;
	}

	/**
	 * Icon of the settings tabs.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return 'gform-icon--mail';
	}

	/**
	 * Settings that hold secrets.
	 *
	 * @return string[]
	 */
	protected function secret_setting_names() {
		return array( 'msg91_authkey', 'twilio_token', 'slack_bot_token' );
	}

	/**
	 * One-off action settings that are not stored.
	 *
	 * @return string[]
	 */
	protected function ephemeral_setting_names() {
		return array( 'slack_refresh_channels' );
	}

	// # SCRIPTS -------------------------------------------------------------------------------------------------------

	/**
	 * Enqueue the "Send test" script on the feed edit page of this add-on only.
	 *
	 * @return array
	 */
	public function scripts() {
		$strings = array();
		if ( $this->is_feed_edit_page() ) {
			$strings = array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'cindova_gfotp_send_test' ),
				'formId'        => absint( rgget( 'id' ) ),
				'sending'       => __( 'Sending…', 'otp-verification-sms-for-gravity-forms' ),
				'noNumber'      => __( 'Enter a phone number to send the test SMS to.', 'otp-verification-sms-for-gravity-forms' ),
				'requestFailed' => __( 'The test request failed. Reload the page and try again.', 'otp-verification-sms-for-gravity-forms' ),
				'noResponse'    => __( 'No response text was returned.', 'otp-verification-sms-for-gravity-forms' ),
			);
		}

		$mine = array(
			array(
				'handle'    => 'cindova_gfotp_admin_feed',
				'src'       => CINDOVA_GFOTP_URL . 'assets/js/admin-feed.js',
				'version'   => CINDOVA_GFOTP_VERSION,
				'deps'      => array( 'jquery' ),
				'in_footer' => true,
				'strings'   => $strings,
				'enqueue'   => array( array( $this, 'is_feed_edit_page' ) ),
			),
		);

		return array_merge( parent::scripts(), $mine );
	}

	// # PLUGIN SETTINGS -----------------------------------------------------------------------------------------------

	/**
	 * Plugin settings page title.
	 *
	 * @return string
	 */
	public function plugin_settings_title() {
		return esc_html__( 'SMS & Slack Notifications', 'otp-verification-sms-for-gravity-forms' );
	}

	/**
	 * Build a live dependency on one setting.
	 *
	 * @param string $field  Setting name.
	 * @param string $values Value that makes the dependency met.
	 * @return array
	 */
	private function live_dependency( $field, $values ) {
		return array(
			'live'   => true,
			'fields' => array(
				array(
					'field'  => $field,
					'values' => array( $values ),
				),
			),
		);
	}

	/**
	 * Plugin settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'id'          => 'provider',
				'title'       => esc_html__( 'SMS provider', 'otp-verification-sms-for-gravity-forms' ),
				'description' => esc_html__( 'Choose the provider used by all SMS notification feeds. Slack is configured below. Gravity Forms stores these settings as plain text in the database; define the GF_ENCRYPTION_KEY constant in wp-config.php to encrypt them at rest (see the Gravity Forms documentation).', 'otp-verification-sms-for-gravity-forms' ),
				'fields'      => array(
					array(
						'name'          => 'sms_provider',
						'type'          => 'radio',
						'label'         => esc_html__( 'SMS provider', 'otp-verification-sms-for-gravity-forms' ),
						'default_value' => 'msg91',
						'horizontal'    => true,
						'choices'       => array(
							array(
								'label' => esc_html__( 'MSG91', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'msg91',
							),
							array(
								'label' => esc_html__( 'Twilio', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'twilio',
							),
						),
					),
				),
			),
			array(
				'id'         => 'msg91',
				'title'      => esc_html__( 'MSG91', 'otp-verification-sms-for-gravity-forms' ),
				'dependency' => $this->live_dependency( 'sms_provider', 'msg91' ),
				'fields'     => array(
					array(
						'name'          => 'msg91_api',
						'type'          => 'radio',
						'label'         => esc_html__( 'MSG91 API', 'otp-verification-sms-for-gravity-forms' ),
						'default_value' => cindova_gfotp_get_msg91_api_mode(),
						'choices'       => array(
							array(
								'label' => esc_html__( 'Flow API v5 (recommended)', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'flow',
							),
							array(
								'label' => esc_html__( 'Legacy sendhttp (free-text message + DLT template ID)', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'legacy',
							),
						),
					),
					array(
						'name'  => 'msg91_authkey',
						'type'  => 'cindova_secret',
						'label' => esc_html__( 'MSG91 Auth Key', 'otp-verification-sms-for-gravity-forms' ),
					),
					array(
						'name'        => 'msg91_senderid',
						'type'        => 'text',
						'label'       => esc_html__( 'MSG91 Sender ID', 'otp-verification-sms-for-gravity-forms' ),
						'description' => esc_html__( 'Legacy sendhttp API only. Not used by the Flow API.', 'otp-verification-sms-for-gravity-forms' ),
						'dependency'  => $this->live_dependency( 'msg91_api', 'legacy' ),
					),
					array(
						'name'          => 'msg91_route',
						'type'          => 'text',
						'label'         => esc_html__( 'MSG91 Route', 'otp-verification-sms-for-gravity-forms' ),
						'default_value' => '4',
						'description'   => esc_html__( 'Legacy sendhttp API only. Default is 4.', 'otp-verification-sms-for-gravity-forms' ),
						'dependency'    => $this->live_dependency( 'msg91_api', 'legacy' ),
					),
				),
			),
			array(
				'id'         => 'twilio',
				'title'      => esc_html__( 'Twilio', 'otp-verification-sms-for-gravity-forms' ),
				'dependency' => $this->live_dependency( 'sms_provider', 'twilio' ),
				'fields'     => array(
					array(
						'name'        => 'twilio_phone',
						'type'        => 'text',
						'label'       => esc_html__( 'Twilio From Phone Number', 'otp-verification-sms-for-gravity-forms' ),
						'description' => esc_html__( 'For example +1234567890.', 'otp-verification-sms-for-gravity-forms' ),
					),
					array(
						'name'  => 'twilio_sid',
						'type'  => 'text',
						'label' => esc_html__( 'Twilio Account SID', 'otp-verification-sms-for-gravity-forms' ),
					),
					array(
						'name'  => 'twilio_token',
						'type'  => 'cindova_secret',
						'label' => esc_html__( 'Twilio Auth Token', 'otp-verification-sms-for-gravity-forms' ),
					),
				),
			),
			array(
				'id'     => 'slack',
				'title'  => esc_html__( 'Slack', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name'                => 'slack_bot_token',
						'type'                => 'cindova_secret',
						'label'               => esc_html__( 'Slack Bot User OAuth Token', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'Requires the channels:read, groups:read and chat:write scopes. Saving a new token loads the channel list.', 'otp-verification-sms-for-gravity-forms' ),
						'validation_callback' => array( $this, 'validate_slack_token' ),
					),
					array(
						'name'        => 'slack_channel',
						'type'        => 'select',
						'label'       => esc_html__( 'Default channel', 'otp-verification-sms-for-gravity-forms' ),
						'description' => esc_html__( 'Used by Slack feeds that do not choose a channel. Make sure your bot is a member of the channel.', 'otp-verification-sms-for-gravity-forms' ),
						'choices'     => function () {
							return $this->get_slack_channel_choices( esc_html__( 'Select a channel', 'otp-verification-sms-for-gravity-forms' ) );
						},
					),
					array(
						'name'         => 'slack_refresh_channels',
						'type'         => 'toggle',
						'label'        => esc_html__( 'Refresh the channel list when saving', 'otp-verification-sms-for-gravity-forms' ),
						'toggle_label' => esc_html__( 'Refresh the channel list when saving', 'otp-verification-sms-for-gravity-forms' ),
					),
				),
			),
		);
	}

	/**
	 * Cached Slack channels as select choices.
	 *
	 * @param string $first_label Label of the leading empty choice.
	 * @return array
	 */
	public function get_slack_channel_choices( $first_label ) {
		$choices  = array(
			array(
				'label' => $first_label,
				'value' => '',
			),
		);
		$channels = get_option( 'cindova_gfotp_slack_channels', array() );
		foreach ( is_array( $channels ) ? $channels : array() as $channel ) {
			if ( empty( $channel['id'] ) ) {
				continue;
			}
			$choices[] = array(
				'label' => isset( $channel['name'] ) ? (string) $channel['name'] : (string) $channel['id'],
				'value' => (string) $channel['id'],
			);
		}
		return $choices;
	}

	/**
	 * Name of a cached Slack channel (falls back to the ID).
	 *
	 * @param string $channel_id Channel ID.
	 * @return string
	 */
	private function get_slack_channel_name( $channel_id ) {
		$channels = get_option( 'cindova_gfotp_slack_channels', array() );
		foreach ( is_array( $channels ) ? $channels : array() as $channel ) {
			if ( isset( $channel['id'], $channel['name'] ) && (string) $channel['id'] === (string) $channel_id ) {
				return '#' . $channel['name'];
			}
		}
		return (string) $channel_id;
	}

	/**
	 * Load the Slack channel list when a new token is saved or a refresh is requested.
	 * A failure is shown as an error on the token field and the settings are not saved.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param mixed                                             $value Posted token (blank keeps the stored one).
	 * @return void
	 */
	public function validate_slack_token( $field, $value ) {
		$posted  = $field->settings->get_posted_values();
		$new     = is_string( $value ) ? sanitize_text_field( $value ) : '';
		$refresh = ! empty( $posted['slack_refresh_channels'] );

		if ( ! empty( $posted['slack_bot_token_remove'] ) ) {
			delete_option( 'cindova_gfotp_slack_channels' );
			return;
		}

		$token = '' !== $new ? $new : (string) $this->get_plugin_setting( 'slack_bot_token' );
		if ( '' === $token || ( '' === $new && ! $refresh ) ) {
			return;
		}

		$channels = cindova_gfotp_slack_fetch_channels( $token );
		if ( is_wp_error( $channels ) ) {
			$field->set_error(
				sprintf(
					/* translators: %s: error returned by Slack, e.g. invalid_auth or missing_scope. */
					esc_html__( 'Slack could not load the channel list: %s', 'otp-verification-sms-for-gravity-forms' ),
					esc_html( $channels->get_error_message() )
				)
			);
			return;
		}
		update_option( 'cindova_gfotp_slack_channels', $channels, false );
	}

	// # FEEDS ---------------------------------------------------------------------------------------------------------

	/**
	 * Feeds are always available.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return true;
	}

	/**
	 * Feed settings fields.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$provider_label = 'twilio' === cindova_gfotp_get_sms_provider() ? 'Twilio' : 'MSG91';
		if ( 'MSG91' === $provider_label ) {
			$provider_hint = 'flow' === cindova_gfotp_get_msg91_api_mode()
				? esc_html__( 'Current provider: MSG91 Flow API. The Template ID and Flow variables are used; the message is ignored.', 'otp-verification-sms-for-gravity-forms' )
				: esc_html__( 'Current provider: MSG91 legacy API. The message is sent; the Template ID is the optional DLT template ID. Flow variables are ignored.', 'otp-verification-sms-for-gravity-forms' );
		} else {
			$provider_hint = esc_html__( 'Current provider: Twilio. The message is sent as the SMS body. Template ID and Flow variables are ignored.', 'otp-verification-sms-for-gravity-forms' );
		}
		$merge_help = esc_html__( 'Use placeholders like {{field_1}} or Gravity Forms merge tags such as {Name:1}, {entry_id} or {all_fields}.', 'otp-verification-sms-for-gravity-forms' );

		return array(
			array(
				'title'  => esc_html__( 'Notification settings', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'type'     => 'text',
						'label'    => esc_html__( 'Name', 'otp-verification-sms-for-gravity-forms' ),
						'required' => true,
						'class'    => 'medium',
					),
					array(
						'name'          => 'notification_type',
						'type'          => 'radio',
						'label'         => esc_html__( 'Notification type', 'otp-verification-sms-for-gravity-forms' ),
						'default_value' => 'sms',
						'horizontal'    => true,
						'choices'       => array(
							array(
								'label' => esc_html__( 'SMS', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'sms',
							),
							array(
								'label' => esc_html__( 'Slack', 'otp-verification-sms-for-gravity-forms' ),
								'value' => 'slack',
							),
						),
					),
				),
			),
			array(
				'id'          => 'sms',
				'title'       => esc_html__( 'SMS', 'otp-verification-sms-for-gravity-forms' ),
				'description' => $provider_hint,
				'dependency'  => $this->live_dependency( 'notification_type', 'sms' ),
				'fields'      => array(
					array(
						'name'         => 'phone_field',
						'type'         => 'field_select',
						'label'        => esc_html__( 'Phone field', 'otp-verification-sms-for-gravity-forms' ),
						'description'  => esc_html__( 'The SMS is sent to the number entered in this field.', 'otp-verification-sms-for-gravity-forms' ),
						'required'     => true,
						'auto_mapping' => false,
						'args'         => array( 'input_types' => array( 'phone', 'text' ) ),
					),
					array(
						'name'                => 'message',
						'type'                => 'textarea',
						'label'               => esc_html__( 'Message', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'MSG91 legacy API and Twilio: the SMS text.', 'otp-verification-sms-for-gravity-forms' ) . ' ' . $merge_help,
						'class'               => 'merge-tag-support mt-position-right',
						'validation_callback' => array( $this, 'validate_message_setting' ),
					),
					array(
						'name'        => 'msg91_template_id',
						'type'        => 'text',
						'label'       => esc_html__( 'Template ID (MSG91)', 'otp-verification-sms-for-gravity-forms' ),
						'description' => esc_html__( 'Flow API v5: the Flow template ID from your MSG91 account (required to send). Legacy API: the optional DLT template ID.', 'otp-verification-sms-for-gravity-forms' ),
						'class'       => 'medium',
					),
					array(
						'name'                => 'msg91_variables',
						'type'                => 'textarea',
						'label'               => esc_html__( 'Flow variables (MSG91)', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => esc_html__( 'Flow API v5 only (ignored by the legacy API). One name=value per line. A template variable ##var1## is sent as var1, for example: var1={{field_1}}. Names are case-sensitive.', 'otp-verification-sms-for-gravity-forms' ) . ' ' . $merge_help,
						'class'               => 'merge-tag-support mt-position-right code',
						'validation_callback' => array( $this, 'validate_message_setting' ),
					),
				),
			),
			array(
				'id'         => 'slack-message',
				'title'      => esc_html__( 'Slack', 'otp-verification-sms-for-gravity-forms' ),
				'dependency' => $this->live_dependency( 'notification_type', 'slack' ),
				'fields'     => array(
					array(
						'name'                => 'slack_message',
						'type'                => 'textarea',
						'label'               => esc_html__( 'Slack message', 'otp-verification-sms-for-gravity-forms' ),
						'description'         => $merge_help,
						'required'            => true,
						'class'               => 'merge-tag-support mt-position-right',
						'validation_callback' => array( $this, 'validate_message_setting' ),
					),
					array(
						'name'        => 'slack_channel',
						'type'        => 'select',
						'label'       => esc_html__( 'Channel', 'otp-verification-sms-for-gravity-forms' ),
						'description' => esc_html__( 'Leave on the default to use the channel chosen under Forms, Settings, SMS & Slack.', 'otp-verification-sms-for-gravity-forms' ),
						'choices'     => function () {
							return $this->get_slack_channel_choices( esc_html__( 'Default channel', 'otp-verification-sms-for-gravity-forms' ) );
						},
					),
				),
			),
			array(
				'title'  => esc_html__( 'Conditional logic', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name'           => 'feedCondition',
						'type'           => 'feed_condition',
						'label'          => esc_html__( 'Condition', 'otp-verification-sms-for-gravity-forms' ),
						'checkbox_label' => esc_html__( 'Enable condition', 'otp-verification-sms-for-gravity-forms' ),
						'instructions'   => esc_html__( 'Send this notification if', 'otp-verification-sms-for-gravity-forms' ),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Send a test', 'otp-verification-sms-for-gravity-forms' ),
				'fields' => array(
					array(
						'name' => 'test_controls',
						'type' => 'html',
						'html' => $this->get_test_controls_html(),
					),
				),
			),
		);
	}

	/**
	 * Markup of the "Send test" control. It posts the values currently entered in the form
	 * (saved or not) to the AJAX handler and prints the provider response as plain text.
	 *
	 * @return string
	 */
	private function get_test_controls_html() {
		return '<div class="cindova-gfotp-test">'
			. '<p class="gform-settings-description">' . esc_html__( 'Sends a test using the values currently entered above; you do not need to save first. The test has no entry, so merge tags such as {Name:1} are not replaced and are sent as written. Slack tests post to the chosen channel (or the default channel).', 'otp-verification-sms-for-gravity-forms' ) . '</p>'
			. '<p class="cindova-gfotp-test-number"><label for="cindova_gfotp_test_number">' . esc_html__( 'Test phone number (SMS)', 'otp-verification-sms-for-gravity-forms' ) . '</label><br />'
			. '<input type="text" id="cindova_gfotp_test_number" class="medium" placeholder="+14155552671" autocomplete="off" /></p>'
			. '<p><button type="button" class="button" id="cindova_gfotp_send_test">' . esc_html__( 'Send test', 'otp-verification-sms-for-gravity-forms' ) . '</button></p>'
			. '<p id="cindova_gfotp_test_result" role="status" aria-live="polite"></p>'
			. '</div>';
	}

	/**
	 * Message textareas accept any text (Slack uses angle brackets for mentions and links);
	 * the value is cleaned in save_feed_settings() and only ever sent to SMS/Slack APIs.
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param mixed                                             $value Posted value.
	 * @return void
	 */
	public function validate_message_setting( $field, $value ) {
		if ( $field->required && rgblank( $value ) ) {
			$field->set_error( esc_html__( 'This field is required.', 'otp-verification-sms-for-gravity-forms' ) );
		}
	}

	/**
	 * Sanitize the feed settings before they are stored. Fields of a section hidden by the
	 * notification type are still posted, so this covers every field, not only visible ones.
	 *
	 * @param int|string $feed_id  Feed ID (0 for a new feed).
	 * @param int        $form_id  Form ID.
	 * @param array      $settings Posted settings.
	 * @return int Feed ID.
	 */
	public function save_feed_settings( $feed_id, $form_id, $settings ) {
		foreach ( array( 'message', 'msg91_variables', 'slack_message' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = cindova_gfotp_sanitize_message( $settings[ $key ] );
			}
		}
		foreach ( array( 'feedName', 'notification_type', 'phone_field', 'msg91_template_id', 'slack_channel' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = sanitize_text_field( $settings[ $key ] );
			}
		}
		return parent::save_feed_settings( $feed_id, $form_id, $settings );
	}

	/**
	 * Feed list columns.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'          => esc_html__( 'Name', 'otp-verification-sms-for-gravity-forms' ),
			'notification_type' => esc_html__( 'Type', 'otp-verification-sms-for-gravity-forms' ),
			'details'           => esc_html__( 'Details', 'otp-verification-sms-for-gravity-forms' ),
		);
	}

	/**
	 * Feed list "Type" column.
	 *
	 * @param array $feed Feed.
	 * @return string
	 */
	public function get_column_value_notification_type( $feed ) {
		return 'slack' === rgars( $feed, 'meta/notification_type' ) ? esc_html__( 'Slack', 'otp-verification-sms-for-gravity-forms' ) : esc_html__( 'SMS', 'otp-verification-sms-for-gravity-forms' );
	}

	/**
	 * Feed list "Details" column: the phone field label (SMS) or the channel (Slack).
	 *
	 * @param array $feed Feed.
	 * @return string
	 */
	public function get_column_value_details( $feed ) {
		$meta = rgar( $feed, 'meta' );
		if ( 'slack' === rgar( $meta, 'notification_type' ) ) {
			$channel = (string) rgar( $meta, 'slack_channel' );
			return '' === $channel ? esc_html__( 'Default channel', 'otp-verification-sms-for-gravity-forms' ) : esc_html( $this->get_slack_channel_name( $channel ) );
		}

		$phone_field_id = (string) rgar( $meta, 'phone_field' );
		if ( '' === $phone_field_id ) {
			return '';
		}
		$form  = GFAPI::get_form( (int) rgar( $feed, 'form_id' ) );
		$field = $form ? GFFormsModel::get_field( $form, $phone_field_id ) : null;
		return esc_html( is_object( $field ) ? GFCommon::get_label( $field, $phone_field_id ) : $phone_field_id );
	}

	// # PROCESSING ----------------------------------------------------------------------------------------------------

	/**
	 * Send the notification of a feed. The framework skips spam entries and feeds whose
	 * condition is not met.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return bool True when sent, false on failure (the framework saves the failed status).
	 */
	public function process_feed( $feed, $entry, $form ) {
		if ( 'slack' === rgars( $feed, 'meta/notification_type' ) ) {
			return $this->process_slack_feed( $feed, $entry, $form );
		}
		return $this->process_sms_feed( $feed, $entry, $form );
	}

	/**
	 * Send the SMS of a feed through the selected provider.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return bool
	 */
	private function process_sms_feed( $feed, $entry, $form ) {
		$meta           = rgar( $feed, 'meta' );
		$phone_field_id = (string) rgar( $meta, 'phone_field' );
		$phone          = '' !== $phone_field_id ? trim( (string) $this->get_field_value( $form, $entry, $phone_field_id ) ) : '';
		if ( '' === $phone ) {
			$this->add_feed_error( esc_html__( 'No SMS was sent because the phone field is empty.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
			return false;
		}

		$message     = (string) rgar( $meta, 'message' );
		$template_id = (string) rgar( $meta, 'msg91_template_id' );
		$sent        = false;

		if ( 'twilio' === cindova_gfotp_get_sms_provider() ) {
			$provider = 'Twilio';
			if ( '' === $message ) {
				$this->add_feed_error( esc_html__( 'No SMS was sent because the message is empty.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
				return false;
			}
			$sent = cindova_gfotp_send_twilio_sms( $phone, $message, false, $entry, $form );
		} elseif ( 'flow' === cindova_gfotp_get_msg91_api_mode() ) {
			$provider = 'MSG91';
			if ( '' === $template_id ) {
				$this->add_feed_error( esc_html__( 'No SMS was sent because the MSG91 Flow template ID is empty.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
				return false;
			}
			$sent = cindova_gfotp_send_msg91_flow_sms( $phone, $template_id, (string) rgar( $meta, 'msg91_variables' ), false, $entry, $form );
		} else {
			$provider = 'MSG91';
			if ( '' === $message ) {
				$this->add_feed_error( esc_html__( 'No SMS was sent because the message is empty.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
				return false;
			}
			$sent = cindova_gfotp_send_msg91_sms( $phone, $message, $template_id, false, $entry, $form );
		}

		if ( ! $sent ) {
			$this->add_feed_error(
				sprintf(
					/* translators: %s: SMS provider name. */
					esc_html__( 'The SMS could not be sent through %s. Check the provider credentials and the Gravity Forms logs.', 'otp-verification-sms-for-gravity-forms' ),
					$provider
				),
				$feed,
				$entry,
				$form
			);
			return false;
		}

		$this->log_debug( __METHOD__ . "(): SMS sent via {$provider} for entry #" . (int) rgar( $entry, 'id' ) . '.' );
		$this->add_note(
			$entry['id'],
			sprintf(
				/* translators: 1: phone number, 2: SMS provider name. */
				esc_html__( 'SMS sent to %1$s via %2$s.', 'otp-verification-sms-for-gravity-forms' ),
				$phone,
				$provider
			),
			'success'
		);
		return true;
	}

	/**
	 * Post the Slack message of a feed.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return bool
	 */
	private function process_slack_feed( $feed, $entry, $form ) {
		$meta    = rgar( $feed, 'meta' );
		$message = (string) rgar( $meta, 'slack_message' );
		$channel = (string) rgar( $meta, 'slack_channel' );
		if ( '' === $channel ) {
			$channel = (string) cindova_gfotp_notify_setting( 'slack_channel', '' );
		}

		if ( '' === $message ) {
			$this->add_feed_error( esc_html__( 'No Slack message was sent because the message is empty.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
			return false;
		}
		if ( '' === $channel ) {
			$this->add_feed_error( esc_html__( 'No Slack message was sent because no channel is selected.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
			return false;
		}

		if ( ! cindova_gfotp_send_slack_notification( $message, $entry, $form, $channel ) ) {
			$this->add_feed_error( esc_html__( 'The Slack message could not be sent. Check the bot token, the channel and the Gravity Forms logs.', 'otp-verification-sms-for-gravity-forms' ), $feed, $entry, $form );
			return false;
		}

		$this->log_debug( __METHOD__ . '(): Slack notification sent for entry #' . (int) rgar( $entry, 'id' ) . '.' );
		$this->add_note(
			$entry['id'],
			sprintf(
				/* translators: %s: Slack channel. */
				esc_html__( 'Slack notification sent to %s.', 'otp-verification-sms-for-gravity-forms' ),
				$this->get_slack_channel_name( $channel )
			),
			'success'
		);
		return true;
	}

	// # TEST MESSAGE --------------------------------------------------------------------------------------------------

	/**
	 * AJAX handler for the "Send test" button on the feed edit page.
	 *
	 * @return void
	 */
	public function ajax_send_test() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cindova_gfotp_send_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'otp-verification-sms-for-gravity-forms' ) ), 403 );
		}
		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'otp-verification-sms-for-gravity-forms' ) ), 403 );
		}

		$text = static function ( $key, $multiline = false ) {
			// The nonce was verified above.
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return '';
			}
			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on the next line.
			return $multiline ? cindova_gfotp_sanitize_message( $value ) : sanitize_text_field( $value );
		};

		$type = 'slack' === $text( 'type' ) ? 'slack' : 'sms';
		$form = GFAPI::get_form( absint( $text( 'form_id' ) ) );

		$result = $this->send_test(
			$type,
			array(
				'phone'       => $text( 'phone' ),
				'message'     => $text( 'message', true ),
				'template_id' => $text( 'template_id' ),
				'variables'   => $text( 'variables', true ),
				'channel'     => $text( 'channel' ),
			),
			is_array( $form ) ? $form : array()
		);

		wp_send_json_success( array( 'message' => $result ) );
	}

	/**
	 * Send a test SMS or Slack message with an empty entry (merge tags stay as written).
	 *
	 * @param string $type Notification type: 'sms' or 'slack'.
	 * @param array  $args phone, message, template_id, variables and channel (unsaved feed values).
	 * @param array  $form Form array (may be empty).
	 * @return string Provider response text.
	 */
	public function send_test( $type, $args, $form = array() ) {
		$message = (string) rgar( $args, 'message' );

		if ( 'slack' === $type ) {
			if ( '' === $message ) {
				return __( 'No Slack message entered.', 'otp-verification-sms-for-gravity-forms' );
			}
			return (string) cindova_gfotp_send_slack_notification( $message, array(), $form, (string) rgar( $args, 'channel' ), true );
		}

		$phone = (string) rgar( $args, 'phone' );
		if ( '' === $phone ) {
			return __( 'No test number provided.', 'otp-verification-sms-for-gravity-forms' );
		}
		$template_id = (string) rgar( $args, 'template_id' );

		if ( 'twilio' === cindova_gfotp_get_sms_provider() ) {
			if ( '' === $message ) {
				return __( 'No Twilio message configured.', 'otp-verification-sms-for-gravity-forms' );
			}
			return (string) cindova_gfotp_send_twilio_sms( $phone, $message, true, array(), $form );
		}
		if ( 'flow' === cindova_gfotp_get_msg91_api_mode() ) {
			if ( '' === $template_id ) {
				return __( 'No MSG91 Flow template ID configured.', 'otp-verification-sms-for-gravity-forms' );
			}
			return (string) cindova_gfotp_send_msg91_flow_sms( $phone, $template_id, (string) rgar( $args, 'variables' ), true, array(), $form );
		}
		if ( '' === $message ) {
			return __( 'No MSG91 message configured.', 'otp-verification-sms-for-gravity-forms' );
		}
		return (string) cindova_gfotp_send_msg91_sms( $phone, $message, $template_id, true, array(), $form );
	}
}
