<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	Core Functions
*	
*/
// If this file is called directly, abort. //
if ( ! defined( 'WPINC' ) ) {
	die;
} // end if
/*
*
* Custom Front End Ajax Scripts / Loads In WP Footer
*
*/
function fwss_frontend_ajax_form_scripts() {
	?>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            "use strict";
            // add basic front-end ajax page scripts here
            $('#fwss_custom_plugin_form').submit(function (event) {
                event.preventDefault();
                // Vars
                var myInputFieldValue = $('#myInputField').val();
                // Ajaxify the Form
                var data = {
                    'action': 'fwss_custom_plugin_frontend_ajax',
                    'myInputFieldValue': myInputFieldValue,
                };

                // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
                var ajaxurl = "<?php echo admin_url( 'admin-ajax.php' );?>";
                $.post(ajaxurl, data, function (response) {
                    console.log(response);
                    if (response.Status == true) {
                        console.log(response.message);
                        $('#fwss_custom_plugin_form_wrap').html(response);

                    } else {
                        console.log(response.message);
                        $('#fwss_custom_plugin_form_wrap').html(response);
                    }
                });
            });
        }(jQuery));
    </script>
<?php }

add_action( 'wp_footer', 'fwss_frontend_ajax_form_scripts' );

/**
 * استخراج متغیرهای استاندارد یک سفارش ووکامرس برای جایگذاری در متن پیامک.
 * هر متغیر هم با %name% و هم {name} پشتیبانی می‌شود (برای راحتی کاربر).
 *
 * @param WC_Order|int $order_or_id
 * @param array        $extra   متغیرهای اضافی (مثلاً برای پیامک محصول، p_name/p_price)
 * @return array  key=>value برای str_replace
 */
function fwss_get_order_variables( $order_or_id, $extra = array() ) {
	$order = ( $order_or_id instanceof WC_Order ) ? $order_or_id : wc_get_order( $order_or_id );
	if ( ! $order ) {
		return array();
	}
	$first_name = (string) $order->get_billing_first_name();
	$last_name  = (string) $order->get_billing_last_name();
	$fullname   = trim( $first_name . ' ' . $last_name );
	if ( $fullname === '' ) {
		$fullname = $order->get_formatted_billing_full_name();
	}
	$products = array();
	foreach ( $order->get_items() as $it ) {
		$products[] = $it->get_name();
	}
	$vars = array(
		'order_id'          => (string) $order->get_id(),
		'sitename'          => get_bloginfo( 'name' ),
		'customer_fullname' => $fullname,
		'first_name'        => $first_name,
		'last_name'         => $last_name,
		'b_first_name'      => $first_name,
		'b_last_name'       => $last_name,
		'billing_phone'     => (string) $order->get_billing_phone(),
		'total'             => wp_strip_all_tags( wc_price( $order->get_total() ) ),
		'products'          => implode( '، ', $products ),
		'city'              => (string) $order->get_billing_city(),
		'address'           => (string) $order->get_billing_address_1(),
	);
	return array_merge( $vars, $extra );
}

/**
 * جایگذاری متغیرها در متن — هم %name% و هم {name}.
 */
function fwss_replace_variables( $message, $vars ) {
	if ( ! is_array( $vars ) || empty( $vars ) ) {
		return $message;
	}
	$search  = array();
	$replace = array();
	foreach ( $vars as $k => $v ) {
		$search[]  = '%' . $k . '%';
		$replace[] = $v;
		$search[]  = '{' . $k . '}';
		$replace[] = $v;
	}
	return str_replace( $search, $replace, (string) $message );
}

function fwss_add_scheduled_sms_postbox() {
//	if(!MfnOoziKwMxcQkAbMtMe::is_activated()){return;}
	add_meta_box(
		'fwss-scheduled-sms',
		'ارسال پیامک زماندار',
		'fwss_add_scheduled_sms_box',
		array('product','lp_course'),
		'normal',
		'core'
	);
}

add_action( 'add_meta_boxes', 'fwss_add_scheduled_sms_postbox' );

function fwss_add_scheduled_sms_box() {
	$default_send_time = get_option( 'fwss_send_time', '16:59' );
	$p_id              = get_the_ID();
	$fwss_metas        = get_post_meta( $p_id, '_fwss_sms_metas', true ) ?? [];
	$index             = ! empty( $fwss_metas ) ? max( array_keys( $fwss_metas ) ) + 1 : 0;
	if ( get_post_type() === 'product' ):
		$statuses = wc_get_order_statuses();
    elseif ( get_post_type() === 'lp_course' ):
	    $statuses = learn_press_get_order_statuses();
	endif;
	$tip = '<h4 style="margin:0 0 8px;">متغیرهای قابل استفاده در متن پیام</h4>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:6px 16px; font-size:12px;">
                                <div><strong>اطلاعات محصول:</strong></div>
                                <div></div>
                                <div>%p_name% — نام محصول</div>
                                <div>%p_price% — قیمت</div>
                                <div>%p_link% — لینک محصول</div>
                                <div></div>
                                <div><strong>اطلاعات سفارش/خریدار:</strong></div>
                                <div></div>
                                <div>%order_id% — شماره سفارش</div>
                                <div>%customer_fullname% — نام کامل</div>
                                <div>%first_name% — نام</div>
                                <div>%last_name% — نام خانوادگی</div>
                                <div>%billing_phone% — تلفن</div>
                                <div>%total% — مبلغ سفارش</div>
                                <div>%city% — شهر</div>
                                <div>%sitename% — نام سایت</div>
                            </div>
                            <p style="margin-top:8px; font-size:11px; color:#64748b;">هر متغیر را می‌توانید با هر دو قالب %نام% یا {نام} بنویسید.</p>';
	?>
    <div id="fwss_scheduled_sms_container">
        <div id="fwss_top">
            <div id="fwss_add_sms">اضافه کردن</div>
        </div>
        <div class="fwss-info-message">
		    <?php echo $tip; ?>
        </div>
        <input type="hidden" id="fwss_next_index" name="fwss_next_index" value="<?php echo $index; ?>">
        <div id="sms_container">
			<?php if ( ! empty( $fwss_metas ) ) {
				foreach ( $fwss_metas as $i => $fwss_meta ) { ?>
                    <div class="sms">
                        <div class="delete">
                            <div id="delete_row_<?php echo $i; ?>"><img
                                        src="https://img.icons8.com/windows/32/000000/macos-close.png"/></div>
                        </div>
                        <div class="active">
                            <label for="fwss_sms_active_<?php echo $i; ?>" class="toggle-control">
                                <input type="checkbox" id="fwss_sms_active_<?php echo $i; ?>"
                                       name="fwss_sms_meta[<?php echo $i; ?>][active]" <?php echo ( $fwss_meta['active'] == "on" ) ? "checked" : ''; ?>>
                                <span class="control"></span>
                            </label>
                        </div>
                        <div class="time">
                            <label for="fwss_sms_time_<?php echo $i; ?>">زمان</label>
                            <input type="number" min="0" class="fwss_time_input"
                                   name="fwss_sms_meta[<?php echo $i; ?>][time]" id="fwss_sms_time_<?php echo $i; ?>"
                                   value="<?php echo $fwss_meta['time']; ?>">
                            <span> روز بعد از</span>
                        </div>
                        <div class="order_status">
                            <label for="fwss_sms_order_status_<?php echo $i; ?>">وضعیت سفارش</label>
                            <select name="fwss_sms_meta[<?php echo $i; ?>][order_status]"
                                    id="fwss_sms_order_status_<?php echo $i; ?>">
								<?php
								foreach ( $statuses as $status => $status_name ) {
									$selected = $fwss_meta['order_status'] === $status ? "selected" : '';
									echo '<option value="' . esc_attr( $status ) . '"' . $selected . '>' . esc_html( $status_name ) . '</option>';
								}
								?>
                            </select>
                        </div>
                        <div class="hour">
                            <label for="fwss_sms_hour_<?php echo $i; ?>">ساعت</label>
                            <input type="text" placeholder="16:59" name="fwss_sms_meta[<?php echo $i; ?>][hour]"
                                   id="fwss_sms_hour_<?php echo $i; ?>"
                                   value="<?php echo ( isset( $fwss_meta['hour'] ) ) ? $fwss_meta['hour'] : $default_send_time; ?>">
                        </div>
                        <div class="sms_content">
                            <label for="fwss_sms_content_0">متن پیام</label>
                            <textarea rows="5" cols="20" name="fwss_sms_meta[<?php echo $i; ?>][content]"
                                      id="fwss_sms_order_content_<?php echo $i; ?>"><?php echo $fwss_meta['content']; ?></textarea>
                        </div>
                    </div>
				<?php }
			} ?>
        </div>
    </div>
	<?php
}

add_action( 'save_post', 'fwss_product_sms_meta_save' );
function fwss_product_sms_meta_save( $post_id ) {
    if (!isset($_POST['fwss_sms_meta'])){
        return;
    }
	$sms_metas = $_POST['fwss_sms_meta'];
	if ( ! empty( $sms_metas ) ) {
		update_post_meta( $post_id, '_fwss_sms_metas', $sms_metas );
	}
}

add_action( 'woocommerce_order_status_changed', 'fwss_order_status_change_happened', 10, 3 );
function fwss_order_status_change_happened( $order_id, $status_changed_from, $status_changed_to ) {

    
	$order  = wc_get_order( $order_id );
	$mobile = $order->get_billing_phone();
       
	if ( empty( $mobile ) ) {
		return;
	}
   
	foreach ( $order->get_items() as $item_id => $item ) {
		$product_id             = $item->get_product_id();
		$registered_sms_to_send = get_post_meta( $product_id, '_fwss_sms_metas', true );
     
		if ( empty( $registered_sms_to_send ) ) {
			continue;
		}
     
		foreach ( $registered_sms_to_send as $i => $sms_meta ) {
          
			if ( $sms_meta['order_status'] === 'wc-' . $status_changed_to && $sms_meta['active'] === "on" && isset($sms_meta['time']) && ! empty( $sms_meta['content'] ) ) {
				// v3.13.10: استفاده از helper یکپارچه — حالا همه متغیرهای سفارش/خریدار
				// در دسترس هستند (نه فقط p_name/p_price/p_link/sitename).
				$vars = fwss_get_order_variables( $order, array(
					'p_name'  => $item->get_name(),
					'p_price' => $item->get_subtotal(),
					'p_link'  => get_permalink( $product_id ),
				) );
				$message = fwss_replace_variables( $sms_meta['content'], $vars );
                

$default_hour_to_send = get_option('fwss_send_time', '16:59');
$hour_to_send = $sms_meta['hour'] ?? $default_hour_to_send;
$order_date_modified = $order->get_date_modified('Y-m-d H:i:s');
$days_to_add = $sms_meta['time'] ?? 0;
$datetime_str = date('Y-m-d', strtotime($order_date_modified . " + $days_to_add days")) . ' ' . $hour_to_send . ':00';
$date = new DateTime($datetime_str, new DateTimeZone('Asia/Tehran'));
$date->setTimezone(new DateTimeZone('UTC'));
$date_to_send = $date->format('Y-m-d\TH:i:s');

                
				fwss_send_scheduled_sms( $mobile, $date_to_send, $message );
			}
		}
	}
}

//add_filter('update_user_metadata', 'fwss_monitor_update_user_metadata',10 , 4);
function fwss_monitor_update_user_metadata( $check, $object_id, $meta_key, $meta_value ) {
	// Operator-precedence fix: previously this was `strtolower($meta_key !== 'digits_phone')`
	// which evaluated to `strtolower(bool)` and always returned ''. Correct comparison below.
	if ( strtolower( (string) $meta_key ) !== 'digits_phone' ) {
		return $check;
	}
	$fwss_active_digits = get_option( 'fwss_active_digits', 'false' );
	if ( $fwss_active_digits === 'false' ) {
		return $check;
	}
	$mobile               = $meta_value;
	$users_sms_data       = get_option( 'fwss_users_sms_data', [] );
	$user                 = get_userdata( $object_id );
	$display_name         = $user->display_name;
	$user_name            = $user->user_login;
	foreach ( $users_sms_data as $user_sms_data ) {
		$message      = str_replace( array(
			'%display_name%',
			'%username%',
		), array(
			$display_name,
			$user_name,
		), $user_sms_data['content'] );
$default_hour_to_send = get_option('fwss_send_time', '16:59');
$hour_to_send = $sms_meta['hour'] ?? $default_hour_to_send;
$order_date_modified = $order->get_date_modified('Y-m-d H:i:s');
$days_to_add = $sms_meta['time'] ?? 0;
$datetime_str = date('Y-m-d', strtotime($order_date_modified . " + $days_to_add days")) . ' ' . $hour_to_send . ':00';
$date = new DateTime($datetime_str, new DateTimeZone('Asia/Tehran'));
$date->setTimezone(new DateTimeZone('UTC'));
$date_to_send = $date->format('Y-m-d\TH:i:s');
		fwss_send_scheduled_sms( $mobile, $date_to_send, $message );
	}

	return $check;
}

add_action( 'user_register', 'fwss_after_user_registered', 99 );
function fwss_after_user_registered( $user_id ) {
	$already_sent_sms = get_user_meta( $user_id, 'fwss_sent_scheduled_sms_for_user', true );
	if ( ! empty( $already_sent_sms ) && $already_sent_sms === '1' ) {
		//return;
	}
	$fwss_active_digits          = get_option( 'fwss_active_digits', 'false' );
	$fwss_custom_phone_meta_keys = get_option( 'fwss_custom_phone_meta_keys', '' );
	if ( $fwss_active_digits === 'false' && empty( $fwss_custom_phone_meta_keys ) ) {
		return;
	}
	if ( $fwss_active_digits == 'true' ) {
		$digits_phone = get_user_meta( $user_id, 'digits_phone', true );
	} else if ( ! empty( $fwss_custom_phone_meta_keys ) ) {
		$digits_phone = get_user_meta( $user_id, $fwss_custom_phone_meta_keys, true );
	}
	if ( empty( $digits_phone ) ) {
		return;
	}
 
	$users_sms_data       = get_option( 'fwss_users_sms_data', [] );
	if ( empty( $users_sms_data ) ) {
		return;
	}
	$user         = get_userdata( $user_id );
	$display_name = $user->display_name;
	$user_name    = $user->user_login;
	foreach ( $users_sms_data as $user_sms_data ) {
		$message      = str_replace( array(
			'%display_name%',
			'%username%',
		), array(
			$display_name,
			$user_name,
		), $user_sms_data['content'] );
			// Build the scheduled send time using user_register time as the base (no $order in this scope).
			$default_hour_to_send = get_option( 'fwss_send_time', '16:59' );
			$hour_to_send         = isset( $user_sms_data['hour'] ) && $user_sms_data['hour'] !== '' ? $user_sms_data['hour'] : $default_hour_to_send;
			$base_date            = current_time( 'Y-m-d H:i:s' );
			$days_to_add          = isset( $user_sms_data['time'] ) ? (int) $user_sms_data['time'] : 0;
			$datetime_str         = gmdate( 'Y-m-d', strtotime( $base_date . " + $days_to_add days" ) ) . ' ' . $hour_to_send . ':00';
			$site_tz              = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
			$date                 = new DateTime( $datetime_str, $site_tz );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			$date_to_send = $date->format( 'Y-m-d\TH:i:s' );
			fwss_send_scheduled_sms( $digits_phone, $date_to_send, $message );
	}
	update_user_meta( $user_id, 'fwss_sent_scheduled_sms_for_user', '1' );
}

add_action( 'gform_entry_created', 'fwss_gf_field_filled' );
function fwss_gf_field_filled( $entry ) {
	$gf_sms_data = get_option( 'fwss_gf_sms_data', [] );
	foreach ( $gf_sms_data as $sms_data ) {
		$exploded = explode( "-", $sms_data['gf_formatted_id'] );
		if ( $exploded[0] !== $entry['form_id'] ) {
			continue;
		}
		$mobile = $entry[ $exploded[1] ];
		if ( empty( $mobile ) ) {
			continue;
		}
		$result = true;
		if ( ! empty( $sms_data['condition_active'] ) && $sms_data['condition_active'] === "on" ) {
			$all_condition = $sms_data['all_or_one'];
			if ( $all_condition === 'all' ) {
				foreach ( $sms_data['condition'] as $condition ) {
					$result = fwss_matches_operation( $entry[ $condition['field'] ], $condition['value'], $condition['operator'] );
					if ( ! $result ) {
						break;
					}
				}
			} elseif ( $all_condition === 'any' ) {
				foreach ( $sms_data['condition'] as $condition ) {
					$result = fwss_matches_operation( $entry[ $condition['field'] ], $condition['value'], $condition['operator'] );
					if ( $result ) {
						break;
					}
				}
			}
		}
 	if ( $result ) {
			$default_hour_to_send = get_option( 'fwss_send_time', '16:59' );
			$hour_to_send         = $sms_data['hour'] ?? $default_hour_to_send;
			$date_to_send         = date( 'Y-m-d ' . $hour_to_send, strtotime( date( "Y-m-d H:i:s" ) . ' + ' . $sms_data['time'] . ' days' ) );
			fwss_send_scheduled_sms( $mobile, $date_to_send, $sms_data['content'] );
		}

	}
}

add_action( 'gform_pre_submission', 'fwss_gf_pre_submission' );
function fwss_gf_pre_submission( $form ) {
	foreach ( $_POST as $name => $value ) {
		$_POST[ $name ] = fwss_tr_num( $value );
	}
}

function fwss_matches_operation( $val1, $val2, $operation ) {

	$val1 = ! rgblank( $val1 ) ? strtolower( $val1 ) : '';
	$val2 = ! rgblank( $val2 ) ? strtolower( $val2 ) : '';

	switch ( $operation ) {
		case 'is' :
			return $val1 == $val2;
			break;

		case 'isnot' :
			return $val1 != $val2;
			break;

		case 'greater_than':
		case '>' :
			$val1 = fwss_try_convert_float( $val1 );
			$val2 = fwss_try_convert_float( $val2 );

			return $val1 > $val2;
			break;

		case 'less_than':
		case '<' :
			$val1 = fwss_try_convert_float( $val1 );
			$val2 = fwss_try_convert_float( $val2 );

			return $val1 < $val2;
			break;

		case 'contains' :
			return ! rgblank( $val2 ) && strpos( $val1, $val2 ) !== false;
			break;

		case 'starts_with' :
			return ! rgblank( $val2 ) && strpos( $val1, $val2 ) === 0;
			break;

		case 'ends_with' :
			// If target value is a 0 set $val2 to 0 rather than the empty string it currently is to prevent false positives.
			if ( empty( $val2 ) ) {
				$val2 = '0';
			}

			$start = strlen( $val1 ) - strlen( $val2 );

			if ( $start < 0 ) {
				return false;
			}

			$tail = substr( $val1, $start );

			return $val2 == $tail;
			break;
	}


	return false;
}

function fwss_try_convert_float( $text ) {

	$number_format = 'decimal_dot';
	if ( GFCommon::is_numeric( $text, $number_format ) ) {
		return GFCommon::clean_number( $text, $number_format );
	}

	return 0;
}

add_action( 'init', 'fwss_wc_order_actions_sms' );
function fwss_wc_order_actions_sms() {
	if (!function_exists('is_woocommerce')){
        return;
    }
	$wc_sms = get_option( 'fwss_wc_sms_data' );
	if ( $wc_sms && is_array( $wc_sms ) ) {
		foreach ( $wc_sms as $sms ) {
			if ( ! isset( $sms['active'] ) ) {
				continue;
			}
			$status = str_replace( 'wc-', '', $sms['order_status'] );
			add_action( 'woocommerce_order_status_' . $status, function ( $order_id ) use ( $sms, $status ) {
				$order  = wc_get_order( $order_id );
				$mobile = $order->get_billing_phone();
				if ( empty( $mobile ) || empty( $sms['content'] ) || ! isset( $sms['time'] ) ) {
					return;
				}
				// v3.13.10: همه متغیرهای استاندارد سفارش (نه فقط order_id/sitename)
				$vars = fwss_get_order_variables( $order );
				$message = fwss_replace_variables( $sms['content'], $vars );
				// Build the scheduled send time. Local loop var is $sms (not $sms_meta).
				$default_hour_to_send = get_option( 'fwss_send_time', '16:59' );
				$hour_to_send         = isset( $sms['hour'] ) && $sms['hour'] !== '' ? $sms['hour'] : $default_hour_to_send;
				$order_modified_obj   = $order->get_date_modified();
				$order_date_modified  = $order_modified_obj ? $order_modified_obj->format( 'Y-m-d H:i:s' ) : current_time( 'Y-m-d H:i:s' );
				$days_to_add          = isset( $sms['time'] ) ? (int) $sms['time'] : 0;
				$datetime_str         = gmdate( 'Y-m-d', strtotime( $order_date_modified . " + $days_to_add days" ) ) . ' ' . $hour_to_send . ':00';
				$site_tz              = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
				$date                 = new DateTime( $datetime_str, $site_tz );
				$date->setTimezone( new DateTimeZone( 'UTC' ) );
				$date_to_send = $date->format( 'Y-m-d\TH:i:s' );
				fwss_send_scheduled_sms( $mobile, $date_to_send, $message );
			}, 10, 1 );
		}
	}
}

add_action( 'learn-press/order/status-changed', 'fwss_order_lms_changed_status', 10, 3 );
function fwss_order_lms_changed_status( $order_id, $old_status, $new_status ) {
	$order = learn_press_get_order( $order_id );
    $user_id = $order->get_user_id();
	$fwss_custom_phone_meta_keys = get_option( 'fwss_custom_phone_meta_keys', '' );
	$mobile = get_user_meta( $user_id, $fwss_custom_phone_meta_keys, true );
	if ( empty( $mobile ) ) {
		return;
	}
	foreach ( $order->get_items() as $item_id => $item ) {
		$product_id             = $item['course_id'];
		$registered_sms_to_send = get_post_meta( $product_id, '_fwss_sms_metas', true );
		if ( empty( $registered_sms_to_send ) ) {
			continue;
		}
		foreach ( $registered_sms_to_send as $i => $sms_meta ) {
			if ( $sms_meta['order_status'] === 'lp-' . $new_status && $sms_meta['active'] === "on" && ! empty( $sms_meta['time'] ) && ! empty( $sms_meta['content'] ) ) {
			$message              = str_replace( array(
				'%p_name%',
				'%p_price%',
				'%sitename%',
				'%p_link%',
			), array(
				$item['name'],
				$item['subtotal'],
				get_bloginfo( "name" ),
				get_permalink( $product_id )
			), $sms_meta['content'] );
			// $sms_meta and $order are defined in this scope (LP order loop).
			$default_hour_to_send = get_option( 'fwss_send_time', '16:59' );
			$hour_to_send         = isset( $sms_meta['hour'] ) && $sms_meta['hour'] !== '' ? $sms_meta['hour'] : $default_hour_to_send;
			$order_modified_obj   = method_exists( $order, 'get_date_modified' ) ? $order->get_date_modified() : null;
			if ( is_object( $order_modified_obj ) && method_exists( $order_modified_obj, 'format' ) ) {
				$order_date_modified = $order_modified_obj->format( 'Y-m-d H:i:s' );
			} else {
				$order_date_modified = current_time( 'Y-m-d H:i:s' );
			}
			$days_to_add  = isset( $sms_meta['time'] ) ? (int) $sms_meta['time'] : 0;
			$datetime_str = gmdate( 'Y-m-d', strtotime( $order_date_modified . " + $days_to_add days" ) ) . ' ' . $hour_to_send . ':00';
			$site_tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
			$date         = new DateTime( $datetime_str, $site_tz );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			$date_to_send = $date->format( 'Y-m-d\TH:i:s' );
			fwss_send_scheduled_sms( $mobile, $date_to_send, $message );
			}
		}
	}
}