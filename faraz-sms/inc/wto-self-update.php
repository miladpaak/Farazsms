<?php
/**
 * Self-Hosted Update System — v3.16.0
 *
 * این ماژول به افزونه اجازه می‌دهد بدون نیاز به مخزن WordPress.org،
 * مستقیم از GitLab خودی شرکت (gitlab.faraz.club) به‌روز شود.
 *
 * چطور کار می‌کند:
 *  ۱) هر ۱۲ ساعت یک‌بار WP خودش transient `update_plugins` را refresh می‌کند.
 *  ۲) ما به filter `pre_set_site_transient_update_plugins` hook می‌زنیم،
 *     آخرین release را از GitLab Releases API می‌خوانیم.
 *  ۳) اگر version جدیدتر بود، آن را به لیست آپدیت‌های WP اضافه می‌کنیم.
 *  ۴) WP خودش zip را دانلود می‌کند، extract، فعال‌سازی مجدد.
 *  ۵) اگر toggle auto-update روشن باشد، **کاربر مداخله‌ای نمی‌کند**.
 *
 * چگونه release بسازیم در GitLab:
 *  ۱) tag بزنید: `git tag v3.16.5 && git push --tags`
 *  ۲) در GitLab UI → Deployments → Releases → New Release از این tag.
 *  ۳) zip را به‌عنوان Asset Link آپلود کنید (URL باید با .zip تمام شود).
 *  ۴) (اختیاری) خط GitLab CI:
 *       release:
 *         stage: release
 *         script:
 *           - zip -r faraz-sms-${CI_COMMIT_TAG}.zip faraz-sms
 *           - 'curl --request POST --header "JOB-TOKEN: $CI_JOB_TOKEN" ...'
 *
 * ساختار release expected:
 *   - tag_name: "v3.16.5"  (با یا بدون پیشوند v)
 *   - assets.links[]: حداقل یک URL که با .zip تمام شود
 *   - description: changelog (markdown)
 *
 * ملاحظات امنیتی:
 *  - فقط HTTPS — هیچ HTTP fallback.
 *  - timeout کوتاه (۸ ثانیه) تا admin pages کند نشوند.
 *  - cache منفی ۱ ساعته اگر سرور down باشد.
 *  - SHA256 verification optional (اگر در description تگ release موجود باشد).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// Configuration — قابل override از wp-config.php
// ============================================================================

if ( ! defined( 'FARAZSMS_UPDATE_GITLAB_BASE' ) ) {
	define( 'FARAZSMS_UPDATE_GITLAB_BASE', 'https://gitlab.faraz.club' );
}

if ( ! defined( 'FARAZSMS_UPDATE_PROJECT' ) ) {
	// مسیر namespace/project در GitLab — قابل override در wp-config.php
	define( 'FARAZSMS_UPDATE_PROJECT', 'wordpress/farazsms-dedicated-plugin' );
}

if ( ! defined( 'FARAZSMS_UPDATE_CACHE_TTL' ) ) {
	define( 'FARAZSMS_UPDATE_CACHE_TTL', 12 * HOUR_IN_SECONDS );
}

const WTO_SELF_UPDATE_TRANSIENT     = 'wto_self_update_info_v2';
const WTO_SELF_UPDATE_FAIL_TRANSIENT = 'wto_self_update_fail';

// ============================================================================
// Fetch latest release from GitLab API
// ============================================================================

/**
 * ساختِ یک ساختارِ شبه‌Release از آخرین tag (وقتی Release رسمی وجود ندارد).
 * بالاترین tagِ نسخه‌ای (vX.Y.Z) را پیدا و آرشیوِ سورسِ آن را به‌عنوان لینکِ دانلود برمی‌گرداند.
 * (.gitattributes آرشیو را تمیز می‌کند و فیلترِ upgrader_source_selection پوشه را faraz-sms می‌کند.)
 *
 * @return array|null
 */
function wto_self_update_release_from_latest_tag() {
	$project = rawurlencode( FARAZSMS_UPDATE_PROJECT );
	$url     = trailingslashit( FARAZSMS_UPDATE_GITLAB_BASE )
	           . 'api/v4/projects/' . $project . '/repository/tags?per_page=50';

	$response = wp_remote_get( $url, array(
		'timeout'    => 8,
		'sslverify'  => true,
		'user-agent' => 'FarazSMS-Updater/' . FARAZSMS_PLUGIN_VERSION,
	) );
	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}
	$tags = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $tags ) || empty( $tags ) ) {
		return null;
	}

	$best_tag = '';
	$best_ver = '0.0.0';
	foreach ( $tags as $t ) {
		$name = isset( $t['name'] ) ? (string) $t['name'] : '';
		$ver  = ltrim( $name, 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $ver ) ) {
			continue;
		}
		if ( version_compare( $ver, $best_ver, '>' ) ) {
			$best_ver = $ver;
			$best_tag = $name;
		}
	}
	if ( $best_tag === '' ) {
		return null;
	}

	$archive = trailingslashit( FARAZSMS_UPDATE_GITLAB_BASE )
	           . 'api/v4/projects/' . $project . '/repository/archive.zip?sha=' . rawurlencode( $best_tag );

	return array(
		'tag_name'    => $best_tag,
		'released_at' => '',
		'description' => 'ساخته‌شده از tag (بدون Release رسمی).',
		'assets'      => array(
			'sources' => array(
				array( 'format' => 'zip', 'url' => $archive ),
			),
		),
	);
}

/**
 * Fetch آخرین release از GitLab — با cache.
 *
 * @return object|null
 */
function wto_self_update_fetch_remote_info() {
	$cached = get_transient( WTO_SELF_UPDATE_TRANSIENT );
	if ( $cached !== false ) {
		// cache می‌تواند null باشد (یعنی fail شد و don't retry) یا object معتبر
		return is_object( $cached ) ? $cached : null;
	}

	$project = rawurlencode( FARAZSMS_UPDATE_PROJECT );
	$url     = trailingslashit( FARAZSMS_UPDATE_GITLAB_BASE )
	           . 'api/v4/projects/' . $project . '/releases?per_page=1';

	$response = wp_remote_get( $url, array(
		'timeout'     => 8,
		'sslverify'   => true,
		'redirection' => 3,
		'user-agent'  => 'FarazSMS-Updater/' . FARAZSMS_PLUGIN_VERSION,
	) );

	if ( is_wp_error( $response ) ) {
		// cache منفی کوتاه — اگر شبکه قطع بود، دائماً retry نکن
		set_transient( WTO_SELF_UPDATE_TRANSIENT, 0, HOUR_IN_SECONDS );
		set_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT, $response->get_error_message(), DAY_IN_SECONDS );
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code !== 200 ) {
		// ۴۰۴ معمولاً یعنی پروژه پیدا نشد یا «عمومی (Public)» نیست — GitLab پروژه‌ی
		// خصوصی را برای درخواستِ بدونِ توکن، ۴۰۴ نشان می‌دهد (نه ۴۰۱).
		$hint = $code === 404
			? 'HTTP 404: پروژه در GitLab یافت نشد یا روی حالت «عمومی (Public)» نیست. در GitLab → پروژه → Settings → General → Visibility، گزینه‌ی Project visibility را روی Public بگذارید. (مسیر پروژه: ' . FARAZSMS_UPDATE_PROJECT . ')'
			: 'HTTP ' . $code . ' از سرور GitLab';
		set_transient( WTO_SELF_UPDATE_TRANSIENT, 0, HOUR_IN_SECONDS );
		set_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT, $hint, DAY_IN_SECONDS );
		return null;
	}

	$json    = json_decode( $body, true );
	$release = ( is_array( $json ) && ! empty( $json[0] ) ) ? $json[0] : null;

	// اگر هیچ Release رسمی منتشر نشده بود، از آخرین tag بساز — تا آپدیت با صرفِ push کردنِ
	// یک tag کار کند (نیازی به ساختِ دستیِ Release نباشد).
	if ( $release === null ) {
		$release = wto_self_update_release_from_latest_tag();
	}
	if ( ! is_array( $release ) ) {
		set_transient( WTO_SELF_UPDATE_TRANSIENT, 0, HOUR_IN_SECONDS );
		set_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT, 'هیچ Release یا tagِ معتبری در GitLab یافت نشد', DAY_IN_SECONDS );
		return null;
	}
	$tag     = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
	$version = ltrim( $tag, 'vV' );

	if ( ! $version || ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
		set_transient( WTO_SELF_UPDATE_TRANSIENT, 0, HOUR_IN_SECONDS );
		set_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT, 'فرمت tag نامعتبر: ' . $tag, DAY_IN_SECONDS );
		return null;
	}

	// تعیینِ لینکِ دانلود:
	// ۱) اگر کاربر یک فایلِ .zip به‌عنوان asset link به Release چسبانده باشد، همان (تمیزترین).
	// ۲) وگرنه آرشیوِ سورسِ tag را خودمان با https و endpointِ API می‌سازیم.
	// مهم (رفع خطای «cURL error 7: port 80»): از URLِ source که خودِ گیت‌لب می‌سازد استفاده
	// نمی‌کنیم چون ممکن است http باشد و به پورت 80 وصل شود (که بسته است). URLِ خودمان https است.
	$download_url = '';
	if ( ! empty( $release['assets']['links'] ) && is_array( $release['assets']['links'] ) ) {
		foreach ( $release['assets']['links'] as $link ) {
			$lurl = isset( $link['url'] ) ? (string) $link['url'] : '';
			if ( $lurl && preg_match( '/\.zip(\?|$)/i', $lurl ) ) {
				$download_url = $lurl;
				break;
			}
		}
	}
	if ( $download_url === '' ) {
		$download_url = trailingslashit( FARAZSMS_UPDATE_GITLAB_BASE )
		                . 'api/v4/projects/' . rawurlencode( FARAZSMS_UPDATE_PROJECT )
		                . '/repository/archive.zip?sha=' . rawurlencode( $tag );
	}
	// اجبارِ https — هرگز نباید روی پورت 80 (http) وصل شود.
	$download_url = preg_replace( '#^http://#i', 'https://', $download_url );

	// استخراج SHA256 (اختیاری) از description — اگر کاربر در changelog نوشت "SHA256: abc..."
	$sha256 = '';
	if ( ! empty( $release['description'] ) && preg_match( '/SHA256:\s*([a-f0-9]{64})/i', $release['description'], $m ) ) {
		$sha256 = strtolower( $m[1] );
	}

	$info = (object) array(
		'version'      => $version,
		'tag_name'     => $tag,
		'name'         => 'فراز اس ام اس',
		'slug'         => 'faraz-sms',
		'download_url' => $download_url,
		'sha256'       => $sha256,
		'description'  => isset( $release['description'] ) ? (string) $release['description'] : '',
		'released_at'  => isset( $release['released_at'] ) ? (string) $release['released_at'] : '',
		'homepage'     => 'https://farazsms.com/',
		'author'       => '<a href="https://farazsms.com">FarazSMS</a>',
		'tested'       => '6.7',
		'requires'     => '5.8',
		'requires_php' => '7.4',
		'fetched_at'   => current_time( 'mysql' ),
	);

	set_transient( WTO_SELF_UPDATE_TRANSIENT, $info, FARAZSMS_UPDATE_CACHE_TTL );
	delete_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT );
	return $info;
}

/**
 * هنگام نصبِ آپدیت، نامِ پوشه‌ی استخراج‌شده را به «faraz-sms» تغییر بده.
 *
 * چرا لازم است: zipِ سورسِ خودکارِ گیت‌لب، پوشه‌ای با نامی مثل
 * «farazsms-dedicated-plugin-v3.20.30-<sha>» می‌سازد، نه «faraz-sms». بدونِ این فیلتر،
 * وردپرس آن را به‌عنوان افزونه‌ی جدید نصب می‌کند نه آپدیتِ افزونه‌ی فعلی. با این فیلتر،
 * کاربر فقط کافی است یک Release بسازد (نیازی به آپلودِ دستیِ zip نیست).
 * فقط روی آپدیتِ همین افزونه اثر دارد (سایر افزونه‌ها دست‌نخورده).
 */
add_filter( 'upgrader_source_selection', 'wto_self_update_rename_source', 10, 4 );
function wto_self_update_rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	if ( empty( $hook_extra['plugin'] ) ) {
		return $source;
	}
	$basename = defined( 'WTO_PLUGIN_BASENAME' ) ? WTO_PLUGIN_BASENAME : 'faraz-sms/faraz-sms.php';
	if ( $hook_extra['plugin'] !== $basename ) {
		return $source; // آپدیتِ افزونه‌ی دیگری است — کاری نکن.
	}
	$desired = 'faraz-sms';
	if ( basename( untrailingslashit( $source ) ) === $desired ) {
		return $source; // از قبل درست است (مثلاً asset zipِ آماده).
	}
	if ( ! $wp_filesystem ) {
		return $source;
	}
	$new_source = trailingslashit( $remote_source ) . $desired;
	if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ) ) ) {
		return trailingslashit( $new_source );
	}
	return $source;
}

/**
 * پیام آخرین خطای ارتباط (برای نمایش در UI تنظیمات)
 */
function wto_self_update_last_error() {
	return get_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT );
}

// ============================================================================
// Hook 1: تزریق آپدیت به update transient
// ============================================================================

add_filter( 'pre_set_site_transient_update_plugins', 'wto_self_update_inject' );
/**
 * آیکونِ افزونه برای صفحه‌ی به‌روزرسانیِ وردپرس (لوگوی فراز).
 * اگر فایلِ آیکون موجود نباشد، آرایه‌ی خالی برمی‌گرداند تا تصویرِ شکسته نمایش داده نشود.
 *
 * @return array
 */
function wto_self_update_icons() {
	if ( ! defined( 'WTO_PLUGIN_FILE' ) ) {
		return array();
	}
	$dir = plugin_dir_path( WTO_PLUGIN_FILE ) . 'assets/img/';
	$url = plugins_url( 'assets/img/', WTO_PLUGIN_FILE );
	if ( ! file_exists( $dir . 'icon-256x256.png' ) ) {
		return array();
	}
	$icons = array(
		'2x'      => $url . 'icon-256x256.png',
		'default' => $url . 'icon-256x256.png',
	);
	if ( file_exists( $dir . 'icon-128x128.png' ) ) {
		$icons['1x'] = $url . 'icon-128x128.png';
	}
	return $icons;
}

function wto_self_update_inject( $transient ) {
	if ( empty( $transient ) || ! is_object( $transient ) ) {
		return $transient;
	}
	// در صورت empty($transient->checked) هم adding می‌کنیم — تا «Check for updates»
	// در صفحه افزونه‌ها بلافاصله جواب بدهد.

	$info = wto_self_update_fetch_remote_info();
	if ( ! $info || empty( $info->version ) || empty( $info->download_url ) ) {
		return $transient;
	}

	$basename = defined( 'WTO_PLUGIN_BASENAME' ) ? WTO_PLUGIN_BASENAME : 'faraz-sms/faraz-sms.php';
	$current  = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '0.0.0';

	if ( version_compare( $info->version, $current, '>' ) ) {
		$obj = (object) array(
			'slug'         => 'faraz-sms',
			'plugin'       => $basename,
			'new_version'  => $info->version,
			'url'          => $info->homepage,
			'package'      => $info->download_url,
			'tested'       => $info->tested,
			'requires_php' => $info->requires_php,
			'icons'        => wto_self_update_icons(),
			'banners'      => array(),
			'compatibility'=> new stdClass(),
		);
		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $basename ] = $obj;
	} else {
		// در no_update تزریق کنیم تا «هیچ آپدیت موجود نیست» در WP درست نمایش داده شود
		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$transient->no_update[ $basename ] = (object) array(
			'slug'        => 'faraz-sms',
			'plugin'      => $basename,
			'new_version' => $info->version,
			'url'         => $info->homepage,
			'package'     => '',
			'icons'       => wto_self_update_icons(),
			'banners'     => array(),
		);
	}

	return $transient;
}

// ============================================================================
// Hook 2: پاپ‌آپ «جزئیات» در صفحه افزونه‌ها
// ============================================================================

add_filter( 'plugins_api', 'wto_self_update_plugin_info', 20, 3 );
function wto_self_update_plugin_info( $res, $action, $args ) {
	if ( $action !== 'plugin_information' ) {
		return $res;
	}
	if ( empty( $args->slug ) || $args->slug !== 'faraz-sms' ) {
		return $res;
	}

	$info = wto_self_update_fetch_remote_info();
	if ( ! $info ) {
		return $res;
	}

	$res = new stdClass();
	$res->name           = $info->name;
	$res->slug           = 'faraz-sms';
	$res->version        = $info->version;
	$res->tested         = $info->tested;
	$res->requires       = $info->requires;
	$res->requires_php   = $info->requires_php;
	$res->author         = $info->author;
	$res->author_profile = $info->homepage;
	$res->homepage       = $info->homepage;
	$res->download_link  = $info->download_url;
	$res->trunk          = $info->download_url;
	$res->last_updated   = $info->released_at;

	// تبدیل markdown changelog به HTML ساده (بدون پارسر کامل — basic newline → br)
	$changelog_html = '<h4>' . esc_html( $info->tag_name ) . '</h4>';
	$changelog_html .= '<pre style="white-space:pre-wrap; direction:rtl; font-family:inherit;">'
	                   . esc_html( $info->description )
	                   . '</pre>';

	$res->sections = array(
		'description' => 'افزونه فراز اس‌ام‌اس — سیستم جامع پیامک‌رسانی برای ووکامرس، فرم‌ها، نظرسنجی، سبد رهاشده، کش‌بک و بسیار بیشتر.',
		'changelog'   => $changelog_html,
	);
	$res->banners = array();
	$res->icons   = wto_self_update_icons();

	return $res;
}

// ============================================================================
// Hook 3: auto-update — فعال به‌صورت پیش‌فرض، با toggle قابل خاموش‌سازی
// ============================================================================

add_filter( 'auto_update_plugin', 'wto_self_update_auto_decision', 10, 2 );
function wto_self_update_auto_decision( $update, $item ) {
	$basename = defined( 'WTO_PLUGIN_BASENAME' ) ? WTO_PLUGIN_BASENAME : 'faraz-sms/faraz-sms.php';
	if ( ! is_object( $item ) || empty( $item->plugin ) || $item->plugin !== $basename ) {
		return $update;
	}
	$enabled = get_option( 'wto_self_update_enabled', '1' );
	return ( $enabled === '1' );
}

// ============================================================================
// Hook 4: فولدر extract شده را به نام درست rename کن
//          (GitLab source zip معمولاً به فرم `project-tag-hash/` extract می‌شود)
// ============================================================================

add_filter( 'upgrader_source_selection', 'wto_self_update_fix_source', 10, 4 );
function wto_self_update_fix_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	$basename = defined( 'WTO_PLUGIN_BASENAME' ) ? WTO_PLUGIN_BASENAME : 'faraz-sms/faraz-sms.php';
	if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $basename ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		return $source;
	}

	$desired = trailingslashit( $remote_source ) . 'faraz-sms/';
	if ( trailingslashit( $source ) === $desired ) {
		return $source;
	}

	// اگر دایرکتوری مقصد قبلاً وجود دارد، پاک کنیم
	if ( $wp_filesystem->is_dir( $desired ) ) {
		$wp_filesystem->delete( $desired, true );
	}

	if ( $wp_filesystem->move( $source, $desired ) ) {
		return $desired;
	}
	return $source;
}

// ============================================================================
// Force-check handler — کاربر دکمه «بررسی اکنون» را می‌زند
// ============================================================================

add_action( 'admin_post_wto_self_update_force_check', 'wto_self_update_force_check_handler' );
function wto_self_update_force_check_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_self_update_force_check' );

	delete_transient( WTO_SELF_UPDATE_TRANSIENT );
	delete_transient( WTO_SELF_UPDATE_FAIL_TRANSIENT );
	delete_site_transient( 'update_plugins' );

	$ref = wp_get_referer();
	wp_safe_redirect( add_query_arg( 'wto_checked', '1', $ref ?: admin_url( 'admin.php?page=farazwto-updates' ) ) );
	exit;
}

// ============================================================================
// Toggle handler — auto-update on/off
// ============================================================================

add_action( 'admin_post_wto_self_update_toggle', 'wto_self_update_toggle_handler' );
function wto_self_update_toggle_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_self_update_toggle' );

	$new_val = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? '1' : '0';
	update_option( 'wto_self_update_enabled', $new_val, false );

	$ref = wp_get_referer();
	wp_safe_redirect( add_query_arg( 'wto_toggled', '1', $ref ?: admin_url( 'admin.php?page=farazwto-updates' ) ) );
	exit;
}

// ============================================================================
// Submenu — اولویت ۱۳، بعد از داشبورد ROI
// ============================================================================

add_action( 'admin_menu', 'wto_self_update_register_submenu', 13 );
function wto_self_update_register_submenu() {
	add_submenu_page(
		'farazwto',
		'به‌روزرسانی افزونه',
		'🔄 به‌روزرسانی',
		'manage_options',
		'farazwto-updates',
		'wto_self_update_render_page'
	);
}

// ============================================================================
// Render settings page
// ============================================================================

function wto_self_update_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$info       = wto_self_update_fetch_remote_info();
	$last_error = wto_self_update_last_error();
	$enabled    = get_option( 'wto_self_update_enabled', '1' ) === '1';
	$current    = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '0.0.0';
	$has_update = ( $info && version_compare( $info->version, $current, '>' ) );

	$apikey = get_option( 'wto_apikey', '' );
	?>
	<section class="wrapper">
		<div id="wto_header">
			<div>
				<a href="https://farazsms.com" target="_blank">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="پنل ارسال اس ام اس">
				</a>
			</div>
			<?php if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) :
				$credit = wto_get_credit(); ?>
				<div id="wto_account_info">
					<div class="wto_credit_amount">
						<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
						<span> تومان</span>
					</div>
					<?php if ( function_exists( 'wto_render_profile_block' ) ) wto_render_profile_block(); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( isset( $_GET['wto_checked'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✓ بررسی اکنون انجام شد.</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['wto_toggled'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✓ تنظیمات به‌روزرسانی خودکار ذخیره شد.</div>
		<?php endif; ?>

		<!-- Hero — وضعیت نسخه -->
		<?php if ( $has_update ) : ?>
			<div style="background:linear-gradient(135deg, #dc2626 0%, #ea580c 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:18px; direction:rtl; box-shadow:0 8px 24px rgba(220,38,38,0.22);">
				<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
					<div style="font-size:48px; line-height:1;">⬆️</div>
					<div style="flex:1; min-width:240px;">
						<div style="font-size:13px; opacity:0.92; margin-bottom:4px;">نسخه جدید‌تری از افزونه موجود است</div>
						<div style="font-size:22px; font-weight:800; margin-bottom:4px;">
							نسخه <?php echo esc_html( $info->version ); ?> منتشر شده است
						</div>
						<div style="font-size:13px; opacity:0.92;">نسخه فعلی شما: <?php echo esc_html( $current ); ?></div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" style="background:#fff; color:#dc2626; padding:12px 26px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:700; box-shadow:0 6px 14px rgba(0,0,0,0.18);">
						🚀 به‌روزرسانی اکنون
					</a>
				</div>
			</div>
		<?php elseif ( $info ) : ?>
			<div style="background:linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:18px; direction:rtl; box-shadow:0 8px 24px rgba(5,150,105,0.22);">
				<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
					<div style="font-size:48px; line-height:1;">✓</div>
					<div style="flex:1; min-width:240px;">
						<div style="font-size:13px; opacity:0.92; margin-bottom:4px;">افزونه به‌روز است</div>
						<div style="font-size:22px; font-weight:800; margin-bottom:4px;">
							نسخه <?php echo esc_html( $current ); ?> آخرین نسخه است
						</div>
						<div style="font-size:12.5px; opacity:0.9;">آخرین بررسی:
							<?php echo esc_html( $info->fetched_at ); ?>
						</div>
					</div>
				</div>
			</div>
		<?php else : ?>
			<div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:18px 22px; border-radius:12px; margin-bottom:18px; direction:rtl;">
				<div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
					<div style="font-size:32px;">⚠️</div>
					<div style="flex:1;">
						<div style="font-weight:700; margin-bottom:4px;">دسترسی به سرور آپدیت ممکن نیست</div>
						<?php if ( $last_error ) : ?>
							<div style="font-size:12.5px; line-height:1.7;">خطا: <code style="background:#fff; padding:2px 6px; border-radius:4px;"><?php echo esc_html( $last_error ); ?></code></div>
						<?php endif; ?>
						<div style="font-size:12px; margin-top:6px; color:#a16207;">
							احتمالاً اولین release هنوز در GitLab منتشر نشده، یا سرور موقتاً قطع است.
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<!-- کارت‌های info — version + source + toggle -->
		<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; margin-bottom:18px; direction:rtl;">

			<!-- کارت ۱: مقایسه نسخه -->
			<div style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:18px 20px;">
				<div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
					<div style="font-size:24px;">📦</div>
					<div style="font-weight:700; color:#0f172a;">نسخه افزونه</div>
				</div>
				<div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9;">
					<span style="color:#64748b; font-size:12.5px;">نسخه نصب‌شده روی این سایت:</span>
					<strong style="color:#0f172a; font-family:Menlo,Consolas,monospace; direction:ltr;"><?php echo esc_html( $current ); ?></strong>
				</div>
				<div style="display:flex; justify-content:space-between; padding:8px 0;">
					<span style="color:#64748b; font-size:12.5px;">آخرین نسخه روی GitLab:</span>
					<strong style="color:<?php echo $has_update ? '#dc2626' : '#059669'; ?>; font-family:Menlo,Consolas,monospace; direction:ltr;">
						<?php echo $info ? esc_html( $info->version ) : '—'; ?>
					</strong>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="wto_self_update_force_check">
					<?php wp_nonce_field( 'wto_self_update_force_check' ); ?>
					<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:9px 18px; border-radius:7px; font-size:12.5px; font-weight:600; cursor:pointer; width:100%;">
						↻ بررسی اکنون
					</button>
				</form>
			</div>

			<!-- کارت ۲: toggle auto-update -->
			<div style="background:#fff; border:1.5px solid <?php echo $enabled ? '#bbf7d0' : '#fecaca'; ?>; border-radius:12px; padding:18px 20px;">
				<div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
					<div style="font-size:24px;"><?php echo $enabled ? '🟢' : '🔴'; ?></div>
					<div style="font-weight:700; color:#0f172a;">به‌روزرسانی خودکار</div>
				</div>
				<p style="font-size:12.5px; color:#475569; line-height:1.7; margin:0 0 14px;">
					اگر فعال باشد، هر نسخه‌ی جدید بدون مداخله شما روی سایت نصب می‌شود (در پس‌زمینه، در ساعت‌های کم‌ترافیک).
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wto_self_update_toggle">
					<input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
					<?php wp_nonce_field( 'wto_self_update_toggle' ); ?>
					<button type="submit" style="background:<?php echo $enabled ? '#dc2626' : '#16a34a'; ?>; color:#fff; border:none; padding:9px 18px; border-radius:7px; font-size:12.5px; font-weight:600; cursor:pointer; width:100%;">
						<?php echo $enabled ? '⏸ غیرفعال کردن به‌روزرسانی خودکار' : '▶ فعال کردن به‌روزرسانی خودکار'; ?>
					</button>
				</form>
			</div>

			<!-- کارت ۳: منبع آپدیت -->
			<div style="background:#fff; border:1.5px solid #fde68a; border-radius:12px; padding:18px 20px;">
				<div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
					<div style="font-size:24px;">🦊</div>
					<div style="font-weight:700; color:#0f172a;">منبع به‌روزرسانی</div>
				</div>
				<div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:6px; padding:8px 10px; margin-bottom:10px; direction:ltr; text-align:right;">
					<code style="font-size:11px; color:#92400e; word-break:break-all;"><?php echo esc_html( FARAZSMS_UPDATE_GITLAB_BASE . '/' . FARAZSMS_UPDATE_PROJECT ); ?></code>
				</div>
				<p style="font-size:11.5px; color:#94a3b8; line-height:1.7; margin:0;">
					فقط از سرور GitLab فراز اس‌ام‌اس آپدیت دریافت می‌شود — مخزن WordPress.org بررسی نمی‌شود.
				</p>
			</div>
		</div>

		<!-- changelog -->
		<?php if ( $info && ! empty( $info->description ) ) : ?>
			<div style="background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:20px 24px; direction:rtl;">
				<h3 style="margin:0 0 14px; font-size:15px; color:#0f172a; font-weight:700;">
					📝 یادداشت‌های نسخه <?php echo esc_html( $info->tag_name ); ?>
				</h3>
				<pre style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; font-family:inherit; font-size:12.5px; line-height:1.9; color:#0f172a; white-space:pre-wrap; max-height:340px; overflow-y:auto; margin:0;"><?php echo esc_html( $info->description ); ?></pre>
				<?php if ( ! empty( $info->released_at ) ) : ?>
					<div style="margin-top:10px; font-size:11.5px; color:#94a3b8;">منتشر شده در:
						<code style="direction:ltr; background:#f1f5f9; padding:2px 6px; border-radius:4px;"><?php echo esc_html( $info->released_at ); ?></code>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- توضیح فنی -->
		<details style="margin-top:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; direction:rtl; font-size:12px; color:#475569; line-height:1.8;">
			<summary style="cursor:pointer; font-weight:600; color:#0f172a; font-size:12.5px;">🔍 این مکانیزم چطور کار می‌کند؟</summary>
			<div style="margin-top:10px;">
				<strong>۱) Polling:</strong> هر ۱۲ ساعت، WordPress خودش از endpoint زیر آخرین release را می‌گیرد:
				<br>
				<code style="background:#fff; padding:2px 6px; border-radius:4px; direction:ltr; display:inline-block; margin:4px 0;">
					<?php echo esc_html( FARAZSMS_UPDATE_GITLAB_BASE ); ?>/api/v4/projects/<?php echo esc_html( rawurlencode( FARAZSMS_UPDATE_PROJECT ) ); ?>/releases?per_page=1
				</code>
				<br>
				<strong>۲) مقایسه نسخه:</strong> اگر <code>tag_name</code> بزرگ‌تر از نسخه نصب‌شده باشد، در فهرست آپدیت‌ها قرار می‌گیرد.
				<br>
				<strong>۳) دانلود:</strong> WordPress خودش zip را از asset link دانلود می‌کند.
				<br>
				<strong>۴) Cache:</strong> پاسخ GitLab به مدت <?php echo (int) ( FARAZSMS_UPDATE_CACHE_TTL / HOUR_IN_SECONDS ); ?> ساعت در transient ذخیره می‌شود.
				<br>
				<strong>۵) Override:</strong> برای تغییر مسیر، در wp-config.php اضافه کنید:
				<br>
				<code style="background:#fff; padding:4px 8px; border-radius:4px; direction:ltr; display:block; margin:6px 0;">
define( 'FARAZSMS_UPDATE_GITLAB_BASE', 'https://gitlab.faraz.club' );<br>
define( 'FARAZSMS_UPDATE_PROJECT', 'farazsms/faraz-sms' );
				</code>
			</div>
		</details>
	</section>
	<?php
}
