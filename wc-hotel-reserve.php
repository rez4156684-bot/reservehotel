<?php
/**
 * Plugin Name: WooCommerce Hotel Reservation System - Professional Edition
 * Description: سیستم پیشرفته رزرو هتل برای محصولات ساده ووکامرس
 * Version: 9.0.0
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
        // تب محصول در ووکامرس
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_footer', [$this, 'admin_calendar_modal']);

        // نمایش در صفحه محصول
        add_action('woocommerce_before_add_to_cart_button', [$this, 'display_hotel_rooms']);
        add_action('wp_footer', [$this, 'frontend_scripts']);

        // AJAX
        add_action('wp_ajax_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);
        add_action('wp_ajax_nopriv_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);

        // سبد خرید
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price']);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
    }

    public function add_product_data_tab($tabs) {
        $tabs['hotel_rooms'] = [
            'label' => '🏨 اتاق‌های هتل',
            'target' => 'hotel_rooms_data',
            'class' => ['show_if_simple']
        ];
        return $tabs;
    }

    public function add_product_data_panel() {
        global $post;
        $rooms = get_post_meta($post->ID, '_hotel_rooms', true);
        $rooms = is_array($rooms) ? $rooms : [];
        ?>
        <div id="hotel_rooms_data" class="panel woocommerce_options_panel">
            <div class="options_group" style="padding:15px;">

                <div style="background:#e3f2fd;padding:15px;border-radius:8px;margin-bottom:20px;border-right:4px solid #2196f3;">
                    <h4 style="margin:0 0 10px 0;">📖 راهنما</h4>
                    <p style="margin:0;color:#666;">این محصول یک <strong>هتل</strong> است. در این بخش اتاق‌های مختلف هتل را تعریف کنید. هر اتاق دارای نام، ظرفیت، قیمت پایه و بازه‌های قیمت‌گذاری مخصوص خودش است.</p>
                </div>

                <button type="button" class="button button-primary button-large" id="add-new-room-btn">
                    ➕ افزودن اتاق جدید
                </button>

                <div id="hotel-rooms-container" style="margin-top:20px;">
                    <?php if (!empty($rooms)): ?>
                        <?php foreach ($rooms as $index => $room): ?>
                            <?php $this->render_room_admin($index, $room); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div id="no-rooms-msg" style="background:#fff3cd;padding:20px;border-radius:8px;text-align:center;">
                            <p style="margin:0;">⚠️ هنوز اتاقی اضافه نشده است. روی دکمه بالا کلیک کنید.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <style>
        .room-admin-item {
            background: #fff;
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 0;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .room-admin-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-admin-header h3 { margin: 0; font-size: 16px; }
        .room-admin-body { padding: 20px; }
        .room-admin-fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        .room-admin-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .room-admin-field input,
        .room-admin-field textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .room-admin-field textarea { min-height: 60px; resize: vertical; }
        .date-ranges-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        .date-range-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 12px;
            margin-bottom: 10px;
            display: grid;
            grid-template-columns: 2fr 2fr 1.5fr auto auto;
            gap: 10px;
            align-items: center;
        }
        .date-range-item input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn-remove-room {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-add-date-range {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-remove-date-range {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-select-date {
            background: #2196f3;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var roomCounter = <?php echo count($rooms); ?>;

            // افزودن اتاق جدید
            $('#add-new-room-btn').on('click', function() {
                $('#no-rooms-msg').remove();
                var html = generateRoomHTML(roomCounter, {
                    name: '',
                    capacity: 1,
                    base_price: '',
                    description: '',
                    date_ranges: []
                });
                $('#hotel-rooms-container').append(html);
                roomCounter++;
            });

            function generateRoomHTML(index, data) {
                var html = '<div class="room-admin-item" data-room-index="' + index + '">' +
                    '<div class="room-admin-header">' +
                        '<h3>🚪 اتاق #' + (index + 1) + '</h3>' +
                        '<button type="button" class="btn-remove-room">🗑️ حذف</button>' +
                    '</div>' +
                    '<div class="room-admin-body">' +
                        '<div class="room-admin-fields">' +
                            '<div class="room-admin-field">' +
                                '<label>نام اتاق *</label>' +
                                '<input type="text" name="hotel_rooms[' + index + '][name]" value="' + (data.name || '') + '" placeholder="مثال: اتاق دو تخته VIP" required>' +
                            '</div>' +
                            '<div class="room-admin-field">' +
                                '<label>ظرفیت (تعداد اتاق) *</label>' +
                                '<input type="number" name="hotel_rooms[' + index + '][capacity]" value="' + (data.capacity || 1) + '" min="1" required>' +
                            '</div>' +
                            '<div class="room-admin-field">' +
                                '<label>قیمت پایه (تومان/شب) *</label>' +
                                '<input type="number" name="hotel_rooms[' + index + '][base_price]" value="' + (data.base_price || '') + '" step="1000" required>' +
                            '</div>' +
                            '<div class="room-admin-field">' +
                                '<label>توضیحات اتاق</label>' +
                                '<textarea name="hotel_rooms[' + index + '][description]" placeholder="امکانات، توضیحات...">' + (data.description || '') + '</textarea>' +
                            '</div>' +
                        '</div>' +
                        '<div class="date-ranges-section">' +
                            '<h4 style="margin:0 0 10px 0;">📅 بازه‌های قیمت‌گذاری</h4>' +
                            '<p style="margin:0 0 10px 0;color:#666;font-size:13px;">می‌توانید برای بازه‌های زمانی مختلف (تعطیلات، آخر هفته) قیمت متفاوت تعیین کنید.</p>' +
                            '<button type="button" class="btn-add-date-range" data-room-index="' + index + '">➕ افزودن بازه قیمت</button>' +
                            '<div class="date-ranges-list" data-room-index="' + index + '"></div>' +
                            '<input type="hidden" name="hotel_rooms[' + index + '][date_ranges_json]" class="date-ranges-json">' +
                        '</div>' +
                    '</div>' +
                '</div>';
                return html;
            }

            // حذف اتاق
            $(document).on('click', '.btn-remove-room', function() {
                if (confirm('آیا مطمئن هستید؟')) {
                    $(this).closest('.room-admin-item').remove();
                    if ($('.room-admin-item').length === 0) {
                        $('#hotel-rooms-container').html('<div id="no-rooms-msg" style="background:#fff3cd;padding:20px;border-radius:8px;text-align:center;"><p style="margin:0;">⚠️ هنوز اتاقی اضافه نشده است.</p></div>');
                    }
                }
            });

            // افزودن بازه قیمت
            $(document).on('click', '.btn-add-date-range', function() {
                var roomIndex = $(this).data('room-index');
                var container = $('.date-ranges-list[data-room-index="' + roomIndex + '"]');
                var rangeIndex = container.find('.date-range-item').length;

                var html = '<div class="date-range-item">' +
                    '<input type="text" class="range-from" placeholder="از تاریخ: 1403/09/15" readonly>' +
                    '<input type="text" class="range-to" placeholder="تا تاریخ: 1403/09/20" readonly>' +
                    '<input type="number" class="range-price" placeholder="قیمت/شب" step="1000">' +
                    '<button type="button" class="btn-select-date" data-target="from">📅 از</button>' +
                    '<button type="button" class="btn-select-date" data-target="to">📅 تا</button>' +
                    '<label style="white-space:nowrap;"><input type="checkbox" class="range-disabled"> غیرفعال</label>' +
                    '<button type="button" class="btn-remove-date-range">حذف</button>' +
                '</div>';

                container.append(html);
            });

            // حذف بازه قیمت
            $(document).on('click', '.btn-remove-date-range', function() {
                $(this).closest('.date-range-item').remove();
            });

            // باز کردن تقویم برای انتخاب تاریخ
            var currentDateField = null;

            $(document).on('click', '.btn-select-date', function() {
                var target = $(this).data('target'); // 'from' or 'to'
                var rangeItem = $(this).closest('.date-range-item');

                if (target === 'from') {
                    currentDateField = rangeItem.find('.range-from');
                } else {
                    currentDateField = rangeItem.find('.range-to');
                }

                // باز کردن مودال تقویم
                openAdminCalendar();
            });

            function openAdminCalendar() {
                // نمایش مودال تقویم (در انتهای کد تعریف می‌شود)
                $('#admin-calendar-modal').fadeIn();
                renderAdminCalendar();
            }

            // ذخیره بازه‌ها قبل از submit
            $('form#post').on('submit', function() {
                $('.room-admin-item').each(function() {
                    var roomIndex = $(this).data('room-index');
                    var ranges = [];

                    $(this).find('.date-range-item').each(function() {
                        var from = $(this).find('.range-from').val();
                        var to = $(this).find('.range-to').val();
                        var price = $(this).find('.range-price').val();
                        var disabled = $(this).find('.range-disabled').is(':checked');

                        if (from && to) {
                            ranges.push({
                                from: from,
                                to: to,
                                price: price || 0,
                                disabled: disabled
                            });
                        }
                    });

                    $(this).find('.date-ranges-json').val(JSON.stringify(ranges));
                });
            });

            // تقویم ادمین (در پایین تعریف می‌شود)
            window.adminCalendarSelectDate = function(dateStr) {
                if (currentDateField) {
                    currentDateField.val(dateStr);
                }
                $('#admin-calendar-modal').fadeOut();
            };

            window.renderAdminCalendar = function() {
                var today = new Date();
                var jalali = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
                var year = jalali[0];
                var month = jalali[1];

                $('#admin-cal-year').text(year);
                $('#admin-cal-month').text(getPersianMonth(month));

                var html = '';
                var daysInMonth = getDaysInJalaliMonth(year, month);
                var firstDay = getFirstDayOfJalaliMonth(year, month);

                // روزهای خالی
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="admin-cal-day empty"></div>';
                }

                // روزهای ماه
                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = year + '/' + pad(month) + '/' + pad(day);
                    html += '<div class="admin-cal-day" data-date="' + dateStr + '">' + day + '</div>';
                }

                $('#admin-calendar-days').html(html);
            };

            $(document).on('click', '.admin-cal-day:not(.empty)', function() {
                var dateStr = $(this).data('date');
                adminCalendarSelectDate(dateStr);
            });

            $('#admin-cal-close').on('click', function() {
                $('#admin-calendar-modal').fadeOut();
            });

            // توابع کمکی
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

            function getDaysInJalaliMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                return 29; // ساده‌سازی شده
            }

            function getFirstDayOfJalaliMonth(year, month) {
                // ساده‌سازی شده
                return 0;
            }

            function getPersianMonth(m) {
                var months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
                return months[m - 1];
            }

            function pad(n) { return n < 10 ? '0' + n : n; }
        });
        </script>
        <?php
    }

    private function render_room_admin($index, $room) {
        ?>
        <div class="room-admin-item" data-room-index="<?php echo $index; ?>">
            <div class="room-admin-header">
                <h3>🚪 اتاق: <?php echo esc_html($room['name'] ?? 'اتاق #' . ($index + 1)); ?></h3>
                <button type="button" class="btn-remove-room">🗑️ حذف</button>
            </div>
            <div class="room-admin-body">
                <div class="room-admin-fields">
                    <div class="room-admin-field">
                        <label>نام اتاق *</label>
                        <input type="text" name="hotel_rooms[<?php echo $index; ?>][name]" value="<?php echo esc_attr($room['name'] ?? ''); ?>" placeholder="مثال: اتاق دو تخته VIP" required>
                    </div>
                    <div class="room-admin-field">
                        <label>ظرفیت (تعداد اتاق) *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][capacity]" value="<?php echo esc_attr($room['capacity'] ?? 1); ?>" min="1" required>
                    </div>
                    <div class="room-admin-field">
                        <label>قیمت پایه (تومان/شب) *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][base_price]" value="<?php echo esc_attr($room['base_price'] ?? ''); ?>" step="1000" required>
                    </div>
                    <div class="room-admin-field">
                        <label>توضیحات اتاق</label>
                        <textarea name="hotel_rooms[<?php echo $index; ?>][description]" placeholder="امکانات، توضیحات..."><?php echo esc_textarea($room['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="date-ranges-section">
                    <h4 style="margin:0 0 10px 0;">📅 بازه‌های قیمت‌گذاری</h4>
                    <p style="margin:0 0 10px 0;color:#666;font-size:13px;">می‌توانید برای بازه‌های زمانی مختلف قیمت متفاوت تعیین کنید.</p>
                    <button type="button" class="btn-add-date-range" data-room-index="<?php echo $index; ?>">➕ افزودن بازه قیمت</button>
                    <div class="date-ranges-list" data-room-index="<?php echo $index; ?>">
                        <?php if (!empty($room['date_ranges'])): ?>
                            <?php foreach ($room['date_ranges'] as $range): ?>
                                <div class="date-range-item">
                                    <input type="text" class="range-from" value="<?php echo esc_attr($range['from']); ?>" placeholder="از تاریخ" readonly>
                                    <input type="text" class="range-to" value="<?php echo esc_attr($range['to']); ?>" placeholder="تا تاریخ" readonly>
                                    <input type="number" class="range-price" value="<?php echo esc_attr($range['price'] ?? ''); ?>" placeholder="قیمت/شب" step="1000">
                                    <button type="button" class="btn-select-date" data-target="from">📅 از</button>
                                    <button type="button" class="btn-select-date" data-target="to">📅 تا</button>
                                    <label style="white-space:nowrap;"><input type="checkbox" class="range-disabled" <?php checked($range['disabled'] ?? false, true); ?>> غیرفعال</label>
                                    <button type="button" class="btn-remove-date-range">حذف</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="hotel_rooms[<?php echo $index; ?>][date_ranges_json]" class="date-ranges-json">
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_calendar_modal() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'product') {
            ?>
            <div id="admin-calendar-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999999;align-items:center;justify-content:center;">
                <div style="background:white;border-radius:12px;width:400px;max-width:90%;">
                    <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:15px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;">📅 انتخاب تاریخ</h3>
                        <button type="button" id="admin-cal-close" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:5px 10px;border-radius:5px;cursor:pointer;">✕</button>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                            <button type="button" style="background:#667eea;color:white;border:none;padding:8px 12px;border-radius:5px;cursor:pointer;">❮</button>
                            <div><span id="admin-cal-month"></span> <span id="admin-cal-year"></span></div>
                            <button type="button" style="background:#667eea;color:white;border:none;padding:8px 12px;border-radius:5px;cursor:pointer;">❯</button>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;">
                            <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                        </div>
                        <div id="admin-calendar-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;"></div>
                    </div>
                </div>
            </div>
            <style>
            #admin-calendar-modal { display: none; }
            #admin-calendar-modal.show { display: flex !important; }
            .admin-cal-day {
                padding: 10px;
                text-align: center;
                border-radius: 5px;
                cursor: pointer;
                background: #f5f5f5;
            }
            .admin-cal-day:hover:not(.empty) {
                background: #e3f2fd;
            }
            .admin-cal-day.empty {
                background: transparent;
                cursor: default;
            }
            </style>
            <?php
        }
    }

    public function save_product_meta($post_id) {
        if (!isset($_POST['hotel_rooms'])) {
            return;
        }

        $rooms = [];
        foreach ($_POST['hotel_rooms'] as $index => $room_data) {
            if (!empty($room_data['name']) && !empty($room_data['base_price'])) {
                $date_ranges = [];
                if (!empty($room_data['date_ranges_json'])) {
                    $decoded = json_decode(stripslashes($room_data['date_ranges_json']), true);
                    if (is_array($decoded)) {
                        $date_ranges = $decoded;
                    }
                }

                $rooms[] = [
                    'id' => 'room_' . $post_id . '_' . $index . '_' . time(),
                    'name' => sanitize_text_field($room_data['name']),
                    'capacity' => intval($room_data['capacity'] ?? 1),
                    'base_price' => floatval($room_data['base_price']),
                    'description' => sanitize_textarea_field($room_data['description'] ?? ''),
                    'date_ranges' => $date_ranges
                ];
            }
        }

        update_post_meta($post_id, '_hotel_rooms', $rooms);
    }

    public function display_hotel_rooms() {
        global $product;
        if (!$product || !$product->is_type('simple')) return;

        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);
        if (empty($rooms) || !is_array($rooms)) return;

        ?>
        <div class="hotel-rooms-section" style="margin:30px 0;padding:0;">
            <h3 style="font-size:22px;margin-bottom:20px;color:#333;">🏨 اتاق‌های موجود</h3>

            <div class="rooms-grid" style="display:grid;gap:15px;">
                <?php foreach ($rooms as $room): ?>
                    <?php
                    $min_price = $room['base_price'];
                    if (!empty($room['date_ranges'])) {
                        foreach ($room['date_ranges'] as $range) {
                            if (!empty($range['price']) && $range['price'] < $min_price) {
                                $min_price = $range['price'];
                            }
                        }
                    }
                    ?>
                    <div class="room-card" style="background:#f8f9fa;border:2px solid #e0e0e0;border-radius:10px;padding:15px;display:flex;justify-content:space-between;align-items:center;transition:all 0.3s;" data-room='<?php echo esc_attr(json_encode($room)); ?>'>
                        <div style="flex:1;">
                            <h4 style="margin:0 0 8px 0;font-size:18px;color:#667eea;">🚪 <?php echo esc_html($room['name']); ?></h4>
                            <?php if (!empty($room['description'])): ?>
                                <p style="margin:0 0 8px 0;color:#666;font-size:14px;"><?php echo esc_html($room['description']); ?></p>
                            <?php endif; ?>
                            <div style="color:#999;font-size:13px;">
                                ظرفیت: <?php echo $room['capacity']; ?> اتاق
                            </div>
                        </div>
                        <div style="text-align:left;padding:0 15px;">
                            <div style="color:#999;font-size:12px;margin-bottom:5px;">قیمت از:</div>
                            <div style="font-size:20px;font-weight:bold;color:#28a745;margin-bottom:10px;">
                                <?php echo number_format($min_price); ?> <span style="font-size:12px;">تومان/شب</span>
                            </div>
                            <button type="button" class="room-reserve-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:bold;">
                                رزرو
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .room-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .room-reserve-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }
        </style>
        <?php
    }

    public function frontend_scripts() {
        if (!is_product()) return;

        global $product;
        if (!$product || !$product->is_type('simple')) return;

        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);
        if (empty($rooms)) return;

        ?>
        <!-- Modal تقویم رزرو -->
        <div id="booking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:white;border-radius:15px;max-width:600px;width:95%;max-height:90vh;overflow-y:auto;">
                <div style="padding:20px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:15px 15px 0 0;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;" id="modal-title">📅 رزرو اتاق</h3>
                    <button type="button" id="close-modal-btn" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;font-size:18px;">✕</button>
                </div>
                <div style="padding:25px;">
                    <div id="booking-guide" style="background:#e3f2fd;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;">
                        <strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <button type="button" id="prev-month-btn" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;">❮</button>
                        <div id="current-month-display" style="font-weight:bold;font-size:18px;"></div>
                        <button type="button" id="next-month-btn" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;">❯</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;">
                        <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                    </div>
                    <div id="calendar-days-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;"></div>
                    <div id="booking-summary" style="display:none;background:#f0f8ff;padding:20px;border-radius:10px;margin-top:20px;">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin-bottom:15px;">
                            <div><small style="color:#666;">تاریخ ورود</small><div style="font-weight:bold;color:#667eea;" id="sum-checkin">-</div></div>
                            <div><small style="color:#666;">تاریخ خروج</small><div style="font-weight:bold;color:#11998e;" id="sum-checkout">-</div></div>
                        </div>
                        <div><strong>✓ تعداد شب‌ها:</strong> <span id="sum-nights">0</span> شب</div>
                        <div style="margin-top:10px;"><strong>💰 قیمت کل:</strong> <span id="sum-price">0</span> تومان</div>
                        <div id="price-detail" style="font-size:13px;color:#666;margin-top:10px;max-height:100px;overflow-y:auto;"></div>
                    </div>
                    <button type="button" id="add-to-cart-final" style="display:none;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:15px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;width:100%;margin-top:20px;">
                        ✓ افزودن به سبد خرید
                    </button>
                </div>
            </div>
        </div>

        <style>
        #booking-modal { display: none; }
        #booking-modal.active { display: flex !important; }
        .cal-day {
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border: 2px solid transparent;
            transition: all 0.3s;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cal-day:hover:not(.disabled):not(.past):not(.empty) {
            background: #e3f2fd;
            transform: translateY(-2px);
        }
        .cal-day.disabled, .cal-day.past { background: #f5f5f5; color: #ccc; cursor: not-allowed; }
        .cal-day.selected-in { background: linear-gradient(135deg,#667eea,#764ba2); color: white; font-weight: bold; }
        .cal-day.selected-out { background: linear-gradient(135deg,#11998e,#38ef7d); color: white; font-weight: bold; }
        .cal-day.in-range { background: #fff3cd; border-color: #ffc107; }
        .cal-day.empty { background: transparent; cursor: default; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var currentRoom = null;
            var currentYear, currentMonth;
            var selectedCheckIn = null;
            var selectedCheckOut = null;
            var selectingMode = 'checkin';

            var persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

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

            function jalaliToGregorian(jy, jm, jd) {
                jy = parseInt(jy); jm = parseInt(jm); jd = parseInt(jd);
                jy += 1595;
                var days = 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186) - 355668;
                var gy = 400 * Math.floor(days / 146097);
                days %= 146097;
                if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
                gy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                var gd = days + 1;
                var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                var gm = 0;
                for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) { gd -= sal_a[gm]; }
                return [gy, gm, gd];
            }

            function pad(n) { return n < 10 ? '0' + n : n; }
            function formatDate(y, m, d) { return y + '/' + pad(m) + '/' + pad(d); }

            function getTodayJalali() {
                var now = new Date();
                return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            }

            function getDaysInMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                return 29;
            }

            function getFirstDayOfMonth(year, month) {
                var greg = jalaliToGregorian(year, month, 1);
                var date = new Date(greg[0], greg[1] - 1, greg[2]);
                return (date.getDay() + 1) % 7;
            }

            function renderCalendar() {
                var daysInMonth = getDaysInMonth(currentYear, currentMonth);
                var firstDay = getFirstDayOfMonth(currentYear, currentMonth);
                var today = getTodayJalali();
                var todayStr = formatDate(today[0], today[1], today[2]);

                $('#current-month-display').text(persianMonths[currentMonth - 1] + ' ' + currentYear);

                var html = '';
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="cal-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = formatDate(currentYear, currentMonth, day);
                    var classes = ['cal-day'];
                    var disabled = false;

                    if (dateStr < todayStr) {
                        classes.push('past');
                        disabled = true;
                    }

                    if (selectedCheckIn && dateStr === selectedCheckIn) classes.push('selected-in');
                    if (selectedCheckOut && dateStr === selectedCheckOut) classes.push('selected-out');
                    if (selectedCheckIn && selectedCheckOut && dateStr > selectedCheckIn && dateStr < selectedCheckOut) {
                        classes.push('in-range');
                    }

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '" data-disabled="' + disabled + '">' + day + '</div>';
                }

                $('#calendar-days-grid').html(html);
            }

            function calculatePrice() {
                if (!selectedCheckIn || !selectedCheckOut || !currentRoom) return;

                $.post(woocommerce_params.ajax_url, {
                    action: 'hotel_check_room_availability',
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    nonce: '<?php echo wp_create_nonce("hotel_booking"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#sum-nights').text(data.nights);
                        $('#sum-price').text(data.total.toLocaleString('fa-IR'));

                        var detail = '';
                        data.breakdown.forEach(function(item) {
                            detail += '<div>' + item.date + ': ' + item.price.toLocaleString('fa-IR') + ' تومان</div>';
                        });
                        $('#price-detail').html(detail);

                        $('#booking-summary').show();
                        $('#add-to-cart-final').show();
                    }
                });
            }

            $('.room-reserve-btn').on('click', function() {
                currentRoom = JSON.parse($(this).closest('.room-card').attr('data-room'));
                $('#modal-title').text('📅 رزرو ' + currentRoom.name);

                var today = getTodayJalali();
                currentYear = today[0];
                currentMonth = today[1];
                selectedCheckIn = null;
                selectedCheckOut = null;
                selectingMode = 'checkin';

                $('#booking-guide').html('<strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>');
                $('#booking-summary').hide();
                $('#add-to-cart-final').hide();

                renderCalendar();
                $('#booking-modal').addClass('active');
            });

            $('#close-modal-btn, #booking-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#booking-modal').removeClass('active');
                }
            });

            $(document).on('click', '.cal-day', function() {
                if ($(this).data('disabled') || $(this).hasClass('empty')) return;

                var dateStr = $(this).data('date');

                if (selectingMode === 'checkin') {
                    selectedCheckIn = dateStr;
                    selectedCheckOut = null;
                    selectingMode = 'checkout';
                    $('#sum-checkin').text(dateStr);
                    $('#sum-checkout').text('-');
                    $('#booking-guide').html('<strong>🎯 حالا تاریخ خروج را انتخاب کنید</strong>');
                    $('#booking-summary').hide();
                    $('#add-to-cart-final').hide();
                    renderCalendar();
                } else {
                    if (dateStr <= selectedCheckIn) {
                        alert('تاریخ خروج باید بعد از تاریخ ورود باشد');
                        return;
                    }
                    selectedCheckOut = dateStr;
                    $('#sum-checkout').text(dateStr);
                    renderCalendar();
                    calculatePrice();
                }
            });

            $('#prev-month-btn').on('click', function() {
                currentMonth--;
                if (currentMonth < 1) { currentMonth = 12; currentYear--; }
                renderCalendar();
            });

            $('#next-month-btn').on('click', function() {
                currentMonth++;
                if (currentMonth > 12) { currentMonth = 1; currentYear++; }
                renderCalendar();
            });

            $('#add-to-cart-final').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('در حال افزودن...');

                $.post(woocommerce_params.ajax_url, {
                    action: 'hotel_add_room_to_cart',
                    product_id: <?php echo $product->get_id(); ?>,
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    nonce: '<?php echo wp_create_nonce("hotel_add_cart"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('✓ اتاق با موفقیت به سبد خرید اضافه شد!');
                        $('#booking-modal').removeClass('active');
                        $(document.body).trigger('wc_fragment_refresh');
                    } else {
                        alert('خطا: ' + (response.data ? response.data.message : 'خطای نامشخص'));
                    }
                    btn.prop('disabled', false).text('✓ افزودن به سبد خرید');
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_check_availability() {
        check_ajax_referer('hotel_booking', 'nonce');

        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);

        $pricing = $this->calculate_room_price($room_data, $check_in, $check_out);
        $availability = ['available' => true];

        wp_send_json_success(array_merge($availability, $pricing));
    }

    private function calculate_room_price($room, $check_in, $check_out) {
        $check_in_parts = explode('/', $check_in);
        $check_out_parts = explode('/', $check_out);

        $check_in_gregorian = $this->jalali_to_gregorian($check_in_parts[0], $check_in_parts[1], $check_in_parts[2]);
        $check_out_gregorian = $this->jalali_to_gregorian($check_out_parts[0], $check_out_parts[1], $check_out_parts[2]);

        $start = new DateTime($check_in_gregorian[0] . '-' . $check_in_gregorian[1] . '-' . $check_in_gregorian[2]);
        $end = new DateTime($check_out_gregorian[0] . '-' . $check_out_gregorian[1] . '-' . $check_out_gregorian[2]);

        $total_price = 0;
        $nights = 0;
        $breakdown = [];

        $current = clone $start;
        while ($current < $end) {
            $current_jalali = $this->gregorian_to_jalali($current->format('Y'), $current->format('m'), $current->format('d'));
            $current_date_str = $current_jalali[0] . '/' . str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);

            $night_price = $room['base_price'];

            // چک کردن بازه‌های قیمت
            if (!empty($room['date_ranges'])) {
                foreach ($room['date_ranges'] as $range) {
                    if ($current_date_str >= $range['from'] && $current_date_str <= $range['to']) {
                        if (!empty($range['price'])) {
                            $night_price = floatval($range['price']);
                        }
                        break;
                    }
                }
            }

            $total_price += $night_price;
            $nights++;
            $breakdown[] = ['date' => $current_date_str, 'price' => $night_price];

            $current->modify('+1 day');
        }

        return [
            'total' => $total_price,
            'nights' => $nights,
            'breakdown' => $breakdown
        ];
    }

    public function ajax_add_room_to_cart() {
        check_ajax_referer('hotel_add_cart', 'nonce');

        $product_id = intval($_POST['product_id']);
        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);

        $cart_item_data = [
            'hotel_room_id' => $room_data['id'],
            'hotel_room_name' => $room_data['name'],
            'hotel_check_in' => $check_in,
            'hotel_check_out' => $check_out,
            'hotel_room_data' => $room_data
        ];

        $added = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);

        if ($added) {
            wp_send_json_success(['message' => 'اتاق به سبد خرید اضافه شد']);
        } else {
            wp_send_json_error(['message' => 'خطا در افزودن به سبد خرید']);
        }
    }

    public function update_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['hotel_room_data'])) {
                $pricing = $this->calculate_room_price(
                    $cart_item['hotel_room_data'],
                    $cart_item['hotel_check_in'],
                    $cart_item['hotel_check_out']
                );
                $cart_item['data']->set_price($pricing['total']);
            }
        }
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['hotel_room_name'])) {
            $item_data[] = ['name' => 'اتاق', 'value' => $cart_item['hotel_room_name']];
        }
        if (isset($cart_item['hotel_check_in'])) {
            $item_data[] = ['name' => 'تاریخ ورود', 'value' => $cart_item['hotel_check_in']];
        }
        if (isset($cart_item['hotel_check_out'])) {
            $item_data[] = ['name' => 'تاریخ خروج', 'value' => $cart_item['hotel_check_out']];
        }
        if (isset($cart_item['hotel_room_data'])) {
            $pricing = $this->calculate_room_price(
                $cart_item['hotel_room_data'],
                $cart_item['hotel_check_in'],
                $cart_item['hotel_check_out']
            );
            $item_data[] = ['name' => 'تعداد شب', 'value' => $pricing['nights'] . ' شب'];
        }
        return $item_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['hotel_room_name'])) {
            $item->add_meta_data('اتاق', $values['hotel_room_name'], true);
        }
        if (isset($values['hotel_check_in'])) {
            $item->add_meta_data('_hotel_check_in', $values['hotel_check_in'], false);
            $item->add_meta_data('تاریخ ورود', $values['hotel_check_in'], true);
        }
        if (isset($values['hotel_check_out'])) {
            $item->add_meta_data('_hotel_check_out', $values['hotel_check_out'], false);
            $item->add_meta_data('تاریخ خروج', $values['hotel_check_out'], true);
        }
        if (isset($values['hotel_room_data'])) {
            $item->add_meta_data('_hotel_room_data', $values['hotel_room_data'], false);
        }
    }

    private function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = intval($jy); $jm = intval($jm); $jd = intval($jd);
        $jy += 1595;
        $days = 365 * $jy + floor($jy / 33) * 8 + floor((($jy % 33) + 3) / 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186) - 355668;
        $gy = 400 * floor($days / 146097);
        $days %= 146097;
        if ($days > 36524) { $gy += 100 * floor(--$days / 36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * floor($days / 1461);
        $days %= 1461;
        if ($days > 365) { $gy += floor(($days - 1) / 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) { $gd -= $sal_a[$gm]; }
        return [$gy, $gm, $gd];
    }

    private function gregorian_to_jalali($gy, $gm, $gd) {
        $gy = intval($gy); $gm = intval($gm); $gd = intval($gd);
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
}

function wc_hotel_reserve_init() {
    if (class_exists('WooCommerce')) {
        WC_Hotel_Reserve::get_instance();
    }
}
add_action('plugins_loaded', 'wc_hotel_reserve_init', 20);
