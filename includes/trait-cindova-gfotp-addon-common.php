<?php
/**
 * Behavior shared by both Gravity Forms add-ons: the masked "cindova_secret" settings field.
 *
 * Gravity Forms has no password field, and its text field writes the stored value into the
 * page and wipes it when saved blank. Secret fields therefore never print the stored value;
 * a blank submission keeps the stored value and an explicit "remove" checkbox clears it.
 *
 * @package CindovaGfOtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shared add-on behavior.
 */
trait Cindova_GFOTP_Addon_Common {

	/**
	 * Names of the plugin settings that hold secrets (rendered by the cindova_secret field).
	 *
	 * @return string[]
	 */
	protected function secret_setting_names() {
		return array();
	}

	/**
	 * Names of posted settings that are one-off actions and must not be stored.
	 *
	 * @return string[]
	 */
	protected function ephemeral_setting_names() {
		return array();
	}

	/**
	 * Names of string settings that are stored as they are (already normalized elsewhere).
	 *
	 * @return string[]
	 */
	protected function passthrough_setting_names() {
		return array();
	}

	/**
	 * Hide the framework's own "Settings" link on the Plugins screen; the plugin adds a single one.
	 *
	 * @param string[] $links Action links.
	 * @param string   $file  Plugin file.
	 * @return string[]
	 */
	public function plugin_settings_link( $links, $file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Overrides the framework method signature.
		return $links;
	}

	/**
	 * Save the plugin settings.
	 *
	 * This is the save callback of the plugin settings page, so it sees every posted value:
	 * a blank secret keeps the stored one (also for sections hidden by a live dependency,
	 * which are still posted), "{name}_remove" clears it, and one-off action keys are dropped.
	 * Pass "{name}_remove" => '1' to clear a secret programmatically.
	 *
	 * @param array $settings Settings to store.
	 * @return void
	 */
	public function update_plugin_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$previous = $this->get_plugin_settings();
		$previous = is_array( $previous ) ? $previous : array();

		foreach ( $this->secret_setting_names() as $name ) {
			$remove_key = $name . '_remove';
			$remove     = ! empty( $settings[ $remove_key ] );
			unset( $settings[ $remove_key ] );

			if ( $remove ) {
				$settings[ $name ] = '';
				continue;
			}
			$posted = isset( $settings[ $name ] ) && is_string( $settings[ $name ] ) ? sanitize_text_field( $settings[ $name ] ) : '';
			if ( '' === $posted ) {
				$settings[ $name ] = isset( $previous[ $name ] ) && is_string( $previous[ $name ] ) ? $previous[ $name ] : '';
			} else {
				$settings[ $name ] = $posted;
			}
		}
		foreach ( $this->ephemeral_setting_names() as $name ) {
			unset( $settings[ $name ] );
		}
		// Sections hidden by a live dependency are posted without validation, so sanitize every value here.
		foreach ( $settings as $name => $value ) {
			if ( is_string( $value ) && ! in_array( $name, $this->passthrough_setting_names(), true ) ) {
				$settings[ $name ] = sanitize_text_field( $value );
			}
		}

		parent::update_plugin_settings( $settings );
	}

	/**
	 * Whether a secret is currently stored.
	 *
	 * @param string $name Setting name.
	 * @return bool
	 */
	public function has_saved_secret( $name ) {
		$value = $this->get_plugin_setting( $name );
		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Render the masked secret field (callback of the "cindova_secret" settings field type).
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Base $field Settings field.
	 * @param bool                                              $should_echo Whether to output the markup.
	 * @return string
	 */
	public function settings_cindova_secret( $field, $should_echo = true ) {
		$input_name = $field->settings->get_input_name_prefix() . '_' . $field->name;
		$has_saved  = $this->has_saved_secret( $field->name );

		$html  = $field->get_description();
		$html .= sprintf(
			'<span class="%1$s"><input type="password" name="%2$s" id="%3$s" value="" autocomplete="new-password" class="medium" />%4$s</span>',
			esc_attr( $field->get_container_classes() ),
			esc_attr( $input_name ),
			esc_attr( $field->name ),
			$field->get_error_icon()
		);
		if ( $has_saved ) {
			$html .= sprintf(
				'<span class="gform-settings-description cindova-gfotp-secret-note">%1$s</span>'
				. '<label class="cindova-gfotp-secret-remove"><input type="checkbox" name="%2$s_remove" id="%3$s_remove" value="1" /> %4$s</label>',
				esc_html__( 'A value is saved. Leave blank to keep it.', 'otp-verification-sms-for-gravity-forms' ),
				esc_attr( $input_name ),
				esc_attr( $field->name ),
				esc_html__( 'Remove the saved value', 'otp-verification-sms-for-gravity-forms' )
			);
		}

		if ( $should_echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above and Gravity Forms markup.
		}
		return $html;
	}
}
