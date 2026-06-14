<?php
/**
 * Wallet Recharge Module — Phase 9 (v3.13.5)
 *
 * این فایل قابلیت خرید شارژ پنل پیامک را به‌صورت مستقیم از داخل افزونه فراهم می‌کند.
 *
 * Endpoint مصرفی:
 *
 *   POST https://api.iranpayamak.com/ws/v1/account/wallet/charge
 *
 * Request body:
 *
 *   { "amount": <Toman>, "redirectUrl": "<callback URL>" }
 *
 * Response: انتظار می‌رود شامل URL درگاه پرداخت باشد (در فیلدهایی مثل
 * paymentUrl / payment_url / gateway_url / redirect_url — همه را به‌صورت دفاعی
 * بررسی می‌کنیم).
 *
 * بسته‌های پیشنهادی:
 *
 *   1,000,000 — 3,000,000 — 5,000,000 — 10,000,000  تومان
 *
 * + امکان وارد کردن مبلغ دلخواه.
 *
 * هشدار مهم: شارژ خریداری‌شده قابل بازگشت نیست — این پیام قبل از پرداخت با
 * confirm نشان داده می‌شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  Render: کارت خرید شارژ (قابل استفاده مجدد در چندین صفحه)
 * ════════════════════════════════════════════════════════════════════════ */

function wto_render_wallet_recharge_panel( $args = array() ) {
	static $assets_printed = false;

	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$args = wp_parse_args( $args, array(
		'title' => 'خرید شارژ پنل پیامک',
		'intro' => 'بدون نیاز به رفتن به پنل، اعتبار خود را همین‌جا افزایش دهید. پرداخت از طریق درگاه امن فراز اس‌ام‌اس انجام می‌شود.',
	) );

	$nonce = wp_create_nonce( 'wto_wallet_charge' );
	$packages = array(
		array( 'amount' => 1000000,  'label' => '۱ میلیون',   'desc' => 'بسته شروع' ),
		array( 'amount' => 3000000,  'label' => '۳ میلیون',   'desc' => 'پرفروش ⭐' ),
		array( 'amount' => 5000000,  'label' => '۵ میلیون',   'desc' => 'بسته متوسط' ),
		array( 'amount' => 10000000, 'label' => '۱۰ میلیون',  'desc' => 'بسته حرفه‌ای' ),
	);
	?>
	<div class="wto-wallet-panel" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<div class="wto-wallet-panel__hero">
			<div class="wto-wallet-panel__hero-icon">
				<span class="dashicons dashicons-money-alt"></span>
			</div>
			<div class="wto-wallet-panel__hero-text">
				<h3><?php echo esc_html( $args['title'] ); ?></h3>
				<p><?php echo esc_html( $args['intro'] ); ?></p>
			</div>
		</div>

		<div class="wto-wallet-panel__packages">
			<?php foreach ( $packages as $pkg ) : ?>
				<button type="button" class="wto-wallet-pkg" data-amount="<?php echo esc_attr( $pkg['amount'] ); ?>">
					<span class="wto-wallet-pkg__label"><?php echo esc_html( $pkg['label'] ); ?></span>
					<span class="wto-wallet-pkg__unit">تومان</span>
					<span class="wto-wallet-pkg__desc"><?php echo esc_html( $pkg['desc'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="wto-wallet-panel__custom">
			<label class="wto-wallet-panel__custom-label">یا مبلغ دلخواه:</label>
			<div class="wto-wallet-panel__custom-row">
				<input type="text" class="wto-wallet-custom-amount" placeholder="مثلاً 2,000,000" autocomplete="off" inputmode="numeric">
				<span class="wto-wallet-panel__custom-unit">تومان</span>
				<button type="button" class="button button-primary wto-wallet-custom-pay">
					<span class="dashicons dashicons-cart"></span>
					پرداخت
				</button>
			</div>
		</div>

		<div class="wto-wallet-panel__notice">
			<strong>⚠️ توجه مهم:</strong>
			شارژ خریداری‌شده به هیچ عنوان قابل بازگشت یا استرداد نیست. مسئولیت انتخاب مبلغ بر عهده‌ی شماست. قبل از پرداخت از صحت مبلغ مطمئن شوید.
		</div>

		<div class="wto-wallet-panel__result" style="display:none;"></div>
	</div>
	<?php

	if ( $assets_printed ) {
		return;
	}
	$assets_printed = true;
	?>
	<style id="wto-wallet-panel-css">
	.wto-wallet-panel {
		direction: rtl;
		background: #fff;
		border: 1px solid #e2e8f0;
		border-radius: 12px;
		padding: 22px 24px;
		margin: 0 0 24px;
		box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
		font-family: Tahoma, 'IRANSans', 'Vazir', sans-serif;
	}
	.wto-wallet-panel * { box-sizing: border-box; }

	.wto-wallet-panel__hero {
		display: flex;
		gap: 14px;
		align-items: center;
		padding-bottom: 16px;
		margin-bottom: 18px;
		border-bottom: 1px solid #f1f5f9;
	}
	.wto-wallet-panel__hero-icon {
		flex-shrink: 0;
		width: 52px;
		height: 52px;
		background: linear-gradient(135deg, #10b981 0%, #059669 100%);
		border-radius: 12px;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.wto-wallet-panel__hero-icon .dashicons {
		color: #fff;
		font-size: 28px;
		width: 28px;
		height: 28px;
	}
	.wto-wallet-panel__hero-text h3 {
		margin: 0 0 4px;
		font-size: 17px;
		color: #0f172a;
		font-weight: 700;
	}
	.wto-wallet-panel__hero-text p {
		margin: 0;
		color: #475569;
		font-size: 13px;
		line-height: 1.8;
	}

	/* بسته‌ها */
	.wto-wallet-panel__packages {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: 12px;
		margin-bottom: 18px;
	}
	.wto-wallet-pkg {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 4px;
		padding: 16px 12px;
		background: #f8fafc;
		border: 2px solid #e2e8f0;
		border-radius: 10px;
		cursor: pointer;
		transition: all 0.15s;
		font-family: inherit;
		text-align: center;
	}
	.wto-wallet-pkg:hover {
		background: #ecfdf5;
		border-color: #10b981;
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
	}
	.wto-wallet-pkg:disabled {
		opacity: 0.6;
		cursor: not-allowed;
		transform: none;
	}
	.wto-wallet-pkg__label {
		font-size: 18px;
		font-weight: 700;
		color: #0f172a;
	}
	.wto-wallet-pkg__unit {
		font-size: 11px;
		color: #64748b;
	}
	.wto-wallet-pkg__desc {
		font-size: 12px;
		color: #10b981;
		font-weight: 500;
		margin-top: 4px;
	}
	.wto-wallet-pkg.is-loading {
		background: #f1f5f9;
		color: #94a3b8;
	}

	/* مبلغ دلخواه */
	.wto-wallet-panel__custom {
		background: #f8fafc;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
		padding: 14px 16px;
		margin-bottom: 14px;
	}
	.wto-wallet-panel__custom-label {
		font-size: 13px;
		font-weight: 600;
		color: #334155;
		margin-bottom: 8px;
		display: block;
	}
	.wto-wallet-panel__custom-row {
		display: flex;
		gap: 8px;
		align-items: center;
		flex-wrap: wrap;
	}
	.wto-wallet-custom-amount {
		flex: 1 1 200px;
		padding: 8px 12px;
		border: 1px solid #cbd5e1;
		border-radius: 6px;
		direction: ltr;
		text-align: center;
		font-family: monospace;
		font-size: 15px;
		font-weight: 600;
	}
	.wto-wallet-custom-amount:focus {
		outline: none;
		border-color: #10b981;
		box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
	}
	.wto-wallet-panel__custom-unit {
		font-size: 12px;
		color: #64748b;
	}
	.wto-wallet-custom-pay {
		display: inline-flex !important;
		align-items: center;
		gap: 4px;
		padding: 7px 18px !important;
		height: auto !important;
		background: #10b981 !important;
		border-color: #10b981 !important;
	}
	.wto-wallet-custom-pay:hover {
		background: #059669 !important;
		border-color: #059669 !important;
	}
	.wto-wallet-custom-pay .dashicons { font-size: 14px; width: 14px; height: 14px; }
	.wto-wallet-custom-pay.is-loading .dashicons { animation: wto-wallet-spin 0.8s linear infinite; }
	@keyframes wto-wallet-spin { to { transform: rotate(360deg); } }

	/* هشدار */
	.wto-wallet-panel__notice {
		background: #fef3c7;
		border: 1px solid #fde68a;
		border-radius: 8px;
		padding: 12px 16px;
		font-size: 13px;
		color: #78350f;
		line-height: 1.8;
	}
	.wto-wallet-panel__notice strong {
		color: #92400e;
	}

	/* نتیجه */
	.wto-wallet-panel__result {
		margin-top: 12px;
		padding: 10px 14px;
		border-radius: 8px;
		font-size: 13px;
		line-height: 1.7;
	}
	.wto-wallet-panel__result.is-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
	.wto-wallet-panel__result.is-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
	</style>

	<script type="text/javascript">
	jQuery(function($) {
		function normalizeDigits(s) {
			var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
			var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
			s = String(s || '');
			for (var i = 0; i < 10; i++) {
				s = s.replace(new RegExp(fa[i], 'g'), i).replace(new RegExp(ar[i], 'g'), i);
			}
			return s;
		}
		function formatNumber(n) {
			n = parseInt(n, 10);
			if (isNaN(n)) return '';
			return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		}
		function parseAmount(s) {
			s = normalizeDigits(s).replace(/[^\d]/g, '');
			return parseInt(s, 10) || 0;
		}

		$('.wto-wallet-panel').each(function() {
			var $p = $(this);
			if ($p.data('wto-inited')) return;
			$p.data('wto-inited', true);

			var nonce = $p.data('nonce');
			var $custom = $p.find('.wto-wallet-custom-amount');
			var $customBtn = $p.find('.wto-wallet-custom-pay');
			var $result = $p.find('.wto-wallet-panel__result');

			// فرمت دلاری روی مبلغ دلخواه هنگام تایپ
			$custom.on('input', function() {
				var n = parseAmount($(this).val());
				$(this).val(n > 0 ? formatNumber(n) : '');
			});

			function startCharge(amount, $btn) {
				if (!amount || amount < 10000) {
					$result.removeClass('is-success').addClass('is-error')
						.text('حداقل مبلغ پرداخت ۱۰,۰۰۰ تومان است.').show();
					return;
				}
				var msg = 'آیا از خرید شارژ به مبلغ ' + formatNumber(amount) + ' تومان مطمئن هستید؟\n\n' +
					'⚠️ توجه: این مبلغ به هیچ عنوان قابل بازگشت نیست.';
				if (!confirm(msg)) return;

				$btn.prop('disabled', true).addClass('is-loading');
				$result.hide();

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'wto_wallet_charge',
						nonce: nonce,
						amount: amount
					}
				}).done(function(res) {
					if (res && res.success && res.data && res.data.payment_url) {
						$result.removeClass('is-error').addClass('is-success')
							.html('✓ در حال انتقال به درگاه پرداخت...').show();
						// انتقال به درگاه پرداخت
						setTimeout(function() {
							window.location.href = res.data.payment_url;
						}, 600);
					} else {
						var emsg = (res && res.data && res.data.message) ? res.data.message : 'خطای ناشناخته';
						$result.removeClass('is-success').addClass('is-error')
							.html('✗ ' + emsg).show();
						$btn.prop('disabled', false).removeClass('is-loading');
					}
				}).fail(function(xhr) {
					var emsg = 'خطای ارتباط با سرور.';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						emsg = xhr.responseJSON.data.message;
					}
					$result.removeClass('is-success').addClass('is-error').text(emsg).show();
					$btn.prop('disabled', false).removeClass('is-loading');
				});
			}

			// کلیک روی بسته‌های پیشنهادی
			$p.on('click', '.wto-wallet-pkg', function(e) {
				e.preventDefault();
				var amount = parseInt($(this).data('amount'), 10);
				startCharge(amount, $(this));
			});

			// کلیک روی پرداخت با مبلغ دلخواه
			$customBtn.on('click', function(e) {
				e.preventDefault();
				var amount = parseAmount($custom.val());
				startCharge(amount, $(this));
			});

			// Enter داخل input مبلغ دلخواه
			$custom.on('keypress', function(e) {
				if (e.which === 13) {
					e.preventDefault();
					$customBtn.click();
				}
			});
		});
	});
	</script>
	<?php
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  AJAX: ساخت درخواست پرداخت و گرفتن URL درگاه از فراز
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_wto_wallet_charge', 'wto_wallet_charge_ajax' );
function wto_wallet_charge_ajax() {
	check_ajax_referer( 'wto_wallet_charge', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}

	$amount = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
	if ( $amount < 10000 ) {
		wp_send_json_error( array( 'message' => 'حداقل مبلغ پرداخت ۱۰,۰۰۰ تومان است' ) );
	}
	if ( $amount > 100000000 ) {
		// محدودیت بالا برای جلوگیری از خطای کاربر (۱۰۰ میلیون تومان).
		wp_send_json_error( array( 'message' => 'حداکثر مبلغ مجاز ۱۰۰,۰۰۰,۰۰۰ تومان است. برای مبالغ بیشتر مستقیماً از پنل اقدام کنید.' ) );
	}

	// منبع کلید: ابتدا از PWSMS، سپس از تنظیمات افزونه ما.
	$apikey = '';
	if ( function_exists( 'wto_pwsms_resolve_apikey' ) ) {
		$apikey = wto_pwsms_resolve_apikey();
	}
	if ( $apikey === '' ) {
		$apikey = trim( (string) get_option( 'wto_apikey', '' ) );
	}
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => 'ابتدا کلید دسترسی (Api-Key) را وارد و ذخیره کنید' ) );
	}

	// redirect URL: کاربر بعد از پرداخت به این آدرس برمی‌گردد.
	$redirect_url = admin_url( 'admin.php?page=farazwto-settings&wto_charge_callback=1' );

	$payload = array(
		'amount'      => $amount,
		'redirectUrl' => $redirect_url,
	);

	$request_args = array(
		'method'    => 'POST',
		'timeout'   => 25,
		'sslverify' => true,
		'headers'   => array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'Api-Key'      => $apikey,
		),
		'body'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
	);

	if ( function_exists( 'wto_remote_post_with_fallback' ) ) {
		$response = wto_remote_post_with_fallback(
			'https://api.iranpayamak.com/ws/v1/account/wallet/charge',
			$request_args
		);
	} else {
		$response = wp_remote_post( 'https://api.iranpayamak.com/ws/v1/account/wallet/charge', $request_args );
	}

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => 'خطای شبکه: ' . esc_html( $response->get_error_message() ) ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	// استخراج URL درگاه از پاسخ — به‌صورت دفاعی چندین نام فیلد بررسی می‌شود
	// چون مستندات apidog ممکن است فیلد دقیق را با نام‌های مختلف برگرداند.
	$payment_url = wto_wallet_extract_payment_url( $data );

	if ( $payment_url !== '' ) {
		// success — حتی اگر status موجود نباشد، چون URL برگشته
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[wto-wallet] charge ok: amount=%d url=%s', $amount, substr( $payment_url, 0, 80 ) ) );
		}
		wp_send_json_success( array(
			'payment_url' => $payment_url,
			'amount'      => $amount,
		) );
	}

	// failure — استخراج پیام خطا
	$msg = wto_wallet_extract_error_message( $data );
	if ( $msg === '' ) {
		if ( $code === 401 || $code === 403 ) {
			$msg = 'کلید دسترسی نامعتبر است یا اجازه‌ی این عملیات را ندارد';
		} elseif ( (int) $code === 0 ) {
			$msg = 'دسترسی به API ممکن نیست — سرور هاست شما درخواست‌های خروجی را مسدود کرده است';
		} elseif ( $code >= 400 && $code < 500 ) {
			$msg = 'درخواست نامعتبر (HTTP ' . $code . ')';
		} elseif ( $code >= 500 ) {
			$msg = 'خطای سرور فراز (HTTP ' . $code . ') — بعداً دوباره تلاش کنید';
		} else {
			$msg = 'پاسخ نامعتبر از سرور (HTTP ' . $code . ')';
		}
	}
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[wto-wallet] charge fail: amount=%d code=%d msg=%s body=%s',
			$amount, $code, $msg, substr( (string) $body, 0, 200 ) ) );
	}
	wp_send_json_error( array( 'message' => $msg ) );
}

/**
 * استخراج URL درگاه از پاسخ به‌صورت دفاعی.
 * چون مستندات دقیق response را در اختیار نداریم، چندین فیلد ممکن را بررسی می‌کنیم.
 */
function wto_wallet_extract_payment_url( $data ) {
	if ( ! is_array( $data ) ) {
		return '';
	}
	$candidates = array( 'paymentUrl', 'payment_url', 'redirectUrl', 'redirect_url', 'gateway_url', 'gatewayUrl', 'url', 'link' );
	$sources    = array( $data );
	if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
		$sources[] = $data['data'];
	}
	if ( isset( $data['result'] ) && is_array( $data['result'] ) ) {
		$sources[] = $data['result'];
	}
	foreach ( $sources as $src ) {
		foreach ( $candidates as $key ) {
			if ( isset( $src[ $key ] ) && is_string( $src[ $key ] ) && filter_var( $src[ $key ], FILTER_VALIDATE_URL ) ) {
				return (string) $src[ $key ];
			}
		}
	}
	// اگر پاسخ خود یک URL ساده باشد (به‌ندرت)
	if ( is_string( $data ) && filter_var( $data, FILTER_VALIDATE_URL ) ) {
		return $data;
	}
	return '';
}

function wto_wallet_extract_error_message( $data ) {
	if ( ! is_array( $data ) ) {
		return '';
	}
	if ( isset( $data['messages'] ) ) {
		return is_array( $data['messages'] ) ? implode( '، ', array_map( 'strval', $data['messages'] ) ) : (string) $data['messages'];
	}
	if ( isset( $data['message'] ) ) {
		return (string) $data['message'];
	}
	if ( isset( $data['error'] ) ) {
		return is_array( $data['error'] ) ? implode( '، ', array_map( 'strval', $data['error'] ) ) : (string) $data['error'];
	}
	return '';
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  Callback notice — وقتی کاربر از درگاه پرداخت برمی‌گردد
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_notices', 'wto_wallet_charge_callback_notice', 5 );
function wto_wallet_charge_callback_notice() {
	if ( ! isset( $_GET['wto_charge_callback'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Cache اعتبار را پاک می‌کنیم تا اعتبار جدید fetch شود.
	$apikey = trim( (string) get_option( 'wto_apikey', '' ) );
	if ( $apikey !== '' ) {
		delete_transient( 'wto_credit_' . md5( $apikey ) );
	}
	?>
	<div class="notice notice-success is-dismissible" style="border-right-color:#10b981;">
		<p>
			<strong style="font-size:14px;">✓ بازگشت از درگاه پرداخت</strong><br>
			اگر پرداخت موفق بوده، اعتبار شما به‌روز شده است. صفحه را تازه‌سازی کنید (F5) تا مبلغ جدید نمایش داده شود.
			در صورت بروز هر مشکل، با پشتیبانی فراز اس‌ام‌اس تماس بگیرید.
		</p>
	</div>
	<?php
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  Hooks: نمایش پنل خرید شارژ روی صفحات مرتبط
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * v3.14.9: پنل خرید شارژ از صفحه تنظیمات افزونه حذف شد — همراه با هشدار
 * بازگشت‌ناپذیری که در آن نمایش داده می‌شد. خرید شارژ همچنان از داشبورد
 * (farazwto-dashboard) و صفحه تنظیمات افزونه پیامک ووکامرس قابل دسترسی است.
 */
// add_action( 'admin_notices', 'wto_wallet_render_on_settings_page', 25 );
function wto_wallet_render_on_settings_page() {
	// intentionally empty — حفظ شده برای backward compat اگر جای دیگری call شده
}

/**
 * نمایش پنل خرید شارژ در صفحه تنظیمات افزونه پیامک ووکامرس (PWSMS)
 * priority=4 تا قبل از connection panel (priority 5) رندر شود.
 */
add_action( 'pwoosms_settings_form_top_sms_main_settings', 'wto_wallet_render_on_pwsms_page', 4 );
function wto_wallet_render_on_pwsms_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wto_render_wallet_recharge_panel();
}
