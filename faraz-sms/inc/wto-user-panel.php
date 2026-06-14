<?php
/**
 * پنل کاربریِ مشتری — فراز اس ام اس
 *
 * یک پنلِ کاربریِ سازگار با ووکامرس که برای هر مشتریِ واردشده نمایش می‌دهد:
 *   - تعداد خریدها و مجموعِ مبلغِ خریدها
 *   - زمانِ عضویت (مدت + تاریخِ شمسی)
 *   - موجودیِ کیفِ پول
 *   - مجموعِ کش‌بکِ استفاده‌شده + موجودیِ کش‌بک
 *   - تعداد دیدگاه‌های ثبت‌شده در سایت
 *   - فرمِ واردکردنِ تاریخِ تولد (سیم‌کشی‌شده به ماژولِ تبریکِ تولدِ ما)
 *
 * امکاناتِ مدیر:
 *   - فعال/غیرفعال‌کردنِ کلِ پنل (اگر بخواهد از پنلِ دیگری استفاده کند)
 *   - انتخابِ اینکه کدام کارت‌ها نمایش داده شوند
 *   - افزودنِ آیتم‌های منو در سمتِ راستِ پنل (لینکِ دلخواه)
 *   - نمایشِ شورت‌کدِ سایر افزونه‌ها داخلِ پنل
 *
 * نمایش از دو راه: شورت‌کد
 *   [farazsms_user_panel]
 * و تبِ «پنل کاربری» در «حساب کاربریِ» ووکامرس.
 *
 * همه‌ی CSS داخلِ wrapperِ .wto-up اسکوپ شده تا استایلِ قالبِ سایت دست‌نخورده بماند.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_USER_PANEL_OPTION = 'wto_user_panel_settings';

/**
 * تنظیماتِ پنل با مقادیرِ پیش‌فرض.
 *
 * @return array
 */
function wto_user_panel_settings() {
	$s = get_option( WTO_USER_PANEL_OPTION, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	$defaults = array(
		'enabled'     => '1',
		'title'       => 'پنل کاربری',
		'cards'       => array(
			'orders'       => '1',
			'total_spent'  => '1',
			'membership'   => '1',
			'wallet'       => '1',
			'cashback'     => '1',
			'comments'     => '1',
		),
		'menu_items'  => array(), // هر آیتم: array('label'=>..,'url'=>..)
		'shortcodes'  => array(), // هر آیتم: array('title'=>..,'shortcode'=>..)
	);
	$s = wp_parse_args( $s, $defaults );
	$s['cards'] = wp_parse_args( is_array( $s['cards'] ) ? $s['cards'] : array(), $defaults['cards'] );
	if ( ! is_array( $s['menu_items'] ) ) {
		$s['menu_items'] = array();
	}
	if ( ! is_array( $s['shortcodes'] ) ) {
		$s['shortcodes'] = array();
	}
	return $s;
}

function wto_user_panel_is_enabled() {
	$s = wto_user_panel_settings();
	return $s['enabled'] === '1';
}

// ============================================================================
// کمک‌تابع‌های داده — همگی با function_exists محافظت شده‌اند تا اگر ماژولی
// خاموش بود، پنل خطا ندهد.
// ============================================================================

/**
 * تبدیلِ ارقامِ لاتین به فارسی.
 *
 * @param string|int $str
 * @return string
 */
function wto_up_fa_num( $str ) {
	$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	return str_replace( range( 0, 9 ), $fa, (string) $str );
}

/**
 * تبدیلِ تاریخِ میلادی به شمسی (الگوریتمِ استاندارد).
 *
 * @param int $gy
 * @param int $gm
 * @param int $gd
 * @return array [jy, jm, jd]
 */
function wto_up_g2j( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
	$days %= 12053;
	$jy   += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + intdiv( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

/**
 * جمعِ همه‌ی متریک‌های یک کاربر برای نمایش در پنل.
 *
 * @param int $user_id
 * @return array
 */
function wto_user_panel_get_data( $user_id ) {
	$user_id = (int) $user_id;
	$u       = get_userdata( $user_id );

	$order_count = 0;
	$total_spent = 0.0;
	if ( function_exists( 'wc_get_customer_order_count' ) ) {
		$order_count = (int) wc_get_customer_order_count( $user_id );
	}
	if ( function_exists( 'wc_get_customer_total_spent' ) ) {
		$total_spent = (float) wc_get_customer_total_spent( $user_id );
	}

	// زمانِ عضویت
	$reg_ts   = $u ? strtotime( $u->user_registered ) : 0;
	$reg_jal  = '';
	$duration = '';
	if ( $reg_ts ) {
		list( $jy, $jm, $jd ) = wto_up_g2j( (int) gmdate( 'Y', $reg_ts ), (int) gmdate( 'n', $reg_ts ), (int) gmdate( 'j', $reg_ts ) );
		$reg_jal              = wto_up_fa_num( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
		$days_member          = max( 0, (int) floor( ( time() - $reg_ts ) / DAY_IN_SECONDS ) );
		$years                = intdiv( $days_member, 365 );
		$months               = intdiv( $days_member % 365, 30 );
		$parts                = array();
		if ( $years ) {
			$parts[] = wto_up_fa_num( $years ) . ' سال';
		}
		if ( $months ) {
			$parts[] = wto_up_fa_num( $months ) . ' ماه';
		}
		if ( ! $parts ) {
			$parts[] = wto_up_fa_num( $days_member ) . ' روز';
		}
		$duration = implode( ' و ', $parts );
	}

	// کیفِ پول
	$wallet = function_exists( 'wto_wallet_balance' ) ? (float) wto_wallet_balance( $user_id ) : null;

	// کش‌بک — موجودی + استفاده‌شده
	$cashback_active = function_exists( 'wto_cashback_get_balance' ) ? (float) wto_cashback_get_balance( $user_id ) : null;
	$cashback_used   = null;
	if ( function_exists( 'wto_cashback_redemptions_table' ) ) {
		global $wpdb;
		$rd            = wto_cashback_redemptions_table();
		$cashback_used = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $rd WHERE user_id = %d", $user_id ) );
	}

	// تعداد دیدگاه‌ها
	$comments = (int) get_comments( array(
		'user_id' => $user_id,
		'count'   => true,
	) );

	// تاریخِ تولد (شمسی) از user_meta
	$birthday = (string) get_user_meta( $user_id, 'wto_birthday_jalali', true );

	return array(
		'order_count'     => $order_count,
		'total_spent'     => $total_spent,
		'reg_jalali'      => $reg_jal,
		'duration'        => $duration,
		'wallet'          => $wallet,
		'cashback_active' => $cashback_active,
		'cashback_used'   => $cashback_used,
		'comments'        => $comments,
		'birthday'        => $birthday,
		'display_name'    => $u ? $u->display_name : '',
	);
}

/**
 * قالب‌بندیِ مبلغ با ووکامرس (یا fallback ساده).
 *
 * @param float $amount
 * @return string
 */
function wto_up_money( $amount ) {
	if ( function_exists( 'wc_price' ) ) {
		return wc_price( $amount );
	}
	return wto_up_fa_num( number_format( (float) $amount ) ) . ' تومان';
}

// ============================================================================
// رندرِ پنل (فرانت‌اند) — مشترکِ شورت‌کد و تبِ ووکامرس
// ============================================================================

/**
 * هندلرِ ذخیره‌ی تاریخِ تولد از داخلِ پنل (POST). به ماژولِ تبریکِ تولد سیم‌کشی شده.
 *
 * @return string پیامِ نتیجه (HTML) یا رشته‌ی خالی.
 */
function wto_user_panel_handle_birthday_post() {
	if ( empty( $_POST['wto_up_birthday_submit'] ) ) {
		return '';
	}
	if ( ! is_user_logged_in() ) {
		return '';
	}
	if ( ! isset( $_POST['wto_up_birthday_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_up_birthday_nonce'] ) ), 'wto_up_birthday' ) ) {
		return '<div class="wto-up-msg wto-up-msg-err">درخواست نامعتبر است.</div>';
	}
	if ( ! function_exists( 'wto_birthday_save' ) ) {
		return '<div class="wto-up-msg wto-up-msg-err">ماژول تبریک تولد فعال نیست.</div>';
	}

	$user_id = get_current_user_id();
	$input   = isset( $_POST['wto_up_birthday'] ) ? sanitize_text_field( wp_unslash( $_POST['wto_up_birthday'] ) ) : '';

	// شماره‌ی موبایلِ کاربر را بهترین‌حالت پیدا کن (برای ثبت در جدولِ تولد).
	$mobile = get_user_meta( $user_id, 'billing_phone', true );
	if ( ! $mobile ) {
		$mobile = get_user_meta( $user_id, '0digits_phone_no', true );
	}
	if ( ! $mobile ) {
		$u = get_userdata( $user_id );
		if ( $u && preg_match( '/^(0|98|\+98)?9\d{9}$/', $u->user_login ) ) {
			$mobile = $u->user_login;
		}
	}
	if ( ! $mobile ) {
		return '<div class="wto-up-msg wto-up-msg-err">برای ثبتِ تاریخ تولد، ابتدا شماره موبایلِ خود را در حساب کاربری ثبت کنید.</div>';
	}

	$u   = get_userdata( $user_id );
	$res = wto_birthday_save( array(
		'mobile'     => $mobile,
		'first_name' => $u ? $u->first_name : '',
		'last_name'  => $u ? $u->last_name : '',
		'email'      => $u ? $u->user_email : '',
		'user_id'    => $user_id,
		'input'      => $input,
		'source'     => 'user_panel',
	) );

	if ( is_wp_error( $res ) ) {
		return '<div class="wto-up-msg wto-up-msg-err">' . esc_html( $res->get_error_message() ) . '</div>';
	}
	return '<div class="wto-up-msg wto-up-msg-ok">✅ تاریخ تولد ثبت شد. در روزِ تولدتان برایتان پیامکِ تبریک ارسال می‌شود.</div>';
}

/**
 * رندرِ کاملِ پنل.
 *
 * @return string HTML
 */
function wto_user_panel_render() {
	if ( ! is_user_logged_in() ) {
		return '<div class="wto-up"><div class="wto-up-main"><p>برای مشاهده‌ی پنل کاربری، ابتدا وارد شوید.</p></div></div>';
	}
	$s = wto_user_panel_settings();
	if ( $s['enabled'] !== '1' ) {
		return '';
	}

	$birthday_msg = wto_user_panel_handle_birthday_post();
	$user_id      = get_current_user_id();
	$d            = wto_user_panel_get_data( $user_id );
	$cards        = $s['cards'];

	ob_start();
	?>
	<div class="wto-up" dir="rtl">
		<aside class="wto-up-nav">
			<div class="wto-up-nav-title"><?php echo esc_html( $s['title'] ); ?></div>
			<ul>
				<li><a href="#" class="wto-up-link is-active" data-target="overview">🏠 نمای کلی</a></li>
				<li><a href="#" class="wto-up-link" data-target="birthday">🎂 تاریخ تولد</a></li>
				<?php foreach ( $s['shortcodes'] as $i => $sc ) :
					if ( empty( $sc['title'] ) ) {
						continue;
					} ?>
					<li><a href="#" class="wto-up-link" data-target="sc-<?php echo (int) $i; ?>">🧩 <?php echo esc_html( $sc['title'] ); ?></a></li>
				<?php endforeach; ?>
				<?php foreach ( $s['menu_items'] as $mi ) :
					if ( empty( $mi['label'] ) || empty( $mi['url'] ) ) {
						continue;
					} ?>
					<li><a href="<?php echo esc_url( $mi['url'] ); ?>" class="wto-up-extlink">🔗 <?php echo esc_html( $mi['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</aside>

		<main class="wto-up-main">
			<?php if ( $birthday_msg ) {
				echo $birthday_msg; // قبلاً escape شده
			} ?>

			<section class="wto-up-section is-active" data-section="overview">
				<h3 class="wto-up-h">سلام <?php echo esc_html( $d['display_name'] ); ?> 👋</h3>
				<div class="wto-up-cards">
					<?php if ( $cards['orders'] === '1' ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">🛍️</span><span class="wto-up-card-v"><?php echo esc_html( wto_up_fa_num( $d['order_count'] ) ); ?></span><span class="wto-up-card-l">تعداد خریدها</span></div>
					<?php endif; ?>
					<?php if ( $cards['total_spent'] === '1' ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">💳</span><span class="wto-up-card-v"><?php echo wp_kses_post( wto_up_money( $d['total_spent'] ) ); ?></span><span class="wto-up-card-l">مجموع خریدها</span></div>
					<?php endif; ?>
					<?php if ( $cards['membership'] === '1' && $d['reg_jalali'] ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">📅</span><span class="wto-up-card-v"><?php echo esc_html( $d['duration'] ); ?></span><span class="wto-up-card-l">عضو از <?php echo esc_html( $d['reg_jalali'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $cards['wallet'] === '1' && $d['wallet'] !== null ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">👛</span><span class="wto-up-card-v"><?php echo wp_kses_post( wto_up_money( $d['wallet'] ) ); ?></span><span class="wto-up-card-l">موجودی کیف پول</span></div>
					<?php endif; ?>
					<?php if ( $cards['cashback'] === '1' && $d['cashback_active'] !== null ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">🎁</span><span class="wto-up-card-v"><?php echo wp_kses_post( wto_up_money( $d['cashback_active'] ) ); ?></span><span class="wto-up-card-l">کش‌بک قابل استفاده</span></div>
					<?php endif; ?>
					<?php if ( $cards['cashback'] === '1' && $d['cashback_used'] !== null ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">✅</span><span class="wto-up-card-v"><?php echo wp_kses_post( wto_up_money( $d['cashback_used'] ) ); ?></span><span class="wto-up-card-l">کش‌بک استفاده‌شده</span></div>
					<?php endif; ?>
					<?php if ( $cards['comments'] === '1' ) : ?>
						<div class="wto-up-card"><span class="wto-up-card-i">💬</span><span class="wto-up-card-v"><?php echo esc_html( wto_up_fa_num( $d['comments'] ) ); ?></span><span class="wto-up-card-l">دیدگاه‌های شما</span></div>
					<?php endif; ?>
				</div>
			</section>

			<section class="wto-up-section" data-section="birthday">
				<h3 class="wto-up-h">🎂 تاریخ تولد</h3>
				<p class="wto-up-note">تاریخ تولدتان را به‌صورتِ شمسی وارد کنید تا در روزِ تولد برایتان پیامکِ تبریک (و گاهی کدِ تخفیف) ارسال شود.</p>
				<form method="post" action="" class="wto-up-bform">
					<?php wp_nonce_field( 'wto_up_birthday', 'wto_up_birthday_nonce' ); ?>
					<label>تاریخ تولد (شمسی):</label>
					<input type="text" name="wto_up_birthday" value="<?php echo esc_attr( wto_up_fa_num( $d['birthday'] ) ); ?>" placeholder="۱۳۷۰/۰۳/۱۵" inputmode="numeric">
					<button type="submit" name="wto_up_birthday_submit" value="1">ثبت تاریخ تولد</button>
				</form>
			</section>

			<?php foreach ( $s['shortcodes'] as $i => $sc ) :
				if ( empty( $sc['title'] ) || empty( $sc['shortcode'] ) ) {
					continue;
				} ?>
				<section class="wto-up-section" data-section="sc-<?php echo (int) $i; ?>">
					<h3 class="wto-up-h"><?php echo esc_html( $sc['title'] ); ?></h3>
					<div class="wto-up-sc"><?php echo do_shortcode( wp_unslash( $sc['shortcode'] ) ); ?></div>
				</section>
			<?php endforeach; ?>
		</main>
	</div>
	<?php echo wto_user_panel_inline_assets(); ?>
	<?php
	return ob_get_clean();
}

/**
 * CSS و JSِ اینلاینِ پنل — کاملاً داخلِ .wto-up اسکوپ شده.
 *
 * @return string
 */
function wto_user_panel_inline_assets() {
	ob_start();
	?>
	<style id="wto-up-css">
	.wto-up{display:flex;gap:20px;align-items:flex-start;font-family:inherit;direction:rtl;text-align:right;flex-wrap:wrap}
	.wto-up *{box-sizing:border-box}
	.wto-up-nav{flex:0 0 220px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
	.wto-up-nav-title{font-size:15px;font-weight:700;color:#111827;margin:0 0 10px;padding:0 4px 10px;border-bottom:1px solid #f0f0f0}
	.wto-up-nav ul{list-style:none;margin:0;padding:0}
	.wto-up-nav li{margin:0}
	.wto-up-nav a{display:block;padding:9px 12px;border-radius:8px;color:#374151;text-decoration:none;font-size:13.5px;transition:background .15s,color .15s}
	.wto-up-nav a:hover{background:#f3f4f6;color:#111827}
	.wto-up-nav a.is-active{background:#eef2ff;color:#4338ca;font-weight:600}
	.wto-up-main{flex:1 1 340px;min-width:0;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
	.wto-up-h{font-size:17px;font-weight:700;color:#111827;margin:0 0 16px}
	.wto-up-section{display:none}
	.wto-up-section.is-active{display:block}
	.wto-up-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}
	.wto-up-card{display:flex;flex-direction:column;gap:4px;padding:16px;border:1px solid #eef0f3;border-radius:12px;background:linear-gradient(180deg,#fbfbfd,#fff)}
	.wto-up-card-i{font-size:22px}
	.wto-up-card-v{font-size:17px;font-weight:700;color:#111827}
	.wto-up-card-l{font-size:12.5px;color:#6b7280}
	.wto-up-note{font-size:13px;color:#6b7280;margin:0 0 14px;line-height:1.9}
	.wto-up-bform{display:flex;flex-direction:column;gap:8px;max-width:320px}
	.wto-up-bform label{font-size:13px;color:#374151;font-weight:600}
	.wto-up-bform input{padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;direction:rtl}
	.wto-up-bform button{padding:10px 16px;border:none;border-radius:8px;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;font-size:14px}
	.wto-up-bform button:hover{background:#4338ca}
	.wto-up-msg{padding:11px 14px;border-radius:8px;font-size:13.5px;margin:0 0 16px}
	.wto-up-msg-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
	.wto-up-msg-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
	.wto-up-sc{font-size:14px}
	@media(max-width:600px){.wto-up-nav{flex:1 1 100%}}
	</style>
	<script id="wto-up-js">
	(function(){
		var root=document.querySelector('.wto-up');
		if(!root)return;
		root.querySelectorAll('.wto-up-link').forEach(function(a){
			a.addEventListener('click',function(e){
				e.preventDefault();
				var t=a.getAttribute('data-target');
				root.querySelectorAll('.wto-up-link').forEach(function(x){x.classList.remove('is-active');});
				a.classList.add('is-active');
				root.querySelectorAll('.wto-up-section').forEach(function(s){
					s.classList.toggle('is-active', s.getAttribute('data-section')===t);
				});
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

// شورت‌کد
add_shortcode( 'farazsms_user_panel', 'wto_user_panel_shortcode' );
function wto_user_panel_shortcode() {
	if ( ! wto_user_panel_is_enabled() ) {
		return '';
	}
	return wto_user_panel_render();
}

// ============================================================================
// یکپارچگی با «حساب کاربریِ» ووکامرس — تبِ «پنل کاربری»
// ============================================================================

add_action( 'init', 'wto_user_panel_add_endpoint' );
function wto_user_panel_add_endpoint() {
	if ( ! wto_user_panel_is_enabled() ) {
		return;
	}
	add_rewrite_endpoint( 'faraz-panel', EP_ROOT | EP_PAGES );
	if ( get_option( 'wto_up_ep_flushed' ) !== '1' ) {
		flush_rewrite_rules( false );
		update_option( 'wto_up_ep_flushed', '1' );
	}
}

add_filter( 'woocommerce_account_menu_items', 'wto_user_panel_account_menu', 20 );
function wto_user_panel_account_menu( $items ) {
	if ( ! wto_user_panel_is_enabled() ) {
		return $items;
	}
	$s      = wto_user_panel_settings();
	$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
	if ( $logout !== null ) {
		unset( $items['customer-logout'] );
	}
	$items['faraz-panel'] = $s['title'];
	if ( $logout !== null ) {
		$items['customer-logout'] = $logout;
	}
	return $items;
}

add_action( 'woocommerce_account_faraz-panel_endpoint', 'wto_user_panel_account_content' );
function wto_user_panel_account_content() {
	echo wto_user_panel_render(); // داخلِ render همه‌چیز escape شده.
}

// ============================================================================
// صفحه‌ی تنظیماتِ مدیر
// ============================================================================

add_action( 'admin_menu', 'wto_user_panel_register_admin', 50 );
function wto_user_panel_register_admin() {
	add_submenu_page(
		'farazwto',
		'پنل کاربری',
		'پنل کاربری',
		'manage_options',
		'farazwto-user-panel',
		'wto_user_panel_render_admin'
	);
}

function wto_user_panel_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = wto_user_panel_settings();

	if ( isset( $_POST['wto_up_admin_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_up_admin_nonce'] ) ), 'wto_up_admin_save' ) ) {
		$s['enabled'] = isset( $_POST['enabled'] ) ? '1' : '0';
		$s['title']   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'پنل کاربری';

		$card_keys = array( 'orders', 'total_spent', 'membership', 'wallet', 'cashback', 'comments' );
		foreach ( $card_keys as $ck ) {
			$s['cards'][ $ck ] = isset( $_POST['card'][ $ck ] ) ? '1' : '0';
		}

		// آیتم‌های منوی سفارشی
		$s['menu_items'] = array();
		if ( isset( $_POST['menu_label'] ) && is_array( $_POST['menu_label'] ) ) {
			$labels = wp_unslash( $_POST['menu_label'] );
			$urls   = isset( $_POST['menu_url'] ) ? wp_unslash( $_POST['menu_url'] ) : array();
			foreach ( $labels as $i => $label ) {
				$label = sanitize_text_field( $label );
				$url   = isset( $urls[ $i ] ) ? esc_url_raw( $urls[ $i ] ) : '';
				if ( $label !== '' && $url !== '' ) {
					$s['menu_items'][] = array( 'label' => $label, 'url' => $url );
				}
			}
		}

		// شورت‌کدها
		$s['shortcodes'] = array();
		if ( isset( $_POST['sc_title'] ) && is_array( $_POST['sc_title'] ) ) {
			$titles = wp_unslash( $_POST['sc_title'] );
			$codes  = isset( $_POST['sc_code'] ) ? wp_unslash( $_POST['sc_code'] ) : array();
			foreach ( $titles as $i => $title ) {
				$title = sanitize_text_field( $title );
				$code  = isset( $codes[ $i ] ) ? wp_kses_post( $codes[ $i ] ) : '';
				if ( $title !== '' && $code !== '' ) {
					$s['shortcodes'][] = array( 'title' => $title, 'shortcode' => $code );
				}
			}
		}

		update_option( WTO_USER_PANEL_OPTION, $s, false );
		update_option( 'wto_up_ep_flushed', '0' ); // ری‌فلشِ rewrite در init بعدی.
		echo '<div class="notice notice-success"><p>تنظیماتِ پنل کاربری ذخیره شد.</p></div>';
		$s = wto_user_panel_settings();
	}

	$account_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'faraz-panel' ) : '';
	?>
	<div class="wrap" style="direction:rtl;max-width:840px">
		<h1>👤 پنل کاربری</h1>
		<p style="color:#555;font-size:13px;line-height:2">
			یک پنلِ کاربریِ سازگار با ووکامرس. دو راهِ نمایش:
		</p>
		<ul style="font-size:13px;line-height:2;color:#444">
			<li>تبِ «<?php echo esc_html( $s['title'] ); ?>» در <strong>حساب کاربریِ ووکامرس</strong> به‌صورتِ خودکار اضافه می‌شود<?php echo $account_url ? ' (<a href="' . esc_url( $account_url ) . '" target="_blank">پیش‌نمایش</a>)' : ''; ?>.</li>
			<li>یا با شورت‌کدِ زیر در هر صفحه‌ای:</li>
		</ul>
		<p><code style="direction:ltr;display:inline-block;background:#f6f7f7;border:1px solid #ddd;padding:6px 10px;border-radius:6px">[farazsms_user_panel]</code></p>

		<form method="post" action="" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-top:14px">
			<?php wp_nonce_field( 'wto_up_admin_save', 'wto_up_admin_nonce' ); ?>

			<p>
				<label style="display:inline-flex;align-items:center;gap:10px;font-weight:600;font-size:14px">
					<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?> style="width:18px;height:18px">
					پنل کاربریِ فراز فعال باشد
				</label>
				<br><span style="color:#888;font-size:12px">اگر از پنلِ کاربریِ دیگری استفاده می‌کنید، این را خاموش کنید — تب و شورت‌کد حذف می‌شوند.</span>
			</p>

			<p>
				<label style="font-weight:600;font-size:13px">عنوان پنل:</label><br>
				<input type="text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" class="regular-text">
			</p>

			<hr style="border:none;border-top:1px solid #eee;margin:18px 0">
			<h2 style="font-size:15px;margin:0 0 8px">کارت‌های نمایشی</h2>
			<?php
			$card_labels = array(
				'orders'      => 'تعداد خریدها',
				'total_spent' => 'مجموع خریدها',
				'membership'  => 'زمان عضویت',
				'wallet'      => 'موجودی کیف پول',
				'cashback'    => 'کش‌بک (قابل استفاده + استفاده‌شده)',
				'comments'    => 'تعداد دیدگاه‌ها',
			);
			foreach ( $card_labels as $ck => $cl ) : ?>
				<label style="display:inline-flex;align-items:center;gap:8px;margin:0 0 8px 18px;font-size:13px">
					<input type="checkbox" class="wto-toggle" name="card[<?php echo esc_attr( $ck ); ?>]" value="1" <?php checked( isset( $s['cards'][ $ck ] ) ? $s['cards'][ $ck ] : '0', '1' ); ?>>
					<?php echo esc_html( $cl ); ?>
				</label>
			<?php endforeach; ?>

			<hr style="border:none;border-top:1px solid #eee;margin:18px 0">
			<h2 style="font-size:15px;margin:0 0 4px">آیتم‌های منوی سفارشی (سمتِ راستِ پنل)</h2>
			<p style="color:#888;font-size:12px;margin:0 0 8px">لینک‌های دلخواه که در منوی پنل نمایش داده می‌شوند (مثلاً لینک به یک صفحه‌ی خاص).</p>
			<table class="widefat" id="wto-up-menu-rows" style="margin-bottom:8px"><tbody>
				<?php
				$rows = $s['menu_items'];
				$rows[] = array( 'label' => '', 'url' => '' ); // یک ردیفِ خالی
				foreach ( $rows as $mi ) : ?>
					<tr>
						<td><input type="text" name="menu_label[]" value="<?php echo esc_attr( $mi['label'] ); ?>" placeholder="عنوان" style="width:100%"></td>
						<td><input type="text" name="menu_url[]" value="<?php echo esc_attr( $mi['url'] ); ?>" placeholder="https://..." style="width:100%;direction:ltr"></td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
			<button type="button" class="button" onclick="wtoUpAddRow('wto-up-menu-rows',['menu_label[]','menu_url[]'],['عنوان','https://...'])">+ ردیف</button>

			<hr style="border:none;border-top:1px solid #eee;margin:18px 0">
			<h2 style="font-size:15px;margin:0 0 4px">شورت‌کدِ افزونه‌های دیگر</h2>
			<p style="color:#888;font-size:12px;margin:0 0 8px">هر شورت‌کد به‌صورتِ یک تبِ جدا داخلِ پنل نمایش داده می‌شود.</p>
			<table class="widefat" id="wto-up-sc-rows" style="margin-bottom:8px"><tbody>
				<?php
				$scrows = $s['shortcodes'];
				$scrows[] = array( 'title' => '', 'shortcode' => '' );
				foreach ( $scrows as $sc ) : ?>
					<tr>
						<td style="width:30%"><input type="text" name="sc_title[]" value="<?php echo esc_attr( $sc['title'] ); ?>" placeholder="عنوان تب" style="width:100%"></td>
						<td><input type="text" name="sc_code[]" value="<?php echo esc_attr( $sc['shortcode'] ); ?>" placeholder="[other_plugin_shortcode]" style="width:100%;direction:ltr"></td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
			<button type="button" class="button" onclick="wtoUpAddRow('wto-up-sc-rows',['sc_title[]','sc_code[]'],['عنوان تب','[shortcode]'])">+ ردیف</button>

			<p style="margin-top:22px"><button type="submit" class="button button-primary">💾 ذخیره تنظیمات</button></p>
		</form>
	</div>
	<script>
	function wtoUpAddRow(tableId, names, placeholders){
		var tb=document.getElementById(tableId).querySelector('tbody');
		var tr=document.createElement('tr');
		names.forEach(function(n,i){
			var td=document.createElement('td');
			var inp=document.createElement('input');
			inp.type='text';inp.name=n;inp.placeholder=placeholders[i];inp.style.width='100%';
			if(n.indexOf('url')>-1||n.indexOf('code')>-1)inp.style.direction='ltr';
			td.appendChild(inp);tr.appendChild(td);
		});
		tb.appendChild(tr);
	}
	</script>
	<?php
}
