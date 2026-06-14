<?php
/*
 * فراز اس ام اس – متاتگ و sitemap محصولات (ادغام در افزونه)
 * Product meta tags + Product sitemap (با پیشوند wto_ برای جلوگیری از تداخل)
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * v3.14.5: بررسی فعال بودن «فراز بین» — اگر toggle خاموش است، هیچ meta tag
 * روی صفحه محصول و هیچ sitemap اختصاصی منتشر نمی‌شود.
 *
 * این option همیشه وجود دارد — پیش‌فرض '1' (فعال).
 */
function wto_farazbin_is_enabled() {
	return get_option( 'wto_farazbin_enabled', '1' ) === '1';
}

// متاتگ‌های سفارشی در صفحه محصول
function wto_add_product_meta_tags() {
	// فراز بین خاموش → meta tag منتشر نشود
	if ( ! wto_farazbin_is_enabled() ) {
		return;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	// Use a local variable rather than mutating the $product global from within the head hook.
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$product_title         = $product->get_title();
	$product_regular_price = $product->get_regular_price();
	$product_sale_price    = $product->get_sale_price();
	$product_sku           = $product->get_sku();
	$product_available     = $product->is_in_stock() ? '1' : '0';
	$product_discount_pct  = '';

	if ( ! empty( $product_regular_price ) && ! empty( $product_sale_price ) && (float) $product_regular_price > 0 ) {
		$product_discount_pct = round( ( ( (float) $product_regular_price - (float) $product_sale_price ) / (float) $product_regular_price ) * 100 );
	}

	$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
	$product_image      = get_the_post_thumbnail_url( $product->get_id(), 'full' );

	echo "<meta name='product_title' content='" . esc_attr( $product_title ) . "' />\n";
	echo "<meta name='product_regular_price' content='" . esc_attr( $product_regular_price ) . "' />\n";
	if ( $product_sale_price ) {
		echo "<meta name='product_sale_price' content='" . esc_attr( $product_sale_price ) . "' />\n";
	}
	echo "<meta name='product_id' content='" . esc_attr( $product_sku ) . "' />\n";
	echo "<meta name='product_available' content='" . esc_attr( $product_available ) . "' />\n";
	if ( ! empty( $product_discount_pct ) ) {
		echo "<meta name='product_discount_percentage' content='" . esc_attr( $product_discount_pct ) . "' />\n";
	}
	echo "<meta name='product_category' content='" . esc_attr( is_array( $product_categories ) ? implode( ', ', $product_categories ) : '' ) . "' />\n";
	echo "<meta name='product_image' content='" . esc_url( $product_image ) . "' />\n";
}
add_action( 'wp_head', 'wto_add_product_meta_tags' );

/**
 * Safely emit a value inside <![CDATA[...]]> by neutralising any literal `]]>` in the payload.
 */
function wto_cdata_safe( $value ) {
	return str_replace( ']]>', ']]]]><![CDATA[>', (string) $value );
}

/**
 * Public product sitemap endpoint.
 *
 * Critical safety notes for 10,000-store deployments:
 *  - The endpoint is public and uncacheable by default. We MUST NOT load every
 *    product into memory (the previous `posts_per_page => -1` build was a DoS
 *    vector — a single GET could OOM PHP on shops with thousands of products).
 *  - We chunk pages of 500 products and serve a sitemap-index when no page
 *    parameter is given, plus full-output caching via transient (6 hours).
 */
function wto_generate_product_sitemap_with_meta_tags() {
	$per_page    = 500;
	$page_var    = get_query_var( 'my_sitemap_products_page' );
	$page        = $page_var !== '' ? (int) $page_var : 0;
	// v3.13.12: version-based cache key. به‌جای DELETE با LIKE روی wp_options
	// (که روی هر save_post_product یک full table scan بود)، فقط یک شمارنده‌ی
	// version را افزایش می‌دهیم. transient های قدیمی به‌طور طبیعی expire می‌شوند.
	$version     = (int) get_option( 'wto_sitemap_cache_version', 1 );
	$transient   = 'wto_sitemap_p_v' . $version . '_' . ( $page > 0 ? (int) $page : 'index' );
	$cached      = get_transient( $transient );

	header( 'Content-Type: application/xml; charset=UTF-8' );

	if ( $cached !== false ) {
		echo $cached; // phpcs:ignore — already-escaped cached XML
		exit;
	}

	ob_start();

	if ( $page <= 0 ) {
		// Sitemap index: list one entry per chunk.
		$count_q = new WP_Query( array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		$total  = (int) $count_q->found_posts;
		$chunks = max( 1, (int) ceil( $total / $per_page ) );

		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		for ( $i = 1; $i <= $chunks; $i++ ) {
			$loc = add_query_arg( 'page', $i, home_url( '/my-sitemap-products/' ) );
			echo '<sitemap>';
			echo '<loc>' . esc_url( $loc ) . '</loc>';
			echo '</sitemap>';
		}
		echo '</sitemapindex>';
	} else {
		$products = new WP_Query( array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		if ( $products->have_posts() ) {
			while ( $products->have_posts() ) {
				$products->the_post();
				$product_id = get_the_ID();
				$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( ! $product ) {
					continue;
				}
				$product_url     = get_permalink( $product_id );
				$product_lastmod = get_the_modified_time( 'Y-m-d', $product_id );

				echo '<url>';
				echo '<loc>' . esc_url( $product_url ) . '</loc>';
				echo '<lastmod>' . esc_html( $product_lastmod ) . '</lastmod>';
				echo '<changefreq>weekly</changefreq>';
				echo '<priority>0.8</priority>';
				echo '<product>';
				echo '<title><![CDATA[' . wto_cdata_safe( $product->get_name() ) . ']]></title>';
				echo '<regular_price>' . esc_html( $product->get_regular_price() ) . '</regular_price>';
				if ( $product->get_sale_price() ) {
					echo '<sale_price>' . esc_html( $product->get_sale_price() ) . '</sale_price>';
				}
				echo '<sku>' . esc_html( $product->get_sku() ) . '</sku>';
				echo '<stock>' . ( $product->is_in_stock() ? 'In Stock' : 'Out of Stock' ) . '</stock>';
				echo '<image_url>' . esc_url( get_the_post_thumbnail_url( $product_id, 'full' ) ) . '</image_url>';
				echo '</product>';
				echo '</url>';
			}
		}

		echo '</urlset>';
		wp_reset_postdata();
	}

	$xml = ob_get_clean();
	set_transient( $transient, $xml, 6 * HOUR_IN_SECONDS );
	echo $xml; // phpcs:ignore — already-escaped XML built above
	exit;
}

// Invalidate sitemap cache when products change.
// v3.13.12 SECURITY/PERF FIX: قبل از این، یک query با LIKE روی wp_options اجرا می‌شد
// (full table scan روی هر save_post_product). روی فروشگاهی با ۵۰k محصول، این
// به‌معنی تک‌تک save → full scan روی همه options سایت بود. حالا فقط شمارنده version
// را افزایش می‌دهیم — یک update_option ساده. transient های قدیمی به‌خودی خود
// در ۶ ساعت expire می‌شوند (WP cleanup).
function wto_invalidate_sitemap_cache( $post_id ) {
	if ( get_post_type( $post_id ) !== 'product' ) {
		return;
	}
	$version = (int) get_option( 'wto_sitemap_cache_version', 1 );
	update_option( 'wto_sitemap_cache_version', $version + 1, false ); // autoload=false
}
add_action( 'save_post_product', 'wto_invalidate_sitemap_cache' );
add_action( 'deleted_post', 'wto_invalidate_sitemap_cache' );

// ثبت مسیر سایت‌مپ
function wto_register_sitemap_route() {
	add_rewrite_rule(
		'^my-sitemap-products/?$',
		'index.php?my_sitemap_products=1',
		'top'
	);
	add_rewrite_rule(
		'^my-sitemap-products/page/([0-9]+)/?$',
		'index.php?my_sitemap_products=1&my_sitemap_products_page=$matches[1]',
		'top'
	);
}
add_action( 'init', 'wto_register_sitemap_route' );

// Flush rewrite rules once on activation/upgrade so the route works without saving permalinks.
function wto_maybe_flush_sitemap_rewrites() {
	if ( get_option( 'wto_sitemap_rewrites_flushed' ) === '2' ) {
		return;
	}
	wto_register_sitemap_route();
	flush_rewrite_rules( false );
	update_option( 'wto_sitemap_rewrites_flushed', '2', false );
}
add_action( 'admin_init', 'wto_maybe_flush_sitemap_rewrites' );

// هندل درخواست سایت‌مپ
function wto_handle_sitemap_request() {
	if ( get_query_var( 'my_sitemap_products' ) ) {
		// v3.14.5: اگر فراز بین خاموش است، sitemap اختصاصی هم منتشر نشود — 404.
		if ( ! wto_farazbin_is_enabled() ) {
			status_header( 404 );
			nocache_headers();
			return;
		}
		// Allow ?page=N as a fallback in addition to /page/N/
		$qp = isset( $_GET['page'] ) ? (int) $_GET['page'] : 0;
		if ( $qp > 0 ) {
			set_query_var( 'my_sitemap_products_page', $qp );
		}
		wto_generate_product_sitemap_with_meta_tags();
	}
}
add_action( 'template_redirect', 'wto_handle_sitemap_request' );

// متغیر کوئری سایت‌مپ
function wto_add_sitemap_query_var( $vars ) {
	$vars[] = 'my_sitemap_products';
	$vars[] = 'my_sitemap_products_page';
	return $vars;
}
add_filter( 'query_vars', 'wto_add_sitemap_query_var' );
