<?php
/**
 * Plugin Name: WooCommerce Hotel Reservation System - Professional Edition
 * Description: سیستم پیشرفته رزرو هتل برای محصولات ساده ووکامرس
 * Version: 10.2.0
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
        add_action('woocommerce_after_single_product_summary', [$this, 'display_hotel_rooms'], 5);
        add_action('wp_footer', [$this, 'frontend_scripts']);

        // مخفی کردن دکمه افزودن به سبد و quantity
        add_filter('woocommerce_is_purchasable', [$this, 'hide_add_to_cart_button'], 10, 2);
        add_action('woocommerce_single_product_summary', [$this, 'hide_quantity_field'], 1);

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

    public function hide_add_to_cart_button($purchasable, $product) {
        if (get_post_meta($product->get_id(), '_enable_hotel_reservation', true) === 'yes') {
            return false;
        }
        return $purchasable;
    }

    public function hide_quantity_field() {
        global $product;
        if ($product && get_post_meta($product->get_id(), '_enable_hotel_reservation', true) === 'yes') {
            echo '<style>.quantity { display: none !important; }</style>';
        }
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
        $enabled = get_post_meta($post->ID, '_enable_hotel_reservation', true) === 'yes';
        $rooms = get_post_meta($post->ID, '_hotel_rooms', true);
        $rooms = is_array($rooms) ? $rooms : [];
        ?>
        <div id="hotel_rooms_data" class="panel woocommerce_options_panel">
            <div class="options_group" style="padding:20px;">

                <!-- فعال/غیرفعال کردن -->
                <div style="background:#e3f2fd;padding:20px;border-radius:10px;margin-bottom:25px;border-right:5px solid #2196f3;">
                    <label style="display:flex;align-items:center;cursor:pointer;">
                        <input type="checkbox" name="_enable_hotel_reservation" id="enable_hotel_reservation" value="yes" <?php checked($enabled, true); ?> style="width:20px;height:20px;margin-left:10px;">
                        <span style="font-size:16px;font-weight:bold;">✓ فعال کردن سیستم رزرو هتل برای این محصول</span>
                    </label>
                    <p style="margin:10px 0 0 30px;color:#666;font-size:13px;">با فعال کردن این گزینه، دکمه "افزودن به سبد خرید" معمولی حذف می‌شود و رزرو از طریق انتخاب اتاق و تاریخ انجام می‌شود.</p>
                </div>

                <div id="hotel-rooms-panel" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                    <div style="background:#f0f8ff;padding:15px;border-radius:8px;margin-bottom:20px;border-right:4px solid #2196f3;">
                        <h4 style="margin:0 0 10px 0;">📖 راهنما</h4>
                        <p style="margin:0;color:#666;">این محصول یک <strong>هتل</strong> است. در این بخش اتاق‌های مختلف هتل را تعریف کنید. هر اتاق دارای نام، ظرفیت، قیمت پایه و بازه‌های قیمت‌گذاری مخصوص خودش است.</p>
                    </div>

                    <button type="button" class="button button-primary button-large" id="add-new-room-btn" style="margin-bottom:20px;">
                        ➕ افزودن اتاق جدید
                    </button>

                    <div id="hotel-rooms-container">
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
        </div>

        <style>
        .room-admin-item {
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .room-admin-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 18px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-admin-header h3 { margin: 0; font-size: 17px; font-weight: 600; }
        .room-admin-body { padding: 25px; }
        .room-admin-fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .room-admin-fields {
                grid-template-columns: 1fr;
            }
        }
        .room-admin-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        .room-admin-field input,
        .room-admin-field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.3s;
            box-sizing: border-box;
        }
        .room-admin-field input:focus,
        .room-admin-field textarea:focus {
            border-color: #667eea;
            outline: none;
        }
        .room-admin-field textarea { min-height: 70px; resize: vertical; }
        .date-ranges-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            border: 1px solid #e0e0e0;
        }
        .date-ranges-section h4 {
            margin: 0 0 10px 0;
            font-size: 15px;
            color: #333;
        }
        .date-range-item {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            align-items: center;
        }
        @media (min-width: 1200px) {
            .date-range-item {
                grid-template-columns: 2fr 1.2fr auto auto auto;
            }
        }
        @media (max-width: 1199px) and (min-width: 768px) {
            .date-range-item {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 767px) {
            .date-range-item {
                grid-template-columns: 1fr;
            }
        }
        .date-range-item input[type="text"],
        .date-range-item input[type="number"] {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            width: 100%;
            box-sizing: border-box;
        }
        .btn-remove-room {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-remove-room:hover { background: #c82333; }
        .btn-add-date-range {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 12px;
        }
        .btn-remove-date-range {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
        }
        .btn-select-date-range {
            background: #2196f3;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
        }
        .date-range-item label {
            white-space: nowrap;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var roomCounter = <?php echo count($rooms); ?>;

            // نمایش/مخفی پنل اتاق‌ها
            $('#enable_hotel_reservation').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#hotel-rooms-panel').slideDown();
                } else {
                    $('#hotel-rooms-panel').slideUp();
                }
            });

            // افزودن اتاق جدید
            $('#add-new-room-btn').on('click', function() {
                $('#no-rooms-msg').remove();
                var html = generateRoomHTML(roomCounter, {
                    name: '',
                    guest_capacity: 2,
                    base_price: '',
                    description: '',
                    allow_extra_guest: false,
                    max_extra_guests: 0,
                    extra_guest_price: 0,
                    date_ranges: []
                });
                $('#hotel-rooms-container').append(html);
                roomCounter++;
            });

            function generateRoomHTML(index, data) {
                var html = '<div class="room-admin-item" data-room-index="' + index + '">' +
                    '<div class="room-admin-header">' +
                        '<h3>🚪 اتاق #' + (index + 1) + '</h3>' +
                        '<button type="button" class="btn-remove-room">🗑️ حذف اتاق</button>' +
                    '</div>' +
                    '<div class="room-admin-body">' +
                        '<div class="room-admin-fields">' +
                            '<div class="room-admin-field">' +
                                '<label>نام اتاق *</label>' +
                                '<input type="text" name="hotel_rooms[' + index + '][name]" value="' + (data.name || '') + '" placeholder="مثال: اتاق دو تخته VIP" required>' +
                            '</div>' +
                            '<div class="room-admin-field">' +
                                '<label>ظرفیت (تعداد نفر) *</label>' +
                                '<input type="number" name="hotel_rooms[' + index + '][guest_capacity]" value="' + (data.guest_capacity || 2) + '" min="1" required>' +
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
                        '<div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">' +
                            '<label style="display:flex;align-items:center;cursor:pointer;margin-bottom:10px;">' +
                                '<input type="checkbox" class="allow-extra-guest" name="hotel_rooms[' + index + '][allow_extra_guest]" value="1" ' + (data.allow_extra_guest ? 'checked' : '') + ' style="width:18px;height:18px;margin-left:8px;">' +
                                '<strong>امکان نفر اضافه</strong>' +
                            '</label>' +
                            '<div class="extra-guest-fields" style="display:' + (data.allow_extra_guest ? 'grid' : 'none') + ';grid-template-columns:1fr 1fr;gap:15px;margin-top:10px;">' +
                                '<div>' +
                                    '<label style="display:block;margin-bottom:5px;font-size:13px;">حداکثر نفر اضافه</label>' +
                                    '<input type="number" name="hotel_rooms[' + index + '][max_extra_guests]" value="' + (data.max_extra_guests || 0) + '" min="0" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">' +
                                '</div>' +
                                '<div>' +
                                    '<label style="display:block;margin-bottom:5px;font-size:13px;">قیمت هر نفر اضافه (تومان/شب)</label>' +
                                    '<input type="number" name="hotel_rooms[' + index + '][extra_guest_price]" value="' + (data.extra_guest_price || 0) + '" step="1000" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="date-ranges-section">' +
                            '<h4>📅 بازه‌های قیمت‌گذاری</h4>' +
                            '<p style="margin:0 0 10px 0;color:#666;font-size:13px;">می‌توانید برای بازه‌های زمانی مختلف (تعطیلات، آخر هفته) قیمت متفاوت تعیین کنید.</p>' +
                            '<button type="button" class="btn-add-date-range" data-room-index="' + index + '">➕ افزودن بازه قیمت</button>' +
                            '<div class="date-ranges-list" data-room-index="' + index + '"></div>' +
                            '<input type="hidden" name="hotel_rooms[' + index + '][date_ranges_json]" class="date-ranges-json">' +
                        '</div>' +
                    '</div>' +
                '</div>';
                return html;
            }

            // نمایش فیلدهای نفر اضافه
            $(document).on('change', '.allow-extra-guest', function() {
                $(this).closest('div').find('.extra-guest-fields').slideToggle();
            });

            // حذف اتاق
            $(document).on('click', '.btn-remove-room', function() {
                if (confirm('آیا مطمئن هستید که می‌خواهید این اتاق را حذف کنید؟')) {
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

                var html = '<div class="date-range-item">' +
                    '<input type="text" class="range-dates" placeholder="انتخاب بازه تاریخ" readonly style="cursor:pointer;">' +
                    '<input type="number" class="range-price" placeholder="قیمت/شب" step="1000">' +
                    '<button type="button" class="btn-select-date-range">📅 انتخاب تاریخ</button>' +
                    '<label><input type="checkbox" class="range-disabled"> غیرفعال</label>' +
                    '<button type="button" class="btn-remove-date-range">✕</button>' +
                    '<input type="hidden" class="range-from">' +
                    '<input type="hidden" class="range-to">' +
                '</div>';

                container.append(html);
            });

            // حذف بازه قیمت
            $(document).on('click', '.btn-remove-date-range', function() {
                $(this).closest('.date-range-item').remove();
            });

            // باز کردن تقویم برای انتخاب بازه
            var currentRangeItem = null;

            $(document).on('click', '.btn-select-date-range', function() {
                currentRangeItem = $(this).closest('.date-range-item');
                openAdminCalendar();
            });

            function openAdminCalendar() {
                $('#admin-calendar-modal').fadeIn();
                window.adminSelectedFrom = null;
                window.adminSelectedTo = null;
                renderAdminCalendar();
            }

            // ذخیره بازه‌ها قبل از submit
            $('form#post').on('submit', function() {
                $('.room-admin-item').each(function() {
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

            // تقویم ادمین
            window.adminSelectedFrom = null;
            window.adminSelectedTo = null;
            window.adminCurrentYear = null;
            window.adminCurrentMonth = null;

            window.adminCalendarSelectDate = function(dateStr) {
                if (!window.adminSelectedFrom) {
                    window.adminSelectedFrom = dateStr;
                    renderAdminCalendar();
                } else if (!window.adminSelectedTo) {
                    if (dateStr <= window.adminSelectedFrom) {
                        alert('❌ تاریخ پایان باید بعد از تاریخ شروع باشد');
                        return;
                    }
                    window.adminSelectedTo = dateStr;

                    if (currentRangeItem) {
                        currentRangeItem.find('.range-from').val(window.adminSelectedFrom);
                        currentRangeItem.find('.range-to').val(window.adminSelectedTo);
                        currentRangeItem.find('.range-dates').val(window.adminSelectedFrom + ' تا ' + window.adminSelectedTo);
                    }

                    $('#admin-calendar-modal').fadeOut();
                    window.adminSelectedFrom = null;
                    window.adminSelectedTo = null;
                } else {
                    window.adminSelectedFrom = dateStr;
                    window.adminSelectedTo = null;
                    renderAdminCalendar();
                }
            };

            window.renderAdminCalendar = function() {
                var today = new Date();
                var jalali = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());

                if (!window.adminCurrentYear || !window.adminCurrentMonth) {
                    window.adminCurrentYear = jalali[0];
                    window.adminCurrentMonth = jalali[1];
                }

                $('#admin-cal-year').text(window.adminCurrentYear);
                $('#admin-cal-month').text(getPersianMonth(window.adminCurrentMonth));

                var guideText = '📅 تاریخ شروع را انتخاب کنید';
                if (window.adminSelectedFrom && !window.adminSelectedTo) {
                    guideText = '📅 تاریخ پایان را انتخاب کنید';
                }
                $('#admin-cal-guide').html(guideText);

                var html = '';
                var daysInMonth = getDaysInJalaliMonth(window.adminCurrentYear, window.adminCurrentMonth);
                var firstDay = getFirstDayOfJalaliMonth(window.adminCurrentYear, window.adminCurrentMonth);

                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="admin-cal-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = window.adminCurrentYear + '/' + pad(window.adminCurrentMonth) + '/' + pad(day);
                    var classes = 'admin-cal-day';

                    if (window.adminSelectedFrom && dateStr === window.adminSelectedFrom) {
                        classes += ' selected-from';
                    }
                    if (window.adminSelectedTo && dateStr === window.adminSelectedTo) {
                        classes += ' selected-to';
                    }
                    if (window.adminSelectedFrom && window.adminSelectedTo && dateStr > window.adminSelectedFrom && dateStr < window.adminSelectedTo) {
                        classes += ' in-range';
                    }
                    if (window.adminSelectedFrom && !window.adminSelectedTo && dateStr > window.adminSelectedFrom) {
                        classes += ' selectable';
                    }

                    html += '<div class="' + classes + '" data-date="' + dateStr + '">' + day + '</div>';
                }

                $('#admin-calendar-days').html(html);
            };

            $(document).on('click', '.admin-cal-day:not(.empty)', function() {
                var dateStr = $(this).data('date');
                adminCalendarSelectDate(dateStr);
            });

            $('#admin-cal-close').on('click', function() {
                $('#admin-calendar-modal').fadeOut();
                window.adminSelectedFrom = null;
                window.adminSelectedTo = null;
            });

            $('#admin-cal-prev').on('click', function() {
                window.adminCurrentMonth--;
                if (window.adminCurrentMonth < 1) {
                    window.adminCurrentMonth = 12;
                    window.adminCurrentYear--;
                }
                renderAdminCalendar();
            });

            $('#admin-cal-next').on('click', function() {
                window.adminCurrentMonth++;
                if (window.adminCurrentMonth > 12) {
                    window.adminCurrentMonth = 1;
                    window.adminCurrentYear++;
                }
                renderAdminCalendar();
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
                return 29;
            }

            function getFirstDayOfJalaliMonth(year, month) {
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
                <button type="button" class="btn-remove-room">🗑️ حذف اتاق</button>
            </div>
            <div class="room-admin-body">
                <div class="room-admin-fields">
                    <div class="room-admin-field">
                        <label>نام اتاق *</label>
                        <input type="text" name="hotel_rooms[<?php echo $index; ?>][name]" value="<?php echo esc_attr($room['name'] ?? ''); ?>" placeholder="مثال: اتاق دو تخته VIP" required>
                    </div>
                    <div class="room-admin-field">
                        <label>ظرفیت (تعداد نفر) *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][guest_capacity]" value="<?php echo esc_attr($room['guest_capacity'] ?? 2); ?>" min="1" required>
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

                <div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">
                    <label style="display:flex;align-items:center;cursor:pointer;margin-bottom:10px;">
                        <input type="checkbox" class="allow-extra-guest" name="hotel_rooms[<?php echo $index; ?>][allow_extra_guest]" value="1" <?php checked($room['allow_extra_guest'] ?? false, true); ?> style="width:18px;height:18px;margin-left:8px;">
                        <strong>امکان نفر اضافه</strong>
                    </label>
                    <div class="extra-guest-fields" style="display:<?php echo (!empty($room['allow_extra_guest']) ? 'grid' : 'none'); ?>;grid-template-columns:1fr 1fr;gap:15px;margin-top:10px;">
                        <div>
                            <label style="display:block;margin-bottom:5px;font-size:13px;">حداکثر نفر اضافه</label>
                            <input type="number" name="hotel_rooms[<?php echo $index; ?>][max_extra_guests]" value="<?php echo esc_attr($room['max_extra_guests'] ?? 0); ?>" min="0" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px;font-size:13px;">قیمت هر نفر اضافه (تومان/شب)</label>
                            <input type="number" name="hotel_rooms[<?php echo $index; ?>][extra_guest_price]" value="<?php echo esc_attr($room['extra_guest_price'] ?? 0); ?>" step="1000" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">
                        </div>
                    </div>
                </div>

                <div class="date-ranges-section">
                    <h4>📅 بازه‌های قیمت‌گذاری</h4>
                    <p style="margin:0 0 10px 0;color:#666;font-size:13px;">می‌توانید برای بازه‌های زمانی مختلف قیمت متفاوت تعیین کنید.</p>
                    <button type="button" class="btn-add-date-range" data-room-index="<?php echo $index; ?>">➕ افزودن بازه قیمت</button>
                    <div class="date-ranges-list" data-room-index="<?php echo $index; ?>">
                        <?php if (!empty($room['date_ranges'])): ?>
                            <?php foreach ($room['date_ranges'] as $range): ?>
                                <div class="date-range-item">
                                    <input type="text" class="range-dates" value="<?php echo esc_attr($range['from'] . ' تا ' . $range['to']); ?>" placeholder="انتخاب بازه تاریخ" readonly style="cursor:pointer;">
                                    <input type="number" class="range-price" value="<?php echo esc_attr($range['price'] ?? ''); ?>" placeholder="قیمت/شب" step="1000">
                                    <button type="button" class="btn-select-date-range">📅 انتخاب تاریخ</button>
                                    <label><input type="checkbox" class="range-disabled" <?php checked($range['disabled'] ?? false, true); ?>> غیرفعال</label>
                                    <button type="button" class="btn-remove-date-range">✕</button>
                                    <input type="hidden" class="range-from" value="<?php echo esc_attr($range['from']); ?>">
                                    <input type="hidden" class="range-to" value="<?php echo esc_attr($range['to']); ?>">
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
                <div style="background:white;border-radius:12px;width:450px;max-width:95%;">
                    <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:18px 20px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;font-size:16px;">📅 انتخاب بازه تاریخ</h3>
                        <button type="button" id="admin-cal-close" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:6px 12px;border-radius:5px;cursor:pointer;font-size:16px;">✕</button>
                    </div>
                    <div style="padding:20px;">
                        <div id="admin-cal-guide" style="background:#e3f2fd;padding:10px;border-radius:6px;margin-bottom:15px;text-align:center;font-size:14px;font-weight:600;color:#1976d2;">
                            📅 تاریخ شروع را انتخاب کنید
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                            <button type="button" id="admin-cal-prev" style="background:#667eea;color:white;border:none;padding:8px 14px;border-radius:5px;cursor:pointer;font-weight:bold;">❮</button>
                            <div style="font-size:16px;font-weight:600;"><span id="admin-cal-month"></span> <span id="admin-cal-year"></span></div>
                            <button type="button" id="admin-cal-next" style="background:#667eea;color:white;border:none;padding:8px 14px;border-radius:5px;cursor:pointer;font-weight:bold;">❯</button>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;font-size:13px;">
                            <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                        </div>
                        <div id="admin-calendar-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                    </div>
                </div>
            </div>
            <style>
            #admin-calendar-modal { display: none; }
            #admin-calendar-modal.show { display: flex !important; }
            .admin-cal-day {
                padding: 12px;
                text-align: center;
                border-radius: 6px;
                cursor: pointer;
                background: #f5f5f5;
                font-size: 14px;
                transition: all 0.2s;
            }
            .admin-cal-day:hover:not(.empty) {
                background: #e3f2fd;
                transform: scale(1.05);
            }
            .admin-cal-day.selected-from {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                font-weight: bold;
            }
            .admin-cal-day.selected-to {
                background: linear-gradient(135deg, #11998e, #38ef7d);
                color: white;
                font-weight: bold;
            }
            .admin-cal-day.in-range {
                background: #fff3cd;
                border: 1px solid #ffc107;
            }
            .admin-cal-day.selectable:hover {
                background: #c8e6c9;
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
        // فعال/غیرفعال
        $enabled = isset($_POST['_enable_hotel_reservation']) && $_POST['_enable_hotel_reservation'] === 'yes' ? 'yes' : 'no';
        update_post_meta($post_id, '_enable_hotel_reservation', $enabled);

        if (!isset($_POST['hotel_rooms'])) {
            delete_post_meta($post_id, '_hotel_rooms');
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
                    'guest_capacity' => intval($room_data['guest_capacity'] ?? 2),
                    'base_price' => floatval($room_data['base_price']),
                    'description' => sanitize_textarea_field($room_data['description'] ?? ''),
                    'allow_extra_guest' => isset($room_data['allow_extra_guest']),
                    'max_extra_guests' => intval($room_data['max_extra_guests'] ?? 0),
                    'extra_guest_price' => floatval($room_data['extra_guest_price'] ?? 0),
                    'date_ranges' => $date_ranges
                ];
            }
        }

        update_post_meta($post_id, '_hotel_rooms', $rooms);
    }

    public function display_hotel_rooms() {
        global $product;

        if (!$product || !$product->is_type('simple')) {
            return;
        }

        $enabled = get_post_meta($product->get_id(), '_enable_hotel_reservation', true);

        if ($enabled !== 'yes') {
            return;
        }

        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);

        if (empty($rooms) || !is_array($rooms)) {
            return;
        }

        ?>
        <div class="hotel-rooms-section" style="margin:30px 0;padding:20px;background:#fff;border-radius:10px;">
            <h3 style="font-size:22px;margin-bottom:20px;color:#333;font-weight:600;">🏨 اتاق‌های موجود</h3>

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
                    <div class="room-card" style="background:#f8f9fa;border:2px solid #e0e0e0;border-radius:10px;padding:18px;display:flex;justify-content:space-between;align-items:center;transition:all 0.3s;flex-wrap:wrap;gap:15px;" data-room='<?php echo esc_attr(json_encode($room)); ?>'>
                        <div style="flex:1;min-width:250px;">
                            <h4 style="margin:0 0 10px 0;font-size:18px;color:#667eea;font-weight:600;">🚪 <?php echo esc_html($room['name']); ?></h4>
                            <?php if (!empty($room['description'])): ?>
                                <p style="margin:0 0 10px 0;color:#666;font-size:14px;"><?php echo esc_html($room['description']); ?></p>
                            <?php endif; ?>
                            <div style="color:#999;font-size:13px;">
                                👥 ظرفیت: <?php echo $room['guest_capacity']; ?> نفر
                                <?php if (!empty($room['allow_extra_guest']) && $room['max_extra_guests'] > 0): ?>
                                    <span style="color:#28a745;font-weight:600;"> + حداکثر <?php echo $room['max_extra_guests']; ?> نفر اضافه</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:left;padding:0 20px;">
                            <div style="color:#999;font-size:12px;margin-bottom:5px;">قیمت از:</div>
                            <div style="font-size:22px;font-weight:bold;color:#28a745;margin-bottom:12px;">
                                <?php echo number_format($min_price); ?> <span style="font-size:13px;">تومان/شب</span>
                            </div>
                            <button type="button" class="room-select-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;font-weight:bold;font-size:15px;">
                                📅 انتخاب تاریخ رزرو
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .room-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #667eea;
            transform: translateY(-2px);
        }
        .room-select-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(102,126,234,0.5);
        }
        @media (max-width: 768px) {
            .room-card {
                flex-direction: column;
                text-align: center;
            }
            .room-card > div {
                width: 100%;
                text-align: center !important;
                padding: 0 !important;
            }
        }
        </style>
        <?php
    }

    public function frontend_scripts() {
        if (!is_product()) return;

        global $product;
        if (!$product || !$product->is_type('simple')) return;

        $enabled = get_post_meta($product->get_id(), '_enable_hotel_reservation', true);
        if ($enabled !== 'yes') return;

        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);
        if (empty($rooms)) return;

        $ajax_url = admin_url('admin-ajax.php');
        $cart_url = wc_get_cart_url();
        ?>
        <!-- Modal تقویم رزرو -->
        <div id="booking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:white;border-radius:15px;max-width:650px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 15px 50px rgba(0,0,0,0.5);">
                <div style="padding:20px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:15px 15px 0 0;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;" id="modal-title">📅 رزرو اتاق</h3>
                    <button type="button" id="close-modal-btn" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;font-size:18px;">✕</button>
                </div>
                <div style="padding:28px;">
                    <div id="booking-guide" style="background:#e3f2fd;padding:14px;border-radius:8px;margin-bottom:20px;text-align:center;font-size:15px;font-weight:600;">
                        🎯 لطفاً تاریخ ورود را انتخاب کنید
                    </div>

                    <!-- انتخاب نفر اضافه -->
                    <div id="extra-guests-section" style="display:none;background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">
                        <label style="display:block;font-weight:600;margin-bottom:10px;">👥 تعداد نفر اضافه:</label>
                        <select id="extra-guests-select" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;">
                            <option value="0">بدون نفر اضافه</option>
                        </select>
                        <div id="extra-guests-price-info" style="margin-top:10px;color:#666;font-size:13px;"></div>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <button type="button" id="prev-month-btn" style="background:#667eea;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:bold;">❮</button>
                        <div id="current-month-display" style="font-weight:bold;font-size:18px;"></div>
                        <button type="button" id="next-month-btn" style="background:#667eea;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:bold;">❯</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:12px;text-align:center;font-weight:bold;color:#666;font-size:13px;">
                        <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                    </div>
                    <div id="calendar-days-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;"></div>
                    <div id="booking-summary" style="display:none;background:#f0f8ff;padding:22px;border-radius:10px;margin-top:22px;border:2px solid #2196f3;">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin-bottom:18px;">
                            <div><small style="color:#666;font-size:12px;">تاریخ ورود</small><div style="font-weight:bold;color:#667eea;font-size:15px;" id="sum-checkin">-</div></div>
                            <div><small style="color:#666;font-size:12px;">تاریخ خروج</small><div style="font-weight:bold;color:#11998e;font-size:15px;" id="sum-checkout">-</div></div>
                        </div>
                        <div style="margin-bottom:12px;"><strong>✓ تعداد شب‌ها:</strong> <span id="sum-nights">0</span> شب</div>
                        <div style="margin-bottom:12px;" id="sum-extra-info"></div>
                        <div style="margin-bottom:12px;font-size:18px;"><strong style="color:#28a745;">💰 قیمت کل:</strong> <span id="sum-price" style="font-weight:bold;color:#28a745;">0</span> تومان</div>
                        <div id="price-detail" style="font-size:13px;color:#666;max-height:120px;overflow-y:auto;border-top:1px solid #ddd;padding-top:12px;"></div>
                    </div>
                    <button type="button" id="reserve-room-btn" style="display:none;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:16px;border:none;border-radius:8px;cursor:pointer;font-size:17px;font-weight:bold;width:100%;margin-top:20px;box-shadow:0 4px 15px rgba(17,153,142,0.4);">
                        ✓ رزرو اتاق
                    </button>
                </div>
            </div>
        </div>

        <style>
        #booking-modal { display: none; }
        #booking-modal.active { display: flex !important; }
        .cal-day {
            padding: 13px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border: 2px solid transparent;
            transition: all 0.3s;
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
        }
        .cal-day:hover:not(.disabled):not(.past):not(.empty) {
            background: #e3f2fd;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .cal-day.disabled, .cal-day.past { background: #f5f5f5; color: #ccc; cursor: not-allowed; }
        .cal-day.selected-in { background: linear-gradient(135deg,#667eea,#764ba2); color: white; font-weight: bold; border-color: #667eea; }
        .cal-day.selected-out { background: linear-gradient(135deg,#11998e,#38ef7d); color: white; font-weight: bold; border-color: #11998e; }
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
            var selectedExtraGuests = 0;

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

                selectedExtraGuests = parseInt($('#extra-guests-select').val() || 0);

                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'hotel_check_room_availability',
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    extra_guests: selectedExtraGuests,
                    nonce: '<?php echo wp_create_nonce("hotel_booking"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#sum-nights').text(data.nights);

                        if (selectedExtraGuests > 0) {
                            $('#sum-extra-info').html('<strong>👥 نفرات اضافه:</strong> ' + selectedExtraGuests + ' نفر').show();
                        } else {
                            $('#sum-extra-info').hide();
                        }

                        $('#sum-price').text(data.total.toLocaleString('fa-IR'));

                        var detail = '<strong>جزئیات قیمت:</strong><br>';
                        data.breakdown.forEach(function(item) {
                            detail += item.date + ': ' + item.price.toLocaleString('fa-IR') + ' تومان<br>';
                        });

                        if (data.extra_guest_total > 0) {
                            detail += '<br><strong>نفرات اضافه:</strong> ' + data.extra_guest_total.toLocaleString('fa-IR') + ' تومان';
                        }

                        $('#price-detail').html(detail);

                        $('#booking-summary').show();
                        $('#reserve-room-btn').show();
                    } else {
                        alert('خطا در محاسبه قیمت');
                    }
                }).fail(function() {
                    alert('خطا در ارتباط با سرور');
                });
            }

            $('.room-select-btn').on('click', function() {
                currentRoom = JSON.parse($(this).closest('.room-card').attr('data-room'));
                $('#modal-title').text('📅 رزرو ' + currentRoom.name);

                // تنظیم نفرات اضافه
                if (currentRoom.allow_extra_guest && currentRoom.max_extra_guests > 0) {
                    var options = '<option value="0">بدون نفر اضافه</option>';
                    for (var i = 1; i <= currentRoom.max_extra_guests; i++) {
                        options += '<option value="' + i + '">' + i + ' نفر (هر نفر: ' + currentRoom.extra_guest_price.toLocaleString('fa-IR') + ' تومان/شب)</option>';
                    }
                    $('#extra-guests-select').html(options);
                    $('#extra-guests-price-info').text('قیمت هر نفر اضافه: ' + currentRoom.extra_guest_price.toLocaleString('fa-IR') + ' تومان در شب');
                    $('#extra-guests-section').show();
                } else {
                    $('#extra-guests-section').hide();
                }

                var today = getTodayJalali();
                currentYear = today[0];
                currentMonth = today[1];
                selectedCheckIn = null;
                selectedCheckOut = null;
                selectingMode = 'checkin';
                selectedExtraGuests = 0;

                $('#booking-guide').html('🎯 لطفاً تاریخ ورود را انتخاب کنید');
                $('#booking-summary').hide();
                $('#reserve-room-btn').hide();

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
                    $('#booking-guide').html('🎯 حالا لطفاً تاریخ خروج را انتخاب کنید');
                    $('#booking-summary').hide();
                    $('#reserve-room-btn').hide();
                    renderCalendar();
                } else {
                    if (dateStr <= selectedCheckIn) {
                        alert('❌ تاریخ خروج باید بعد از تاریخ ورود باشد');
                        return;
                    }
                    selectedCheckOut = dateStr;
                    $('#sum-checkout').text(dateStr);
                    renderCalendar();
                    calculatePrice();
                }
            });

            $('#extra-guests-select').on('change', function() {
                if (selectedCheckIn && selectedCheckOut) {
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

            $('#reserve-room-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('در حال رزرو...');

                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'hotel_add_room_to_cart',
                    product_id: <?php echo $product->get_id(); ?>,
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    extra_guests: selectedExtraGuests,
                    nonce: '<?php echo wp_create_nonce("hotel_add_cart"); ?>'
                }, function(response) {
                    if (response.success) {
                        window.location.href = '<?php echo esc_js($cart_url); ?>';
                    } else {
                        alert('❌ خطا: ' + (response.data && response.data.message ? response.data.message : 'خطای نامشخص'));
                        btn.prop('disabled', false).text('✓ رزرو اتاق');
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX Error:', textStatus, errorThrown);
                    alert('❌ خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
                    btn.prop('disabled', false).text('✓ رزرو اتاق');
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
        $extra_guests = intval($_POST['extra_guests'] ?? 0);

        $pricing = $this->calculate_room_price($room_data, $check_in, $check_out, $extra_guests);
        $availability = ['available' => true];

        wp_send_json_success(array_merge($availability, $pricing));
    }

    private function calculate_room_price($room, $check_in, $check_out, $extra_guests = 0) {
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

        // محاسبه نفرات اضافه
        $extra_guest_total = 0;
        if ($extra_guests > 0 && !empty($room['extra_guest_price'])) {
            $extra_guest_total = $extra_guests * $nights * floatval($room['extra_guest_price']);
            $total_price += $extra_guest_total;
        }

        return [
            'total' => $total_price,
            'nights' => $nights,
            'breakdown' => $breakdown,
            'extra_guest_total' => $extra_guest_total
        ];
    }

    public function ajax_add_room_to_cart() {
        check_ajax_referer('hotel_add_cart', 'nonce');

        $product_id = intval($_POST['product_id']);
        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);
        $extra_guests = intval($_POST['extra_guests'] ?? 0);

        $cart_item_data = [
            'hotel_room_id' => $room_data['id'],
            'hotel_room_name' => $room_data['name'],
            'hotel_check_in' => $check_in,
            'hotel_check_out' => $check_out,
            'hotel_extra_guests' => $extra_guests,
            'hotel_room_data' => $room_data
        ];

        $added = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);

        if ($added) {
            wp_send_json_success(['message' => 'اتاق رزرو شد']);
        } else {
            wp_send_json_error(['message' => 'خطا در رزرو اتاق']);
        }
    }

    public function update_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['hotel_room_data'])) {
                $pricing = $this->calculate_room_price(
                    $cart_item['hotel_room_data'],
                    $cart_item['hotel_check_in'],
                    $cart_item['hotel_check_out'],
                    $cart_item['hotel_extra_guests'] ?? 0
                );
                $cart_item['data']->set_price($pricing['total']);
            }
        }
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['hotel_room_name'])) {
            $item_data[] = ['name' => '🚪 اتاق', 'value' => $cart_item['hotel_room_name']];
        }
        if (isset($cart_item['hotel_check_in'])) {
            $item_data[] = ['name' => '📅 ورود', 'value' => $cart_item['hotel_check_in']];
        }
        if (isset($cart_item['hotel_check_out'])) {
            $item_data[] = ['name' => '📅 خروج', 'value' => $cart_item['hotel_check_out']];
        }
        if (isset($cart_item['hotel_room_data'])) {
            $pricing = $this->calculate_room_price(
                $cart_item['hotel_room_data'],
                $cart_item['hotel_check_in'],
                $cart_item['hotel_check_out'],
                $cart_item['hotel_extra_guests'] ?? 0
            );
            $item_data[] = ['name' => '🌙 تعداد شب', 'value' => $pricing['nights'] . ' شب'];
        }
        if (isset($cart_item['hotel_extra_guests']) && $cart_item['hotel_extra_guests'] > 0) {
            $item_data[] = ['name' => '👥 نفرات اضافه', 'value' => $cart_item['hotel_extra_guests'] . ' نفر'];
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
        if (isset($values['hotel_extra_guests']) && $values['hotel_extra_guests'] > 0) {
            $item->add_meta_data('نفرات اضافه', $values['hotel_extra_guests'] . ' نفر', true);
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
