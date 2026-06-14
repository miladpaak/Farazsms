<?php
/**
 * Custom user_meta → Phonebook — v3.17.6
 *
 * بسیاری از افزونه‌های وردپرس شماره موبایل کاربر را در user_meta ذخیره می‌کنند
 * (مثل Digits, Persian Woocommerce SMS, و بسیاری دیگر). این ماژول به ادمین
 * اجازه می‌دهد یک meta_key مشخص را انتخاب کند و:
 *   ۱) همه‌ی کاربران موجود با آن meta_key را در یک batch به دفترچه فراز بفرستد
 *   ۲) از این به بعد، هر بار user_meta تغییر کند، خودکار push شود
 *
 * چند mapping قابل ذخیره است — هر یک meta_key مختلف به دفترچه‌ی متفاوت.
 *
 * Performance: bulk_upsert با chunk 500 — مشابه GF sync.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_CUSTOM_META_PB_OPTION = 'wto_custom_meta_phonebook_mappings';

// ============================================================================
// Storage
// ============================================================================

function wto_custom_meta_pb_get_mappings() {
	$raw = get_option( WTO_CUSTOM_META_PB_OPTION, array() );
	return is_array( $raw ) ? $raw : array();
}

function wto_custom_meta_pb_save_mappings( $mappings ) {
	update_option( WTO_CUSTOM_META_PB_OPTION, array_values( $mappings ), false );
}

function wto_custom_meta_pb_get_api() {
	if ( ! class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
		$path = dirname( __FILE__, 2 ) . '/modules/farazsms-next/includes/class-phonebook-api.php';
		if ( file_exists( $path ) ) require_once $path;
	}
	return class_exists( 'FarazSMS_Next_Phonebook_API' ) ? new FarazSMS_Next_Phonebook_API() : null;
}

// ============================================================================
// Save mapping handler
// ============================================================================

add_action( 'admin_post_wto_custom_meta_pb_save_mapping', 'wto_custom_meta_pb_handle_save_mapping' );
function wto_custom_meta_pb_handle_save_mapping() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_custom_meta_pb_save_mapping' );

	$meta_key       = sanitize_text_field( $_POST['meta_key'] ?? '' );
	$name_meta_key  = sanitize_text_field( $_POST['name_meta_key'] ?? '' );
	$phonebook_id   = (int) ( $_POST['phonebook_id'] ?? 0 );

	if ( $meta_key === '' || $phonebook_id <= 0 ) {
		wp_safe_redirect( add_query_arg( 'cm_pb_error', rawurlencode( 'کلید متا و دفترچه باید مشخص شوند.' ), wp_get_referer() ) );
		exit;
	}

	$mappings = wto_custom_meta_pb_get_mappings();
	$mappings[] = array(
		'id'             => uniqid( 'cm_' ),
		'meta_key'       => $meta_key,
		'name_meta_key'  => $name_meta_key,
		'phonebook_id'   => $phonebook_id,
		'created_at'     => current_time( 'mysql' ),
		'last_imported'  => '',
		'import_count'   => 0,
	);
	wto_custom_meta_pb_save_mappings( $mappings );

	wp_safe_redirect( add_query_arg( 'cm_pb_saved', '1', wp_get_referer() ) );
	exit;
}

// Delete mapping
add_action( 'admin_post_wto_custom_meta_pb_delete', 'wto_custom_meta_pb_handle_delete' );
function wto_custom_meta_pb_handle_delete() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_custom_meta_pb_delete' );
	$id = sanitize_text_field( $_POST['mapping_id'] ?? '' );
	$mappings = wto_custom_meta_pb_get_mappings();
	$mappings = array_filter( $mappings, function ( $m ) use ( $id ) {
		return ( $m['id'] ?? '' ) !== $id;
	} );
	wto_custom_meta_pb_save_mappings( $mappings );
	wp_safe_redirect( add_query_arg( 'cm_pb_deleted', '1', wp_get_referer() ) );
	exit;
}

// ============================================================================
// Import history — AJAX
// ============================================================================

add_action( 'wp_ajax_wto_custom_meta_pb_import', 'wto_custom_meta_pb_ajax_import' );
function wto_custom_meta_pb_ajax_import() {
	check_ajax_referer( 'wto_custom_meta_pb_import', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ) );

	$apikey = get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) wp_send_json_error( array( 'message' => 'کلید دسترسی وارد نشده' ) );

	$id = sanitize_text_field( $_POST['mapping_id'] ?? '' );
	$mappings = wto_custom_meta_pb_get_mappings();
	$mapping = null;
	foreach ( $mappings as $m ) {
		if ( ( $m['id'] ?? '' ) === $id ) { $mapping = $m; break; }
	}
	if ( ! $mapping ) wp_send_json_error( array( 'message' => 'Mapping یافت نشد' ) );

	$meta_key      = $mapping['meta_key'];
	$name_meta_key = $mapping['name_meta_key'] ?? '';
	$phonebook_id  = (int) $mapping['phonebook_id'];

	// query users with this meta key
	global $wpdb;
	$users = $wpdb->get_results( $wpdb->prepare(
		"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
		$meta_key
	) );

	if ( empty( $users ) ) wp_send_json_error( array( 'message' => 'هیچ کاربری با این متا کلید یافت نشد.' ) );

	$contacts = array();
	$seen = array();
	foreach ( $users as $row ) {
		$raw_phone = (string) $row->meta_value;
		$normalized = function_exists( 'wto_normalize_phone' ) ? wto_normalize_phone( $raw_phone ) : $raw_phone;
		if ( ! preg_match( '/^09\d{9}$/', $normalized ) ) continue;
		if ( isset( $seen[ $normalized ] ) ) continue;
		$seen[ $normalized ] = true;

		$name = '';
		if ( $name_meta_key !== '' ) {
			$name = (string) get_user_meta( (int) $row->user_id, $name_meta_key, true );
		}
		if ( $name === '' ) {
			$u = get_userdata( (int) $row->user_id );
			if ( $u ) $name = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name;
		}

		$contacts[] = array(
			'name'  => sanitize_text_field( $name ),
			'phone' => $normalized,
		);
	}

	if ( empty( $contacts ) ) wp_send_json_error( array( 'message' => 'هیچ شماره موبایل معتبری یافت نشد.' ) );

	$api = wto_custom_meta_pb_get_api();
	if ( ! $api ) wp_send_json_error( array( 'message' => 'API در دسترس نیست' ) );

	$result = $api->bulk_upsert_contacts( $phonebook_id, $contacts, $apikey, 500 );
	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => $result['message'] ?? 'خطای نامشخص' ) );
	}

	// Update last_imported
	foreach ( $mappings as $i => $m ) {
		if ( ( $m['id'] ?? '' ) === $id ) {
			$mappings[ $i ]['last_imported'] = current_time( 'mysql' );
			$mappings[ $i ]['import_count']  = count( $contacts );
			break;
		}
	}
	wto_custom_meta_pb_save_mappings( $mappings );

	wp_send_json_success( array(
		'message' => sprintf( '✓ %d مخاطب به دفترچه اضافه شد.', count( $contacts ) ),
		'count'   => count( $contacts ),
	) );
}

// ============================================================================
// Auto-sync on user_meta update — for ongoing capture
// ============================================================================

add_action( 'updated_user_meta', 'wto_custom_meta_pb_on_meta_update', 10, 4 );
add_action( 'added_user_meta', 'wto_custom_meta_pb_on_meta_update', 10, 4 );
function wto_custom_meta_pb_on_meta_update( $meta_id, $user_id, $meta_key, $meta_value ) {
	$mappings = wto_custom_meta_pb_get_mappings();
	if ( empty( $mappings ) ) return;

	$apikey = get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) return;

	foreach ( $mappings as $m ) {
		if ( ( $m['meta_key'] ?? '' ) !== $meta_key ) continue;
		$phonebook_id = (int) ( $m['phonebook_id'] ?? 0 );
		if ( $phonebook_id <= 0 ) continue;

		$normalized = function_exists( 'wto_normalize_phone' ) ? wto_normalize_phone( (string) $meta_value ) : (string) $meta_value;
		if ( ! preg_match( '/^09\d{9}$/', $normalized ) ) continue;

		$name_meta_key = $m['name_meta_key'] ?? '';
		$name = '';
		if ( $name_meta_key !== '' ) {
			$name = (string) get_user_meta( (int) $user_id, $name_meta_key, true );
		}
		if ( $name === '' ) {
			$u = get_userdata( (int) $user_id );
			if ( $u ) $name = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name;
		}

		$api = wto_custom_meta_pb_get_api();
		if ( $api ) $api->add_contact( $phonebook_id, sanitize_text_field( $name ), $normalized, $apikey );
	}
}

// ============================================================================
// Render content (embedded in phonebook tabs)
// ============================================================================

function wto_custom_meta_pb_render_content() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$apikey   = get_option( 'wto_apikey', '' );
	$mappings = wto_custom_meta_pb_get_mappings();

	// لیست دفترچه‌های فراز
	$phonebooks = array();
	if ( $apikey !== '' ) {
		$api = wto_custom_meta_pb_get_api();
		if ( $api ) {
			$pb_resp = $api->get_phonebooks( $apikey );
			if ( is_array( $pb_resp ) && ! empty( $pb_resp['success'] ) && ! empty( $pb_resp['phonebooks'] ) ) {
				$phonebooks = $pb_resp['phonebooks'];
			}
		}
	}

	// Common meta key suggestions
	$common_keys = array(
		'digt_countrycode_mobile' => 'افزونه Digits — شماره با کد کشور',
		'digt_mobile'             => 'افزونه Digits — شماره معمولی',
		'digits_phone'             => 'افزونه Digits (قدیمی)',
		'billing_phone'            => 'WooCommerce — موبایل آدرس صورتحساب',
		'persianwc_phone'          => 'افزونه پیامک ووکامرس',
		'mobile'                   => 'فیلد عمومی mobile',
		'phone'                    => 'فیلد عمومی phone',
		'user_mobile'              => 'فیلد عمومی user_mobile',
	);
	?>
	<!-- Hero -->
	<div style="background:linear-gradient(135deg, #4338ca 0%, #7c3aed 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:18px; box-shadow:0 8px 24px rgba(124,58,237,0.18); position:relative; overflow:hidden; direction:rtl;">
		<div style="position:absolute; top:-10px; left:-12px; font-size:120px; opacity:0.1; line-height:1;">🔧</div>
		<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap; position:relative; z-index:2;">
			<div style="font-size:48px;">🔧</div>
			<div style="flex:1; min-width:220px;">
				<h2 style="margin:0 0 6px; font-size:20px; font-weight:800;">فیلد متا اختصاصی → دفترچه تلفن</h2>
				<div style="font-size:13px; opacity:0.94; line-height:1.7;">
					اگر شماره موبایل کاربران شما توسط افزونه‌ی دیگری در user_meta ذخیره شده، اینجا meta_key آن را وارد کنید
					تا تمام شماره‌ها در دفترچه تلفن فراز ذخیره شوند.
				</div>
			</div>
		</div>
	</div>

	<!-- توضیح مفهومی -->
	<div style="background:#fef9c3; border:1.5px solid #fde047; border-radius:12px; padding:14px 18px; margin-bottom:18px; direction:rtl;">
		<h3 style="margin:0 0 10px; font-size:14px; color:#713f12; font-weight:700;">📖 «فیلد متا اختصاصی» چیست؟</h3>
		<p style="margin:0 0 8px; color:#854d0e; font-size:12.5px; line-height:1.8;">
			در وردپرس، هر کاربر می‌تواند فیلدهای اضافی داشته باشد که در جدول
			<code style="background:#fff; padding:2px 7px; border-radius:4px; direction:ltr; display:inline-block; font-family:Menlo,Consolas,monospace;">wp_usermeta</code>
			ذخیره می‌شوند. این فیلدها <strong>کلید (key)</strong> و <strong>مقدار (value)</strong> دارند.
		</p>
		<p style="margin:0 0 8px; color:#854d0e; font-size:12.5px; line-height:1.8;">
			خیلی از افزونه‌های وردپرس شماره موبایل را در یک کلید مخصوص ذخیره می‌کنند. مثلاً:
		</p>
		<ul style="margin:6px 0; padding-right:24px; color:#854d0e; font-size:12.5px; line-height:2;">
			<li>افزونه <strong>Digits</strong>: کلید <code style="background:#fff; padding:1px 6px; border-radius:4px; direction:ltr;">digt_countrycode_mobile</code></li>
			<li>افزونه <strong>پیامک حرفه‌ای ووکامرس</strong>: کلید <code style="background:#fff; padding:1px 6px; border-radius:4px; direction:ltr;">billing_phone</code></li>
			<li>افزونه‌های دیگر معمولاً: <code style="background:#fff; padding:1px 6px; border-radius:4px; direction:ltr;">mobile</code> یا <code style="background:#fff; padding:1px 6px; border-radius:4px; direction:ltr;">phone</code></li>
		</ul>
		<p style="margin:8px 0 0; color:#854d0e; font-size:12.5px; line-height:1.8;">
			برای پیدا کردن کلید دقیق افزونه‌ی خود: یک کاربر را در پیشخوان وردپرس → کاربران ویرایش کنید و meta key شماره موبایل را از URL یا کد PHP افزونه پیدا کنید. اگر مطمئن نیستید با پشتیبانی افزونه فراز اس‌ام‌اس تماس بگیرید.
		</p>
	</div>

	<?php if ( isset( $_GET['cm_pb_saved'] ) ) : ?>
		<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✓ Mapping ذخیره شد.</div>
	<?php endif; ?>
	<?php if ( isset( $_GET['cm_pb_deleted'] ) ) : ?>
		<div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">⚠ Mapping حذف شد.</div>
	<?php endif; ?>
	<?php if ( isset( $_GET['cm_pb_error'] ) ) : ?>
		<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✗ <?php echo esc_html( rawurldecode( $_GET['cm_pb_error'] ) ); ?></div>
	<?php endif; ?>

	<?php if ( $apikey === '' ) : ?>
		<div style="background:#fef3c7; border:1.5px solid #fde68a; color:#92400e; padding:18px 22px; border-radius:12px; margin-bottom:18px; direction:rtl;">
			⚠ کلید دسترسی در <a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>">تنظیمات افزونه</a> وارد نشده.
		</div>
	<?php endif; ?>

	<!-- لیست mapping ها -->
	<?php if ( ! empty( $mappings ) ) : ?>
		<h3 style="margin:6px 0 12px; font-size:15px; color:#0f172a; font-weight:700; direction:rtl;">📋 mapping های فعال</h3>
		<div style="background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; overflow:hidden; margin-bottom:24px; direction:rtl;">
			<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
				<thead style="background:#f8fafc;">
					<tr>
						<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">کلید متا</th>
						<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">دفترچه مقصد</th>
						<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">آخرین ایمپورت</th>
						<th style="text-align:right; padding:12px 16px; border-bottom:1px solid #e5e7eb; font-weight:700;">عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $mappings as $m ) :
						$pb_name = '—';
						foreach ( $phonebooks as $pb ) {
							if ( (int) ( $pb['id'] ?? 0 ) === (int) $m['phonebook_id'] ) { $pb_name = $pb['name'] ?? ''; break; }
						}
						?>
						<tr style="border-bottom:1px solid #f1f5f9;">
							<td style="padding:11px 16px; font-family:Menlo,Consolas,monospace; direction:ltr; text-align:right;"><?php echo esc_html( $m['meta_key'] ); ?></td>
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
								<button type="button" class="wto-cm-pb-import-btn" data-id="<?php echo esc_attr( $m['id'] ); ?>" style="background:#16a34a; color:#fff; border:none; padding:7px 14px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; margin-left:4px;">🚀 ایمپورت</button>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('حذف این mapping؟');">
									<input type="hidden" name="action" value="wto_custom_meta_pb_delete">
									<input type="hidden" name="mapping_id" value="<?php echo esc_attr( $m['id'] ); ?>">
									<?php wp_nonce_field( 'wto_custom_meta_pb_delete' ); ?>
									<button type="submit" style="background:#fff; color:#dc2626; border:1px solid #fecaca; padding:7px 12px; border-radius:6px; font-size:11.5px; font-weight:600; cursor:pointer;">✗ حذف</button>
								</form>
								<div class="wto-cm-pb-msg" style="display:none; margin-top:8px; font-size:11.5px;"></div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<!-- فرم mapping جدید -->
	<?php if ( $apikey !== '' ) : ?>
		<h3 style="margin:18px 0 12px; font-size:15px; color:#0f172a; font-weight:700; direction:rtl;">➕ اضافه کردن mapping جدید</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:22px 26px; direction:rtl;">
			<input type="hidden" name="action" value="wto_custom_meta_pb_save_mapping">
			<?php wp_nonce_field( 'wto_custom_meta_pb_save_mapping' ); ?>

			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:14px;">
				<label>
					<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۱) کلید متا (meta_key) شماره موبایل</span>
					<input type="text" name="meta_key" required list="wto-cm-suggestions" placeholder="مثال: digt_countrycode_mobile" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace;">
					<datalist id="wto-cm-suggestions">
						<?php foreach ( $common_keys as $k => $desc ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_attr( $desc ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<small style="display:block; margin-top:4px; color:#94a3b8; font-size:11px;">می‌توانید از پیشنهادها انتخاب یا کلید دلخواه وارد کنید.</small>
				</label>

				<label>
					<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۲) کلید متا نام (اختیاری)</span>
					<input type="text" name="name_meta_key" placeholder="مثال: first_name" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace;">
					<small style="display:block; margin-top:4px; color:#94a3b8; font-size:11px;">اگر خالی، از first_name + last_name کاربر استفاده می‌شود.</small>
				</label>

				<label>
					<span style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">۳) دفترچه تلفن مقصد</span>
					<select name="phonebook_id" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
						<option value="">— انتخاب دفترچه —</option>
						<?php foreach ( $phonebooks as $pb ) : ?>
							<option value="<?php echo esc_attr( $pb['id'] ?? '' ); ?>"><?php echo esc_html( $pb['name'] ?? '' ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( empty( $phonebooks ) ) : ?>
						<small style="display:block; margin-top:4px; color:#dc2626; font-size:11px;">⚠ ابتدا یک دفترچه در پنل فراز بسازید.</small>
					<?php endif; ?>
				</label>
			</div>

			<div style="display:flex; justify-content:flex-end;">
				<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:10px 24px; border-radius:8px; font-size:13.5px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(67,56,202,0.22);">
					💾 ذخیره mapping
				</button>
			</div>
		</form>

		<script>
		(function($){
			$('.wto-cm-pb-import-btn').on('click', function(){
				var $btn = $(this);
				var id = $btn.data('id');
				var $msg = $btn.closest('td').find('.wto-cm-pb-msg');
				if (!confirm('شروع ایمپورت همه کاربرانی که این متا کلید را دارند؟')) return;
				$btn.prop('disabled', true).text('در حال ایمپورت...');
				$msg.hide();
				$.post(ajaxurl, {
					action: 'wto_custom_meta_pb_import',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wto_custom_meta_pb_import' ) ); ?>',
					mapping_id: id
				}, function(res){
					if (res && res.success) {
						$msg.css({background:'#dcfce7', color:'#166534', padding:'6px 10px', borderRadius:'5px', border:'1px solid #86efac'})
							.text(res.data.message).show();
						setTimeout(function(){ window.location.reload(); }, 1500);
					} else {
						var em = (res && res.data && res.data.message) || 'خطا';
						$msg.css({background:'#fef2f2', color:'#991b1b', padding:'6px 10px', borderRadius:'5px', border:'1px solid #fecaca'})
							.text('✗ ' + em).show();
						$btn.prop('disabled', false).text('🚀 ایمپورت');
					}
				}).fail(function(){
					$msg.css({background:'#fef2f2', color:'#991b1b', padding:'6px 10px', borderRadius:'5px'})
						.text('✗ خطای ارتباط با سرور').show();
					$btn.prop('disabled', false).text('🚀 ایمپورت');
				});
			});
		})(jQuery);
		</script>
	<?php endif; ?>
	<?php
}
