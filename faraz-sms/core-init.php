<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	This file initializes all WTO Core components
*	
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}
// Define WTO Constants
define( 'WTO_CORE_INC', dirname( __FILE__ ) . '/inc/' );
define( 'WTO_CORE_IMG', plugins_url( 'assets/img/', __FILE__ ) );
define( 'WTO_CORE_CSS', plugins_url( 'assets/css/', __FILE__ ) );
define( 'WTO_CORE_JS', plugins_url( 'assets/js/', __FILE__ ) );
/*
*
*  Register CSS
*
*/
function wto_register_core_css( $page ) {
	// Load CSS for main settings page and all submenu pages
	$valid_hooks = array(
		'toplevel_page_farazwto',
		'farazwto_page_farazwto',
		'farazwto_page_farazwto-dashboard',
		'farazwto_page_farazwto-settings',
		'farazwto_page_farazwto-poll',
		'farazwto_page_farazwto-comments',
		'farazwto_page_farazwto-phonebook',
		'farazwto_page_farazwto-lead-magnet',
		'farazwto_page_farazwto-sms-forms',
		'farazwto_page_farazwto-automation',
		'farazwto_page_farazwto-reports',
		'farazwto_page_farazwto-newsletter',
		'farazwto_page_farazwto-notify',
		'farazwto_page_farazwto-abandoned',
		'farazwto_page_farazwto-send-sms',
		'farazwto_page_farazwto-feedback',
		'farazwto_page_farazwto-roi',
		'farazwto_page_farazwto-updates',
		'farazwto_page_farazwto-birthday',
	);

	// Also check by page parameter as fallback
	$valid_pages = array(
		'farazwto',
		'farazwto-dashboard',
		'farazwto-settings',
		'farazwto-poll',
		'farazwto-comments',
		'farazwto-phonebook',
		'farazwto-lead-magnet',
		'farazwto-sms-forms',
		'farazwto-automation',
		'farazwto-reports',
		'farazwto-newsletter',
		'farazwto-notify',
		'farazwto-abandoned',
		'farazwto-send-sms',
		'farazwto-feedback',
		'farazwto-roi',
		'farazwto-updates',
		'farazwto-birthday',
	);

	$is_valid_page = false;
	if (in_array($page, $valid_hooks)) {
		$is_valid_page = true;
	} elseif (isset($_GET['page']) && in_array($_GET['page'], $valid_pages)) {
		$is_valid_page = true;
	}

	if ( $is_valid_page ) {
		// v3.13.13 PERF: dashicons + main CSS را همه جا load می‌کنیم (سبک‌اند).
		// ولی select2 CSS (~28KB) فقط برای صفحاتی که واقعاً dropdown ساخته‌شده با
		// select2 دارند load می‌شود — صفحات «سبک» مثل dashboard/reports/feedback
		// نیازی ندارند.
		wp_enqueue_style( 'wto-settings', WTO_CORE_CSS . 'wto-settings.css', null, '0.0.1', 'all' );
		// v3.18.0: استایل مشترک card-based — یک‌بار enqueue، browser cache می‌کند
		wp_enqueue_style( 'wto-admin-modern', WTO_CORE_CSS . 'wto-admin-modern.css', null, '3.18.0', 'all' );
		wp_enqueue_style( 'dashicons' );

		$select2_pages = array(
			'farazwto', 'farazwto-settings', 'farazwto-poll', 'farazwto-comments',
			'farazwto-phonebook', 'farazwto-sms-forms', 'farazwto-automation',
			'farazwto-newsletter', 'farazwto-notify', 'farazwto-abandoned',
			'farazwto-send-sms',
		);
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( in_array( $current_page, $select2_pages, true ) ) {
			wp_enqueue_style( 'select2css', WTO_CORE_CSS . 'select2.min.css', false, '1.0', 'all' );
		}
	}
	if ( get_post_type() === 'product' || get_post_type() === 'lp_course' ) {
		wp_enqueue_style( 'wto-core', WTO_CORE_CSS . 'wto-core.css', null, '0.0.1', 'all' );
	}
}

;
add_action( 'admin_enqueue_scripts', 'wto_register_core_css' );
/*
*
*  Register JS/Jquery Ready
*
*/
function wto_register_core_js( $page ) {
	// Load JS for main settings page and all submenu pages
	$valid_hooks = array(
		'toplevel_page_farazwto',
		'farazwto_page_farazwto',
		'farazwto_page_farazwto-dashboard',
		'farazwto_page_farazwto-settings',
		'farazwto_page_farazwto-poll',
		'farazwto_page_farazwto-comments',
		'farazwto_page_farazwto-phonebook',
		'farazwto_page_farazwto-lead-magnet',
		'farazwto_page_farazwto-sms-forms',
		'farazwto_page_farazwto-automation',
		'farazwto_page_farazwto-reports',
		'farazwto_page_farazwto-newsletter',
		'farazwto_page_farazwto-notify',
		'farazwto_page_farazwto-abandoned',
		'farazwto_page_farazwto-send-sms',
		'farazwto_page_farazwto-feedback',
		'farazwto_page_farazwto-roi',
		'farazwto_page_farazwto-updates',
		'farazwto_page_farazwto-birthday',
	);

	// Also check by page parameter as fallback
	$valid_pages = array(
		'farazwto',
		'farazwto-dashboard',
		'farazwto-settings',
		'farazwto-poll',
		'farazwto-comments',
		'farazwto-phonebook',
		'farazwto-lead-magnet',
		'farazwto-sms-forms',
		'farazwto-automation',
		'farazwto-reports',
		'farazwto-newsletter',
		'farazwto-notify',
		'farazwto-abandoned',
		'farazwto-send-sms',
		'farazwto-feedback',
		'farazwto-roi',
		'farazwto-updates',
		'farazwto-birthday',
	);

	$is_valid_page = false;
	if (in_array($page, $valid_hooks)) {
		$is_valid_page = true;
	} elseif (isset($_GET['page']) && in_array($_GET['page'], $valid_pages)) {
		$is_valid_page = true;
	}
	
	if ( $is_valid_page ) {
		// v3.13.13 PERF: heavy scripts (jquery-validate + select2 = ~80KB) فقط
		// برای صفحاتی که فرم با validation/dropdown دارند load می‌شوند. صفحات
		// سبک مثل dashboard/reports/feedback/lead-magnet آن‌ها را نمی‌گیرند.
		$heavy_js_pages = array(
			'farazwto', 'farazwto-settings', 'farazwto-poll', 'farazwto-comments',
			'farazwto-phonebook', 'farazwto-sms-forms', 'farazwto-automation',
			'farazwto-newsletter', 'farazwto-notify', 'farazwto-abandoned',
			'farazwto-send-sms',
		);
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$is_heavy_page = in_array( $current_page, $heavy_js_pages, true );

		$settings_deps = array( 'jquery' );
		if ( $is_heavy_page ) {
			wp_enqueue_script( 'jquery-validate', WTO_CORE_JS . 'jquery.validate.min.js', array( 'jquery' ), '0.0.1', true );
			wp_enqueue_script( 'select2', WTO_CORE_JS . 'select2.min.js', array( 'jquery-validate' ), '1.0', true );
			$settings_deps = array( 'jquery', 'select2', 'jquery-validate' );
		}
		wp_enqueue_script( 'wto-settings', WTO_CORE_JS . 'wto-settings.js', $settings_deps, '0.0.1', true );
		$wto_settings_info = array(
			'delete_button'        => WTO_CORE_IMG . 'macos-close.png',
			'create_pattern_nonce' => wp_create_nonce( 'wto_create_pattern' ),
			'save_nonce'           => wp_create_nonce( 'wto_save_settings' ),
		);
		if ( function_exists( 'is_woocommerce' ) ) {
			$wto_settings_info['order_statuses'] = wc_get_order_statuses();
		}
		wp_localize_script( 'wto-settings', 'wto_settings_info', $wto_settings_info );
	}
	if ( get_post_type() === 'product' || get_post_type() === 'lp_course' ) {
			// Use the plugin version as the asset version. time() would bust the browser cache
		// on every admin page-load, hurting performance with zero benefit.
		$wto_core_version = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '1.0.0';
		wp_register_script( 'wto-core', WTO_CORE_JS . 'wto-core.js', 'jquery', $wto_core_version, true );
		$wto_data = [
			'delete_button'  => WTO_CORE_IMG . 'macos-close.png',
		];
		if (get_post_type() === 'product'){
			$wto_data['order_statuses'] = wc_get_order_statuses();
		}elseif (get_post_type() === 'lp_course'){
			$wto_data['order_statuses'] = learn_press_get_order_statuses();
		}
		wp_localize_script( 'wto-core', 'wto_data', $wto_data );
		wp_enqueue_script( 'wto-core' );
	}
}

add_action( 'admin_enqueue_scripts', 'wto_register_core_js' );

/**
 * ثبت ویجت فید فراز اس‌ام‌اس در داشبورد وردپرس
 */
function wto_farazsms_dashboard_feed_widget() {
	wp_add_dashboard_widget(
		'wto_farazsms_feed',
		__( 'آخرین مطالب فراز اس‌ام‌اس', 'wto' ),
		'wto_farazsms_dashboard_feed_content',
		null,
		null,
		'normal',
		'high'
	);
}

/**
 * بررسی وجود و معتبر بودن بنر خبری فراز اس‌ام‌اس (اگر ۲۰۰ و عکس باشد true)
 *
 * @return bool
 */
function wto_farazsms_banner_available() {
	$banner_url = 'https://farazsms.com/plugin/farazsms/news/banner.jpg';
	$cache_key  = 'wto_farazsms_banner_ok';
	$cached     = get_transient( $cache_key );
	if ( true === $cached ) {
		return true;
	}
	if ( false === $cached ) {
		return false;
	}

	$response = wp_remote_head( $banner_url, array(
		'timeout'     => 5,
		'redirection' => 2,
		'sslverify'   => true,
	) );
	if ( is_wp_error( $response ) ) {
		set_transient( $cache_key, false, 1 * HOUR_IN_SECONDS );
		return false;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$type = wp_remote_retrieve_header( $response, 'content-type' );
	$ok   = ( $code === 200 && $type && strpos( strtolower( $type ), 'image/' ) === 0 );
	set_transient( $cache_key, $ok, 1 * HOUR_IN_SECONDS );
	return $ok;
}

/**
 * محتوای ویجت: بنر (در صورت وجود) + دریافت و نمایش آیتم‌های فید فراز اس‌ام‌اس
 */
function wto_farazsms_dashboard_feed_content() {
	$banner_url = 'https://farazsms.com/plugin/farazsms/news/banner.jpg';
	if ( wto_farazsms_banner_available() ) {
		echo '<p style="margin:0 0 12px 0;"><a href="https://farazsms.com/" target="_blank" rel="noopener"><img src="' . esc_url( $banner_url ) . '" alt="فراز اس‌ام‌اس" style="max-width:100%; height:auto; display:block; border:0;" /></a></p>';
	}

	$feed_url = 'https://farazsms.com/feed/';
	$cache_key = 'wto_farazsms_feed_items';
	$cached = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		wto_farazsms_render_feed_items( $cached );
		return;
	}

	if ( ! function_exists( 'fetch_feed' ) ) {
		require_once ABSPATH . WPINC . '/feed.php';
	}

	$feed = fetch_feed( $feed_url );
	if ( is_wp_error( $feed ) ) {
		echo '<p>' . esc_html__( 'دریافت فید امکان‌پذیر نبود.', 'wto' ) . '</p>';
		return;
	}

	$maxitems = $feed->get_item_quantity( 4 );
	$items = $feed->get_items( 0, $maxitems );
	$out = array();

	foreach ( $items as $item ) {
		$out[] = array(
			'title' => $item->get_title(),
			'link'  => $item->get_permalink(),
			'date'  => $item->get_date( 'Y/m/d' ),
		);
	}

	set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
	wto_farazsms_render_feed_items( $out );
}

/**
 * چاپ لیست آیتم‌های فید در داشبورد
 *
 * @param array $items آرایه‌ای از آیتم‌ها با کلیدهای title, link, date
 */
function wto_farazsms_render_feed_items( $items ) {
	if ( empty( $items ) ) {
		echo '<p>' . esc_html__( 'مطلبی یافت نشد.', 'wto' ) . '</p>';
		return;
	}

	echo '<ul class="wto-farazsms-feed-list" style="margin:0; padding-right:1em;">';
	foreach ( $items as $item ) {
		$title = isset( $item['title'] ) ? $item['title'] : '';
		$link  = isset( $item['link'] ) ? $item['link'] : '';
		$date  = isset( $item['date'] ) ? $item['date'] : '';
		if ( ! $title || ! $link ) {
			continue;
		}
		echo '<li style="margin-bottom:10px;">';
		echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>';
		if ( $date ) {
			echo ' <span style="color:#646970; font-size:12px;">(' . esc_html( $date ) . ')</span>';
		}
		echo '</li>';
	}
	echo '</ul>';
	echo '<p style="margin-top:10px;"><a href="https://farazsms.com/" target="_blank" rel="noopener">' . esc_html__( 'سایت فراز اس‌ام‌اس', 'wto' ) . '</a></p>';
}

add_action( 'wp_dashboard_setup', 'wto_farazsms_dashboard_feed_widget' );

/**
 * Enqueue order-review.css only on the page that actually renders [order_review].
 * Previously this CSS was loaded on every frontend page, which leaked generic
 * class names like .review-form, .product-review-info into themes and other plugins.
 */
function enqueue_order_review_styles() {
    if ( is_admin() ) {
        return;
    }
    if ( ! is_singular() ) {
        return;
    }
    $post = get_post();
    if ( ! $post || ! has_shortcode( (string) $post->post_content, 'order_review' ) ) {
        return;
    }
    wp_enqueue_style( 'order-review-style', WTO_CORE_CSS . 'order-review.css', array(), '1.0', 'all' );
}
add_action( 'wp_enqueue_scripts', 'enqueue_order_review_styles' );

function order_review_shortcode($atts) {
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        return "<p>سفارش پیدا نشد.</p>";
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return "<p>سفارش معتبر نیست.</p>";
    }

    // Ownership check — prevent IDOR (anyone could view any order via ?order_id=N before this).
    // TODO(future): if anonymous email-link access is required, add a signed token in the URL.
    if ( ! is_user_logged_in() ) {
        return '<p>برای مشاهده سفارش، ابتدا وارد حساب خود شوید.</p>';
    }
    if ( (int) $order->get_customer_id() !== get_current_user_id()
         && ! current_user_can( 'manage_woocommerce' ) ) {
        return '<p>دسترسی غیرمجاز.</p>';
    }

    $first_name = $order->get_billing_first_name();
    $last_name = $order->get_billing_last_name();
    $full_name = trim($first_name . ' ' . $last_name);

    $output = '<div class="order-review-container">';
    $output .= '<h2>بررسی سفارش #' . esc_html($order_id) . '</h2>';

    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if (!$product) continue;

        $product_id = $product->get_id();
        $product_name = $product->get_name();
        $product_image = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), 'medium')[0];
        $product_description = $product->get_short_description();

        $output .= '<div class="product-review-box">';
        $output .= '<div class="product-review-info">';
        $output .= '<img src="' . esc_url($product_image) . '" alt="' . esc_attr($product_name) . '">';
        $output .= '<div>';
        $output .= '<h3>' . esc_html($product_name) . '</h3>';
        $output .= '<p>' . esc_html($product_description) . '</p>';
        $output .= '</div></div>';

        $comments = get_comments([
            'post_id' => $product_id,
            'meta_key' => 'order_id',
            'meta_value' => $order_id,
            'status' => 'approve',
        ]);

        if (!empty($comments)) {
            $output .= '<div class="review-list">';
            $output .= '<h4>نظرات ثبت شده:</h4>';
            foreach ($comments as $comment) {
                $comment_rating = get_comment_meta($comment->comment_ID, 'rating', true);
                $comment_name = get_comment_meta($comment->comment_ID, 'reviewer_name', true);
                $comment_text = $comment->comment_content;
                $output .= '<div class="single-review">';
                $output .= '<strong>' . esc_html($comment_name) . '</strong>: ⭐ ' . esc_html($comment_rating) . '<br>';
                $output .= '<p>' . esc_html($comment_text) . '</p>';
                $output .= '</div>';
            }
            $output .= '</div>';
        }

        $output .= '
        <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="review-form">
            <input type="hidden" name="action" value="wto_submit_review">
            ' . wp_nonce_field('submit_review_' . $product_id, 'review_nonce_' . $product_id, true, false) . '
            <label>امتیاز:</label>
            <select name="rating" required>
                <option value="">انتخاب کنید</option>
                <option value="5">عالی</option>
                <option value="4">خوب</option>
                <option value="3">متوسط</option>
                <option value="2">ضعیف</option>
                <option value="1">خیلی ضعیف</option>
            </select>
            <label>نظر شما:</label>
            <textarea name="comment" required></textarea>
            <label>نام شما:</label>
            <input type="text" name="reviewer_name" value="' . esc_attr($full_name) . '" required>
            <input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">
            <input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">
            <input type="submit" value="ارسال نظر">
        </form>';

        $output .= '</div>'; // end product box
    }

    $output .= '</div>'; // end container

    return $output;
}
add_shortcode('order_review', 'order_review_shortcode');

/**
 * Handle order-review submission via admin-post.php instead of scanning $_POST on every init.
 * Previously a closure on init scanned the entire $_POST array on every request site-wide,
 * which hurt TTFB on every page load.
 */
add_action( 'admin_post_wto_submit_review', 'wto_handle_submit_review' );
function wto_handle_submit_review() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'برای ارسال نظر، ابتدا وارد شوید.', '', array( 'response' => 403 ) );
    }

    $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    $order_id   = isset( $_POST['order_id'] )   ? intval( $_POST['order_id'] )   : 0;
    if ( ! $product_id || ! $order_id ) {
        wp_die( 'پارامترهای ناقص.', '', array( 'response' => 400 ) );
    }

    check_admin_referer( 'submit_review_' . $product_id, 'review_nonce_' . $product_id );

    // Ownership check — the submitter must own the order being reviewed.
    $order = wc_get_order( $order_id );
    if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
        wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
    }

    $rating        = isset( $_POST['rating'] ) ? intval( $_POST['rating'] ) : 0;
    $comment       = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );
    $reviewer_name = sanitize_text_field( wp_unslash( $_POST['reviewer_name'] ?? '' ) );
    if ( $rating < 1 || $rating > 5 || $comment === '' ) {
        wp_die( 'مقدار امتیاز یا متن نظر نامعتبر است.', '', array( 'response' => 400 ) );
    }

    $current_user = wp_get_current_user();
    $commentdata = array(
        'comment_post_ID'      => $product_id,
        'comment_author'       => $reviewer_name !== '' ? $reviewer_name : $current_user->display_name,
        'comment_author_email' => $current_user->user_email,
        'user_id'              => $current_user->ID,
        'comment_content'      => $comment,
        // Held for moderation by default — site owner approves manually (not auto-approve).
        'comment_approved'     => 0,
        'comment_type'         => '',
    );

    $comment_id = wp_insert_comment( $commentdata );
    if ( $comment_id ) {
        add_comment_meta( $comment_id, 'rating', $rating );
        add_comment_meta( $comment_id, 'order_id', $order_id );
        add_comment_meta( $comment_id, 'reviewer_name', $reviewer_name );

        wp_safe_redirect( add_query_arg( 'review_submitted', 'true', wp_get_referer() ) );
        exit;
    }

    wp_die( 'خطا در ثبت نظر.', '', array( 'response' => 500 ) );
}



function create_order_review_page() {
    $page_title   = 'orderreview';
    $page_content = '[order_review]';

    // get_page_by_title was deprecated in WP 6.2 — use WP_Query instead.
    $query = new WP_Query( array(
        'post_type'              => 'page',
        'title'                  => $page_title,
        'posts_per_page'         => 1,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'fields'                 => 'ids',
    ) );
    $existing_id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

    if ( ! $existing_id ) {
        wp_insert_post( array(
            'post_title'   => $page_title,
            'post_content' => $page_content,
            'post_status'  => 'publish',
            'post_author'  => 1,
            'post_type'    => 'page',
        ) );
    }
}

// Activation hook is registered in the main plugin file (faraz-sms.php) using __FILE__
// so the plugin keeps working even if the directory is renamed.

/*
*
*  Includes
*
*/
// ──────────────────────────────────────────────────────────────────────────
//  بارگذاری ماژول‌ها — تقسیم به سه گروه برای بهینه‌سازی performance
//
//   1) Always — هسته‌ی افزونه که روی هر سایتی لازم است
//   2) WC-only — فقط اگر WooCommerce فعال باشد (تا سایت‌های بدون WC کند نشوند)
//   3) PWSMS-only — وابسته به افزونه پیامک ووکامرس
//
//  این فایل قبل از بارگذاری کامل پلاگین‌ها اجرا می‌شود، پس از option active_plugins
//  برای تشخیص استفاده می‌کنیم (نه class_exists که ترتیب-وابسته است).
// ──────────────────────────────────────────────────────────────────────────

// Dependency detection helpers — load first so other modules can use them.
require_once WTO_CORE_INC . 'wto-deps.php';
// v3.18.0: Object cache wrapper (Redis/Memcached aware). Load early so any
// downstream module can use wto_cache_get/set/delete.
require_once WTO_CORE_INC . 'wto-cache.php';
// v3.18.0: Archive cron — هفتگی رکوردهای قدیمی log را پاک می‌کند.
// روی ۱۰۰k سایت، جلوگیری از رشد بی‌کنترل DB. قابل خاموش‌سازی با constant.
require_once WTO_CORE_INC . 'wto-data-archival.php';
// v3.18.0: Async task helper (Action Scheduler-aware). برای عملیات سنگین.
require_once WTO_CORE_INC . 'wto-async-tasks.php';
// v3.19.0: Rate-limit helper برای endpoint های public — حفاظت در برابر spam/abuse.
require_once WTO_CORE_INC . 'wto-rate-limit.php';

// کیف‌پولِ بومیِ مشتری — مستقل از افزونه‌ی ورود/ثبت‌نام. هدیه‌ی عضویت + مصرف سرِ checkout.
require_once WTO_CORE_INC . 'wto-wallet.php';

// v3.16.0: سیستم به‌روزرسانی خودکار از GitLab خودی — همه جا بارگذاری می‌شود.
// مستقل از WC. hook ها فقط در صفحه افزونه‌ها/cron WP فعال می‌شوند.
require_once WTO_CORE_INC . 'wto-self-update.php';

// v3.17.4: ویجت KPI پنل پیشخوان وردپرس — addictive داده‌محور
// (هیچ API call روی dashboard load — همه از transient cache می‌آیند)
require_once WTO_CORE_INC . 'wto-dashboard-kpi-widget.php';

// v3.17.4: همگام‌سازی Gravity Forms → دفترچه تلفن فراز
// hooks فقط در صورت فعال بودن GF فعال می‌شوند، خود فایل با gf_is_active() گارد دارد.
require_once WTO_CORE_INC . 'wto-gf-phonebook-sync.php';

// v3.17.6: همگام‌سازی فیلد متا اختصاصی (user_meta) → دفترچه تلفن فراز
// برای افزونه‌هایی مثل Digits که شماره را در user_meta ذخیره می‌کنند.
require_once WTO_CORE_INC . 'wto-custom-meta-phonebook.php';

// ── گروه ۱: همیشه بارگذاری شود ─────────────────────────────────────────────
// Faraz SMS API helpers (وابسته به ووکامرس نیست — استفاده می‌شود همه جا)
require_once WTO_CORE_INC . 'wto-sms-api.php';
// نگهبانِ اتصال (تشخیصِ مسدودسازیِ درخواست‌ها) + سازگاری با WP Rocket
require_once WTO_CORE_INC . 'wto-connectivity-guard.php';
require_once WTO_CORE_INC . 'wto-wp-rocket-compat.php';
// API عمومیِ برنامه‌نویسان (توابع پایدارِ ارسال/پترن/دفترچه‌تلفن برای سایر افزونه‌ها)
require_once WTO_CORE_INC . 'wto-developer-api.php';
require_once WTO_CORE_INC . 'wto-feedback-ticket.php';
// گزارشات ارسال پیامک (Send Requests) — صفحه گزارش‌گیری از API فراز اس‌ام‌اس
require_once WTO_CORE_INC . 'wto-send-reports.php';
// v3.17.2: DLR Dashboard — وضعیت تحویل پیامک به مشتری.
// وابسته به wto_send_reports_api_get() — باید بعد از wto-send-reports.php load شود.
require_once WTO_CORE_INC . 'wto-dlr-dashboard.php';
// خبرنامه پیامکی — مشترکین، شورت‌کد، ویجت، ارسال گروهی
require_once WTO_CORE_INC . 'wto-newsletter.php';
// ارسال پیامک از داخل سایت — صفحه ارسال + تاریخچه
require_once WTO_CORE_INC . 'wto-send-sms.php';
// قاب یکپارچه (Unified Dashboard Frame) — صفحه داشبورد + Frame مشترک برای همه صفحات
require_once WTO_CORE_INC . 'wto-unified-dashboard.php';
// سازماندهی و گروه‌بندی منو + onboarding + راهنماهای صفحه‌محور
require_once WTO_CORE_INC . 'wto-menu-organizer.php';
// Load the admin settings
require_once WTO_CORE_INC . 'wto-settings.php';
// Load the Functions
require_once WTO_CORE_INC . 'wto-core-functions.php';
// Load the ajax Request
require_once WTO_CORE_INC . 'wto-ajax-request.php';
require_once WTO_CORE_INC . 'wto-sync-sender-to-feeds.php';
// Load comments SMS module (form field, meta, send on events)
require_once WTO_CORE_INC . 'wto-comments.php';
// OTP backend for form verification (Gravity Forms & Elementor)
require_once WTO_CORE_INC . 'wto-otp.php';
// خرید شارژ از داخل افزونه — بسته‌های پیشنهادی + مبلغ دلخواه + درگاه پرداخت فراز
require_once WTO_CORE_INC . 'wto-wallet-recharge.php';

// v3.13.20: ماژول ورود/ثبت‌نام — فقط در صورت فعال بودن toggle، خود فایل ماژول
// بارگذاری می‌شود. در حالت پیش‌فرض (toggle خاموش)، **هیچ کدی** از این ماژول
// اجرا نمی‌شود — تا با سایر افزونه‌های login/register تداخل نکند.
require_once WTO_CORE_INC . 'wto-login-module-bridge.php';

// v3.14.2: کش‌بک — admin part همیشه load می‌شود تا منو + صفحه تنظیمات قابل
// دسترسی باشد. WC hooks داخل خود فایل با function_exists('WC') گارد شده‌اند،
// پس روی سایت بدون WC هیچ side-effect ندارد و فقط نوتیس «WC نیاز است» نمایش می‌دهد.
require_once WTO_CORE_INC . 'wto-cashback.php';

// v3.14.5: فراز بین — صفحه ادمین toggle. فیچر هسته‌ای (meta tags + sitemap)
// در wto-product-meta-tags.php است که بخشی از گروه WC-only است. این فایل
// همیشه load می‌شود تا منوی ادمین در دسترس باشد.
require_once WTO_CORE_INC . 'wto-farazbin.php';

// v3.14.8: گزارش پیامکی روزانه فروش — cron daily + UI inject شده در صفحه تنظیمات.
// خود فایل with function_exists('wc_get_orders') گارد شده — روی سایت بدون WC،
// cron اجرا نمی‌شود ولی UI همچنان قابل دسترس است.
require_once WTO_CORE_INC . 'wto-daily-sales-report.php';

// v3.17.0: تبریک تولد + کوپن اختصاصی — admin part همیشه load می‌شود.
// WC-only hook ها (checkout, my-account, coupon) داخل خود فایل با
// wto_birthday_is_enabled() و function_exists گارد شده‌اند.
require_once WTO_CORE_INC . 'wto-birthday.php';
require_once WTO_CORE_INC . 'wto-user-panel.php';
require_once WTO_CORE_INC . 'wto-ui-toggle.php';
// یادآورِ هدیه‌ی لید مگنت — پیامک به کاربرانی که هدیه گرفته‌اند ولی خرید نکرده‌اند
require_once WTO_CORE_INC . 'wto-lead-magnet-reminder.php';
require_once WTO_CORE_INC . 'wto-analytics-matomo.php';
// پیامکِ خوش‌آمدگوییِ عضویت + اطلاع‌رسانیِ ورود (زیرمنوی ماژولِ ورود/ثبت‌نام)
require_once WTO_CORE_INC . 'wto-login-welcome-sms.php';

// ── گروه ۲: فقط در صورت فعال بودن WooCommerce ─────────────────────────────
if ( wto_is_wc_active() ) {
	// موجود شد خبرم کن — دکمه روی صفحه محصول ووکامرس + ارسال خودکار پیامک
	require_once WTO_CORE_INC . 'wto-notify-me.php';
	// سبد خرید رها‌شده — tracking + cron + dashboard آماری
	require_once WTO_CORE_INC . 'wto-abandoned-cart.php';
	// نظرسنجی پس از خرید — رابط جدید + راهنما + ساخت الگو + آمار
	require_once WTO_CORE_INC . 'wto-survey.php';
	// Order SMS dedupe + meta tags + scheduled sms — همگی به سفارش/محصول WC مرتبط‌اند
	require_once WTO_CORE_INC . 'wto-order-sms-dedupe.php';
	require_once WTO_CORE_INC . 'wto-product-meta-tags.php';
	// v3.14.10: ثبت لاگ کدهای رهگیری ارسال‌شده — وابسته به WC چون از wc_get_order
	// استفاده می‌کند. در صفحه تنظیمات (تب «گزارش ارسال‌ها») رندر می‌شود.
	require_once WTO_CORE_INC . 'wto-tracking-log.php';
	// v3.15.0: داشبورد ROI — تجمیع revenue از cashback + abandoned + survey + tracking.
	// خود فایل از جدول‌های ماژول‌های دیگر می‌خواند، پس باید بعد از آن‌ها require شود.
	// HPOS-aware و با transient cache ۳۰ دقیقه‌ای.
	require_once WTO_CORE_INC . 'wto-roi-dashboard.php';
	// پیامک زمان‌دار (اتومیشن مارکتینگ) — زیرمنوی فراز اس ام اس
	require_once dirname( __FILE__ ) . '/modules/faraz-woo-scheduled-sms/core-init.php';
}

// ── گروه ۳: ادغام میهن پنل — فقط در صورت فعال بودن آن ─────────────────────
if ( wto_is_mihanpanel_active() ) {
	require_once WTO_CORE_INC . 'wto-mihanpanel-provider.php';
}

// ── گروه ۴: ادغام با افزونه پیامک ووکامرس (PWSMS) ─────────────────────────
// نکته بحرانی (v3.13.14 رفع شد): این فایل filter زیر را register می‌کند:
//
//   pwoosms_sms_gateways
//
// که گیتوی‌های FarazSMSNext و IranPayamak را به افزونه «پیامک حرفه‌ای ووکامرس»
// اضافه می‌کند. در v3.13.6 این به اشتباه پشت چک wto_is_pwsms_active() قرار گرفت —
// که با active_plugins option detect می‌کرد و روی slug های متفاوت (mirror ها،
// نسخه pro، ...) fail می‌شد. نتیجه: گیتوی فراز از لیست افزونه PWSMS غایب می‌شد.
//
// راه‌حل: filter را همیشه register کنیم. اگر PWSMS نباشد، این filter هرگز
// fire نمی‌شود (چون pwoosms_sms_gateways فقط در PWSMS تعریف شده). پس detection
// لازم نیست و این کار بی‌خطر است.
require_once WTO_CORE_INC . 'wto-integration-pwsms.php';
// PWSMS onboarding bridge: bidirectional Api-Key sync + connection panel + test SMS
require_once WTO_CORE_INC . 'wto-pwsms-onboarding.php';
// یکپارچگی با اسپات‌پلیر — تزریقِ زیرمنو + ارسالِ لایسنس با پترنِ فراز (فقط وقتی اسپات‌پلیر نصب است)
require_once WTO_CORE_INC . 'wto-integration-spotplayer.php';
// یکپارچگی با افزونه‌ی «حمل و نقل ووکامرس» (تاپین) — ارسالِ خودکارِ کدِ رهگیری + رویدادهای پیامکی با پترنِ فراز
require_once WTO_CORE_INC . 'wto-integration-pws-tapin.php';

// Define FarazSMS Next module constants
if (!defined('FARAZSMS_NEXT_PLUGIN_DIR')) {
	define('FARAZSMS_NEXT_PLUGIN_DIR', dirname(__FILE__) . '/modules/farazsms-next/');
}
if (!defined('FARAZSMS_NEXT_PLUGIN_URL')) {
	define('FARAZSMS_NEXT_PLUGIN_URL', plugins_url('modules/farazsms-next/', __FILE__));
}
if (!defined('FARAZSMS_NEXT_VERSION')) {
	// Always mirror the main plugin version so the two constants cannot drift on release.
	define('FARAZSMS_NEXT_VERSION', defined('FARAZSMS_PLUGIN_VERSION') ? FARAZSMS_PLUGIN_VERSION : '0.0.0');
}

// Load FarazSMS Next module
if (file_exists(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-admin-menu.php')) {
	require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/lead-magnet-helpers.php';
	require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-admin-menu.php';
	require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-admin-page.php';
	require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-phonebook-api.php';
	// همگام‌سازی خودکار سفارش ووکامرس → دفترچه تلفن — فقط در صورت فعال بودن WC
	if ( wto_is_wc_active() ) {
		require_once WTO_CORE_INC . 'wto-phonebook-wc-auto.php';
	}
	require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-frontend.php';
	
	// Initialize admin menu
	if (is_admin()) {
		$farazsms_next_admin_menu = new FarazSMS_Next_Admin_Menu();
		$farazsms_next_admin_menu->init();
	}
	
	// Initialize frontend
	if (!is_admin()) {
		new FarazSMS_Next_Frontend();
	}
	
	// Register AJAX handlers for Gravity Forms SMS (must be done early)
	add_action('admin_init', function() {
		if (class_exists('GFForms')) {
			// Load configurations class if not already loaded
			if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_Configurations')) {
				require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-configurations.php';
			}
			// Register AJAX handlers
			if (class_exists('FarazSMS_Next_Gravity_Forms_SMS_Configurations')) {
				add_action('wp_ajax_farazsms_select_form', array('FarazSMS_Next_Gravity_Forms_SMS_Configurations', 'select_form_ajax'), 10);
				add_action('wp_ajax_farazsms_create_pattern', array('FarazSMS_Next_Gravity_Forms_SMS_Configurations', 'create_pattern_ajax'), 10);
				add_action('wp_ajax_farazsms_get_pattern', array('FarazSMS_Next_Gravity_Forms_SMS_Configurations', 'get_pattern_ajax'), 10);
			}
		}
		
		// Register AJAX handlers for Elementor SMS
		if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
			// Load configurations class if not already loaded
			if (!class_exists('FarazSMS_Next_Elementor_SMS_Configurations')) {
				require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-configurations.php';
			}
			// Register AJAX handlers
			if (class_exists('FarazSMS_Next_Elementor_SMS_Configurations')) {
				add_action('wp_ajax_farazsms_elementor_select_form', array('FarazSMS_Next_Elementor_SMS_Configurations', 'select_form_ajax'), 10);
				add_action('wp_ajax_farazsms_elementor_create_pattern', array('FarazSMS_Next_Elementor_SMS_Configurations', 'create_pattern_ajax'), 10);
				add_action('wp_ajax_farazsms_elementor_get_pattern', array('FarazSMS_Next_Elementor_SMS_Configurations', 'get_pattern_ajax'), 10);
			}
		}
	}, 5);
	
	// v3.20.8: Load Gravity Forms / Elementor SMS classes on `init` instead of
	// `plugins_loaded` — تا WP 6.7+ Notice برای textdomain "triggered too early"
	// چاپ نشود. این Notice ها می‌توانند با AJAX responses checkout قاطی شوند.
	add_action('init', function() {
		if (class_exists('GFForms')) {
			require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms.php';
			if ( class_exists('FarazSMS_Next_Gravity_Forms_SMS') ) {
				FarazSMS_Next_Gravity_Forms_SMS::construct();
			}
		}
	}, 11);

	add_action('init', function() {
		if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
			require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms.php';
			if ( class_exists('FarazSMS_Next_Elementor_SMS') ) {
				FarazSMS_Next_Elementor_SMS::construct();
			}
		}
	}, 12);
}

/**
 * نمایش اعتبار پنل در نوار بالای پیشخوان (Admin Bar)
 */
function wto_admin_bar_credit( $wp_admin_bar ) {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'wto_show_credit_in_admin_bar', '1' ) !== '1' ) {
		return;
	}
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( empty( $apikey ) || ! function_exists( 'wto_get_credit' ) ) {
		return;
	}
	$credit = wto_get_credit();
	if ( $credit === false || $credit === '' ) {
		return;
	}
	$wp_admin_bar->add_node( array(
		'id'     => 'wto-panel-credit',
		'title'  => 'اعتبار پنل فراز اس ام اس: ' . $credit . ' تومان',
		'parent' => 'top-secondary',
		'href'   => admin_url( 'admin.php?page=farazwto-settings' ),
		'meta'   => array( 'title' => 'تنظیمات فراز اس ام اس' ),
	) );
}
add_action( 'admin_bar_menu', 'wto_admin_bar_credit', 999 );