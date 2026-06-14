<?php
/**
 * Lead Magnet Box Template
 *
 * Security note: every translatable string is treated as untrusted. Translators
 * MUST NOT be able to inject HTML. We split static markup from translated text
 * and use esc_html_e / esc_html__ for plain strings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = function_exists( 'farazsms_next_get_lead_magnet_settings' )
	? farazsms_next_get_lead_magnet_settings()
	: get_option( 'farazsms_next_lead_magnet_settings', array() );

$credit_amount    = isset( $settings['credit_amount'] ) ? (int) $settings['credit_amount'] : 0;
$expiry_days      = isset( $settings['expiry_days'] ) ? (int) $settings['expiry_days'] : 0;
$display_position = isset( $settings['display_position'] ) ? sanitize_html_class( $settings['display_position'] ) : 'bottom-right';
$shop_name        = isset( $settings['shop_name'] ) && ! empty( $settings['shop_name'] )
	? (string) $settings['shop_name']
	: (string) get_bloginfo( 'name' );

// v3.17.4: متن‌های قابل تنظیم با placeholder substitution
$badge_text          = isset( $settings['badge_text'] ) ? (string) $settings['badge_text'] : '🔥 فقط امروز';
$title_template      = isset( $settings['title_template'] ) ? (string) $settings['title_template'] : '{amount} تومان هدیه!';
$headline_template   = isset( $settings['headline_template'] ) ? (string) $settings['headline_template'] : 'با عضویت در {shop}، اعتبار رایگان بگیر و اولین خریدت رو ارزون‌تر کن.';
$disclaimer_template = isset( $settings['disclaimer_template'] ) ? (string) $settings['disclaimer_template'] : '⏰ این هدیه فقط {days} روز اعتبار دارد';
$cta_text            = isset( $settings['cta_text'] ) ? (string) $settings['cta_text'] : '🎁 دریافت اعتبار هدیه';

// placeholder rendering: {amount} {shop} {days} — هرکدام در template مربوطه جایگزین می‌شود.
// amount جوری render می‌شود که با span رنگی نمایش داده شود تا highlight داشته باشد.
$title_rendered = strtr( $title_template, array(
	'{amount}' => '<span class="lead-magnet-amount">' . esc_html( number_format_i18n( $credit_amount ) ) . '</span>',
) );
$headline_rendered = strtr( $headline_template, array(
	'{shop}' => '<strong>' . esc_html( $shop_name ) . '</strong>',
) );
$disclaimer_rendered = strtr( $disclaimer_template, array(
	'{days}' => (string) (int) $expiry_days,
) );

$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
$utm_source  = $site_domain ? rawurlencode( $site_domain ) : '';
$powered_by_url = 'https://farazsms.com/?utm_source=' . $utm_source . '&utm_medium=plugin&utm_campaign=leadmagnet&utm_content=free_plugin';
?>

<div class="farazsms-lead-magnet-box" data-position="<?php echo esc_attr( $display_position ); ?>">
	<button class="farazsms-lead-magnet-close" aria-label="<?php esc_attr_e( 'بستن', 'farazsms-next' ); ?>">
		<span class="close-icon">×</span>
	</button>
	<div class="farazsms-lead-magnet-content">
		<!-- v3.17.4: تمام متن‌ها از تنظیمات افزونه قابل تنظیم هستند، با
		     placeholder های {amount}، {shop}، {days} -->
		<div class="lead-magnet-badge"><?php echo esc_html( $badge_text ); ?></div>
		<h2 class="lead-magnet-title">
			<?php
			// title_rendered شامل HTML کوچک (span رنگی) است — kses post با whitelist عمومی
			echo wp_kses(
				$title_rendered,
				array( 'span' => array( 'class' => true ), 'strong' => array(), 'br' => array() )
			);
			?>
		</h2>
		<div class="lead-magnet-main-content">
			<div class="lead-magnet-text">
				<p class="lead-magnet-headline">
					<?php
					echo wp_kses(
						$headline_rendered,
						array( 'strong' => array(), 'span' => array( 'class' => true ), 'br' => array() )
					);
					?>
				</p>
				<p class="lead-magnet-disclaimer"><?php echo esc_html( $disclaimer_rendered ); ?></p>
			</div>
			<div class="lead-magnet-countdown-circle">
				<svg class="countdown-svg" viewBox="0 0 100 100">
					<circle class="countdown-circle-bg" cx="50" cy="50" r="45"></circle>
					<circle class="countdown-circle-progress" cx="50" cy="50" r="45"></circle>
				</svg>
				<div class="countdown-time">
					<span class="countdown-seconds">00</span>
					:
					<span class="countdown-minutes">01</span>
				</div>
			</div>
		</div>
		<button class="lead-magnet-cta-button"><?php echo esc_html( $cta_text ); ?></button>
		<div class="lead-magnet-powered">
			<?php
			// Static "powered by" link — translators cannot inject HTML because we render
			// the <a> tag from PHP, not from the translation string.
			printf(
				/* translators: %s is the FarazSMS link rendered as HTML */
				esc_html__( 'قدرت گرفته از %s', 'farazsms-next' ),
				'<a href="' . esc_url( $powered_by_url ) . '" target="_blank" rel="noopener">FarazSMS</a>'
			);
			?>
		</div>
	</div>
</div>
