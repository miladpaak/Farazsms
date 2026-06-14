<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	Core Functions
*	
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}
// HPOS detection uses WooCommerce's public OrderUtil API (WC 7.1+).
// Importing Internal\* is unsafe — WooCommerce reserves the right to break that namespace.

/**
 * چک نسخه جدید افزونه از سایت فراز اس ام اس
 * این تابع خودش از wp-cron استفاده نمی‌کند و فقط زمانی که در پیشخوان اجرا شود
 * (حداکثر هر ۲۴ ساعت یک‌بار) نسخه را از endpoint JSON می‌خواند.
 */
function farazsms_check_for_updates() {
	$local_version = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '0.0.0';
	$version_endpoint = 'https://frzs.ir/plugin-version';
	$download_url = 'https://farazsms.com/farazsms-wordpress-plugin/';

	$response = wp_remote_get(
		$version_endpoint,
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		update_option( 'farazsms_last_check_time', time(), false );
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		update_option( 'farazsms_last_check_time', time(), false );
		return;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		update_option( 'farazsms_last_check_time', time(), false );
		return;
	}

	$remote_version = '';

	// حالت اصلی: JSON با کلید version
	$data = json_decode( $body, true );
	if ( is_array( $data ) && ! empty( $data['version'] ) ) {
		$remote_version = trim( (string) $data['version'] );
	}

	// fallback: اگر پاسخ JSON نبود، نسخه semantic را از متن استخراج کن
	if ( empty( $remote_version ) && preg_match( '/\b\d+\.\d+\.\d+(?:\.\d+)?\b/', $body, $matches ) ) {
		$remote_version = trim( (string) $matches[0] );
	}

	if ( empty( $remote_version ) ) {
		update_option( 'farazsms_last_check_time', time(), false );
		return;
	}

	$has_update = version_compare( $remote_version, $local_version, '>' );

	update_option( 'farazsms_last_checked_version', $remote_version, false );
	update_option( 'farazsms_update_available', $has_update ? 1 : 0, false );
	update_option( 'farazsms_update_url', $download_url, false );
	update_option( 'farazsms_last_check_time', time(), false );
}

/**
 * وقتی مدیر وارد پیشخوان می‌شود، حداکثر روزی یک‌بار نسخه جدید را چک کن
 */
add_action( 'admin_init', 'farazsms_maybe_check_updates' );
function farazsms_maybe_check_updates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$last_check = (int) get_option( 'farazsms_last_check_time', 0 );
	if ( $last_check > ( time() - DAY_IN_SECONDS ) ) {
		return;
	}
	farazsms_check_for_updates();
}

/**
 * نمایش نوتیس «آپدیت در دسترس است» فقط در صفحات افزونه فراز اس ام اس
 */
add_action( 'admin_notices', 'farazsms_show_update_notice' );
function farazsms_show_update_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// فقط در صفحات افزونه (page=farazwto یا page=farazwto-*)
	$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	if ( $current_page !== 'farazwto' && 0 !== strpos( $current_page, 'farazwto-' ) ) {
		return;
	}

	$remote        = get_option( 'farazsms_last_checked_version', '' );
	$update_url    = get_option( 'farazsms_update_url', 'https://farazsms.com/farazsms-wordpress-plugin/' );
	$local_version = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '0.0.0';

	if ( empty( $remote ) ) {
		return;
	}

	// برای اطمینان دوباره مقایسه کن
	if ( version_compare( $remote, $local_version, '<=' ) ) {
		update_option( 'farazsms_update_available', 0, false );
		return;
	}
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			نسخه جدید افزونه <strong>فراز اس ام اس</strong> در دسترس است.
			نسخه فعلی شما <code><?php echo esc_html( $local_version ); ?></code> و
			آخرین نسخه <code><?php echo esc_html( $remote ); ?></code> است.
			<a href="<?php echo esc_url( $update_url ); ?>" target="_blank" rel="noopener noreferrer">دانلود نسخه جدید</a>
		</p>
	</div>
	<?php
}
function wto_frontend_ajax_form_scripts() {
	?>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            "use strict";
            $('#wto_custom_plugin_form').submit(function (event) {
                event.preventDefault();
                var myInputFieldValue = $('#myInputField').val();
                var data = {
                    'action': 'wto_custom_plugin_frontend_ajax',
                    'myInputFieldValue': myInputFieldValue,
                };
                var ajaxurl = "<?php echo admin_url( 'admin-ajax.php' );?>";
                $.post(ajaxurl, data, function (response) {
                    console.log(response);
                    if (response.Status == true) {
                        console.log(response.message);
                        $('#wto_custom_plugin_form_wrap').html(response);

                    } else {
                        console.log(response.message);
                        $('#wto_custom_plugin_form_wrap').html(response);
                    }
                });
            });
        }(jQuery));
    </script>
	<?php
}

add_action( 'wp_footer', 'wto_frontend_ajax_form_scripts' );
add_action( 'wp_ajax_wto_custom_plugin_frontend_ajax', 'wto_custom_plugin_frontend_ajax_handler' );
add_action( 'wp_ajax_nopriv_wto_custom_plugin_frontend_ajax', 'wto_custom_plugin_frontend_ajax_handler' );
function wto_custom_plugin_frontend_ajax_handler() {
	check_ajax_referer( 'wto_custom_plugin_frontend_ajax' );
	$myInputFieldValue = sanitize_text_field( $_POST['myInputFieldValue'] );
	$response = [
		'Status'  => true,
		'message' => 'Success! Your value was: ' . $myInputFieldValue,
	];

	wp_send_json( $response );
}

add_action( 'wp_ajax_wto_custom_plugin_admin_ajax', 'wto_custom_plugin_admin_ajax_handler' );
function wto_custom_plugin_admin_ajax_handler() {
	check_ajax_referer( 'wto_custom_plugin_admin_ajax' );
	$myInputFieldValue = sanitize_text_field( $_POST['myInputFieldValue'] );
	$response = [
		'Status'  => true,
		'message' => 'Success! Your value was: ' . $myInputFieldValue,
	];

	wp_send_json( $response );
}
add_action( 'add_meta_boxes', 'wto_admin_order_custom_metabox' );
function wto_admin_order_custom_metabox() {
	$use_cot = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$screen = $use_cot ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

	add_meta_box(
		'custom',
		'کد رهگیری مرسولات (فراز اس ام اس)',
		'wto_custom_metabox_content',
		$screen,
		'side',
		'high'
	);
}

function wto_custom_metabox_content( $object ) {
	if ( $object instanceof \WC_Order ) {
		$order    = $object;
		$order_id = $order->get_id();
	} else {
		$order_id = isset( $object->ID ) ? $object->ID : ( isset( $object->id ) ? $object->id : 0 );
		$order    = wc_get_order( $order_id );
	}
	
	if ( ! $order ) {
		echo '<p>سفارش یافت نشد.</p>';
		return;
	}
	
	$saved_tracking_code = $order->get_meta( 'wto_tracking_code' );
	$saved_carrier       = $order->get_meta( 'wto_carrier' );
	if ( ! in_array( $saved_carrier, array( 'post', 'tipax', 'other' ), true ) ) {
		$saved_carrier = 'post'; // پیش‌فرض
	}
	$carriers = array(
		'post'  => 'پست',
		'tipax' => 'تیپاکس',
		'other' => 'سایر',
	);
	$wto_tracking_settings_url = admin_url( 'admin.php?page=farazwto&tt=settings' );
	?>
	<p style="margin:0 0 10px; padding:8px 10px; background:#f0f6fc; border:1px solid #c3d9ed; border-radius:6px; font-size:12px; line-height:1.7; color:#1d2327;">
		این باکس مربوط به <strong>کد رهگیری مرسولات (فراز اس ام اس)</strong> است.<br>
		ساخت پترن و تنظیمات از مسیر:
		فراز اس ام اس &gt; <a href="<?php echo esc_url( $wto_tracking_settings_url ); ?>">کد رهگیری</a>
	</p>
	<p><strong>شماره سفارش: </strong><?php echo esc_html( $order ? $order->get_order_number() : $order_id ); ?></p>
	<p>
		<label for="tracking_carrier_<?php echo esc_attr( $order_id ); ?>">نوع پست:</label>
		<select id="tracking_carrier_<?php echo esc_attr( $order_id ); ?>" name="tracking_carrier" style="width: 100%;">
			<?php foreach ( $carriers as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_carrier, $val ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="tracking_code_input_<?php echo esc_attr( $order_id ); ?>">کد رهگیری:</label>
		<input type="text" id="tracking_code_input_<?php echo esc_attr( $order_id ); ?>" name="tracking_code_input" value="" style="width: 100%;" />
	</p>
	<p>
		<button type="button" id="save_tracking_code_btn_<?php echo esc_attr( $order_id ); ?>" class="button button-primary">ارسال</button>
	</p>
	<div id="saved_tracking_code_<?php echo esc_attr( $order_id ); ?>" style="<?php echo empty( $saved_tracking_code ) ? 'display: none; ' : ''; ?>margin-top: 15px; padding: 10px; background-color: #f0f0f0; border-left: 4px solid #2271b1;">
		<p style="margin: 0;"><strong>کد رهگیری ذخیره شده:</strong></p>
		<p style="margin: 5px 0 0 0; font-size: 14px; color: #2271b1;" id="tracking_code_display_<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html( $saved_tracking_code ); ?></p>
		<?php if ( $saved_carrier ) : ?>
			<p style="margin: 5px 0 0 0; font-size: 12px; color: #555;">نوع پست: <?php echo esc_html( $carriers[ $saved_carrier ] ?? 'پست' ); ?></p>
		<?php endif; ?>
	</div>
	<script>
		jQuery(document).ready(function($){
			$('#save_tracking_code_btn_<?php echo esc_js( $order_id ); ?>').on('click', function(){
				var trackingCode = $('#tracking_code_input_<?php echo esc_js( $order_id ); ?>').val();
				var carrier = $('#tracking_carrier_<?php echo esc_js( $order_id ); ?>').val() || 'post';
				var orderId = <?php echo intval( $order_id ); ?>;
				if (trackingCode === '') {
					alert('لطفا کد رهگیری را وارد کنید.');
					return;
				}

				// ذخیره متن اصلی دکمه
				var $btn = $(this);
				var originalText = $btn.text();
				var originalDisabled = $btn.prop('disabled');

				// غیرفعال کردن دکمه و نمایش loading
				$btn.prop('disabled', true).text('در حال ارسال...');

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'save_tracking_code',
						order_id: orderId,
						tracking_code: trackingCode,
						carrier: carrier,
						security: '<?php echo wp_create_nonce( 'save_tracking_code_nonce' ); ?>'
					},
					success: function(response){
						// بازگرداندن دکمه به حالت اولیه
						$btn.prop('disabled', false).text(originalText);
						
						if (response.success) {
							$('#tracking_code_input_<?php echo esc_js( $order_id ); ?>').val('');
							$('#saved_tracking_code_<?php echo esc_js( $order_id ); ?>').show();
							$('#tracking_code_display_<?php echo esc_js( $order_id ); ?>').text(trackingCode);
							
							alert(response.data);
						} else {
							alert('خطا: ' + response.data);
						}
					},
					error: function(){
						// بازگرداندن دکمه به حالت اولیه
						$btn.prop('disabled', false).text(originalText);
						
						alert('خطا در ذخیره کد رهگیری');
					}
				});
			});
		});
	</script>
	<?php
}

add_action( 'wp_ajax_save_tracking_code', 'wto_handle_save_tracking_code' );
function wto_handle_save_tracking_code() {
	check_ajax_referer( 'save_tracking_code_nonce', 'security' );

	// v3.13.12 SECURITY FIX (CRITICAL): قبل از این فقط nonce چک می‌شد. هر کاربر
	// logged-in (حتی subscriber) می‌توانست با یک fetch جعلی کد رهگیری هر سفارشی
	// را تغییر دهد و باعث ارسال پیامک بی‌مورد شود. حالا cap واقعی WooCommerce
	// را چک می‌کنیم: edit_shop_orders (به‌صورت کلی) + edit_shop_order (به‌صورت
	// per-order که WC از HPOS هم پشتیبانی می‌کند).
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز.', 403 );
	}

	$order_id      = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
	$tracking_code = isset( $_POST['tracking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_code'] ) ) : '';
	$carrier       = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : 'post';
	if ( ! in_array( $carrier, array( 'post', 'tipax', 'other' ), true ) ) {
		$carrier = 'post';
	}

	if ( empty( $order_id ) || empty( $tracking_code ) ) {
		wp_send_json_error( 'اطلاعات ناقص است.' );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		wp_send_json_error( 'سفارش یافت نشد.' );
	}

	// v3.13.12 SECURITY FIX: per-order cap check برای پشتیبانی از Shop Manager و
	// نقش‌های custom که فقط روی subset سفارش‌ها edit حق دارند.
	if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
		wp_send_json_error( 'دسترسی برای ویرایش این سفارش وجود ندارد.', 403 );
	}

	$order->update_meta_data( 'wto_tracking_code', $tracking_code );
	$order->update_meta_data( 'wto_carrier', $carrier );
	$order->save();

	// انتخاب پترن بر اساس carrier — برای بررسی وضعیت تأیید پترن قبل از ارسال
	if ( $carrier === 'post' ) {
		$pattern_option_key = 'wto_pattern_post';
		$fallback_legacy    = 'wto_pattern';
	} else {
		$pattern_option_key = 'wto_pattern_' . $carrier;
		$fallback_legacy    = '';
	}

	// همیشه یک response معتبر ارسال کن
	try {
	if ( function_exists( 'wto_send_pattern_sms' ) ) {
			// بررسی تأیید پترن قبل از ارسال پیامک (طبق مستندات API)
			$pattern_code = get_option( $pattern_option_key, '' );
			if ( $pattern_code === '' && $fallback_legacy !== '' ) {
				$pattern_code = get_option( $fallback_legacy, '' );
			}
			
			// بررسی تأیید پترن با استفاده از API دریافت جزئیات پترن
			if (!empty($pattern_code) && function_exists('wto_get_pattern_details')) {
				$pattern_response = wto_get_pattern_details($pattern_code);
				$pattern_data = json_decode($pattern_response, true);
				
				// بررسی اینکه response معتبر است یا نه
				if (!$pattern_data || !isset($pattern_data['status']) || $pattern_data['status'] !== 'success') {
					$error_message = isset($pattern_data['message']) ? $pattern_data['message'] : 'پترن یافت نشد یا تأیید نشده است.';
					wp_send_json_error('کد رهگیری ذخیره شد اما پیامک ارسال نشد؛ پترن هنوز تأیید نشده است. (' . $error_message . ')');
					return;
				}
				
				// بررسی وضعیت پترن در data.status
				if (isset($pattern_data['data']['status'])) {
					$pattern_status = $pattern_data['data']['status'];
					
					// اگر پترن در وضعیت pending باشد
					if ($pattern_status === 'pending') {
						wp_send_json_error('کد رهگیری ذخیره شد اما پیامک ارسال نشد؛ پترن در انتظار تأیید است. لطفا صبر کنید تا پترن توسط ادمین تأیید شود.');
						return;
					}
					
					// اگر پترن رد شده باشد
					if ($pattern_status === 'rejected' || $pattern_status === 'reject') {
						$admin_message = isset($pattern_data['data']['admin_message']) ? $pattern_data['data']['admin_message'] : '';
						$error_msg = 'کد رهگیری ذخیره شد اما پیامک ارسال نشد؛ پترن رد شده است.';
						if (!empty($admin_message)) {
							$error_msg .= ' (' . $admin_message . ')';
						}
						wp_send_json_error($error_msg);
						return;
					}
					
					// اگر پترن تأیید شده باشد (approved یا active)
					if ($pattern_status === 'approved' || $pattern_status === 'active') {
						// پترن تأیید شده است، ادامه بده
					} else {
						// وضعیت نامشخص
						wp_send_json_error('کد رهگیری ذخیره شد اما پیامک ارسال نشد؛ وضعیت پترن نامشخص است. (وضعیت: ' . $pattern_status . ')');
						return;
					}
				}
			}
			
			$sms_result = wto_send_pattern_sms( $order_id, $tracking_code, $carrier );

			if ( $sms_result === 'success' ) {
				// v3.14.10: ثبت رکورد در tracking log (فقط بعد از ارسال موفق)
				// hook بصورت غیربلوکه — اگر کسی listener نداشته باشد، تاثیری روی پاسخ نمی‌گذارد.
				do_action( 'wto_tracking_code_sent', $order_id, $tracking_code, $carrier );
				wp_send_json_success( 'کد رهگیری ذخیره شد و پیامک با موفقیت ارسال شد.' );
			} elseif ( $sms_result === 'pattern_not_approved' ) {
				wp_send_json_error( 'کد رهگیری ذخیره شد اما پیامک ارسال نشد؛ پترن هنوز تأیید نشده است.' );
			} elseif ( is_string( $sms_result ) && strpos( $sms_result, 'api_error:' ) === 0 ) {
				$error_msg = substr( $sms_result, strlen( 'api_error:' ) );
				wp_send_json_error( 'کد رهگیری ذخیره شد اما خطا در وب‌سرویس: ' . $error_msg );
			} elseif ( is_string( $sms_result ) && strpos( $sms_result, 'curl_error:' ) === 0 ) {
				$error_msg = substr( $sms_result, strlen( 'curl_error:' ) );
				wp_send_json_error( 'کد رهگیری ذخیره شد اما خطا در ارتباط با وب‌سرویس: ' . $error_msg );
			} else {
				// هر پاسخ نامشخص دیگر
				wp_send_json_error( 'کد رهگیری ذخیره شد اما وضعیت ارسال پیامک نامشخص است.' );
			}
		} else {
			// تابع ارسال پیامک در دسترس نیست
			wp_send_json_success( 'کد رهگیری ذخیره شد.' );
		}
	} catch ( Exception $e ) {
		// اگر خطایی رخ داد، لاگ کن و یک response معتبر ارسال کن
		error_log( 'خطا در wto_handle_save_tracking_code: ' . $e->getMessage() );
		wp_send_json_error( 'کد رهگیری ذخیره شد اما خطایی در ارسال پیامک رخ داد. لطفا دوباره تلاش کنید.' );
	}
}



add_action( 'woocommerce_order_status_changed', 'wto_order_status_change', 10, 3 );
function wto_order_status_change($order_id, $status_changed_from, $status_changed_to ) {
    $send_status = get_option('wto_send_status', '');
    $send_time_days = intval(get_option('wto_send_time', 0));

    if ( $status_changed_to !== $send_status ) {
        return;
    }

    $order = wc_get_order($order_id);
    if ( ! $order ) {
        return;
    }

$date_changed = current_time('Y-m-d H:i:s');
$date_obj = new DateTime($date_changed);
if ($send_time_days > 0) {
    $date_obj->modify("+{$send_time_days} days");
}
$date_obj->setTimezone(new DateTimeZone('UTC'));
$date_to_send = $date_obj->format('Y-m-d\TH:i:s');

    wto_send_scheduled_sms($order_id, $date_to_send);
}

/**
 * Hook برای intercept کردن ارسال پیامک فقط برای gateway FarazSMSNext
 * اولویت 98 — قبل از send_order_sms افزونه پیامک (99).
 */
add_action( 'woocommerce_order_status_changed', 'wto_intercept_sms_for_farazsmsnext', 98, 3 );
add_action( 'woocommerce_checkout_order_processed', 'wto_intercept_sms_on_checkout', 98, 1 );

/**
 * @param int $order_id
 * @return void
 */
function wto_intercept_sms_on_checkout( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	wto_intercept_sms_for_farazsmsnext( $order_id, '', $order->get_status() );
}

/**
 * @param int    $order_id
 * @param string $old_status
 * @param string $new_status
 * @return void
 */
function wto_intercept_sms_for_farazsmsnext( $order_id, $old_status = '', $new_status = 'created' ) {
	if ( ! function_exists( 'PWSMS' ) || ! function_exists( 'wto_is_farazsmsnext_active_gateway' ) ) {
		return;
	}
	if ( ! wto_is_farazsmsnext_active_gateway() ) {
		return;
	}

	if ( function_exists( 'wto_ensure_farazsmsnext_gateway_loaded' ) ) {
		wto_ensure_farazsmsnext_gateway_loaded();
	}
	if ( ! class_exists( 'PW\PWSMS\Gateways\FarazSMSNext', false ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	wto_pwsms_detach_order_sms_hooks();

	$modified_status = PWSMS()->modify_status( $new_status );

	static $handled = array();
	$run_key = (int) $order_id . '|' . $modified_status;
	if ( isset( $handled[ $run_key ] ) ) {
		return;
	}
	$handled[ $run_key ] = true;
	$enable_buyer = PWSMS()->get_option( 'enable_buyer' );
	

		$buyer_allowed_statuses = array_keys(PWSMS()->get_buyer_allowed_statuses());
		if ( in_array( $modified_status, $buyer_allowed_statuses ) ) {
			$mobile = PWSMS()->buyer_mobile( $order_id );
			$message_template = PWSMS()->get_option( 'sms_body_' . $modified_status );
			
			if ( ! empty( $mobile ) && ! empty( $message_template ) ) {
				// دریافت پترن ذخیره شده
				$patterns = get_option( 'wto_patterns', [] );
				$pattern_code = null;
				$section_type = 'buyer';
				
				if ( ! empty( $patterns ) && is_array( $patterns ) ) {
					if ( isset( $patterns[ $section_type ] ) && is_array( $patterns[ $section_type ] ) ) {
						if ( isset( $patterns[ $section_type ][ $modified_status ] ) ) {
							$pattern_code = $patterns[ $section_type ][ $modified_status ];
						}
					}
				}
				
				// اگر پترن پیدا شد، از gateway استفاده کن
				if ( ! empty( $pattern_code ) ) {
					$gateway = new \PW\PWSMS\Gateways\FarazSMSNext();
					
				// استخراج attributes از template
				$pattern_data = [];
				preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message_template, $shortcode_matches );
				$shortcodes = ! empty( $shortcode_matches[1] ) ? array_unique( $shortcode_matches[1] ) : [];
				
			if ( ! empty( $shortcodes ) ) {
					foreach ( $shortcodes as $shortcode ) {
						$attr_value = PWSMS()->replace_short_codes( '{' . $shortcode . '}', $modified_status, $order );
						if ( empty( $attr_value ) ) {
							$pattern_data[ $shortcode ] = null;
						} else {
							$current_encoding = mb_detect_encoding( $attr_value, 'UTF-8, ISO-8859-1, Windows-1252', true );
							if ( $current_encoding && $current_encoding !== 'UTF-8' ) {
								$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', $current_encoding );
							} elseif ( ! $current_encoding ) {
								$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', 'auto' );
							}
							
							$pattern_data[ $shortcode ] = $attr_value;
						}
					}
				}
					
					if ( ! empty( $pattern_data ) && wto_order_sms_should_send_once( $order_id, 2, $modified_status, $mobile ) ) {
						$gateway->mobile = [ $mobile ];
						$message_parts = [ 'patterncode:' . $pattern_code ];
						foreach ( $pattern_data as $key => $value ) {
							if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
								$value = mb_convert_encoding( $value, 'UTF-8', 'auto' );
							}
							$message_parts[] = $key . ':' . $value;
						}
						$gateway->message = implode( "\n", $message_parts );

						$data = array(
							'post_id'        => $order_id,
							'type'           => 2,
							'order_status'   => $modified_status,
							'section_type'   => $section_type,
						);

						$result = $gateway->send( $data );

						if ( $result === true ) {
							$order->add_order_note( sprintf( 'پیامک با موفقیت به مشتری با شماره %s ارسال گردید.', $mobile ) );
						} else {
							$order->add_order_note( sprintf( 'پیامک بخاطر خطا به مشتری با شماره %s ارسال نشد.<br>پاسخ وبسرویس: %s', $mobile, $result ) );
						}
					}
				}
			}
		}
	
	
	$enable_super_admin_sms = PWSMS()->get_option( 'enable_super_admin_sms' );
	
	
		$super_admin_order_status = PWSMS()->get_option( 'super_admin_order_status' );
		$super_admin_statuses = (array) $super_admin_order_status;
		if ( is_object( $super_admin_order_status ) ) {
			$super_admin_statuses = array_keys( (array) $super_admin_order_status );
		} elseif ( is_array( $super_admin_order_status ) && ! empty( $super_admin_order_status ) ) {
			$first_key = key( $super_admin_order_status );
			if ( is_numeric( $first_key ) ) {
				$super_admin_statuses = array_values( $super_admin_order_status );
			} else {
				$super_admin_statuses = array_keys( $super_admin_order_status );
			}
		}
		
		if ( in_array( $modified_status, $super_admin_statuses ) ) {
			$mobile = PWSMS()->get_option( 'super_admin_phone' );
			$message_template = PWSMS()->get_option( 'super_admin_sms_body_' . $modified_status );
			
			if ( ! empty( $mobile ) && ! empty( $message_template ) ) {
				// دریافت پترن ذخیره شده
				$patterns = get_option( 'wto_patterns', [] );
				$pattern_code = null;
				$section_type = 'super_admin';
				
				if ( ! empty( $patterns ) && is_array( $patterns ) ) {
					if ( isset( $patterns[ $section_type ] ) && is_array( $patterns[ $section_type ] ) ) {
						if ( isset( $patterns[ $section_type ][ $modified_status ] ) ) {
							$pattern_code = $patterns[ $section_type ][ $modified_status ];
						}
					}
				}
				
				// اگر پترن پیدا شد، از gateway استفاده کن
				if ( ! empty( $pattern_code ) ) {
					$gateway = new \PW\PWSMS\Gateways\FarazSMSNext();
					
					// استخراج attributes از template
					$pattern_data = [];
					preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message_template, $shortcode_matches );
					$shortcodes = ! empty( $shortcode_matches[1] ) ? array_unique( $shortcode_matches[1] ) : [];
					
				if ( ! empty( $shortcodes ) ) {
					foreach ( $shortcodes as $shortcode ) {
						$attr_value = PWSMS()->replace_short_codes( '{' . $shortcode . '}', $modified_status, $order );
						if ( empty( $attr_value ) ) {
							$pattern_data[ $shortcode ] = null;
						} else {
							$pattern_data[ $shortcode ] = $attr_value;
						}
					}
				}
					
					$mobile_numbers = function_exists( 'wto_split_mobile_list' )
						? wto_split_mobile_list( $mobile )
						: array( trim( (string) $mobile ) );
					$mobile_numbers = array_values( array_filter( $mobile_numbers ) );
					$dedupe_phones  = $mobile_numbers;
					sort( $dedupe_phones );
					$dedupe_key     = implode( ',', $dedupe_phones );

					if ( ! empty( $pattern_data ) && ! empty( $mobile_numbers ) && wto_order_sms_should_send_once( $order_id, 4, $modified_status, $dedupe_key ) ) {
						$gateway->mobile = $mobile_numbers;
						$mobile_label    = implode( '، ', $mobile_numbers );

						$message_parts = array( 'patterncode:' . $pattern_code );
						foreach ( $pattern_data as $key => $value ) {
							$message_parts[] = $key . ':' . $value;
						}
						$gateway->message = implode( "\n", $message_parts );

						$data = array(
							'post_id'      => $order_id,
							'type'         => 4,
							'order_status' => $modified_status,
							'section_type' => $section_type,
						);

						$result = $gateway->send( $data );

						if ( $result === true ) {
							$order->add_order_note( sprintf( 'پیامک با موفقیت به مدیر کل با شماره %s ارسال گردید.', $mobile_label ) );
						} else {
							$order->add_order_note( sprintf( 'پیامک بخاطر خطا به مدیر کل با شماره %s ارسال نشد.<br>پاسخ وبسرویس: %s', $mobile_label, $result ) );
						}
					}
				}
			}
		}

	
	$enable_product_admin_sms = PWSMS()->get_option( 'enable_product_admin_sms' );
	if ( $enable_product_admin_sms ) {
		$order_products = PWSMS()->get_prodcut_lists( $order, 'product_id' );
		$mobiles = PWSMS()->product_admin_mobiles( $order_products['product_id'], $modified_status );
		
		foreach ( (array) $mobiles as $mobile => $product_ids ) {
			$vendor_items = PWSMS()->product_admin_items( $order_products, $product_ids );
			$message_template = PWSMS()->get_option( 'product_admin_sms_body_' . $modified_status );
			
			if ( ! empty( $mobile ) && ! empty( $message_template ) ) {
				// دریافت پترن ذخیره شده
				$patterns = get_option( 'wto_patterns', [] );
				$pattern_code = null;
				$section_type = 'product_admin';
				
				if ( ! empty( $patterns ) && is_array( $patterns ) ) {
					if ( isset( $patterns[ $section_type ] ) && is_array( $patterns[ $section_type ] ) ) {
						if ( isset( $patterns[ $section_type ][ $modified_status ] ) ) {
							$pattern_code = $patterns[ $section_type ][ $modified_status ];
						}
					}
				}
				
				// اگر پترن پیدا شد، از gateway استفاده کن
				if ( ! empty( $pattern_code ) ) {
					$gateway = new \PW\PWSMS\Gateways\FarazSMSNext();
					
					// استخراج attributes از template
					$pattern_data = [];
					preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message_template, $shortcode_matches );
					$shortcodes = ! empty( $shortcode_matches[1] ) ? array_unique( $shortcode_matches[1] ) : [];
					
				if ( ! empty( $shortcodes ) ) {
					foreach ( $shortcodes as $shortcode ) {
						$attr_value = PWSMS()->replace_short_codes( '{' . $shortcode . '}', $modified_status, $order, $vendor_items );
						if ( empty( $attr_value ) ) {
							$pattern_data[ $shortcode ] = null;
						} else {
							$pattern_data[ $shortcode ] = $attr_value;
						}
					}
				}
					
					if ( ! empty( $pattern_data ) && wto_order_sms_should_send_once( $order_id, 5, $modified_status, $mobile ) ) {
						$gateway->mobile = array( $mobile );

						$message_parts = array( 'patterncode:' . $pattern_code );
						foreach ( $pattern_data as $key => $value ) {
							$message_parts[] = $key . ':' . $value;
						}
						$gateway->message = implode( "\n", $message_parts );

						$data = array(
							'post_id'      => $order_id,
							'type'         => 5,
							'order_status' => $modified_status,
							'section_type' => $section_type,
						);

						$result = $gateway->send( $data );

						if ( $result === true ) {
							$order->add_order_note( sprintf( 'پیامک با موفقیت به مدیر محصول با شماره %s ارسال گردید.', $mobile ) );
						} else {
							$order->add_order_note( sprintf( 'پیامک بخاطر خطا به مدیر محصول با شماره %s ارسال نشد.<br>پاسخ وبسرویس: %s', $mobile, $result ) );
						}
					}
				}
			}
		}
	}
}