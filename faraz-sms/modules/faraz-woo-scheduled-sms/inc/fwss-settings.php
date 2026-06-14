<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

// v3.17.6: ادغام با منوی اصلی فراز — submenu به‌جای menu_page مستقل
add_action( 'admin_menu', 'fwss_add_credentials_menu', 25 );
function fwss_add_credentials_menu() {
	add_submenu_page(
		'farazwto',
		__( 'اتومیشن مارکتینگ', 'wto' ),
		__( 'اتومیشن مارکتینگ', 'wto' ),
		'manage_options',
		'farazwto-automation',
		'fwss_admin_settings_page2'
	);
}

function fwss_admin_settings_page2() {
	$uname                  = get_option( 'fwss_uname', '' );
	$pass                   = get_option( 'fwss_pass', '' );
	$sender                 = get_option( 'fwss_sender', 'PRO' );
	if ( $sender === '' ) {
		$sender = 'PRO';
	}
	// v3.17.6: کلید دسترسی از تنظیمات اصلی افزونه فراز خوانده می‌شود — نباید دوباره گرفته شود
	$apikey = '';
	if ( function_exists( 'wto_get_apikey' ) ) {
		$apikey = wto_get_apikey();
	}
	if ( empty( $apikey ) ) {
		$apikey = get_option( 'fwss_apikey', '' );
	}
	$fwss_send_time         = get_option( 'fwss_send_time', '16:59' );
	$digits_installed       = function_exists( 'digit_ready' );
	$gravityForms_installed = class_exists( 'GFForms' );
	$ticket_subject = 'درخواست فعال سازی افزونه پیامک زماندار';
	$ticket = 'با سلام 
                     درخواست فعال سازی افزونه پیامک زماندار و خط باشگاه مشتریان را دارم و تعهد می دهم  جز در افزونه زماندار جای دیگری از خط باشگاه مشتریان استفاده نشود.
                    در صورت هر گونه سواستفاده از خط باشگاه مشتریان (ارسال پیامک از خط باشگاه مشتریان چه در افزونه های دیگر یا از طریق سامانه) فراز اس ام اس مختار است بدون اطلاع قبلی نسبت به غیرفعال سازی و مسدود نمودن پنل کاربری اقدام نمایید.
                    ارسال این درخواست و ثبت در پنل کاربری من به معنی اطلاع دقیق از قوانین خط باشگاه مشتریان بوده و من با اطلاع دقیق این شرایط را می پذیریم.
                    با احترام';
	$ticket .= '<br>' . home_url();
	?>
    <section class="wrapper">
        <div id="fwss_header">
            <div>
                <a href="https://farazsms.com" target="_blank">
                    <img src="<?php echo FWSS_CORE_IMG . 'logo-1.png'; ?>"
                         alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
                </a>
            </div>
	        <?php

		    if ( ! empty( $apikey ) ) {
	$credit = fwss_get_credit( $apikey );
 
	?>
	<div id="fwss_account_info">
		<div class="fsms_credit_amount">
			<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
			<span> تومان</span>
		</div>
	</div>
	<?php
}
	        ?>
        </div>
            <?php
            if (isset($_POST['submit_activate'])){
	            $res = fwss_send_ticket($ticket_subject,$ticket);
	            if ($res){
		            update_option('fwss_ticket_send', true, false);
		            if ( ! get_option( 'fwss_ticket_send_thanks' ) ) {
			            ?>
                        <div class="fwss_notice">
                            <p>با تشکر ، درخواست شما از طریق پیامک تا ساعتی دیگر به شما اطلاع رسانی می شود.</p>
                        </div>
			            <?php
		            }
		            update_option('fwss_ticket_send_thanks', true, false);
	            }else{
                    ?>
                    <div class="fwss_notice">
                        <p>مشکلی پیش آمده است، لطفا مجدد تلاش کنید.</p>
                    </div>
                    <?php
	            }
            }
            ?>
            <?php // v3.17.6: notice «جهت فعالسازی افزونه ابتدا کلید دسترسی...» حذف شد —
                  // کلید مستقیماً از تنظیمات اصلی افزونه فراز اس ام اس خوانده می‌شود. ?>
        <ul class="tabs">
            <li class="active">تنظیمات</li>
            <li>تنظیمات ثبت نام کاربر</li>
            <li>تنظیمات Gravity Form</li>
            <li>تنظیمات ووکامرس</li>
        </ul>
        <ul class="tab__content">
            <li class="active">
                <div class="content__wrapper">
                    <form id="fwss_settings_form" class="fwss_form form-style-2">
                        <?php // v3.17.6: فیلد کلید دسترسی حذف شد — کلید از تنظیمات اصلی افزونه می‌آید
                        if ( empty( $apikey ) ) : ?>
                            <div style="background:#fef3c7; border:1px solid #fde68a; color:#78350f; padding:12px 16px; border-radius:8px; margin-bottom:14px;">
                                ⚠️ کلید دسترسی (Api-Key) در تنظیمات اصلی افزونه وارد نشده است.
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>" style="color:#92400e; font-weight:700; text-decoration:underline;">رفتن به تنظیمات افزونه</a>
                            </div>
                        <?php else : ?>
                            <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:14px;">
                                ✓ از کلید دسترسی <strong>تنظیمات اصلی افزونه فراز اس‌ام‌اس</strong> استفاده می‌شود.
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>" style="color:#047857;">تغییر کلید</a>
                            </div>
                        <?php endif; ?>
                        <label for="fwss_sender">
                            <span class="label">خط ارسال کننده</span>
                            <input type="text" class="input-field" id="fwss_sender" name="fwss_sender"
                                   value="<?php echo esc_attr( $sender ); ?>">
                            <p class="description" style="margin-top:4px; color:#64748b; font-size:11.5px;">پیش‌فرض: <code>PRO</code> (خط باشگاه / اتومیشن). در صورت نیاز خط دلخواه خود را وارد کنید.</p>
                        </label>
                        <br><br>
                        <label for="fwss_send_time">
                            <span class="label">ساعت ارسال پیامک پیشفرض<span class="required">*</span></span>
                            <input type="text" class="input-field" id="fwss_send_time" name="fwss_send_time"
                                   value="<?php echo $fwss_send_time; ?>">
                        </label>
                        <br><br>
                        <div class="fwss-info-message digits_pattern_info">
                            افزونه پیامک زمان دار با پلاگین Learn Press نیز هماهنگ میباشد، جهت مشاهده تنظیمات به صفحه ویرایش هر دوره مراجعه فرمایید.
                        </div>
                        <br><br>
                        <div class="fwss_save_button_container">
                            <button type="submit" class="fwss_button" id="fwss_save_button"><span class="button__text">ذخیره</span>
                            </button>
                            <div id="fwss-response-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </li>
            <li>
                <div class="content__wrapper">
                    <div id="fwss_users_plugin_settings">
						<?php
						$fwss_active_digits = get_option( 'fwss_active_digits', 'false' );
						?>
                        <form id="fwss_users_settings_form" class="fwss_form fwss_form form-style-2">
                            <label for="fwss_active_digits" class="toggle-control">
                                <span class="label" style="padding-top: 0;">ارسال پیامک برای ثبت نام دیجیتس؟</span>
                                <input type="checkbox" id="fwss_active_digits"
                                       name="fwss_active_digits" <?php echo( $fwss_active_digits === 'true' && $digits_installed ? 'checked' : '' );
								echo( ! $digits_installed ? ' disabled' : '' ) ?>>
                                <span class="control <?php echo( ! $digits_installed ? 'not-allowed' : '' ) ?>"></span>
								<?php if ( ! $digits_installed ) { ?>
                                    <div class="fsms-warning-message enter-credentials warning_phonebook">افزونه دیجیتس
                                        نصب نیست
                                    </div>
								<?php } ?>
                            </label>
                            <br>
                            <br>
                            <div class="fwss_form_element">
                                <label for="fwss_custom_phone_meta_keys">انتخاب فیلد کاستوم شماره موبایل</label>
                                <select name="fwss_custom_phone_meta_keys[]" id="fwss_custom_phone_meta_keys"
                                        style="width: 100%;">
									<?php
									global $wpdb;
									$user_meta_keys = $wpdb->get_results( "SELECT DISTINCT meta_key FROM `" . $wpdb->prefix . "usermeta`" );

									$fwss_custom_phone_meta_keys = get_option( 'fwss_custom_phone_meta_keys', '' );
									foreach ( $user_meta_keys as $user_meta_key ) { ?>
                                        <option value="<?php echo $user_meta_key->meta_key; ?>" <?php echo ( $fwss_custom_phone_meta_keys === $user_meta_key->meta_key ) ? 'selected' : ''; ?>><?php echo $user_meta_key->meta_key; ?></option>
									<?php }
									if ( ! in_array( $fwss_custom_phone_meta_keys, $user_meta_keys ) ) { ?>
                                        <option value="<?php echo $fwss_custom_phone_meta_keys; ?>"
                                                selected><?php echo $fwss_custom_phone_meta_keys; ?></option>
									<?php } ?>
                                </select>
                            </div>
                            <br><br>
                            <div class="fwss_save_button_container">
                                <button type="submit" class="fwss_button" id="fwss_save_users_settings_button"><span
                                            class="button__text">ذخیره</span></button>
                                <div id="fwss-users-settings-response-message" style="display: none;"></div>
                            </div>
                        </form>
                    </div>
                    <div class="fwss-info-message digits_pattern_info">متغیرهای قابل استفاده %display_name% و %username%
                        می باشد
                    </div>
                    <div id="fwss_top">
                        <div id="fwss_add_sms">اضافه کردن</div>
                        <div id="fwss_help"><span class="woocommerce-help-tip" data-tip="a"></span></div>
                    </div>
                    <div id="sms_container">
						<?php
						$users_sms_data = get_option( 'fwss_users_sms_data', [] );
						$index          = ! empty( $users_sms_data ) ? ( max( array_keys( $users_sms_data ) ) + 1 ) : 0;
						?>
                        <input type="hidden" id="fwss_next_index" name="fwss_next_index" value="<?php echo $index; ?>">
                        <form id="fwss_users_form" class="fwss_form">
							<?php
							if ( $users_sms_data ) {
								foreach ( $users_sms_data as $i => $fwss_meta ) { ?>
                                    <div class="sms">
                                        <div class="delete">
                                            <div id="delete_row_<?php echo $i; ?>"><img
                                                        src="<?php echo FWSS_CORE_IMG . 'macos-close.png'; ?>"/></div>
                                        </div>
                                        <div class="fwss_active">
                                            <label for="fwss_sms_active_<?php echo $i; ?>" class="toggle-control">
                                                <input class="fwss_inputs" type="checkbox"
                                                       id="fwss_sms_active_<?php echo $i; ?>"
                                                       name="fwss_sms_meta[<?php echo $i; ?>][active]" <?php echo ( $fwss_meta['active_or_not'] == "on" ) ? "checked" : ''; ?>>
                                                <input class="fwss_active_hidden" type="hidden"
                                                       name="fwss_sms_meta[<?php echo $i; ?>][active_or_not]"
                                                       value="<?php echo $fwss_meta['active_or_not']; ?>">
                                                <span class="control"></span>
                                            </label>
                                        </div>
                                        <div class="time">
                                            <label for="fwss_sms_time_<?php echo $i; ?>">زمان</label>
                                            <input class="fwss_inputs" type="number" min="0" class="fwss_time_input"
                                                   name="fwss_sms_meta[<?php echo $i; ?>][time]"
                                                   id="fwss_sms_time_<?php echo $i; ?>"
                                                   value="<?php echo $fwss_meta['time']; ?>" required>
                                            <span> روز</span>
                                        </div>
                                        <div class="hour">
                                            <label for="fwss_sms_hour_<?php echo $i; ?>">ساعت</label>
                                            <input class="fwss_inputs" type="text" placeholder="16:59"
                                                   name="fwss_sms_meta[<?php echo $i; ?>][hour]"
                                                   id="fwss_sms_hour_<?php echo $i; ?>"
                                                   value="<?php echo ( isset( $fwss_meta['hour'] ) ) ? $fwss_meta['hour'] : ''; ?>"
                                                   required>
                                        </div>
                                        <div class="sms_content">
                                            <label for="fwss_sms_content_<?php echo $i; ?>">متن پیام</label>
                                            <textarea class="fwss_inputs" rows="5" cols="20"
                                                      name="fwss_sms_meta[<?php echo $i; ?>][content]"
                                                      id="fwss_sms_order_content_<?php echo $i; ?>"
                                                      required><?php echo $fwss_meta['content']; ?></textarea>
                                        </div>
                                    </div>
								<?php }
							}
							?>
                            <div class="fwss_save_button_container">
                                <button type="submit" class="fwss_button" id="fwss_save_users_button"><span
                                            class="button__text">ذخیره</span></button>
                                <div id="fwss-users-response-message" style="display: none;"></div>
                            </div>
                        </form>
                    </div>
                </div>
            </li>
            <li>
                <div class="content__wrapper">
					<?php if ( ! $gravityForms_installed ) { ?>
                        <div class="fsms-warning-message gravity_not_installed">افزونه Gravity Forms نصب نیست برای
                            مشاهده تنظیمات ابتدا این افزونه را نصب کنید
                        </div>
					<?php } else { ?>
                        <div id="gf_form_and_fields">
                            <div id="fwss_gravity_forms">
                                <div id="fwss_forms">
                                    <label for="fwss-gravity-forms">انتخاب فرم</label>
                                    <select class="fwss_select2" name="fwss-gravity-forms" id="fwss-gravity-forms"
                                            style="width: 60%">
										<?php
										$forms       = GFAPI::get_forms();
										$forms_array = array();
										echo "<option value='-1'></option>";
										foreach ( $forms as $form ) {
											echo "<option value='" . $form['id'] . "'>" . $form['title'] . "</option>";
										}
										?>
                                    </select>
                                </div>
                                <div id="form_fields">
                                    <label for="fwss-gravity-field">انتخاب فیلد موردنظر</label>
                                    <select class="fwss_select2" name="fwss-gravity-field" id="fwss-gravity-field"
                                            style="width: 60%">
										<?php
										echo "<option value='-1'></option>";
										$forms = GFAPI::get_forms();
										foreach ( $forms as $form ) {
											$form = GFAPI::get_form( $form['id'] );
											if ( gettype( $form ) == "array" ) {
												foreach ( $form['fields'] as $field ) {
													echo "<option value='" . $form['id'] . '-' . $field['id'] . "'>" . $field['label'] . "</option>";
												}
											}
										}
										?>
                                    </select>
                                </div>
                            </div>
                 <div class="fwss_gf_field_registered_sms">
    <?php
    $gf_sms_data = get_option( 'fwss_gf_sms_data', [] );
    $index       = ! empty( $gf_sms_data ) ? ( max( array_keys( $gf_sms_data ) ) + 1 ) : 0;

    // ایندکس کردن داده‌ها بر اساس gf_formatted_id برای دسترسی سریع‌تر
    $gf_sms_indexed = [];
    foreach ( $gf_sms_data as $i => $meta ) {
        $gf_sms_indexed[ $meta['gf_formatted_id'] ][] = array_merge( $meta, [ '_i' => $i ] );
    }
    ?>

    <input type="hidden" id="fwss_gf_next_index" name="fwss_next_index" value="<?php echo esc_attr($index); ?>">

    <form id="fwss_gf_sms_form" class="fwss_form">
        <?php
        $forms = GFAPI::get_forms();
        foreach ( $forms as $form_summary ) :
            $form = GFAPI::get_form( $form_summary['id'] );
            if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
                continue;
            }

            foreach ( $form['fields'] as $field ) :
                $formatted_id = $form['id'] . '-' . $field['id'];
                $sms_list     = isset( $gf_sms_indexed[ $formatted_id ] ) ? $gf_sms_indexed[ $formatted_id ] : [];
                ?>
                
                <div class="fwss_show_hide_field" style="display: none;" id="frmid-fldid_<?php echo esc_attr( $formatted_id ); ?>">

                    <!-- فقط یک دکمه اضافه کردن -->
                    <div class="fwss_gf_add_sms fwss_button">اضافه کردن</div>

                    <?php foreach ( $sms_list as $fwss_meta ) :
                        $i = $fwss_meta['_i']; ?>
                        <div class="sms">
                            <input type="hidden"
                                   name="fwss_gf_sms_meta[<?php echo $i; ?>][gf_formatted_id]"
                                   value="<?php echo esc_attr( $fwss_meta['gf_formatted_id'] ); ?>">

                            <div class="delete">
                                <div id="delete_row_<?php echo $i; ?>">
                                    <img src="<?php echo esc_url( FWSS_CORE_IMG . 'macos-close.png' ); ?>"/>
                                </div>
                            </div>

                            <div class="fwss_active">
                                <label for="fwss_gf_sms_active_<?php echo $i; ?>" class="toggle-control">
                                    <input class="fwss_inputs" type="checkbox"
                                           id="fwss_gf_sms_active_<?php echo $i; ?>"
                                           name="fwss_gf_sms_meta[<?php echo $i; ?>][active]"
                                        <?php checked( $fwss_meta['active_or_not'], 'on' ); ?>>
                                    <input class="fwss_active_hidden" type="hidden"
                                           name="fwss_gf_sms_meta[<?php echo $i; ?>][active_or_not]"
                                           value="<?php echo esc_attr( $fwss_meta['active_or_not'] ); ?>">
                                    <span class="control"></span>
                                </label>
                            </div>

                            <div class="time">
                                <label for="fwss_gf_sms_time_<?php echo $i; ?>">زمان</label>
                                <input class="fwss_inputs fwss_time_input" type="number" min="0"
                                       name="fwss_gf_sms_meta[<?php echo $i; ?>][time]"
                                       id="fwss_gf_sms_time_<?php echo $i; ?>"
                                       value="<?php echo esc_attr( $fwss_meta['time'] ); ?>" required>
                                <span> روز</span>
                            </div>

                            <div class="hour">
                                <label for="fwss_gf_sms_hour_<?php echo $i; ?>">ساعت</label>
                                <input class="fwss_inputs" type="text" minlength="5" maxlength="5"
                                       placeholder="16:59"
                                       name="fwss_gf_sms_meta[<?php echo $i; ?>][hour]"
                                       id="fwss_gf_sms_hour_<?php echo $i; ?>"
                                       value="<?php echo esc_attr( $fwss_meta['hour'] ?? '' ); ?>" required>
                            </div>

                            <div class="condition">
                                <label for="fwss_gf_condition_active_<?php echo $i; ?>" class="toggle-control">
                                    منطق شرطی
                                    <input class="fwss_inputs fwss_condition_toggle" type="checkbox"
                                           id="fwss_gf_condition_active_<?php echo $i; ?>"
                                           name="fwss_gf_sms_meta[<?php echo $i; ?>][condition_active]"
                                        <?php checked( $fwss_meta['condition_active'] ?? '', 'on' ); ?>>
                                    <span class="control"></span>
                                </label>
                            </div>

                            <div class="sms_content">
                                <label for="fwss_gf_sms_content_<?php echo $i; ?>">متن پیام</label>
                                <textarea class="fwss_inputs" rows="5" cols="20"
                                          name="fwss_gf_sms_meta[<?php echo $i; ?>][content]"
                                          id="fwss_gf_sms_order_content_<?php echo $i; ?>"
                                          required><?php echo esc_textarea( $fwss_meta['content'] ); ?></textarea>
                            </div>
                        </div>

                        <!-- شرط‌ها -->
                        <div class="fwss_condition_container" id="fwss_condition_container_<?php echo $i; ?>"
                             style="display: none;">
                            <div class="fwss_if_all_condition">
                                <span>ارسال پیامک اگر</span>
                                <select name="fwss_gf_sms_meta[<?php echo $i; ?>][all_or_one]">
                                    <option value="all" <?php selected( $fwss_meta['all_or_one'], 'all' ); ?>>همه</option>
                                    <option value="any" <?php selected( $fwss_meta['all_or_one'], 'any' ); ?>>حداقل یکی</option>
                                </select>
                                <span>از شرط‌های زیر برقرار بود:</span>
                                <div class="plus-button plus-button--small"></div>
                            </div>

                            <?php foreach ( $fwss_meta['condition'] as $j => $condition ) : ?>
                                <div class="fwss_conditions">
                                    <span>اگر</span>

                                    <select class="fwss_gf_conditional_field"
                                            name="fwss_gf_condition_field_[<?php echo $i; ?>][<?php echo $j; ?>][field]"
                                            id="fwss_gf_condition_field_<?php echo $i . '_' . $j; ?>">
                                        <?php foreach ( $form['fields'] as $fieldc ) :
                                            $selected = ( $fieldc['id'] == $condition['field'] ) ? 'selected' : '';
                                            echo "<option value='" . esc_attr( $fieldc['id'] ) . "' $selected>" . esc_html( $fieldc['label'] ) . "</option>";
                                        endforeach; ?>
                                    </select>

                                    <select class="fwss_gf_conditional_operator"
                                            id="fwss_gf_condition_operator_<?php echo $i . '_' . $j; ?>"
                                            name="fwss_gf_condition_operator_[<?php echo $i; ?>][<?php echo $j; ?>][operator]">
                                        <?php
                                        $ops = [
                                            'is' => 'هست',
                                            'isnot' => 'نیست',
                                            '>' => 'بزرگتر از',
                                            '<' => 'کوچکتر از',
                                            'contains' => 'شامل میشود',
                                            'starts_with' => 'شروع میشود',
                                            'ends_with' => 'تمام میشود',
                                        ];
                                        foreach ( $ops as $key => $label ) {
                                            $sel = ( $condition['operator'] == $key ) ? 'selected' : '';
                                            echo "<option value='$key' $sel>$label</option>";
                                        }
                                        ?>
                                    </select>

                                    <div id="fwss_gf_condition_value_<?php echo $i . '_' . $j; ?>" style="display:inline;">
                                        <input type="text" class="condition_field_value" style="padding:3px"
                                               placeholder="یک مقدار وارد کنید"
                                               name="fwss_gf_condition_value_[<?php echo $i; ?>][<?php echo $j; ?>][value]"
                                               value="<?php echo esc_attr( $condition['value'] ?? '' ); ?>">
                                    </div>

                                    <div class="plus-button plus-button--small minus"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php endforeach; // end of sms_list ?>

                </div>
            <?php endforeach; // fields ?>
        <?php endforeach; // forms ?>
        
        <div class="fwss_save_button_container" style="margin-top: 15px;">
            <button type="submit" class="fwss_button" id="fwss_save_gf_sms_button">
                <span class="button__text">ذخیره</span>
            </button>
            <div id="fwss-gf-sms-response-message" style="display: none;"></div>
        </div>
    </form>
</div>

                        </div>
					<?php } ?>
                </div>
            </li>
            <li>
                <div class="content__wrapper">
                    <div class="fwss-info-message digits_pattern_info">برای ارسال زماندار ووکامرس به صفحه ویرایش
                        محصول مورد نظر بروید
                    </div>
                    <br><br>
                    <?php if (function_exists('is_woocommerce')): ?>
                    <div id="fwss_top_wc">
                        <div id="fwss_add_sms_wc" class="fwss_btn_add">اضافه کردن</div>
                    </div>
                    <div id="sms_container_wc">
						<?php
						$wc_sms_data = get_option( 'fwss_wc_sms_data', [] );
						$index       = ! empty( $wc_sms_data ) ? ( max( array_keys( $wc_sms_data ) ) + 1 ) : 0;
						$statuses    = wc_get_order_statuses();
						?>
                        <input type="hidden" id="fwss_next_index_wc" name="fwss_next_index_wc"
                               value="<?php echo $index; ?>">
                        <div class="fwss-info-message digits_pattern_info">متغیرهای قابل استفاده %order_id%
                            می باشد
                        </div>
                        <form id="fwss_wc_form" class="fwss_form">
							<?php
							if ( $wc_sms_data ) {
								foreach ( $wc_sms_data as $i => $fwss_meta ) { ?>
                                    <div class="sms">
                                        <div class="delete">
                                            <div id="delete_row_<?php echo $i; ?>"><img
                                                        src="<?php echo FWSS_CORE_IMG . 'macos-close.png'; ?>"/></div>
                                        </div>
                                        <div class="fwss_active">
                                            <label for="fwss_wc_sms_active_<?php echo $i; ?>" class="toggle-control">
                                                <input class="fwss_inputs" type="checkbox"
                                                       id="fwss_wc_sms_active_<?php echo $i; ?>"
                                                       name="fwss_wc_sms_meta[<?php echo $i; ?>][active]" <?php echo ( $fwss_meta['active_or_not'] == "on" ) ? "checked" : ''; ?>>
                                                <input class="fwss_wc_active_hidden" type="hidden"
                                                       name="fwss_wc_sms_meta[<?php echo $i; ?>][active_or_not]"
                                                       value="<?php echo $fwss_meta['active_or_not']; ?>">
                                                <span class="control"></span>
                                            </label>
                                        </div>
                                        <div class="time">
                                            <label for="fwss_wc_sms_time_<?php echo $i; ?>">زمان</label>
                                            <input class="fwss_inputs" type="number" min="0" class="fwss_time_input"
                                                   name="fwss_wc_sms_meta[<?php echo $i; ?>][time]"
                                                   id="fwss_wc_sms_time_<?php echo $i; ?>"
                                                   value="<?php echo $fwss_meta['time']; ?>" required>
                                            <span> روز</span>
                                        </div>
                                        <div class="order_status">
                                            <label for="fwss_sms_order_status_<?php echo $i; ?>">وضعیت سفارش</label>
                                        <div class="order_status">
    <label for="fwss_sms_order_status_<?php echo $i; ?>">وضعیت سفارش</label>
    <select name="fwss_sms_meta[<?php echo $i; ?>][order_status]"
            id="fwss_sms_order_status_<?php echo $i; ?>">
        <?php
        foreach ( $statuses as $status => $status_name ) {

            // 🚫 وضعیت "در انتظار پرداخت" (wc-pending) را حذف کن
            if ( $status === 'wc-pending' ) {
                continue;
            }

            $selected = ( isset( $fwss_meta['order_status'] ) && $fwss_meta['order_status'] === $status ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $status ) . '" ' . $selected . '>' . esc_html( $status_name ) . '</option>';
        }
        ?>
    </select>
</div>

                                        </div>
                                        <div class="hour">
                                            <label for="fwss_wc_sms_hour_<?php echo $i; ?>">ساعت</label>
                                            <input class="fwss_inputs" type="text" placeholder="16:59"
                                                   name="fwss_wc_sms_meta[<?php echo $i; ?>][hour]"
                                                   id="fwss_wc_sms_hour_<?php echo $i; ?>"
                                                   value="<?php echo ( isset( $fwss_meta['hour'] ) ) ? $fwss_meta['hour'] : ''; ?>"
                                                   required>
                                        </div>
                                        <div class="sms_content">
                                            <label for="fwss_wc_sms_content_<?php echo $i; ?>">متن پیام</label>
                                            <textarea class="fwss_inputs" rows="5" cols="20"
                                                      name="fwss_wc_sms_meta[<?php echo $i; ?>][content]"
                                                      id="fwss_wc_sms_order_content_<?php echo $i; ?>"
                                                      required><?php echo $fwss_meta['content']; ?></textarea>
                                        </div>
                                    </div>
								<?php }
							}
							?>
                            <div class="fwss_save_button_container_wc">
                                <button type="submit" class="fwss_button" id="fwss_save_wc_button"><span
                                            class="button__text">ذخیره</span></button>
                                <div id="fwss-wc-response-message" style="display: none;"></div>
                            </div>
                        </form>
                    </div>
                    <?php else: ?>
                        <div class="fsms-warning-message gravity_not_installed">افزونه Woocommerce نصب نیست برای
                            مشاهده تنظیمات ابتدا این افزونه را نصب کنید
                        </div>
                    <?php endif; ?>
                </div>
            </li>
        </ul>
    </section>
	<?php
}

