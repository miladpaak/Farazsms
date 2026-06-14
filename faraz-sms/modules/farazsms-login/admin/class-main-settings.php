<?php

namespace FarazSMS\Admin;

use FarazSMS\Admin\Admin_Helper as Admin_Helper;

class Settings extends Admin_Helper {

    public function All_Settings() {
        $all_settings = get_option('farazsms_login_settings', []);
        // wp_parse_args تا کلیدهای غایب (مثل code_length وقتی فقط api_key/sender تزریق شده‌اند)
        // پر شوند و notice «Undefined index» ندهند.
        $sms_settings = wp_parse_args( $all_settings['sms'] ?? [], [
            'api_key' => '',
            'sender' => '90008361',
            'pattern_code' => '',
            'code_length' => '6',
            'test_sender' => '',
        ] );
        $general_defaults = [
            'terms_link' => '#',
            'redirect_after_login' => '',
            'checkout_redirect_to_login' => '0',
            'woocommerce_login_redirect' => '0',
            'replace_default_login_forms' => '1',
            'apply_redirect_all_themes' => '1',
        ];
        $general = wp_parse_args($all_settings['general'] ?? [], $general_defaults);
        $appearance = $all_settings['appearance'] ?? [
            'theme' => 'style-1',
            'text_alignment' => 'center',
            // پیش‌فرضِ لوگو = نمادکِ سایت (Site Icon / favicon) از تنظیمات عمومی وردپرس.
            // اگر سایت نمادک نداشته باشد خالی می‌ماند و قالب چیزی نمایش نمی‌دهد.
            'logo' => function_exists('get_site_icon_url') ? get_site_icon_url() : '',
            'background_image' => '',
            'primary_color' => '',
            'background_color' => '',
            'background_color_box' => '',
            'text_color' => '',
            'border_color' => '',
            'custom_css' => '',
        ];
        $wallet = $all_settings['wallet'] ?? [
            'enable_registration_bonus' => '0',
            'registration_bonus_amount' => '0',
            'registration_bonus_description' => __('Welcome bonus for new registration', 'farazsms'),
        ];
        $woo_sms = $all_settings['woo_sms'] ?? [];
        $slide = $all_settings['slide'] ?? [
            'enable_slide' => '0',
            'slide_show_only_guests' => '1',
            'slide_position' => 'right',
            'slide_image' => '',
            'slide_title' => __('Registration Gift', 'farazsms'),
            'slide_description' => __('Sign up now and get a special gift!', 'farazsms'),
            'slide_countdown_minutes' => '1',
            'slide_button_text' => __('Sign Up', 'farazsms'),
            'slide_button_link' => '#',
            'slide_button_color' => '#0BD08B',
            'slide_background_color' => '#ffffff',
            'slide_text_color' => '#333333',
            'slide_title_color' => '#000000',
        ];

        $settings = [
            'sms' => [
                'menu' => __('SMS Settings', 'farazsms'),
                'lable' => __('SMS Service Settings', 'farazsms'),
                'settings' => [
                    'api_key' => [
                        'type' => 'text',
                        'title' => __('API Key', 'farazsms'),
                        'description' => __('Enter your FarazSMS API key', 'farazsms'),
                        'value' => $sms_settings['api_key'],
                        'width' => 'w100 ltr',
                    ],
                    'pattern_code' => [
                        'type' => 'text',
                        'title' => __('Pattern Code', 'farazsms'),
                        'description' => __('create a pattern with %code% parameter in your FarazSMS account', 'farazsms'),
                        'value' => $sms_settings['pattern_code'],
                        'width' => 'w33 ltr',
                    ],
                    'sender' => [
                        'type' => 'text',
                        'title' => __('Sender Number', 'farazsms'),
                        'description'=> __('Enter your FarazSMS sender number', 'farazsms'),
                        'value' => $sms_settings['sender'],
                        'width' => 'w33',
                    ],
                    'code_length' => [
                        'type' => 'number',
                        'title' => __('Code Length', 'farazsms'),
                        'description' => __('Enter the length of the verification code', 'farazsms'),
                        'value' => $sms_settings['code_length'],
                        'width' => 'w33',
                    ],
                    'sms_test' => [
                        'type' => 'sms-test',
                        'title' => __('Send Test SMS', 'farazsms'),
                        'phone_field_id' => 'test_sms_phone',
                        'width' => 'w100',
                    ],
                ],
            ],
            'appearance' => [
                'menu' => __('Appearance Settings', 'farazsms'),
                'lable' => __('Appearance Settings', 'farazsms'),
                'settings' => [
                    'theme' => [
                        'type' => 'image-radio',
                        'title' => __('Choose Theme', 'farazsms'),
                        'value' => $appearance['theme'],
                        'options' => [
                            'style-1' => [
                                'label' => __('Style 1', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/style-1.webp'
                            ],
                            'style-2' => [
                                'label' => __('Style 2', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/style-2.webp'
                            ],
                            'style-3' => [
                                'label' => __('Style 3', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/style-3.webp'
                            ],
                            'style-4' => [
                                'label' => __('Style 4', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/style-4.webp'
                            ],
                        ],
                        'width' => 'w100 image-radio-w20',
                    ],
                    'text_alignment' => [
                        'type' => 'image-radio',
                        'title' => __('Text Alignment', 'farazsms'),
                        'value' => $appearance['text_alignment'],
                        'options' => [
                            'right' => [
                                'label' => __('Right', 'farazsms'),
                                'description' => __('Right', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/text-align-right.webp'
                            ],
                            'center' => [
                                'label' => __('Center', 'farazsms'),
                                'description' => __('Center', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/text-align-center.webp'
                            ],
                            'left' => [
                                'label' => __('Left', 'farazsms'),
                                'description' => __('Left', 'farazsms'),
                                'image' => FarazSMS_ASSETS_URL . 'images/text-align-left.webp'
                            ],
                        ],
                        'width' => 'w100 image-radio-alignment',
                    ],
                    'logo' => [
                        'type' => 'file',
                        'format' => 'img',
                        'title' => __('Logo', 'farazsms'),
                        'description' => __('Upload your website logo to display in the login form', 'farazsms'),
                        'value' => $appearance['logo'],
                        'width' => 'w50',
                    ],
                    'background_image' => [
                        'type' => 'file',
                        'format' => 'img',
                        'title' => __('Background Image', 'farazsms'),
                        'value' => $appearance['background_image'],
                        'width' => 'w50',
                    ],
                    'primary_color' => [
                        'type' => 'color',
                        'title' => __('Primary Color', 'farazsms'),
                        'value' => $appearance['primary_color'],
                        'width' => 'w20',
                    ],
                    'background_color' => [
                        'type' => 'color',
                        'title' => __('Background Color', 'farazsms'),
                        'value' => $appearance['background_color'],
                        'width' => 'w20',
                    ],
                    'background_color_box' => [
                        'type' => 'color',
                        'title' => __('Background Color Box', 'farazsms'),
                        'value' => $appearance['background_color_box'],
                        'width' => 'w20',
                    ],
                    'text_color' => [
                        'type' => 'color',
                        'title' => __('Text Color', 'farazsms'),
                        'value' => $appearance['text_color'],
                        'width' => 'w20',
                    ],
                    'border_color' => [
                        'type' => 'color',
                        'title' => __('Border Color', 'farazsms'),
                        'value' => $appearance['border_color'],
                        'width' => 'w20',
                    ],
                    'custom_css' => [
                        'type' => 'textarea',
                        'title' => __('Custom CSS', 'farazsms'),
                        'value' => $appearance['custom_css'],
                        'width' => 'w100',
                    ],
                ],
            ],
            'general' => [
                'menu' => __('General Settings', 'farazsms'),
                'lable' => __('General Settings', 'farazsms'),
                'settings' => [
                    'headin_general' => [
                        'type' => 'heading',
                        'title' => __('General Settings', 'farazsms'),
                    ],
                    'terms_link' => [
                        'type' => 'text',
                        'title' => __('Terms Link', 'farazsms'),
                        'description' => __('Enter the terms and conditions link', 'farazsms'),
                        'value' => $general['terms_link'],
                        'width' => 'w50',
                    ],
                    'redirect_after_login' => [
                        'type' => 'text',
                        'title' => __('Redirect After Login', 'farazsms'),
                        'value' => $general['redirect_after_login'],
                        'description' => __('Enter the redirect URL after login. If left empty, the user returns to the page they came from.', 'farazsms'),
                        'width' => 'w50',
                    ],
                    'replace_default_login_forms' => [
                        'type' => 'switch',
                        'title' => __('جایگزینی فرم‌های ورود و ثبت‌نام سایت', 'farazsms'),
                        'description' => __('فرم‌های پیش‌فرضِ ورود و ثبت‌نامِ سایت با فرمِ پیامکیِ فراز جایگزین می‌شوند تا کاربران سریع‌تر و راحت‌تر با موبایل وارد شوند. (ورودِ پیشخوانِ مدیریت دست‌نخورده می‌ماند.)', 'farazsms'),
                        'value' => $general['replace_default_login_forms'],
                        'width' => 'w50',
                    ],
                    'apply_redirect_all_themes' => [
                        'type' => 'switch',
                        'title' => __('اعمال فرمِ سفارشی روی همه‌ی قالب‌ها', 'farazsms'),
                        'description' => __('فرمِ اختصاصیِ فراز روی همه‌ی قالب‌های سایت اعمال می‌شود تا تجربه‌ی ورود همه‌جا یکدست باشد. (نیازمندِ روشن‌بودنِ گزینه‌ی بالا.)', 'farazsms'),
                        'value' => $general['apply_redirect_all_themes'] ?? '1',
                        'width' => 'w50',
                    ],
                ],
            ],
            'slide' => [
                'menu' => __('Exit Intent Slide', 'farazsms'),
                'lable' => __('Exit Intent Slide Settings', 'farazsms'),
                'settings' => [
                    'enable_slide' => [
                        'type' => 'switch',
                        'title' => __('Enable Exit Intent Slide', 'farazsms'),
                        'value' => $slide['enable_slide'],
                        'width' => 'w50',
                    ],
                    'slide_show_only_guests' => [
                        'type' => 'switch',
                        'title' => __('Show Slide Only for Guests', 'farazsms'),
                        'description' => __('Show slide only for guests', 'farazsms'),
                        'value' => $slide['slide_show_only_guests'],
                        'width' => 'w50',
                    ],
                    'slide_position' => [
                        'type' => 'select',
                        'title' => __('Slide Position', 'farazsms'),
                        'description' => __('Choose slide position', 'farazsms'),
                        'value' => $slide['slide_position'],
                        'array' => [
                            'right' => __('Right', 'farazsms'),
                            'left' => __('Left', 'farazsms'),
                        ],
                        'width' => 'w50',
                    ],
                    'slide_image' => [
                        'type' => 'file',
                        'format' => 'img',
                        'title' => __('Slide Image', 'farazsms'),
                        'description' => __('Upload image for slide (optional)', 'farazsms'),
                        'value' => $slide['slide_image'],
                        'width' => 'w50',
                    ],
                    'slide_title' => [
                        'type' => 'text',
                        'title' => __('Slide Title', 'farazsms'),
                        'description' => __('Enter slide title', 'farazsms'),
                        'value' => $slide['slide_title'],
                        'width' => 'w100',
                    ],
                    'slide_description' => [
                        'type' => 'textarea',
                        'title' => __('Slide Description', 'farazsms'),
                        'description' => __('Enter slide description', 'farazsms'),
                        'value' => $slide['slide_description'],
                        'width' => 'w100',
                    ],
                    'slide_countdown_minutes' => [
                        'type' => 'number',
                        'title' => __('Countdown Minutes', 'farazsms'),
                        'description' => __('Enter countdown time in minutes', 'farazsms'),
                        'value' => $slide['slide_countdown_minutes'],
                        'width' => 'w50',
                    ],
                    'slide_button_text' => [
                        'type' => 'text',
                        'title' => __('Button Text', 'farazsms'),
                        'description' => __('Enter button text', 'farazsms'),
                        'value' => $slide['slide_button_text'],
                        'width' => 'w50',
                    ],
                    'slide_button_link' => [
                        'type' => 'text',
                        'title' => __('Button Link', 'farazsms'),
                        'description' => __('Enter button link URL', 'farazsms'),
                        'value' => $slide['slide_button_link'],
                        'width' => 'w100',
                    ],
                    'slide_button_color' => [
                        'type' => 'color',
                        'title' => __('Button Color', 'farazsms'),
                        'value' => $slide['slide_button_color'],
                        'width' => 'w33',
                    ],
                    'slide_background_color' => [
                        'type' => 'color',
                        'title' => __('Background Color', 'farazsms'),
                        'value' => $slide['slide_background_color'],
                        'width' => 'w33',
                    ],
                    'slide_text_color' => [
                        'type' => 'color',
                        'title' => __('Text Color', 'farazsms'),
                        'value' => $slide['slide_text_color'],
                        'width' => 'w33',
                    ],
                    'slide_title_color' => [
                        'type' => 'color',
                        'title' => __('Title Color', 'farazsms'),
                        'value' => $slide['slide_title_color'],
                        'width' => 'w33',
                    ],
                ],
            ],
        ];

        if (class_exists('WooCommerce')) {
            $settings['general']['settings'] = array_merge($settings['general']['settings'], [
                'headin_woocommerce' => [
                    'type' => 'heading',
                    'title' => __('WooCommerce Settings', 'farazsms'),
                ],
                'checkout_redirect_to_login' => [
                    'type' => 'switch',
                    'title' => __('Checkout Redirect to Login', 'farazsms'),
                    'description' => __('Redirect checkout to login', 'farazsms'),
                    'value' => $general['checkout_redirect_to_login'],
                    'width' => 'w100',
                ],
                'woocommerce_login_redirect' => [
                    'type' => 'switch',
                    'title' => __('Redirect WooCommerce Login to Custom Login', 'farazsms'),
                    'description' => __('Redirect WooCommerce login to custom login', 'farazsms'),
                    'value' => $general['woocommerce_login_redirect'],
                    'width' => 'w100',
                ],
            ]);

            $settings['wallet'] = [
                'menu' => __('New User Signup Bonus', 'farazsms'),
                'lable' => __('Bonus Settings', 'farazsms'),
                'settings' => [
                    'enable_registration_bonus' => [
                        'type' => 'switch',
                        'title' => __('Enable Registration Bonus', 'farazsms'),
                        'value' => $wallet['enable_registration_bonus'],
                        'width' => 'w100',
                    ],
                    'registration_bonus_amount' => [
                        'type' => 'number',
                        'title' => __('Registration Bonus Amount', 'farazsms') . ' (' . get_woocommerce_currency_symbol() . ')',
                        'description' => __('Enter the registration bonus amount', 'farazsms'),
                        'value' => $wallet['registration_bonus_amount'],
                        'width' => 'w50',
                    ],
                    'registration_bonus_description' => [
                        'type' => 'text',
                        'title' => __('Registration Bonus Description', 'farazsms'),
                        'description' => __('Enter the registration bonus description', 'farazsms'),
                        'value' => $wallet['registration_bonus_description'],
                        'width' => 'w50',
                    ],
                ]
            ];

            if (function_exists('wc_get_order_statuses')) {
                $order_statuses = wc_get_order_statuses();
                
                $shortcodes_html = '<div class="html-setting-shortcodes">
                            <div class="shortcode-section">
                                <h4 class="shortcode-section-title">' . __('Order Details:', 'farazsms') . '</h4>
                                <div class="shortcode-codes-grid">
                                    <div class="shortcode-item">
                                        <code>mobile</code>
                                        <span class="shortcode-desc">' . __('Customer mobile number', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>email</code>
                                        <span class="shortcode-desc">' . __('Customer email', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>status</code>
                                        <span class="shortcode-desc">' . __('Order status', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>all_items</code>
                                        <span class="shortcode-desc">' . __('Order items', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>all_items_full</code>
                                        <span class="shortcode-desc">' . __('Order items with full variable name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>all_items_qty</code>
                                        <span class="shortcode-desc">' . __('Order items with quantity', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>count_items</code>
                                        <span class="shortcode-desc">' . __('Number of order items', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>price</code>
                                        <span class="shortcode-desc">' . __('Order amount', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>order_id</code>
                                        <span class="shortcode-desc">' . __('Order number', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>transaction_id</code>
                                        <span class="shortcode-desc">' . __('Transaction number', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>date</code>
                                        <span class="shortcode-desc">' . __('Order date', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>description</code>
                                        <span class="shortcode-desc">' . __('Customer notes', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>payment_method</code>
                                        <span class="shortcode-desc">' . __('Payment method', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>shipping_method</code>
                                        <span class="shortcode-desc">' . __('Shipping method', 'farazsms') . '</span>
                                    </div>
                                </div>
                            </div>

                            <div class="shortcode-section">
                                <h4 class="shortcode-section-title">' . __('Billing Details:', 'farazsms') . '</h4>
                                <div class="shortcode-codes-grid">
                                    <div class="shortcode-item">
                                        <code>b_first_name</code>
                                        <span class="shortcode-desc">' . __('Customer first name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_last_name</code>
                                        <span class="shortcode-desc">' . __('Customer last name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_company</code>
                                        <span class="shortcode-desc">' . __('Company name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_country</code>
                                        <span class="shortcode-desc">' . __('Country', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_state</code>
                                        <span class="shortcode-desc">' . __('State/Province', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_city</code>
                                        <span class="shortcode-desc">' . __('City', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_address_1</code>
                                        <span class="shortcode-desc">' . __('Address 1', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_address_2</code>
                                        <span class="shortcode-desc">' . __('Address 2', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>b_postcode</code>
                                        <span class="shortcode-desc">' . __('Postcode', 'farazsms') . '</span>
                                    </div>
                                </div>
                            </div>
		
                            <div class="shortcode-section">
                                <h4 class="shortcode-section-title">' . __('Shipping Details:', 'farazsms') . '</h4>
                                <div class="shortcode-codes-grid">
                                    <div class="shortcode-item">
                                        <code>s_first_name</code>
                                        <span class="shortcode-desc">' . __('Customer first name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_last_name</code>
                                        <span class="shortcode-desc">' . __('Customer last name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_company</code>
                                        <span class="shortcode-desc">' . __('Company name', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_country</code>
                                        <span class="shortcode-desc">' . __('Country', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_state</code>
                                        <span class="shortcode-desc">' . __('State/Province', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_city</code>
                                        <span class="shortcode-desc">' . __('City', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_address_1</code>
                                        <span class="shortcode-desc">' . __('Address 1', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_address_2</code>
                                        <span class="shortcode-desc">' . __('Address 2', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>s_postcode</code>
                                        <span class="shortcode-desc">' . __('Postcode', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>post_tracking_code</code>
                                        <span class="shortcode-desc">' . __('Post tracking code', 'farazsms') . '</span>
                                    </div>
                                    <div class="shortcode-item">
                                        <code>post_tracking_url</code>
                                        <span class="shortcode-desc">' . __('Post tracking URL', 'farazsms') . '</span>
                                    </div>
                                </div>
                            </div>
                        </div>';

                $customer_tab_settings = [
                    'sms_shortcodes' => [
                        'type' => 'html',
                        'title' => __('Shortcodes SMS', 'farazsms'),
                        'html' => $shortcodes_html,
                    ],
                    'heading_customer_sms' => [
                        'type' => 'heading',
                        'title' => __('Customer Order SMS', 'farazsms'),
                    ]
                ];
                foreach ($order_statuses as $status_key => $status_label) {
                    $slug = str_replace('wc-', '', $status_key);
                    $enable_id = 'customer_' . $slug . '_enable';
                    $pattern_id = 'customer_' . $slug . '_pattern';
                    $attributes_id = 'customer_' . $slug . '_attributes';
                    $customer_tab_settings[$enable_id] = [
                        'type' => 'switch',
                        'title' => sprintf(__('Enable %s SMS', 'farazsms'), $status_label),
                        'value' => $woo_sms[$enable_id] ?? '0',
                        'width' => 'w20',
                    ];
                    $customer_tab_settings[$pattern_id] = [
                        'type' => 'text',
                        'title' => sprintf(__('Pattern code for %s', 'farazsms'), $status_label),
                        'value' => $woo_sms[$pattern_id] ?? '',
                        'width' => 'w30 ltr',
                    ];
                    $customer_tab_settings[$attributes_id] = [
                        'type' => 'textarea',
                        'title' => sprintf(__('Pattern attributes for %s', 'farazsms'), $status_label),
                        'description' => __('Each parameter should be on one line, for example: <br> b_first_name <br> b_last_name', 'farazsms'),
                        'value' => $woo_sms[$attributes_id] ?? '',
                        'width' => 'w50 ltr',
                    ];
                }

                $admin_tab_settings = [
                    'sms_shortcodes' => [
                        'type' => 'html',
                        'title' => __('Shortcodes SMS', 'farazsms'),
                        'html' => $shortcodes_html,
                    ],
                    'heading_admin_sms' => [
                        'type' => 'heading',
                        'title' => __('Admin Order SMS', 'farazsms'),
                    ],
                    'admin_phone' => [
                        'type' => 'text',
                        'title' => __('Admin mobile number', 'farazsms'),
                        'description' => __('Enter admin mobile number for receiving order SMS', 'farazsms'),
                        'value' => $woo_sms['admin_phone'] ?? '',
                        'width' => 'w100',
                    ],
                ];
                foreach ($order_statuses as $status_key => $status_label) {
                    $slug = str_replace('wc-', '', $status_key);
                    $enable_id = 'admin_' . $slug . '_enable';
                    $pattern_id = 'admin_' . $slug . '_pattern';
                    $attributes_id = 'admin_' . $slug . '_attributes';
                    $admin_tab_settings[$enable_id] = [
                        'type' => 'switch',
                        'title' => sprintf(__('Enable %s SMS', 'farazsms'), $status_label),
                        'value' => $woo_sms[$enable_id] ?? '0',
                        'width' => 'w20',
                    ];
                    $admin_tab_settings[$pattern_id] = [
                        'type' => 'text',
                        'title' => sprintf(__('Pattern code for %s', 'farazsms'), $status_label),
                        'value' => $woo_sms[$pattern_id] ?? '',
                        'width' => 'w30 ltr',
                    ];
                    $admin_tab_settings[$attributes_id] = [
                        'type' => 'textarea',
                        'title' => sprintf(__('Pattern attributes for %s', 'farazsms'), $status_label),
                        'description' => __('Each parameter should be on one line, for example: <br> b_first_name <br> b_last_name', 'farazsms'),
                        'value' => $woo_sms[$attributes_id] ?? '',
                        'width' => 'w50 ltr',
                    ];
                }

                $settings['woo_sms'] = [
                    'menu' => __('WooCommerce Order SMS', 'farazsms'),
                    'lable' => __('WooCommerce Order SMS Settings', 'farazsms'),
                    'settings' => [
                        'woo_sms_tabs' => [
                            'type' => 'tabs',
                            'width' => 'w100',
                            'tabs' => [
                                'customer' => [
                                    'title' => __('Customer SMS', 'farazsms'),
                                    'settings' => $customer_tab_settings,
                                ],
                                'admin' => [
                                    'title' => __('Admin SMS', 'farazsms'),
                                    'settings' => $admin_tab_settings,
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        return $settings;
    }

    protected function General_Settings($current_tab) {
        $all_settings = $this->All_Settings();

        foreach($all_settings as $name => $section) {
            $lable = $section['lable'];
            $settings = $section['settings'];

            if ($name == $current_tab) {
                echo "<div class='content-tab $name-options'>"
                    . "<h2>$lable</h2>"
                    . "<div class='farazsms-form-setting flex flex-wrap'>";
                    foreach($settings as $id => $setting) {
                        echo $this->Farazsms_Type_To_Function($id,$setting);
                    }
                    echo "</div>"
                . "</div>";
            }
        }
    }
}

new Settings;
