<?php
/**
 * Gravity Forms OTP Verification Field
 *
 * Renders: mobile input + "ارسال کد" button + code input (shown after send).
 * Entry value: mobile number. Verification is checked via transient on validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_Field_OTP_Verification extends GF_Field {

	public $type = 'otp_verification';

	public function get_form_editor_field_title() {
		return __( 'تأیید موبایل با پیامک — فراز اس ام اس', 'farazsms-next' );
	}

	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => __( '📱 تأیید موبایل (فراز اس ام اس)', 'farazsms-next' ),
		);
	}

	public function get_form_editor_field_settings() {
		return array(
			'label_setting',
			'description_setting',
			'placeholder_setting',
			'css_class_setting',
			'admin_label_setting',
			'visibility_setting',
		);
	}

	public function is_value_submission_empty( $form_id ) {
		$value = rgpost( 'input_' . $this->id );
		return trim( (string) $value ) === '';
	}

	/**
	 * Frontend field markup: mobile input, send button, message area, code input.
	 *
	 * @param array $form  Form object.
	 * @param mixed $value Field value.
	 * @param null  $entry Entry object (optional).
	 */
	public function get_field_input( $form, $value = '', $entry = null ) {
		$form_id     = (int) $form['id'];
		$id          = (int) $this->id;
		$input_name  = 'input_' . $id;
		$placeholder = $this->placeholder ? esc_attr( $this->placeholder ) : __( '09xxxxxxxxx', 'farazsms-next' );

		$mobile_value = is_array( $value ) ? ( $value[ $id ] ?? '' ) : (string) $value;
		$mobile_value = esc_attr( $mobile_value );

		// v3.17.3: inject modern OTP styles (once per page) — refactor visual.
		wto_otp_maybe_inject_styles();

		ob_start();
		if ( is_admin() ) :
			?>
			<div style="background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; border-radius:8px; padding:8px 12px; margin-bottom:10px; font-size:12px; line-height:1.8;">
				✅ این فیلدِ <strong>تأیید موبایل با فراز اس ام اس</strong> است. برای ارسالِ کدِ تأیید با پیامک، همین فیلد را در فرم قرار دهید (نه فیلدِ تلفنِ پیش‌فرضِ گرویتی‌فرم).
				پیش از انتشار، حتماً از مسیرِ <strong>فراز اس ام اس ← گرویتی‌فرم/المنتور</strong> «پترن کد تأیید» را بسازید.
			</div>
			<?php
		endif;
		?>
		<div class="ginput_container ginput_container_otp wto-gf-otp wto-otp-modern" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-field-id="<?php echo esc_attr( $id ); ?>">
			<div class="wto-otp-row wto-otp-mobile-row">
				<span class="wto-otp-icon">📱</span>
				<input type="tel" name="<?php echo esc_attr( $input_name ); ?>" id="input_<?php echo esc_attr( $form_id ); ?>_<?php echo esc_attr( $id ); ?>"
					value="<?php echo $mobile_value; ?>"
					placeholder="<?php echo $placeholder; ?>"
					class="wto-otp-mobile medium" dir="ltr" autocomplete="tel" />
				<button type="button" class="wto-otp-send-btn"><?php esc_html_e( 'ارسال کد تأیید', 'farazsms-next' ); ?></button>
			</div>
			<div class="wto-otp-msg" role="alert" aria-live="polite"></div>
			<div class="wto-otp-code-row" style="display:none;">
				<span class="wto-otp-icon">🔐</span>
				<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
					class="wto-otp-code medium" placeholder="<?php esc_attr_e( 'کد ۶ رقمی', 'farazsms-next' ); ?>"
					autocomplete="one-time-code" dir="ltr" />
				<button type="button" class="wto-otp-verify-btn"><?php esc_html_e( '✓ تأیید کد', 'farazsms-next' ); ?></button>
			</div>
			<div class="wto-otp-hint"><?php esc_html_e( 'با وارد کردن شماره موبایل و دریافت کد، صحت شماره شما تأیید می‌شود.', 'farazsms-next' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function get_value_save_entry( $value, $form, $input_name, $lead_id, $lead ) {
		$value = parent::get_value_save_entry( $value, $form, $input_name, $lead_id, $lead );
		if ( function_exists( 'wto_otp_normalize_mobile' ) ) {
			$value = wto_otp_normalize_mobile( is_array( $value ) ? implode( '', $value ) : (string) $value );
		}
		return $value;
	}
}
