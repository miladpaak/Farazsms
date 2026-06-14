<?php
/**
 * Elementor Pro Form Field: OTP Verification
 *
 * Renders mobile input + "ارسال کد" button + code input (shown after send).
 * Value submitted: mobile. Verification checked via transient on validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FarazSMS_Elementor_Field_OTP extends \ElementorPro\Modules\Forms\Fields\Field_Base {

	public function get_type() {
		return 'otp_verification';
	}

	public function get_name() {
		return __( 'تأیید موبایل با پیامک (فراز اس ام اس)', 'farazsms-next' );
	}

	public function render( $item, $item_index, $form ) {
		$settings = is_object( $form ) && method_exists( $form, 'get_settings_for_display' ) ? $form->get_settings_for_display() : array();
		$post_id = ! empty( $settings['form_post_id'] ) ? (int) $settings['form_post_id'] : (int) get_the_ID();
		$wid = is_object( $form ) && method_exists( $form, 'get_id' ) ? sanitize_key( (string) $form->get_id() ) : '';
		$otp_ctx = ( $post_id && $wid ) ? $post_id . '_' . $wid : (string) ( $post_id ? $post_id : get_the_ID() );

		$form->add_render_attribute( 'input' . $item_index, array(
			'type'        => 'tel',
			'class'       => 'elementor-field-textual elementor-field-otp-mobile',
			'placeholder' => ! empty( $item['placeholder'] ) ? $item['placeholder'] : '09xxxxxxxxx',
			'autocomplete'=> 'tel',
			'dir'         => 'ltr',
		) );
		$input_id = 'form_field_' . $item_index;
		$form->add_render_attribute( 'input' . $item_index, 'id', $input_id );
		if ( ! empty( $item['custom_id'] ) ) {
			$form->add_render_attribute( 'input' . $item_index, 'name', 'form_fields[' . esc_attr( $item['custom_id'] ) . ']' );
		}
		?>
		<?php
		// v3.17.3: inject modern OTP styles (once per page)
		if ( function_exists( 'wto_otp_maybe_inject_styles' ) ) {
			wto_otp_maybe_inject_styles();
		}
		?>
		<div class="elementor-field-type-otp wto-elementor-otp wto-otp-modern" data-form-id="<?php echo esc_attr( $otp_ctx ); ?>">
			<div class="wto-otp-row">
				<span class="wto-otp-icon">📱</span>
				<input <?php echo $form->get_render_attribute_string( 'input' . $item_index ); ?>>
				<button type="button" class="elementor-button wto-otp-send-btn"><?php esc_html_e( 'ارسال کد تأیید', 'farazsms-next' ); ?></button>
			</div>
			<div class="wto-otp-msg" role="alert"></div>
			<div class="wto-otp-code-row" style="display:none;">
				<span class="wto-otp-icon">🔐</span>
				<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="elementor-field-textual wto-otp-code" placeholder="<?php esc_attr_e( 'کد ۶ رقمی', 'farazsms-next' ); ?>" autocomplete="one-time-code" dir="ltr">
				<button type="button" class="elementor-button wto-otp-verify-btn"><?php esc_html_e( '✓ تأیید کد', 'farazsms-next' ); ?></button>
			</div>
			<div class="wto-otp-hint"><?php esc_html_e( 'با وارد کردن شماره موبایل و دریافت کد، صحت شماره شما تأیید می‌شود.', 'farazsms-next' ); ?></div>
		</div>
		<?php
	}

	public function validation( $field, $record, $ajax_handler ) {
		$mobile = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';
		if ( empty( $mobile ) ) {
			return;
		}
		if ( ! function_exists( 'wto_otp_normalize_mobile' ) || ! function_exists( 'wto_otp_is_verified' ) ) {
			return;
		}
		$mobile   = wto_otp_normalize_mobile( $mobile );
		$settings = $record->get( 'form_settings' );
		$post_id  = ! empty( $settings['form_post_id'] ) ? (int) $settings['form_post_id'] : 0;
		$wid      = ! empty( $settings['id'] ) ? sanitize_key( (string) $settings['id'] ) : '';
		$form_id  = ( $post_id && $wid ) ? $post_id . '_' . $wid : (string) ( $post_id ? $post_id : get_the_ID() );
		if ( ! wto_otp_is_verified( 'elementor', $form_id, $mobile ) ) {
			$ajax_handler->add_error( $field['id'], __( 'لطفاً ابتدا با دکمه «ارسال کد» و «تأیید کد» شماره موبایل را تأیید کنید.', 'farazsms-next' ) );
		}
	}

	public function __construct() {
		parent::__construct();
		add_action( 'elementor/preview/init', array( $this, 'editor_preview_footer' ) );
	}

	public function editor_preview_footer() {
		add_action( 'wp_footer', array( $this, 'content_template_script' ) );
	}

	public function content_template_script() {
		$type = $this->get_type();
		?>
		<script>
		jQuery( function() {
			if ( typeof elementor === 'undefined' ) return;
			elementor.hooks.addFilter( 'elementor_pro/forms/content_template/field/<?php echo esc_js( $type ); ?>', function( inputField, item, i ) {
				var fieldId = 'form_field_' + i;
				return '<div class="elementor-field-type-otp wto-elementor-otp"><input type="tel" id="' + fieldId + '" class="elementor-field-textual elementor-field-otp-mobile" placeholder="09xxxxxxxxx" dir="ltr"> <button type="button" class="elementor-button wto-otp-send-btn"><?php echo esc_js( __( 'ارسال کد', 'farazsms-next' ) ); ?></button> <div class="wto-otp-code-row" style="display:none;"><input type="text" class="wto-otp-code" placeholder="<?php echo esc_js( __( 'کد تأیید', 'farazsms-next' ) ); ?>"> <button type="button" class="elementor-button wto-otp-verify-btn"><?php echo esc_js( __( 'تأیید کد', 'farazsms-next' ) ); ?></button></div></div>';
			}, 10, 3 );
		} );
		</script>
		<?php
	}
}
