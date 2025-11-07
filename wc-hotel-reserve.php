<?php
/**
 * Plugin Name: WooCommerce Hotel Reservation System - Professional Edition
 * Description: سیستم پیشرفته رزرو هتل با چک‌این/چک‌اوت و مدیریت چند اتاق
 * Version: 7.0.0
 * Author: بهادر
 * Text Domain: wc-hotel-reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Hotel_Reserve {

    private static $instance = null;

    private function __construct() {
        $this->init_hooks();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks() {
        // پنل مدیریت محصول
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);

        // نمایش فیلدهای رزرو در صفحه محصول
        add_action('woocommerce_before_add_to_cart_button', [$this, 'display_reservation_fields']);
        add_action('wp_footer', [$this, 'enqueue_scripts_and_styles']);

        // اعتبارسنجی و سبد خرید
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_reservation'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price']);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);

        // AJAX
        add_action('wp_ajax_hotel_check_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_hotel_check_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_hotel_get_disabled_dates', [$this, 'ajax_get_disabled_dates']);
        add_action('wp_ajax_nopriv_hotel_get_disabled_dates', [$this, 'ajax_get_disabled_dates']);

        // فیلتر محصولات برای نمایش فقط اتاق‌ها
        add_action('pre_get_posts', [$this, 'filter_shop_products']);
    }

    /**
     * فقط محصولات room نمایش داده شوند (نه parent hotels)
     */
    public function filter_shop_products($query) {
        if (!is_admin() && $query->is_main_query() && (is_shop() || is_product_category() || is_product_tag())) {
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key' => '_hotel_product_type',
                'value' => 'room',
                'compare' => '='
            ];
            $query->set('meta_query', $meta_query);
        }
    }

    public function get_product_settings($product_id) {
        return [
            'product_type' => get_post_meta($product_id, '_hotel_product_type', true) ?: 'room',
            'parent_hotel_id' => get_post_meta($product_id, '_parent_hotel_id', true),
            'room_capacity' => get_post_meta($product_id, '_room_capacity', true) ?: 1,
            'date_configs' => get_post_meta($product_id, '_hotel_date_configs', true) ?: [],
            'custom_fields' => get_post_meta($product_id, '_hotel_custom_fields', true) ?: []
        ];
    }

    /**
     * دریافت تمام رزروهای یک اتاق در یک بازه تاریخی
     */
    public function get_room_bookings_in_range($product_id, $check_in, $check_out) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                oim_checkin.meta_value as check_in,
                oim_checkout.meta_value as check_out,
                oim_qty.meta_value as quantity
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_product
                ON oi.order_item_id = oim_product.order_item_id
                AND oim_product.meta_key = '_product_id'
                AND oim_product.meta_value = %d
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_checkin
                ON oi.order_item_id = oim_checkin.order_item_id
                AND oim_checkin.meta_key = '_hotel_check_in'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_checkout
                ON oi.order_item_id = oim_checkout.order_item_id
                AND oim_checkout.meta_key = '_hotel_check_out'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                ON oi.order_item_id = oim_qty.order_item_id
                AND oim_qty.meta_key = '_qty'
            INNER JOIN {$wpdb->posts} p
                ON oi.order_id = p.ID
                AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ", $product_id));

        return $results;
    }

    /**
     * چک کردن موجودی برای هر شب در بازه انتخابی
     */
    public function check_availability_for_dates($product_id, $check_in, $check_out, $quantity = 1) {
        $settings = $this->get_product_settings($product_id);
        $capacity = intval($settings['room_capacity']);

        // تبدیل تاریخ‌های شمسی به میلادی
        $check_in_parts = explode('/', $check_in);
        $check_out_parts = explode('/', $check_out);

        $check_in_gregorian = $this->jalali_to_gregorian($check_in_parts[0], $check_in_parts[1], $check_in_parts[2]);
        $check_out_gregorian = $this->jalali_to_gregorian($check_out_parts[0], $check_out_parts[1], $check_out_parts[2]);

        $start = new DateTime($check_in_gregorian[0] . '-' . $check_in_gregorian[1] . '-' . $check_in_gregorian[2]);
        $end = new DateTime($check_out_gregorian[0] . '-' . $check_out_gregorian[1] . '-' . $check_out_gregorian[2]);

        // دریافت تمام رزروهای موجود
        $bookings = $this->get_room_bookings_in_range($product_id, $check_in, $check_out);

        // بررسی هر شب
        $current = clone $start;
        while ($current < $end) {
            $current_jalali = $this->gregorian_to_jalali($current->format('Y'), $current->format('m'), $current->format('d'));
            $current_date_str = $current_jalali[0] . '/' . str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);

            // بررسی تاریخ‌های غیرفعال شده
            foreach ($settings['date_configs'] as $config) {
                if ($config['date'] === $current_date_str && !empty($config['is_disabled'])) {
                    return [
                        'available' => false,
                        'blocked_date' => $current_date_str,
                        'reason' => 'disabled'
                    ];
                }
            }

            // محاسبه تعداد رزروها در این تاریخ
            $booked_on_date = 0;
            foreach ($bookings as $booking) {
                if ($this->date_in_range($current_date_str, $booking->check_in, $booking->check_out)) {
                    $booked_on_date += intval($booking->quantity);
                }
            }

            // اگر ظرفیت کافی نیست
            if ($capacity - $booked_on_date < $quantity) {
                return [
                    'available' => false,
                    'blocked_date' => $current_date_str,
                    'remaining' => max(0, $capacity - $booked_on_date),
                    'reason' => 'full'
                ];
            }

            $current->modify('+1 day');
        }

        return ['available' => true];
    }

    /**
     * دریافت تاریخ‌های غیرفعال برای نمایش در تقویم
     */
    public function get_disabled_dates($product_id) {
        $settings = $this->get_product_settings($product_id);
        $capacity = intval($settings['room_capacity']);
        $disabled = [];

        // تاریخ‌های غیرفعال شده دستی
        foreach ($settings['date_configs'] as $config) {
            if (!empty($config['is_disabled'])) {
                $disabled[] = $config['date'];
            }
        }

        // تاریخ‌های پر شده (برای 3 ماه آینده)
        $today_jalali = $this->gregorian_to_jalali(date('Y'), date('m'), date('d'));
        $start_date = $today_jalali[0] . '/' . str_pad($today_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($today_jalali[2], 2, '0', STR_PAD_LEFT);

        $end_gregorian = strtotime('+90 days');
        $end_jalali = $this->gregorian_to_jalali(date('Y', $end_gregorian), date('m', $end_gregorian), date('d', $end_gregorian));
        $end_date = $end_jalali[0] . '/' . str_pad($end_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($end_jalali[2], 2, '0', STR_PAD_LEFT);

        $bookings = $this->get_room_bookings_in_range($product_id, $start_date, $end_date);

        // چک کردن هر روز
        $current = new DateTime();
        $end_check = new DateTime();
        $end_check->modify('+90 days');

        while ($current <= $end_check) {
            $current_jalali = $this->gregorian_to_jalali($current->format('Y'), $current->format('m'), $current->format('d'));
            $current_date_str = $current_jalali[0] . '/' . str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);

            $booked_on_date = 0;
            foreach ($bookings as $booking) {
                if ($this->date_in_range($current_date_str, $booking->check_in, $booking->check_out)) {
                    $booked_on_date += intval($booking->quantity);
                }
            }

            if ($capacity - $booked_on_date <= 0) {
                $disabled[] = $current_date_str;
            }

            $current->modify('+1 day');
        }

        return array_unique($disabled);
    }

    /**
     * AJAX برای دریافت تاریخ‌های غیرفعال
     */
    public function ajax_get_disabled_dates() {
        $product_id = intval($_POST['product_id']);
        $disabled = $this->get_disabled_dates($product_id);
        wp_send_json_success(['disabled_dates' => $disabled]);
    }

    /**
     * چک می‌کند آیا یک تاریخ در بازه check_in تا check_out قرار دارد
     */
    private function date_in_range($date, $range_start, $range_end) {
        return ($date >= $range_start && $date < $range_end);
    }

    /**
     * محاسبه قیمت کل بر اساس هر شب
     */
    public function calculate_total_price($product_id, $check_in, $check_out) {
        $product = wc_get_product($product_id);
        $base_price = floatval($product->get_price());
        $settings = $this->get_product_settings($product_id);

        // تبدیل تاریخ‌ها به میلادی
        $check_in_parts = explode('/', $check_in);
        $check_out_parts = explode('/', $check_out);

        $check_in_gregorian = $this->jalali_to_gregorian($check_in_parts[0], $check_in_parts[1], $check_in_parts[2]);
        $check_out_gregorian = $this->jalali_to_gregorian($check_out_parts[0], $check_out_parts[1], $check_out_parts[2]);

        $start = new DateTime($check_in_gregorian[0] . '-' . $check_in_gregorian[1] . '-' . $check_in_gregorian[2]);
        $end = new DateTime($check_out_gregorian[0] . '-' . $check_out_gregorian[1] . '-' . $check_out_gregorian[2]);

        $total_price = 0;
        $nights = 0;
        $price_breakdown = [];

        $current = clone $start;
        while ($current < $end) {
            $current_jalali = $this->gregorian_to_jalali($current->format('Y'), $current->format('m'), $current->format('d'));
            $current_date_str = $current_jalali[0] . '/' . str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);

            // چک کردن قیمت سفارشی برای این تاریخ
            $night_price = $base_price;
            foreach ($settings['date_configs'] as $config) {
                if ($config['date'] === $current_date_str && isset($config['price']) && $config['price'] > 0) {
                    $night_price = floatval($config['price']);
                    break;
                }
            }

            $total_price += $night_price;
            $nights++;
            $price_breakdown[] = [
                'date' => $current_date_str,
                'price' => $night_price
            ];

            $current->modify('+1 day');
        }

        return [
            'total' => $total_price,
            'nights' => $nights,
            'breakdown' => $price_breakdown
        ];
    }

    /**
     * تبدیل تاریخ شمسی به میلادی
     */
    private function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = intval($jy);
        $jm = intval($jm);
        $jd = intval($jd);

        $jy += 1595;
        $days = 365 * $jy + floor($jy / 33) * 8 + floor((($jy % 33) + 3) / 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186) - 355668;
        $gy = 400 * floor($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * floor(--$days / 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * floor($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += floor(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /**
     * تبدیل تاریخ میلادی به شمسی
     */
    private function gregorian_to_jalali($gy, $gm, $gd) {
        $gy = intval($gy);
        $gm = intval($gm);
        $gd = intval($gd);

        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + floor(($gy2 + 3) / 4) - floor(($gy2 + 99) / 100) + floor(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * floor($days / 12053);
        $days %= 12053;
        $jy += 4 * floor($days / 1461);
        $days %= 1461;
        if ($days > 365) { $jy += floor(($days - 1) / 365); $days = ($days - 1) % 365; }
        $jm = ($days < 186) ? 1 + floor($days / 31) : 7 + floor(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return [$jy, $jm, $jd];
    }

    public function add_product_data_tab($tabs) {
        $tabs['hotel_reserve'] = [
            'label' => '🏨 تنظیمات هتل',
            'target' => 'hotel_reserve_data',
            'class' => ['show_if_simple']
        ];
        return $tabs;
    }

    public function add_product_data_panel() {
        global $post;
        $settings = $this->get_product_settings($post->ID);
        ?>
        <div id="hotel_reserve_data" class="panel woocommerce_options_panel">
            <div class="hotel-admin-wrapper">

                <!-- نوع محصول -->
                <div class="hotel-section">
                    <h3>🏷️ نوع محصول</h3>
                    <?php
                    woocommerce_wp_select([
                        'id' => '_hotel_product_type',
                        'label' => 'این محصول چیست؟',
                        'options' => [
                            'room' => 'اتاق (قابل رزرو)',
                            'hotel' => 'هتل (والد - غیرقابل رزرو)'
                        ],
                        'value' => $settings['product_type']
                    ]);
                    ?>
                </div>

                <!-- انتخاب هتل والد -->
                <div class="hotel-section" id="parent-hotel-section">
                    <h3>🏢 هتل مربوطه</h3>
                    <?php
                    $hotels = get_posts([
                        'post_type' => 'product',
                        'posts_per_page' => -1,
                        'meta_query' => [
                            [
                                'key' => '_hotel_product_type',
                                'value' => 'hotel'
                            ]
                        ]
                    ]);

                    $hotel_options = ['' => 'انتخاب کنید...'];
                    foreach ($hotels as $hotel) {
                        $hotel_options[$hotel->ID] = $hotel->post_title;
                    }

                    woocommerce_wp_select([
                        'id' => '_parent_hotel_id',
                        'label' => 'این اتاق متعلق به کدام هتل است؟',
                        'options' => $hotel_options,
                        'value' => $settings['parent_hotel_id']
                    ]);
                    ?>
                </div>

                <!-- ظرفیت اتاق -->
                <div class="hotel-section" id="room-capacity-section">
                    <h3>👥 ظرفیت اتاق</h3>
                    <?php
                    woocommerce_wp_text_input([
                        'id' => '_room_capacity',
                        'label' => 'تعداد اتاق موجود',
                        'type' => 'number',
                        'custom_attributes' => ['min' => '1'],
                        'value' => $settings['room_capacity'],
                        'desc_tip' => true,
                        'description' => 'مثلاً اگر 3 اتاق از این نوع دارید، عدد 3 را وارد کنید'
                    ]);
                    ?>
                </div>

                <!-- قیمت‌گذاری تاریخ‌های خاص -->
                <div class="hotel-section" id="date-pricing-section">
                    <h3>📅 قیمت‌گذاری تاریخ‌های خاص</h3>
                    <p style="color:#666;">برای ایام خاص (تعطیلات، آخر هفته و...) می‌توانید قیمت متفاوت تعیین کنید.</p>

                    <p>
                        <button type="button" class="button button-primary" id="hotel-add-date-config">➕ افزودن تاریخ</button>
                    </p>

                    <div id="hotel-date-configs-container">
                        <?php foreach ($settings['date_configs'] as $index => $config): ?>
                        <div class="hotel-date-config-item" data-index="<?php echo $index; ?>">
                            <button type="button" class="button hotel-remove-date-config">حذف</button>

                            <label><strong>📅 تاریخ:</strong></label>
                            <input type="text"
                                   class="hotel-date-config-date"
                                   name="hotel_date_config_dates[]"
                                   value="<?php echo esc_attr($config['date']); ?>"
                                   placeholder="مثال: 1403/09/15"
                                   required>

                            <label><strong>💰 قیمت (تومان):</strong></label>
                            <input type="number"
                                   name="hotel_date_config_prices[]"
                                   value="<?php echo esc_attr($config['price'] ?? ''); ?>"
                                   step="1000"
                                   placeholder="قیمت برای این شب">

                            <label>
                                <input type="checkbox"
                                       name="hotel_date_config_disabled[]"
                                       value="<?php echo $index; ?>"
                                       <?php checked($config['is_disabled'] ?? false, true); ?>>
                                🚫 غیرفعال کردن این تاریخ
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- فیلدهای اضافی -->
                <div class="hotel-section" id="custom-fields-section">
                    <h3>📋 فیلدهای اضافی در فرم رزرو</h3>
                    <p>
                        <button type="button" class="button" id="hotel-add-field">➕ افزودن فیلد</button>
                    </p>

                    <table class="widefat" id="hotel-custom-fields-table">
                        <thead>
                            <tr>
                                <th>عنوان</th>
                                <th>نوع</th>
                                <th>اجباری</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings['custom_fields'] as $index => $field): ?>
                            <tr>
                                <td><input type="text" name="hotel_field_label[]" value="<?php echo esc_attr($field['label']); ?>" required></td>
                                <td>
                                    <select name="hotel_field_type[]">
                                        <option value="text" <?php selected($field['type'], 'text'); ?>>متن</option>
                                        <option value="email" <?php selected($field['type'], 'email'); ?>>ایمیل</option>
                                        <option value="tel" <?php selected($field['type'], 'tel'); ?>>تلفن</option>
                                        <option value="number" <?php selected($field['type'], 'number'); ?>>عدد</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="checkbox" name="hotel_field_required_<?php echo $index; ?>" value="1" <?php checked($field['required'] ?? false, true); ?>>
                                </td>
                                <td><button type="button" class="button hotel-remove-field">حذف</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <style>
        .hotel-admin-wrapper { padding: 20px; }
        .hotel-section { background: #f9f9f9; padding: 20px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #ddd; }
        .hotel-section h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid #2271b1; }
        .hotel-date-config-item { background: white; padding: 15px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; position: relative; }
        .hotel-date-config-item button.hotel-remove-date-config { position: absolute; top: 10px; left: 10px; background: #dc3545; color: white; }
        .hotel-date-config-item label { display: block; margin: 10px 0 5px; font-weight: bold; }
        .hotel-date-config-item input[type="text"],
        .hotel-date-config-item input[type="number"] { width: 100%; max-width: 300px; padding: 8px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var dateConfigCounter = <?php echo count($settings['date_configs']); ?>;
            var fieldCounter = <?php echo count($settings['custom_fields']); ?>;

            // نمایش/مخفی کردن بخش‌ها بر اساس نوع محصول
            function toggleSections() {
                var type = $('#_hotel_product_type').val();
                if (type === 'hotel') {
                    $('#parent-hotel-section, #room-capacity-section, #date-pricing-section, #custom-fields-section').hide();
                } else {
                    $('#parent-hotel-section, #room-capacity-section, #date-pricing-section, #custom-fields-section').show();
                }
            }

            $('#_hotel_product_type').on('change', toggleSections);
            toggleSections();

            // افزودن تاریخ
            $('#hotel-add-date-config').on('click', function(e) {
                e.preventDefault();
                var html = '<div class="hotel-date-config-item" data-index="' + dateConfigCounter + '">' +
                    '<button type="button" class="button hotel-remove-date-config">حذف</button>' +
                    '<label><strong>📅 تاریخ:</strong></label>' +
                    '<input type="text" class="hotel-date-config-date" name="hotel_date_config_dates[]" placeholder="مثال: 1403/09/15" required>' +
                    '<label><strong>💰 قیمت (تومان):</strong></label>' +
                    '<input type="number" name="hotel_date_config_prices[]" step="1000" placeholder="قیمت برای این شب">' +
                    '<label><input type="checkbox" name="hotel_date_config_disabled[]" value="' + dateConfigCounter + '"> 🚫 غیرفعال کردن</label>' +
                '</div>';
                $('#hotel-date-configs-container').append(html);
                dateConfigCounter++;
            });

            $(document).on('click', '.hotel-remove-date-config', function() {
                $(this).closest('.hotel-date-config-item').remove();
            });

            // افزودن فیلد
            $('#hotel-add-field').on('click', function(e) {
                e.preventDefault();
                var html = '<tr>' +
                    '<td><input type="text" name="hotel_field_label[]" required></td>' +
                    '<td><select name="hotel_field_type[]"><option value="text">متن</option><option value="email">ایمیل</option><option value="tel">تلفن</option><option value="number">عدد</option></select></td>' +
                    '<td><input type="checkbox" name="hotel_field_required_' + fieldCounter + '" value="1"></td>' +
                    '<td><button type="button" class="button hotel-remove-field">حذف</button></td>' +
                '</tr>';
                $('#hotel-custom-fields-table tbody').append(html);
                fieldCounter++;
            });

            $(document).on('click', '.hotel-remove-field', function() {
                $(this).closest('tr').remove();
            });
        });
        </script>
        <?php
    }

    public function save_product_meta($post_id) {
        // نوع محصول
        update_post_meta($post_id, '_hotel_product_type', sanitize_text_field($_POST['_hotel_product_type'] ?? 'room'));
        update_post_meta($post_id, '_parent_hotel_id', intval($_POST['_parent_hotel_id'] ?? 0));
        update_post_meta($post_id, '_room_capacity', intval($_POST['_room_capacity'] ?? 1));

        // پیکربندی تاریخ‌ها
        $date_configs = [];
        if (isset($_POST['hotel_date_config_dates']) && is_array($_POST['hotel_date_config_dates'])) {
            $disabled_array = isset($_POST['hotel_date_config_disabled']) ? $_POST['hotel_date_config_disabled'] : [];
            foreach ($_POST['hotel_date_config_dates'] as $index => $date) {
                if (!empty($date)) {
                    $date_configs[] = [
                        'date' => sanitize_text_field($date),
                        'price' => isset($_POST['hotel_date_config_prices'][$index]) ? floatval($_POST['hotel_date_config_prices'][$index]) : 0,
                        'is_disabled' => in_array($index, $disabled_array)
                    ];
                }
            }
        }
        update_post_meta($post_id, '_hotel_date_configs', $date_configs);

        // فیلدهای اضافی
        $custom_fields = [];
        if (isset($_POST['hotel_field_label']) && is_array($_POST['hotel_field_label'])) {
            foreach ($_POST['hotel_field_label'] as $index => $label) {
                if (!empty($label)) {
                    $custom_fields[] = [
                        'label' => sanitize_text_field($label),
                        'type' => sanitize_text_field($_POST['hotel_field_type'][$index] ?? 'text'),
                        'required' => isset($_POST['hotel_field_required_' . $index]) && $_POST['hotel_field_required_' . $index] == '1'
                    ];
                }
            }
        }
        update_post_meta($post_id, '_hotel_custom_fields', $custom_fields);
    }

    public function display_reservation_fields() {
        global $post;
        if (!$post) return;

        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('simple')) return;

        $settings = $this->get_product_settings($post->ID);

        // فقط برای اتاق‌ها نمایش داده شود
        if ($settings['product_type'] !== 'room') {
            echo '<div style="background:#fff3cd;padding:15px;border-radius:8px;margin:20px 0;"><strong>⚠️ این محصول یک هتل است و مستقیماً قابل رزرو نیست.</strong><br>لطفاً یکی از اتاق‌های زیر را انتخاب کنید.</div>';
            return;
        }

        echo '<div class="hotel-reserve-wrapper" style="background:#f8f9fa;padding:25px;border-radius:10px;margin:20px 0;border:2px solid #e9ecef;">';

        // تقویم یکپارچه
        echo '<div class="hotel-field-group" style="margin-bottom:25px;">
                <label style="display:block;font-weight:600;margin-bottom:15px;font-size:18px;"><strong>📅 انتخاب تاریخ ورود و خروج</strong></label>

                <input type="hidden" id="hotel-checkin-input" name="hotel_checkin" required>
                <input type="hidden" id="hotel-checkout-input" name="hotel_checkout" required>

                <!-- نمایش انتخاب شده -->
                <div id="hotel-selection-display" style="background:white;padding:15px;border-radius:8px;margin-bottom:15px;display:none;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;">
                        <div>
                            <small style="color:#666;">تاریخ ورود</small>
                            <div style="font-weight:bold;color:#667eea;" id="selected-checkin">-</div>
                        </div>
                        <div>
                            <small style="color:#666;">تاریخ خروج</small>
                            <div style="font-weight:bold;color:#11998e;" id="selected-checkout">-</div>
                        </div>
                        <div>
                            <small style="color:#666;">تعداد شب</small>
                            <div style="font-weight:bold;color:#f093fb;" id="selected-nights">-</div>
                        </div>
                    </div>
                </div>

                <!-- دکمه باز کردن تقویم -->
                <button type="button" id="hotel-open-calendar-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:15px 30px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;width:100%;box-shadow:0 4px 15px rgba(102,126,234,0.4);">
                    📅 انتخاب تاریخ رزرو
                </button>

                <!-- تقویم مودال -->
                <div id="hotel-calendar-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;align-items:center;justify-content:center;">
                    <div style="background:white;border-radius:15px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 50px rgba(0,0,0,0.5);">
                        <div style="padding:20px;border-bottom:2px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:15px 15px 0 0;">
                            <h3 style="margin:0;">📅 انتخاب تاریخ رزرو</h3>
                            <button type="button" id="hotel-close-calendar" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;font-size:18px;">✕</button>
                        </div>

                        <div style="padding:20px;">
                            <!-- هدر تقویم -->
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                                <button type="button" id="hotel-prev-month" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;font-weight:bold;">❮</button>
                                <div id="hotel-current-month" style="font-weight:bold;font-size:18px;"></div>
                                <button type="button" id="hotel-next-month" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;font-weight:bold;">❯</button>
                            </div>

                            <!-- راهنما -->
                            <div id="hotel-calendar-guide" style="background:#e3f2fd;padding:10px;border-radius:8px;margin-bottom:15px;text-align:center;font-size:14px;">
                                <strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>
                            </div>

                            <!-- روزهای هفته -->
                            <div id="hotel-weekdays" style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;">
                                <div>ش</div>
                                <div>ی</div>
                                <div>د</div>
                                <div>س</div>
                                <div>چ</div>
                                <div>پ</div>
                                <div>ج</div>
                            </div>

                            <!-- روزهای ماه -->
                            <div id="hotel-calendar-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;"></div>

                            <!-- اطلاعات قیمت -->
                            <div id="hotel-price-info" style="display:none;background:#f0f8ff;padding:15px;border-radius:8px;margin-top:20px;">
                                <div style="margin-bottom:10px;">
                                    <strong style="color:#28a745;">✓ تعداد شب‌ها:</strong> <span id="hotel-nights-count">0</span> شب
                                </div>
                                <div style="margin-bottom:10px;">
                                    <strong style="color:#28a745;">💰 قیمت کل:</strong> <span id="hotel-total-price">0</span> تومان
                                </div>
                                <div id="hotel-price-breakdown" style="font-size:13px;color:#666;max-height:150px;overflow-y:auto;"></div>
                            </div>

                            <div id="hotel-availability-warning" style="display:none;background:#dc3545;color:white;padding:15px;border-radius:8px;margin-top:15px;font-weight:bold;text-align:center;">
                                ⚠️ تاریخ‌های انتخابی موجود نیست
                            </div>

                            <!-- دکمه تایید -->
                            <button type="button" id="hotel-confirm-dates" style="display:none;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:15px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;width:100%;margin-top:15px;box-shadow:0 4px 15px rgba(17,153,142,0.4);">
                                ✓ تایید و ادامه
                            </button>
                        </div>
                    </div>
                </div>
              </div>';

        // فیلدهای اضافی
        if (!empty($settings['custom_fields'])) {
            echo '<div class="hotel-field-group" style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;margin-bottom:10px;font-size:16px;"><strong>📋 اطلاعات تکمیلی</strong></label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:15px;">';

            foreach ($settings['custom_fields'] as $index => $field) {
                $field_id = 'hotel_field_' . $index;
                $required = $field['required'] ? 'required' : '';
                $req_star = $field['required'] ? ' *' : '';

                echo '<div>
                        <label for="' . $field_id . '" style="display:block;margin-bottom:5px;font-weight:500;">' . esc_html($field['label']) . $req_star . '</label>';

                $style = 'width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;';

                if ($field['type'] === 'email') {
                    echo '<input type="email" id="' . $field_id . '" name="' . $field_id . '" ' . $required . ' style="' . $style . '">';
                } elseif ($field['type'] === 'tel') {
                    echo '<input type="tel" id="' . $field_id . '" name="' . $field_id . '" ' . $required . ' pattern="09[0-9]{9}" style="' . $style . '">';
                } elseif ($field['type'] === 'number') {
                    echo '<input type="number" id="' . $field_id . '" name="' . $field_id . '" ' . $required . ' style="' . $style . '">';
                } else {
                    echo '<input type="text" id="' . $field_id . '" name="' . $field_id . '" ' . $required . ' style="' . $style . '">';
                }

                echo '</div>';
            }

            echo '</div></div>';
        }

        echo '</div>';
    }

    public function enqueue_scripts_and_styles() {
        if (!is_product()) return;

        global $post;
        if (!$post) return;

        $settings = $this->get_product_settings($post->ID);
        if ($settings['product_type'] !== 'room') return;

        $product_id = $post->ID;
        $base_price = floatval(wc_get_product($product_id)->get_price());
        $date_configs_json = json_encode($settings['date_configs']);
        ?>

        <style>
        #hotel-calendar-modal { display: none; }
        #hotel-calendar-modal.active { display: flex !important; }
        .hotel-day {
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
            border: 2px solid transparent;
            font-weight: 500;
        }
        .hotel-day:hover:not(.disabled):not(.past) {
            background: #e3f2fd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .hotel-day.disabled {
            background: #f5f5f5;
            color: #ccc;
            cursor: not-allowed;
            text-decoration: line-through;
        }
        .hotel-day.past {
            background: #fafafa;
            color: #999;
            cursor: not-allowed;
        }
        .hotel-day.selected-checkin {
            background: linear-gradient(135deg,#667eea,#764ba2);
            color: white;
            font-weight: bold;
            border-color: #667eea;
        }
        .hotel-day.selected-checkout {
            background: linear-gradient(135deg,#11998e,#38ef7d);
            color: white;
            font-weight: bold;
            border-color: #11998e;
        }
        .hotel-day.in-range {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .hotel-day.empty {
            background: transparent;
            cursor: default;
        }
        #hotel-open-calendar-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102,126,234,0.6);
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var productId = <?php echo intval($product_id); ?>;
            var basePrice = <?php echo $base_price; ?>;
            var dateConfigs = <?php echo $date_configs_json; ?>;
            var disabledDates = [];

            var currentYear, currentMonth;
            var selectedCheckIn = null;
            var selectedCheckOut = null;
            var selectingMode = 'checkin'; // 'checkin' or 'checkout'

            // نام ماه‌های فارسی
            var persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

            // تبدیل تاریخ میلادی به شمسی
            function gregorianToJalali(gy, gm, gd) {
                var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
                var jy = (gy <= 1600) ? 0 : 979;
                gy -= (gy <= 1600) ? 621 : 1600;
                var gy2 = (gm > 2) ? (gy + 1) : gy;
                var days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * Math.floor(days / 12053);
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                var jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
                var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
                return [jy, jm, jd];
            }

            // تبدیل تاریخ شمسی به میلادی
            function jalaliToGregorian(jy, jm, jd) {
                jy = parseInt(jy);
                jm = parseInt(jm);
                jd = parseInt(jd);
                jy += 1595;
                var days = 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186) - 355668;
                var gy = 400 * Math.floor(days / 146097);
                days %= 146097;
                if (days > 36524) {
                    gy += 100 * Math.floor(--days / 36524);
                    days %= 36524;
                    if (days >= 365) days++;
                }
                gy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) {
                    gy += Math.floor((days - 1) / 365);
                    days = (days - 1) % 365;
                }
                var gd = days + 1;
                var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                var gm = 0;
                for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) {
                    gd -= sal_a[gm];
                }
                return [gy, gm, gd];
            }

            function pad(n) { return n < 10 ? '0' + n : n; }

            function formatJalaliDate(y, m, d) {
                return y + '/' + pad(m) + '/' + pad(d);
            }

            function getTodayJalali() {
                var now = new Date();
                return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            }

            function getDaysInJalaliMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                // بررسی سال کبیسه
                var breaks = [1, 5, 9, 13, 17, 22, 26, 30];
                var leap = 0;
                for (var i = 0; i < breaks.length; i++) {
                    if ((year % 33) === breaks[i]) {
                        leap = 1;
                        break;
                    }
                }
                return leap ? 30 : 29;
            }

            function getFirstDayOfJalaliMonth(year, month) {
                var greg = jalaliToGregorian(year, month, 1);
                var date = new Date(greg[0], greg[1] - 1, greg[2]);
                var day = date.getDay();
                // تبدیل به فرمت شنبه = 0
                return (day + 1) % 7;
            }

            function isDateDisabled(dateStr) {
                return disabledDates.indexOf(dateStr) !== -1;
            }

            function isDateInPast(year, month, day) {
                var today = getTodayJalali();
                var todayStr = formatJalaliDate(today[0], today[1], today[2]);
                var checkStr = formatJalaliDate(year, month, day);
                return checkStr < todayStr;
            }

            // بارگذاری تاریخ‌های غیرفعال
            function loadDisabledDates() {
                $.post(woocommerce_params.ajax_url, {
                    action: 'hotel_get_disabled_dates',
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        disabledDates = response.data.disabled_dates;
                    }
                });
            }

            // رندر تقویم
            function renderCalendar() {
                var daysInMonth = getDaysInJalaliMonth(currentYear, currentMonth);
                var firstDay = getFirstDayOfJalaliMonth(currentYear, currentMonth);

                $('#hotel-current-month').text(persianMonths[currentMonth - 1] + ' ' + currentYear);

                var html = '';

                // روزهای خالی قبل از شروع ماه
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="hotel-day empty"></div>';
                }

                // روزهای ماه
                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = formatJalaliDate(currentYear, currentMonth, day);
                    var classes = ['hotel-day'];
                    var disabled = false;

                    if (isDateInPast(currentYear, currentMonth, day)) {
                        classes.push('past');
                        disabled = true;
                    } else if (isDateDisabled(dateStr)) {
                        classes.push('disabled');
                        disabled = true;
                    }

                    if (selectedCheckIn && dateStr === selectedCheckIn) {
                        classes.push('selected-checkin');
                    }

                    if (selectedCheckOut && dateStr === selectedCheckOut) {
                        classes.push('selected-checkout');
                    }

                    if (selectedCheckIn && selectedCheckOut && dateStr > selectedCheckIn && dateStr < selectedCheckOut) {
                        classes.push('in-range');
                    }

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '" data-disabled="' + disabled + '">' + day + '</div>';
                }

                $('#hotel-calendar-days').html(html);
            }

            // محاسبه قیمت
            function calculatePrice() {
                if (!selectedCheckIn || !selectedCheckOut) return;

                $.post(woocommerce_params.ajax_url, {
                    action: 'hotel_check_availability',
                    product_id: productId,
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    nonce: '<?php echo wp_create_nonce("hotel_availability"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;

                        $('#hotel-nights-count').text(data.nights);
                        $('#hotel-total-price').text(data.total.toLocaleString('fa-IR'));

                        var breakdown = '';
                        data.breakdown.forEach(function(item) {
                            breakdown += '<div>' + item.date + ': ' + item.price.toLocaleString('fa-IR') + ' تومان</div>';
                        });
                        $('#hotel-price-breakdown').html(breakdown);

                        $('#hotel-price-info').show();

                        if (data.available) {
                            $('#hotel-availability-warning').hide();
                            $('#hotel-confirm-dates').show();
                        } else {
                            $('#hotel-availability-warning').show().text('⚠️ متأسفانه در تاریخ ' + (data.blocked_date || '') + ' ظرفیت کافی ندارد.');
                            $('#hotel-confirm-dates').hide();
                        }
                    }
                });
            }

            // کلیک روی روز
            $(document).on('click', '.hotel-day', function() {
                if ($(this).data('disabled') || $(this).hasClass('empty')) return;

                var dateStr = $(this).data('date');

                if (selectingMode === 'checkin') {
                    selectedCheckIn = dateStr;
                    selectedCheckOut = null;
                    selectingMode = 'checkout';
                    $('#hotel-calendar-guide').html('<strong>🎯 حالا تاریخ خروج را انتخاب کنید</strong>');
                    $('#hotel-price-info').hide();
                    $('#hotel-confirm-dates').hide();
                    renderCalendar();
                } else {
                    if (dateStr <= selectedCheckIn) {
                        alert('تاریخ خروج باید بعد از تاریخ ورود باشد');
                        return;
                    }
                    selectedCheckOut = dateStr;
                    renderCalendar();
                    calculatePrice();
                }
            });

            // باز کردن تقویم
            $('#hotel-open-calendar-btn').on('click', function() {
                var today = getTodayJalali();
                currentYear = today[0];
                currentMonth = today[1];
                selectedCheckIn = null;
                selectedCheckOut = null;
                selectingMode = 'checkin';
                $('#hotel-calendar-guide').html('<strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>');
                $('#hotel-price-info').hide();
                $('#hotel-availability-warning').hide();
                $('#hotel-confirm-dates').hide();
                renderCalendar();
                $('#hotel-calendar-modal').addClass('active');
            });

            // بستن تقویم
            $('#hotel-close-calendar, #hotel-calendar-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#hotel-calendar-modal').removeClass('active');
                }
            });

            // ماه قبل
            $('#hotel-prev-month').on('click', function() {
                currentMonth--;
                if (currentMonth < 1) {
                    currentMonth = 12;
                    currentYear--;
                }
                renderCalendar();
            });

            // ماه بعد
            $('#hotel-next-month').on('click', function() {
                currentMonth++;
                if (currentMonth > 12) {
                    currentMonth = 1;
                    currentYear++;
                }
                renderCalendar();
            });

            // تایید انتخاب
            $('#hotel-confirm-dates').on('click', function() {
                $('#hotel-checkin-input').val(selectedCheckIn);
                $('#hotel-checkout-input').val(selectedCheckOut);

                $('#selected-checkin').text(selectedCheckIn);
                $('#selected-checkout').text(selectedCheckOut);

                var nights = $('#hotel-nights-count').text();
                $('#selected-nights').text(nights);

                $('#hotel-selection-display').show();
                $('#hotel-calendar-modal').removeClass('active');
            });

            // اعتبارسنجی فرم
            $('form.cart').on('submit', function(e) {
                if (!selectedCheckIn || !selectedCheckOut) {
                    e.preventDefault();
                    alert('لطفاً تاریخ ورود و خروج را انتخاب کنید');
                    return false;
                }
            });

            // بارگذاری اولیه
            loadDisabledDates();
        });
        </script>
        <?php
    }

    public function ajax_check_availability() {
        check_ajax_referer('hotel_availability', 'nonce');

        $product_id = intval($_POST['product_id']);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);

        $availability = $this->check_availability_for_dates($product_id, $check_in, $check_out);
        $pricing = $this->calculate_total_price($product_id, $check_in, $check_out);

        wp_send_json_success(array_merge($availability, $pricing));
    }

    public function validate_reservation($passed, $product_id, $quantity) {
        $settings = $this->get_product_settings($product_id);

        if ($settings['product_type'] !== 'room') {
            wc_add_notice('این محصول یک هتل است و قابل رزرو نیست', 'error');
            return false;
        }

        if (empty($_POST['hotel_checkin']) || empty($_POST['hotel_checkout'])) {
            wc_add_notice('لطفاً تاریخ ورود و خروج را انتخاب کنید', 'error');
            return false;
        }

        $check_in = sanitize_text_field($_POST['hotel_checkin']);
        $check_out = sanitize_text_field($_POST['hotel_checkout']);

        if ($check_out <= $check_in) {
            wc_add_notice('تاریخ خروج باید بعد از تاریخ ورود باشد', 'error');
            return false;
        }

        $availability = $this->check_availability_for_dates($product_id, $check_in, $check_out, $quantity);

        if (!$availability['available']) {
            wc_add_notice('متأسفانه در تاریخ‌های انتخابی ظرفیت کافی وجود ندارد', 'error');
            return false;
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (!empty($_POST['hotel_checkin'])) {
            $cart_item_data['hotel_checkin'] = sanitize_text_field($_POST['hotel_checkin']);
        }

        if (!empty($_POST['hotel_checkout'])) {
            $cart_item_data['hotel_checkout'] = sanitize_text_field($_POST['hotel_checkout']);
        }

        $settings = $this->get_product_settings($product_id);
        if (!empty($settings['custom_fields'])) {
            $custom_data = [];
            foreach ($settings['custom_fields'] as $index => $field) {
                $field_name = 'hotel_field_' . $index;
                if (isset($_POST[$field_name])) {
                    $custom_data[$field['label']] = sanitize_text_field($_POST[$field_name]);
                }
            }
            if (!empty($custom_data)) {
                $cart_item_data['hotel_custom_fields'] = $custom_data;
            }
        }

        return $cart_item_data;
    }

    public function update_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['hotel_checkin']) && isset($cart_item['hotel_checkout'])) {
                $pricing = $this->calculate_total_price(
                    $cart_item['product_id'],
                    $cart_item['hotel_checkin'],
                    $cart_item['hotel_checkout']
                );
                $cart_item['data']->set_price($pricing['total']);
            }
        }
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (!empty($cart_item['hotel_checkin'])) {
            $item_data[] = ['name' => 'تاریخ ورود', 'value' => $cart_item['hotel_checkin']];
        }

        if (!empty($cart_item['hotel_checkout'])) {
            $item_data[] = ['name' => 'تاریخ خروج', 'value' => $cart_item['hotel_checkout']];
        }

        if (isset($cart_item['hotel_checkin']) && isset($cart_item['hotel_checkout'])) {
            $pricing = $this->calculate_total_price(
                $cart_item['product_id'],
                $cart_item['hotel_checkin'],
                $cart_item['hotel_checkout']
            );
            $item_data[] = ['name' => 'تعداد شب', 'value' => $pricing['nights'] . ' شب'];
        }

        if (!empty($cart_item['hotel_custom_fields'])) {
            foreach ($cart_item['hotel_custom_fields'] as $label => $value) {
                $item_data[] = ['name' => $label, 'value' => $value];
            }
        }

        return $item_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['hotel_checkin'])) {
            $item->add_meta_data('_hotel_check_in', $values['hotel_checkin'], false);
            $item->add_meta_data('تاریخ ورود', $values['hotel_checkin'], true);
        }

        if (!empty($values['hotel_checkout'])) {
            $item->add_meta_data('_hotel_check_out', $values['hotel_checkout'], false);
            $item->add_meta_data('تاریخ خروج', $values['hotel_checkout'], true);
        }

        if (!empty($values['hotel_custom_fields'])) {
            foreach ($values['hotel_custom_fields'] as $label => $value) {
                $item->add_meta_data($label, $value, true);
            }
        }
    }
}

function wc_hotel_reserve_init() {
    if (class_exists('WooCommerce')) {
        WC_Hotel_Reserve::get_instance();
    }
}
add_action('plugins_loaded', 'wc_hotel_reserve_init', 20);
