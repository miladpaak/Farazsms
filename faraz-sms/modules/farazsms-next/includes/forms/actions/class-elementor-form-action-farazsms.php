<?php
/**
 * Elementor Pro: اکشن «بعد از ارسال» برای نمایش در ویرایشگر فرم.
 * منطق ارسال پیامک همچنان در FarazSMS_Next_Elementor_SMS_Send از طریق
 * elementor_pro/forms/new_record اجرا می‌شود تا با فیدهای ذخیره‌شده سازگار بماند
 * و ارسال تکراری رخ ندهد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FarazSMS_Elementor_Form_Action_After_Submit extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'farazsms';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'فراز اس ام اس', 'farazsms-next' );
	}

	/**
	 * @param \Elementor\Widget_Base $widget
	 * @return void
	 */
	public function register_settings_section( $widget ) {
		// بدون کنترل اضافی؛ تنظیمات از پیشخوان وردپرس (منوی پیامک المنتور) انجام می‌شود.
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
	 * @return void
	 */
	public function run( $record, $ajax_handler ) {
		// عمداً خالی — ارسال توسط new_record انجام می‌شود.
	}

	/**
	 * @param array $element
	 * @return array
	 */
	public function on_export( $element ) {
		return $element;
	}
}
