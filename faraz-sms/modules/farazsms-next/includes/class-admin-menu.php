<?php
/**
 * Admin Menu Class
 *
 * Handles the creation of admin menu and pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Admin_Menu {

    /**
     * Admin page class instance
     *
     * @var FarazSMS_Next_Admin_Page
     */
    private $admin_page;

    /**
     * Initialize the admin menu
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Initialize admin page class
        $this->admin_page = new FarazSMS_Next_Admin_Page();
    }

    /**
     * Add admin menu items as separate submenus
     */
    public function add_admin_menu() {
        // ساخت دفترچه تلفن
        add_submenu_page(
            'farazwto',
            __('باشگاه مشتریان', 'farazsms-next'),
            __('باشگاه مشتریان', 'farazsms-next'),
            'manage_options',
            'farazwto-phonebook',
            array($this, 'render_phonebook_page')
        );
        
        // لید مگنت
        add_submenu_page(
            'farazwto',
            __('لید مگنت', 'farazsms-next'),
            __('لید مگنت', 'farazsms-next'),
            'manage_options',
            'farazwto-lead-magnet',
            array($this, 'render_lead_magnet_page')
        );
        
        // اطلاع رسانی گرویتی - المنتور (صفحه واحد با دو دکمه و تنظیمات OTP)
        add_submenu_page(
            'farazwto',
            __('اطلاع رسانی گرویتی - المنتور', 'farazsms-next'),
            __('اطلاع رسانی گرویتی - المنتور', 'farazsms-next'),
            'manage_options',
            'farazwto-sms-forms',
            array($this, 'render_sms_forms_page')
        );

        // برنامه‌نویسان (مستنداتِ API عمومی) — در نوار کناری، بالای «بازخورد».
        add_submenu_page(
            'farazwto',
            __('برنامه‌نویسان', 'farazsms-next'),
            __('برنامه‌نویسان', 'farazsms-next'),
            'manage_options',
            'farazwto-developers',
            array($this, 'render_developers_page')
        );
    }

    /**
     * Render developers (API docs) page.
     */
    public function render_developers_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'farazsms-next'));
        }
        echo '<div class="wrap" style="margin-top:18px;">';
        $tab_file = WTO_CORE_INC . 'farazsms-next-tabs/tab-developers.php';
        if (file_exists($tab_file)) {
            include $tab_file;
        }
        echo '</div>';
    }

    /**
     * Render phonebook page
     */
    public function render_phonebook_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'farazsms-next'));
        }

        // Get API key for credit display
        if (function_exists('wto_get_apikey')) {
            $apikey = wto_get_apikey();
        } else {
            $apikey = get_option('wto_apikey', '');
        }

        // v3.17.7: تب‌بندی حذف شد — همه‌ی سه بخش (WC + GF + Custom Meta) در یک صفحه‌ی واحد
        // به ترتیب رندر می‌شوند. WC اول، GF دوم، Custom Meta سوم.
        ?>
        <section class="wrapper">
            <div id="wto_header">
                <div>
                    <a href="https://farazsms.com" target="_blank">
                        <img src="<?php echo WTO_CORE_IMG . 'logo-1.png'; ?>" alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
                    </a>
                </div>
                <?php
                if (!empty($apikey) && function_exists('wto_get_credit')) {
                    $credit = wto_get_credit();
                    ?>
                    <div id="wto_account_info">
                        <div class="wto_credit_amount">
                            <span>میزان اعتبار: </span><?php echo esc_html($credit); ?>
                            <span> تومان</span>
                        </div>
                        <?php wto_render_profile_block(); ?>
                    </div>
                    <?php
                }
                ?>
            </div>

            <!-- v3.17.7: همه‌ی بخش‌ها در یک صفحه واحد، با divider بصری بین آن‌ها -->

            <!-- بخش ۱: مشتریان ووکامرس -->
            <div class="wto-pb-section">
                <div class="wto-pb-section__header">
                    <span class="wto-pb-section__icon">🛒</span>
                    <h2 class="wto-pb-section__title"><?php esc_html_e('مشتریان ووکامرس', 'wto'); ?></h2>
                </div>
                <ul class="tab__content">
                    <li class="active">
                        <div class="content__wrapper">
                            <?php $this->admin_page->render_tab('phonebook'); ?>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- بخش ۲: سینک Gravity Forms -->
            <div class="wto-pb-section">
                <div class="wto-pb-section__header">
                    <span class="wto-pb-section__icon">📋</span>
                    <h2 class="wto-pb-section__title"><?php esc_html_e('فرم‌های Gravity', 'wto'); ?></h2>
                </div>
                <?php
                if (function_exists('wto_gf_pb_render_content')) {
                    wto_gf_pb_render_content();
                } else {
                    echo '<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:8px;">ماژول سینک فرم در دسترس نیست.</div>';
                }
                ?>
            </div>

            <!-- بخش ۳: فیلد متا اختصاصی -->
            <div class="wto-pb-section">
                <div class="wto-pb-section__header">
                    <span class="wto-pb-section__icon">🔧</span>
                    <h2 class="wto-pb-section__title"><?php esc_html_e('سایر افزونه‌ها (فیلد متا اختصاصی)', 'wto'); ?></h2>
                </div>
                <?php
                if (function_exists('wto_custom_meta_pb_render_content')) {
                    wto_custom_meta_pb_render_content();
                } else {
                    echo '<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:8px;">ماژول فیلد متا اختصاصی در دسترس نیست.</div>';
                }
                ?>
            </div>

            <style>
            .wto-pb-section {
                margin-bottom: 32px;
                padding-bottom: 32px;
                border-bottom: 2px dashed #e5e7eb;
            }
            .wto-pb-section:last-child {
                border-bottom: 0;
                padding-bottom: 0;
                margin-bottom: 0;
            }
            .wto-pb-section__header {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 0 0 20px;
                padding: 14px 18px;
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-right: 4px solid #4338ca;
                border-radius: 10px;
            }
            .wto-pb-section__icon { font-size: 26px; line-height: 1; }
            .wto-pb-section__title {
                margin: 0;
                font-size: 17px;
                font-weight: 800;
                color: #0f172a;
            }
            </style>
        </section>
        <?php
    }

    /**
     * Render lead magnet page
     */
    public function render_lead_magnet_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'farazsms-next'));
        }

        // Get API key for credit display
        if (function_exists('wto_get_apikey')) {
            $apikey = wto_get_apikey();
        } else {
            $apikey = get_option('wto_apikey', '');
        }
        ?>
        <section class="wrapper">
            <div id="wto_header">
                <div>
                    <a href="https://farazsms.com" target="_blank">
                        <img src="<?php echo WTO_CORE_IMG . 'logo-1.png'; ?>" alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
                    </a>
                </div>
                <?php
                if (!empty($apikey) && function_exists('wto_get_credit')) {
                    $credit = wto_get_credit();
                    ?>
                    <div id="wto_account_info">
                        <div class="wto_credit_amount">
                            <span>میزان اعتبار: </span><?php echo esc_html($credit); ?>
                            <span> تومان</span>
                        </div>
                        <?php wto_render_profile_block(); ?>
                    </div>
                    <?php
                }
                ?>
            </div>
            
            <ul class="tabs">
                <li class="active">لید مگنت</li>
            </ul>
            
            <ul class="tab__content">
                <li class="active">
                    <div class="content__wrapper">
                        <?php $this->admin_page->render_tab('lead-magnet'); ?>
                    </div>
                </li>
            </ul>
        </section>
        <?php
    }

    /**
     * Render SMS Forms page (Gravity / Elementor) with two buttons and OTP settings
     */
    public function render_sms_forms_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'farazsms-next'));
        }
        // v3.14.7 REWRITE: ساختار قدیمی section.wrapper + ul.tabs که با قاب
        // یکپارچه افزونه تداخل داشت، حذف شد. صفحه حالا فقط محتوای اصلی را با
        // ساختار تمیز RTL و کارت‌های مدرن رندر می‌کند — header/credit از قاب
        // یکپارچه می‌آید، نه از این صفحه.

        $elementor_url  = admin_url('admin.php?page=elementor_farazsms');
        $gravity_url    = admin_url('admin.php?page=gf_farazsms');
        $otp_message    = get_option('wto_otp_message', 'کد تأیید شما: %code%');
        $otp_pattern    = get_option('wto_otp_pattern', '');
        $gf_active      = class_exists('GFForms');
        $elementor_active = did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin');
        ?>
        <div style="direction:rtl; font-family:inherit; max-width:980px;">

            <!-- Hero info -->
            <div style="background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%); border:1px solid #c7d2fe; border-radius:12px; padding:18px 22px; margin-bottom:20px;">
                <h3 style="margin:0 0 6px; font-size:16px; color:#312e81; font-weight:700;">
                    📝 اطلاع‌رسانی پیامکی گرویتی فرم و المنتور
                </h3>
                <p style="margin:0; color:#4338ca; font-size:13px; line-height:1.9;">
                    با ثبت هر فرم در سایت، می‌توانید پیامک اطلاع‌رسانی به <strong>مدیر</strong> و <strong>پرکننده فرم</strong> ارسال کنید — با متن دلخواه یا با پترن.
                    همچنین <strong>OTP اعتبارسنجی موبایل</strong> روی فیلدهای فرم برای جلوگیری از اسپم پیاده شده است.
                </p>
            </div>

            <!-- Cards: dispatch to GF / Elementor settings -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; margin-bottom:22px;">
                <!-- Gravity Forms card -->
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 22px; <?php echo ! $gf_active ? 'opacity:0.65;' : ''; ?>">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <div style="width:42px; height:42px; background:#fef3c7; color:#d97706; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px;">📋</div>
                        <div>
                            <h4 style="margin:0; font-size:14.5px; font-weight:700; color:#0f172a;">گرویتی فرم</h4>
                            <?php if ( $gf_active ) : ?>
                                <span style="display:inline-block; background:#dcfce7; color:#15803d; font-size:10px; padding:2px 7px; border-radius:4px; margin-top:2px;">✓ نصب و فعال</span>
                            <?php else : ?>
                                <span style="display:inline-block; background:#fef2f2; color:#b91c1c; font-size:10px; padding:2px 7px; border-radius:4px; margin-top:2px;">✗ نصب نیست</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p style="margin:0 0 14px; font-size:12.5px; color:#475569; line-height:1.9;">
                        تنظیمات اطلاع‌رسانی پیامکی برای هر فرم گرویتی + ارسال پیامک تکی از sidebar صفحه entry.
                    </p>
                    <?php if ( $gf_active ) : ?>
                        <a href="<?php echo esc_url($gravity_url); ?>" style="display:inline-block; background:#4338ca; color:#fff; padding:9px 20px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">⚙️ تنظیمات گرویتی فرم</a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( admin_url('plugin-install.php?s=gravity+forms&tab=search') ); ?>" style="display:inline-block; background:#f1f5f9; color:#475569; padding:9px 20px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">📥 نصب گرویتی فرم</a>
                    <?php endif; ?>
                </div>

                <!-- Elementor card -->
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 22px; <?php echo ! $elementor_active ? 'opacity:0.65;' : ''; ?>">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <div style="width:42px; height:42px; background:#fce7f3; color:#be185d; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px;">🎨</div>
                        <div>
                            <h4 style="margin:0; font-size:14.5px; font-weight:700; color:#0f172a;">المنتور فرم</h4>
                            <?php if ( $elementor_active ) : ?>
                                <span style="display:inline-block; background:#dcfce7; color:#15803d; font-size:10px; padding:2px 7px; border-radius:4px; margin-top:2px;">✓ نصب و فعال</span>
                            <?php else : ?>
                                <span style="display:inline-block; background:#fef2f2; color:#b91c1c; font-size:10px; padding:2px 7px; border-radius:4px; margin-top:2px;">✗ نصب نیست</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p style="margin:0 0 14px; font-size:12.5px; color:#475569; line-height:1.9;">
                        تنظیمات اطلاع‌رسانی پیامکی برای فرم‌های Elementor Pro + امکان ارسال هنگام ثبت فرم.
                    </p>
                    <?php if ( $elementor_active ) : ?>
                        <a href="<?php echo esc_url($elementor_url); ?>" style="display:inline-block; background:#4338ca; color:#fff; padding:9px 20px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">⚙️ تنظیمات المنتور</a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( admin_url('plugin-install.php?s=elementor&tab=search') ); ?>" style="display:inline-block; background:#f1f5f9; color:#475569; padding:9px 20px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">📥 نصب المنتور</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- OTP Pattern settings -->
            <form id="wto_sms_forms_otp_form" style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:22px 24px; margin-bottom:18px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                    <div style="width:38px; height:38px; background:#e0e7ff; color:#4338ca; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px;">🔐</div>
                    <div>
                        <h4 style="margin:0; font-size:14.5px; font-weight:700; color:#0f172a;">پترن پیامک OTP (اعتبارسنجی موبایل در فرم‌ها)</h4>
                        <p style="margin:2px 0 0; font-size:11.5px; color:#64748b;">با این پترن، کد ۶ رقمی برای تأیید موبایل کاربر در فرم‌ها ارسال می‌شود — جلوگیری از اسپم فرم</p>
                    </div>
                </div>

                <?php if ( empty( $otp_pattern ) ) : ?>
                    <div style="background:#fef2f2; border:1px solid #fecaca; color:#b32d2e; border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:12.5px; line-height:1.9;">
                        ⚠️ <strong>هنوز پترن کد تأیید ساخته نشده است.</strong> تا زمانی که پترن را نسازید و در پنلِ فراز تأیید نشود، پیامکِ کد تأیید در فرم‌های گرویتی‌فرم/المنتور <strong>ارسال نمی‌شود</strong>. متن را بنویسید و دکمه‌ی «ساخت پترن خودکار» را بزنید.
                    </div>
                <?php else : ?>
                    <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:12.5px; line-height:1.9;">
                        ✅ پترن کد تأیید ساخته شده است (<code style="direction:ltr;"><?php echo esc_html( $otp_pattern ); ?></code>). اگر هنوز پیامک ارسال نمی‌شود، مطمئن شوید این پترن در <strong>پنلِ فراز تأیید</strong> شده باشد.
                    </div>
                <?php endif; ?>

                <div style="margin-bottom:14px;">
                    <label for="wto_sms_forms_otp_message" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px;">
                        متن پیام پیش‌فرض <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea class="input-field wto-comment-message" id="wto_sms_forms_otp_message" name="wto_otp_message" rows="3" data-section_type="otp" data-status_key="otp" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:13px; line-height:1.9; resize:vertical; direction:rtl; min-height:90px;"><?php echo esc_textarea($otp_message); ?></textarea>
                    <p style="margin:6px 0 0; font-size:11.5px; color:#64748b; line-height:1.8;">
                        متغیر قابل استفاده: <code style="background:#f1f5f9; padding:1px 6px; border-radius:3px;">%code%</code> (کد تأیید ۶ رقمی)
                    </p>
                </div>

                <div style="margin-bottom:14px;">
                    <button type="button" class="wto-comment-create-pattern" data-target_pattern="wto_sms_forms_otp_pattern" data-target_message="wto_sms_forms_otp_message" style="background:#16a34a; color:#fff; border:none; padding:8px 18px; font-size:12.5px; font-weight:600; border-radius:6px; cursor:pointer; font-family:inherit;">
                        ⚡ ساخت پترن خودکار
                    </button>
                    <div class="wto-create-pattern-response wto-comment-pattern-response" style="display:none; margin-top:10px;"></div>
                </div>

                <div style="margin-bottom:18px;">
                    <label for="wto_sms_forms_otp_pattern" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px;">
                        کد پترن OTP <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" class="input-field" id="wto_sms_forms_otp_pattern" name="wto_otp_pattern" value="<?php echo esc_attr($otp_pattern); ?>" style="width:100%; max-width:360px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left; font-family:monospace;">
                    <p style="margin:4px 0 0; font-size:11px; color:#64748b;">اگر دستی پترن ساخته‌اید، کد آن را اینجا وارد کنید.</p>
                </div>

                <div class="wto_save_button_container" style="display:flex; align-items:center; gap:14px;">
                    <button type="submit" id="wto_sms_forms_otp_save_button" style="background:#4338ca; color:#fff; border:none; padding:10px 28px; font-size:13.5px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">
                        💾 ذخیره
                    </button>
                    <div id="wto-sms-forms-otp-response-message" style="display:none;"></div>
                </div>
            </form>

            <!-- Help/Tips -->
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 18px;">
                <h4 style="margin:0 0 8px; font-size:13px; font-weight:700; color:#78350f;">💡 نکات راهنما</h4>
                <ul style="margin:0; padding-right:18px; font-size:12.5px; color:#92400e; line-height:2;">
                    <li><strong>پترن باید توسط پنل فراز تأیید شود</strong> — معمولاً ۱ تا ۲۴ ساعت طول می‌کشد. تا تأیید نشدن، ارسال OTP کار نمی‌کند.</li>
                    <li><strong>نام برند خود را به‌صورت ثابت در متن پترن بنویسید</strong> — استفاده از متغیر برای نام برند، باعث رد پترن می‌شود.</li>
                    <li><strong>چند شماره ادمین:</strong> در تنظیمات اطلاع‌رسانی هر فرم، می‌توانید چند شماره مدیر را با کاما <code>,</code> جدا کنید — افزونه به هر کدام به‌صورت جداگانه ریکوئست می‌فرستد.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Load assets for all new submenus
        $valid_hooks = array(
            'farazwto_page_farazwto-phonebook',
            'farazwto_page_farazwto-lead-magnet',
            'farazwto_page_farazwto-sms-forms'
        );
        
        // Also check by page parameter as fallback
        $valid_pages = array(
            'farazwto-phonebook',
            'farazwto-lead-magnet',
            'farazwto-sms-forms'
        );
        
        $is_valid_page = false;
        if (in_array($hook, $valid_hooks)) {
            $is_valid_page = true;
        } elseif (isset($_GET['page']) && in_array($_GET['page'], $valid_pages)) {
            $is_valid_page = true;
        }
        
        if (!$is_valid_page) {
            return;
        }

        // Enqueue main plugin CSS (same as main settings page)
        wp_enqueue_style(
            'wto-settings',
            WTO_CORE_CSS . 'wto-settings.css',
            null,
            '0.0.1',
            'all'
        );

        // Enqueue FarazSMS Next admin CSS
        wp_enqueue_style(
            'farazsms-next-admin-style',
            WTO_CORE_CSS . 'admin-style.css',
            array('wto-settings'),
            defined('FARAZSMS_NEXT_VERSION') ? FARAZSMS_NEXT_VERSION : '1.0.0'
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'farazsms-next-admin-script',
            WTO_CORE_JS . 'admin-script.js',
            array('jquery'),
            defined('FARAZSMS_NEXT_VERSION') ? FARAZSMS_NEXT_VERSION : '1.0.0',
            true
        );

        // Localize script for AJAX
        wp_localize_script('farazsms-next-admin-script', 'farazsmsNextPhonebook', array(
            'nonce' => wp_create_nonce('farazsms_next_phonebook_ajax'),
        ));
        
        // Enqueue lead magnet CSS and JS for lead magnet page
        $is_lead_magnet = ($hook === 'farazwto_page_farazwto-lead-magnet' || (isset($_GET['page']) && $_GET['page'] === 'farazwto-lead-magnet'));
        if ($is_lead_magnet) {
            wp_enqueue_style(
                'farazsms-next-lead-magnet-style',
                WTO_CORE_CSS . 'lead-magnet.css',
                array('wto-settings'),
                defined('FARAZSMS_NEXT_VERSION') ? FARAZSMS_NEXT_VERSION : '1.0.0'
            );
            
            wp_enqueue_script(
                'farazsms-next-lead-magnet-script',
                WTO_CORE_JS . 'lead-magnet.js',
                array('jquery'),
                defined('FARAZSMS_NEXT_VERSION') ? FARAZSMS_NEXT_VERSION : '1.0.0',
                true
            );
        }
    }
}

