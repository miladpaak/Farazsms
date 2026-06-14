<?php
/**
 * Gravity Forms → Phonebook Auto-Sync — v3.17.4
 *
 * هر شماره موبایلی که از طریق فرم Gravity وارد می‌شود را خودکار به
 * دفترچه تلفن پنل فراز اس‌ام‌اس می‌فرستد + import یکباره ورودی‌های قبلی.
 *
 * فلوی پیکربندی:
 *  ۱) ادمین وارد صفحه «همگام‌سازی فرم‌ها → دفترچه تلفن» می‌شود
 *  ۲) Form → Phone Field → Target Phonebook را انتخاب می‌کند
 *  ۳) (اختیاری) Name Field را هم انتخاب می‌کند
 *  ۴) با کلیک «ایمپورت سوابق»، تمام entry های قبلی به‌صورت batch به Faraz می‌رود
 *  ۵) از آن به بعد، هر submission جدید خودکار push می‌شود
 *
 * چند فرم مختلف می‌توانند به دفترچه‌های مختلف map شوند — mappings آرایه‌ای است.
 *
 * Performance:
 *  - bulk_upsert_contacts با chunk 500 — برای ۱۰هزار entry حدود ۲۰ API call
 *  - import async از طریق AJAX با progress reporting
 *  - hook submission سبک — فقط دو فیلد را extract می‌کند
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_GF_PB_OPTION = 'wto_gf_phonebook_mappings';

// v3.17.5: standalone submenu حذف شد — این صفحه به‌صورت تب در farazwto-phonebook
// رندر می‌شود (برای کاهش شلوغی منو). تابع wto_gf_pb_render_page() از داخل
// render_phonebook_page() صدا زده می‌شود.

// ============================================================================
// Settings storage
// ============================================================================

function wto_gf_pb_get_mappings() {
	$raw = get_option( WTO_GF_PB_OPTION, array() );
	return is_array( $raw ) ? $raw : array();
}

function wto_gf_pb_save_mappings( $mappings ) {
	update_option( WTO_GF_PB_OPTION, array_values( $mappings ), false );
}

// ============================================================================
// Helper: GF active + API instance
// ============================================================================

function wto_gf_pb_is_gf_active() {
	return class_exists( 'GFAPI' ) || class_exists( 'GFForms' );
}

function wto_gf_pb_get_api() {
	if ( ! class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
		$path = dirname( __FILE__, 2 ) . '/modules/farazsms-next/includes/class-phonebook-api.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
	if ( ! class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
		return null;
	}
	return new FarazSMS_Next_Phonebook_API();
}

// ============================================================================
// Save mapping handler
// ============================================================================

add_action( 'admin_post_wto_gf_pb_save_mapping', 'wto_gf_pb_handle_save_mapping' );
function wto_gf_pb_handle_save_mapping() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_gf_pb_save_mapping' );

	$form_id        = (int) ( $_POST['form_id'] ?? 0 );
	$phone_field_id = sanitize_text_field( $_POST['phone_field_id'] ?? '' );
	$name_field_id  = sanitize_text_field( $_POST['name_field_id'] ?? '' );
	$phonebook_id   = (int) ( $_POST['phonebook_id'] ?? 0 );

	if ( $form_id <= 0 || $phone_field_id === '' || $phonebook_id <= 0 ) {
		wp_safe_redirect( add_query_arg( 'gf_pb_error', rawurlencode( 'اطلاعات ناقص است — فرم، فیلد موبایل، و دفترچه باید انتخاب شوند.' ), wp_get_referer() ) );
		exit;
	}

	$mappings   = wto_gf_pb_get_mappings();
	$mappings[] = array(
		'id'             => uniqid( 'm_' ),
		'form_id'        => $form_id,
		'phone_field_id' => $phone_field_id,
		'name_field_id'  => $name_field_id,
		'phonebook_id'   => $phonebook_id,
		'created_at'     => current_time( 'mysql' ),
		'last_imported'  => '',
		'import_count'   => 0,
	);
	wto_gf_pb_save_mappings( $mappings );

	wp_safe_redirect( add_query_arg( 'gf_pb_saved', '1', wp_get_referer() ) );
	exit;
}

// ============================================================================
// Delete mapping
// ============================================================================

add_action( 'admin_post_wto_gf_pb_delete_mapping', 'wto_gf_pb_handle_delete_mapping' );
function wto_gf_pb_handle_delete_mapping() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_gf_pb_delete_mapping' );

	$id       = sanitize_text_field( $_POST['mapping_id'] ?? '' );
	$mappings = wto_gf_pb_get_mappings();
	$mappings = array_filter( $mappings, function ( $m ) use ( $id ) {
		return ( $m['id'] ?? '' ) !== $id;
	} );
	wto_gf_pb_save_mappings( $mappings );

	wp_safe_redirect( add_query_arg( 'gf_pb_deleted', '1', wp_get_referer() ) );
	exit;
}

// ============================================================================
// One-time historical import — AJAX
// ============================================================================

add_action( 'wp_ajax_wto_gf_pb_import_history', 'wto_gf_pb_ajax_import_history' );
function wto_gf_pb_ajax_import_history() {
	check_ajax_referer( 'wto_gf_pb_import', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ) );
	}
	if ( ! wto_gf_pb_is_gf_active() ) {
		wp_send_json_error( array( 'message' => 'افزونه Gravity Forms فعال نیست.' ) );
	}
	$apikey = get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => 'کلید دسترسی (Api-Key) را در تنظیمات وارد کنید.' ) );
	}

	$id       = sanitize_text_field( $_POST['mapping_id'] ?? '' );
	$mappings = wto_gf_pb_get_mappings();
	$mapping  = null;
	foreach ( $mappings as $m ) {
		if ( ( $m['id'] ?? '' ) === $id ) {
			$mapping = $m;
			break;
		}
	}
	if ( ! $mapping ) {
		wp_send_json_error( array( 'message' => 'Mapping یافت نشد.' ) );
	}

	// خواندن همه entry های فرم
	$form_id        = (int) $mapping['form_id'];
	$phone_field_id = (string) $mapping['phone_field_id'];
	$name_field_id  = (string) ( $mapping['name_field_id'] ?? '' );
	$phonebook_id   = (int) $mapping['phonebook_id'];

	$entries = array();
	try {
		$entries = GFAPI::get_entries(
			$form_id,
			array( 'status' => 'active' ),
			null,
			array( 'offset' => 0, 'page_size' => 5000 )  // سقف ۵هزار entry per request
		);
	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => 'خطا در خواندن entry ها: ' . $e->getMessage() ) );
	}

	if ( ! is_array( $entries ) || empty( $entries ) ) {
		wp_send_json_error( array( 'message' => 'هیچ entry در این فرم یافت نشد.' ) );
	}

	// ساخت contacts array — duplicates توسط Faraz dedup می‌شود (mobile unique)
	$contacts = array();
	$seen_mobiles = array();
	foreach ( $entries as $entry ) {
		$raw_phone = isset( $entry[ $phone_field_id ] ) ? (string) $entry[ $phone_field_id ] : '';
		if ( $raw_phone === '' ) continue;

		$normalized = function_exists( 'wto_normalize_phone' )
			? wto_normalize_phone( $raw_phone ) : $raw_phone;
		if ( ! preg_match( '/^09\d{9}$/', $normalized ) ) continue;
		if ( isset( $seen_mobiles[ $normalized ] ) ) continue;
		$seen_mobiles[ $normalized ] = true;

		$name = $name_field_id !== '' && isset( $entry[ $name_field_id ] )
			? sanitize_text_field( (string) $entry[ $name_field_id ] ) : '';

		$contacts[] = array(
			'name'  => $name,
			'phone' => $normalized,
		);
	}

	if ( empty( $contacts ) ) {
		wp_send_json_error( array( 'message' => 'هیچ شماره موبایل معتبری در entry ها یافت نشد. (فرمت باید 09xxxxxxxxx باشد)' ) );
	}

	// v3.18.0: برای import های بزرگ (>۱۰۰۰ مخاطب) از Action Scheduler استفاده می‌کنیم
	// تا PHP timeout نخوریم. هر chunk به‌صورت یک action جداگانه scheduled می‌شود.
	$total_contacts = count( $contacts );
	$use_async = $total_contacts > 1000 && function_exists( 'wto_async_available' ) && wto_async_available();

	if ( $use_async ) {
		// در chunk های ۵۰۰ تایی به‌صورت async
		$chunks = array_chunk( $contacts, 500 );
		foreach ( $chunks as $chunk ) {
			wto_async_dispatch( 'wto_gf_pb_push_chunk', array( $phonebook_id, $chunk, $apikey ) );
		}

		// به‌روز کردن mapping
		foreach ( $mappings as $i => $m ) {
			if ( ( $m['id'] ?? '' ) === $id ) {
				$mappings[ $i ]['last_imported'] = current_time( 'mysql' );
				$mappings[ $i ]['import_count']  = $total_contacts;
				break;
			}
		}
		wto_gf_pb_save_mappings( $mappings );

		wp_send_json_success( array(
			'message' => sprintf( '✓ %d مخاطب در صف ارسال (background) قرار گرفت. در عرض چند دقیقه کامل می‌شود.', $total_contacts ),
			'count'   => $total_contacts,
			'total'   => count( $entries ),
			'async'   => true,
		) );
	}

	// Push to Faraz با bulk_upsert (sync — برای کم تا متوسط)
	$api = wto_gf_pb_get_api();
	if ( ! $api ) {
		wp_send_json_error( array( 'message' => 'ماژول phonebook API در دسترس نیست.' ) );
	}

	$result = $api->bulk_upsert_contacts( $phonebook_id, $contacts, $apikey, 500 );

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		$em = is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : 'خطای نامشخص در ارتباط با سرور';
		wp_send_json_error( array( 'message' => $em ) );
	}

	// به‌روز کردن mapping با شمارش
	foreach ( $mappings as $i => $m ) {
		if ( ( $m['id'] ?? '' ) === $id ) {
			$mappings[ $i ]['last_imported'] = current_time( 'mysql' );
			$mappings[ $i ]['import_count']  = $total_contacts;
			break;
		}
	}
	wto_gf_pb_save_mappings( $mappings );

	wp_send_json_success( array(
		'message'  => sprintf( '✓ %d مخاطب با موفقیت در دفترچه تلفن ذخیره شد.', $total_contacts ),
		'count'    => $total_contacts,
		'total'    => count( $entries ),
	) );
}

/**
 * v3.18.0: callback که توسط Action Scheduler صدا زده می‌شود — push یک chunk به Faraz.
 * Function-name (نه closure) لازم است تا AS بتواند serialize کند.
 */
function wto_gf_pb_push_chunk( $phonebook_id, $contacts, $apikey ) {
	$api = wto_gf_pb_get_api();
	if ( ! $api ) return;
	$api->bulk_upsert_contacts( (int) $phonebook_id, $contacts, $apikey, 500 );
}

// ============================================================================
// On every new submission — auto push
// ============================================================================

add_action( 'gform_after_submission', 'wto_gf_pb_on_submission', 10, 2 );
function wto_gf_pb_on_submission( $entry, $form ) {
	$form_id = (int) ( $form['id'] ?? 0 );
	if ( $form_id <= 0 ) return;

	$mappings = wto_gf_pb_get_mappings();
	if ( empty( $mappings ) ) return;

	$apikey = get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) return;

	$api = wto_gf_pb_get_api();
	if ( ! $api ) return;

	foreach ( $mappings as $m ) {
		if ( (int) ( $m['form_id'] ?? 0 ) !== $form_id ) continue;

		$phone_field_id = (string) ( $m['phone_field_id'] ?? '' );
		$name_field_id  = (string) ( $m['name_field_id'] ?? '' );
		$phonebook_id   = (int) ( $m['phonebook_id'] ?? 0 );
		if ( $phone_field_id === '' || $phonebook_id <= 0 ) continue;

		$raw_phone = isset( $entry[ $phone_field_id ] ) ? (string) $entry[ $phone_field_id ] : '';
		if ( $raw_phone === '' ) continue;

		$normalized = function_exists( 'wto_normalize_phone' )
			? wto_normalize_phone( $raw_phone ) : $raw_phone;
		if ( ! preg_match( '/^09\d{9}$/', $normalized ) ) continue;

		$name = $name_field_id !== '' && isset( $entry[ $name_field_id ] )
			? sanitize_text_field( (string) $entry[ $name_field_id ] ) : '';

		// تک contact — استفاده از add_contact بهتر است (less overhead than bulk)
		$api->add_contact( $phonebook_id, $name, $normalized, $apikey );
	}
}

// ============================================================================
// Render admin page — قابل استفاده هم به‌صورت standalone، هم embed در phonebook
// ============================================================================

/**
 * تابع اصلی render — وقتی به‌صورت standalone صدا زده شود، wrapper کامل می‌سازد.
 */
function wto_gf_pb_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$apikey = get_option( 'wto_apikey', '' );
	?>
	<section class="wrapper" style="direction:rtl;">
		<div id="wto_header">
			<div><a href="https://farazsms.com" target="_blank"><img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt=""></a></div>
			<?php if ( $apikey !== '' && function_exists( 'wto_get_credit' ) ) : $credit = wto_get_credit(); ?>
				<div id="wto_account_info"><div class="wto_credit_amount"><span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?><span> تومان</span></div><?php if ( function_exists( 'wto_render_profile_block' ) ) wto_render_profile_block(); ?></div>
			<?php endif; ?>
		</div>
		<?php wto_gf_pb_render_content(); ?>
	</section>
	<?php
}

/**
 * v3.17.5: محتوای صفحه (بدون wrapper) — برای embed در صفحه phonebook.
 */
function wto_gf_pb_render_content() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$apikey   = get_option( 'wto_apikey', '' );
	$mappings = wto_gf_pb_get_mappings();
	$gf_ok    = wto_gf_pb_is_gf_active();
	$api_ok   = $apikey !== '';

	// لیست فرم‌های GF
	$forms = array();
	if ( $gf_ok && class_exists( 'GFAPI' ) ) {
		$all = GFAPI::get_forms();
		foreach ( $all as $f ) {
			$forms[ (int) $f['id'] ] = array(
				'id'     => (int) $f['id'],
				'title'  => (string) ( $f['title'] ?? 'بدون نام' ),
				'fields' => isset( $f['fields'] ) && is_array( $f['fields'] ) ? $f['fields'] : array(),
			);
		}
	}

	// لیست دفترچه‌های فراز
	$phonebooks = array();
	if ( $api_ok ) {
		$api = wto_gf_pb_get_api();
		if ( $api ) {
			$pb_resp = $api->get_phonebooks( $apikey );
			if ( is_array( $pb_resp ) && ! empty( $pb_resp['success'] ) && ! empty( $pb_resp['phonebooks'] ) ) {
				$phonebooks = $pb_resp['phonebooks'];
			}
		}
	}
	?>
		<!-- Hero -->
		<div style="background:linear-gradient(135deg, #0e7490 0%, #06b6d4 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:18px; box-shadow:0 8px 24px rgba(14,116,144,0.18); position:relative; overflow:hidden;">
			<div style="position:absolute; top:-10px; left:-12px; font-size:120px; opacity:0.1; line-height:1;">📋</div>
			<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap; position:relative; z-index:2;">
				<div style="font-size:48px;">🔄</div>
				<div style="flex:1; min-width:220px;">
					<h2 style="margin:0 0 6px; font-size:20px; font-weight:800;">همگام‌سازی Gravity Forms → دفترچه تلفن</h2>
					<div style="font-size:13px; opacity:0.94; line-height:1.7;">
						هر فرم را به یک دفترچه تلفن متفاوت map کنید. ورودی‌های قبلی یکباره import می‌شوند،
						و submission های آینده به‌صورت خودکار به Faraz می‌رود.
					</div>
				</div>
			</div>
		</div>

		<?php if ( isset( $_GET['gf_pb_saved'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px;">✓ Mapping ذخیره شد.</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['gf_pb_deleted'] ) ) : ?>
			<div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; margin-bottom:14px;">⚠ Mapping حذف شد.</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['gf_pb_error'] ) ) : ?>
			<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:14px;">✗ <?php echo esc_html( rawurldecode( $_GET['gf_pb_error'] ) ); ?></div>
		<?php endif; ?>

		<?php if ( ! $gf_ok ) : ?>
			<div style="background:#fef3c7; border:1.5px solid #fde68a; color:#92400e; padding:18px 22px; border-radius:12px; margin-bottom:18px;">
				⚠ افزونه Gravity Forms روی این سایت نصب یا فعال نیست. ابتدا آن را فعال کنید.
			</div>
		<?php endif; ?>

		<?php if ( ! $api_ok ) : ?>
			<div style="background:#fef3c7; border:1.5px solid #fde68a; color:#92400e; padding:18px 22px; border-radius:12px; margin-bottom:18px;">
				⚠ کلید دسترسی (Api-Key) در <a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>">تنظیمات افزونه</a> وارد نشده است.
			</div>
		<?php endif; ?>

		<!-- لیست mapping های موجود -->
		<?php if ( ! empty( $mappings ) ) : ?>
			<h3 style="margin:6px 0 12px; font-size:15px; color:#0f172a; font-weight:700;">📋 سینک‌های فعال</h3>
			<div style="background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; overflow:hidden; margin-bottom:24px;">
				<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">فرم</th>
							<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">فیلد موبایل</th>
							<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">دفترچه مقصد</th>
							<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">آخرین ایمپورت</th>
							<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">عملیات</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mappings as $m ) :
							$form_title = isset( $forms[ $m['form_id'] ] ) ? $forms[ $m['form_id'] ]['title'] : 'فرم #' . (int) $m['form_id'];
							$pb_name = '—';
							foreach ( $phonebooks as $pb ) {
								if ( (int) ( $pb['id'] ?? 0 ) === (int) $m['phonebook_id'] ) { $pb_name = $pb['name'] ?? ''; break; }
							}
							?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:11px 16px;"><strong><?php echo esc_html( $form_title ); ?></strong> <span style="color:#94a3b8;">#<?php echo (int) $m['form_id']; ?></span></td>
								<td style="padding:11px 16px; font-family:Menlo,Consolas,monospace; color:#475569; direction:ltr; text-align:right;">field #<?php echo esc_html( $m['phone_field_id'] ); ?></td>
								<td style="padding:11px 16px;"><?php echo esc_html( $pb_name ); ?> <span style="color:#94a3b8;">#<?php echo (int) $m['phonebook_id']; ?></span></td>
								<td style="padding:11px 16px; color:#64748b;">
									<?php if ( ! empty( $m['last_imported'] ) ) : ?>
										<?php echo esc_html( $m['last_imported'] ); ?>
										<br><span style="font-size:11px; color:#16a34a;">(<?php echo (int) $m['import_count']; ?> مخاطب)</span>
									<?php else : ?>
										<span style="color:#94a3b8;">هنوز import نشده</span>
									<?php endif; ?>
								</td>
								<td style="padding:11px 16px;">
									<button type="button" class="wto-gf-pb-import-btn" data-mapping-id="<?php echo esc_attr( $m['id'] ); ?>" style="background:#16a34a; color:#fff; border:none; padding:7px 14px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; margin-left:4px;">
										🚀 ایمپورت سوابق
									</button>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('این mapping حذف شود؟');">
										<input type="hidden" name="action" value="wto_gf_pb_delete_mapping">
										<input type="hidden" name="mapping_id" value="<?php echo esc_attr( $m['id'] ); ?>">
										<?php wp_nonce_field( 'wto_gf_pb_delete_mapping' ); ?>
										<button type="submit" style="background:#fff; color:#dc2626; border:1px solid #fecaca; padding:7px 12px; border-radius:6px; font-size:11.5px; font-weight:600; cursor:pointer;">✗ حذف</button>
									</form>
									<div class="wto-gf-pb-import-msg" style="display:none; margin-top:8px; font-size:11.5px;"></div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- فرم اضافه کردن mapping جدید -->
		<?php if ( $gf_ok && $api_ok ) : ?>
			<h3 style="margin:18px 0 12px; font-size:15px; color:#0f172a; font-weight:700;">➕ اضافه کردن سینک جدید</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wto-gf-pb-form" style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:22px 26px;">
				<input type="hidden" name="action" value="wto_gf_pb_save_mapping">
				<?php wp_nonce_field( 'wto_gf_pb_save_mapping' ); ?>

				<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:14px;">
					<label>
						<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۱) انتخاب فرم</span>
						<select name="form_id" id="wto-gf-pb-form-id" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
							<option value="">— انتخاب فرم —</option>
							<?php foreach ( $forms as $f ) : ?>
								<option value="<?php echo esc_attr( $f['id'] ); ?>"><?php echo esc_html( $f['title'] ); ?> (#<?php echo (int) $f['id']; ?>)</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label>
						<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۲) فیلد شماره موبایل</span>
						<select name="phone_field_id" id="wto-gf-pb-phone-field" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
							<option value="">— ابتدا فرم را انتخاب کنید —</option>
						</select>
					</label>

					<label>
						<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۳) فیلد نام (اختیاری)</span>
						<select name="name_field_id" id="wto-gf-pb-name-field" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
							<option value="">— بدون نام —</option>
						</select>
					</label>

					<label>
						<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۴) دفترچه تلفن مقصد</span>
						<select name="phonebook_id" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
							<option value="">— انتخاب دفترچه —</option>
							<?php foreach ( $phonebooks as $pb ) : ?>
								<option value="<?php echo esc_attr( $pb['id'] ?? '' ); ?>"><?php echo esc_html( $pb['name'] ?? '' ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( empty( $phonebooks ) ) : ?>
							<small style="display:block; margin-top:4px; color:#dc2626; font-size:11px;">⚠ هیچ دفترچه تلفنی در پنل فراز شما یافت نشد. ابتدا یک دفترچه بسازید.</small>
						<?php endif; ?>
					</label>
				</div>

				<div style="display:flex; justify-content:flex-end;">
					<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:10px 24px; border-radius:8px; font-size:13.5px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(67,56,202,0.22);">
						💾 ذخیره سینک
					</button>
				</div>
			</form>

			<!-- داده‌های فرم‌ها برای JS -->
			<script type="application/json" id="wto-gf-pb-forms-data"><?php echo wp_json_encode( $forms, JSON_UNESCAPED_UNICODE ); ?></script>
			<script>
			(function($){
				var formsData = {};
				try {
					formsData = JSON.parse(document.getElementById('wto-gf-pb-forms-data').textContent);
				} catch (e) {}

				$('#wto-gf-pb-form-id').on('change', function(){
					var fid = $(this).val();
					var $phone = $('#wto-gf-pb-phone-field');
					var $name = $('#wto-gf-pb-name-field');
					$phone.html('<option value="">— انتخاب فیلد —</option>');
					$name.html('<option value="">— بدون نام —</option>');
					if (!fid || !formsData[fid]) return;
					var fields = formsData[fid].fields || [];
					fields.forEach(function(field){
						var label = field.label || ('field #' + field.id);
						var typ = field.type || '';
						$phone.append('<option value="' + field.id + '">' + label + ' [' + typ + ']</option>');
						$name.append('<option value="' + field.id + '">' + label + ' [' + typ + ']</option>');
					});
				});

				// Import history button
				$('.wto-gf-pb-import-btn').on('click', function(){
					var $btn = $(this);
					var mappingId = $btn.data('mapping-id');
					var $msg = $btn.closest('td').find('.wto-gf-pb-import-msg');
					if (!confirm('شروع ایمپورت تمام ورودی‌های قبلی این فرم به دفترچه؟ این فرایند ممکن است چند دقیقه طول بکشد.')) return;
					$btn.prop('disabled', true).text('در حال ایمپورت...');
					$msg.hide().removeClass('success error');
					$.post(ajaxurl, {
						action: 'wto_gf_pb_import_history',
						nonce: '<?php echo esc_js( wp_create_nonce( 'wto_gf_pb_import' ) ); ?>',
						mapping_id: mappingId
					}, function(res){
						if (res && res.success) {
							$msg.css({background:'#dcfce7', color:'#166534', padding:'6px 10px', borderRadius:'5px', border:'1px solid #86efac'})
								.text(res.data.message).show();
							setTimeout(function(){ window.location.reload(); }, 1500);
						} else {
							var em = (res && res.data && res.data.message) || 'خطا';
							$msg.css({background:'#fef2f2', color:'#991b1b', padding:'6px 10px', borderRadius:'5px', border:'1px solid #fecaca'})
								.text('✗ ' + em).show();
							$btn.prop('disabled', false).text('🚀 ایمپورت سوابق');
						}
					}).fail(function(){
						$msg.css({background:'#fef2f2', color:'#991b1b', padding:'6px 10px', borderRadius:'5px', border:'1px solid #fecaca'})
							.text('✗ خطای ارتباط با سرور').show();
						$btn.prop('disabled', false).text('🚀 ایمپورت سوابق');
					});
				});
			})(jQuery);
			</script>
		<?php endif; ?>

		<!-- توضیحات -->
		<details style="margin-top:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; font-size:12px; color:#475569; line-height:1.8;">
			<summary style="cursor:pointer; font-weight:600; color:#0f172a; font-size:12.5px;">🔍 چگونه کار می‌کند؟</summary>
			<div style="margin-top:10px;">
				<strong>۱) Mapping بسازید:</strong> فرم Gravity → فیلد موبایل → دفترچه تلفن مقصد.
				<br>
				<strong>۲) ایمپورت سوابق:</strong> دکمه «🚀 ایمپورت سوابق» تمام entry های موجود (تا ۵۰۰۰) را در یک batch ارسال می‌کند.
				<br>
				<strong>۳) سینک خودکار:</strong> از این به بعد، هر submission جدید روی این فرم خودکار به دفترچه مقصد اضافه می‌شود.
				<br>
				<strong>۴) چند فرم، چند دفترچه:</strong> می‌توانید چندین mapping داشته باشید — هر فرم با دفترچه‌ای متفاوت.
				<br>
				<strong>Performance:</strong> bulk upsert با chunk 500 — برای ۱۰هزار مخاطب حدود ۲۰ API call.
				<br>
				<strong>نرمالایز:</strong> همه‌ی شماره‌ها به فرمت <code style="background:#fff; padding:1px 6px; border-radius:4px; direction:ltr;">09xxxxxxxxx</code> تبدیل می‌شوند. شماره‌های نامعتبر skip می‌شوند.
			</div>
		</details>
	<?php
}
