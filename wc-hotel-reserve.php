<?php
/**
 * Plugin Name: WooCommerce Hotel Reservation System - Professional Edition
 * Description: سیستم پیشرفته رزرو هتل - هر محصول یک هتل با چندین اتاق
 * Version: 8.0.0
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
        // پنل مدیریت محصول (هتل)
        add_action('add_meta_boxes', [$this, 'add_hotel_rooms_metabox']);
        add_action('save_post_product', [$this, 'save_hotel_rooms']);

        // نمایش اتاق‌ها در صفحه محصول
        add_action('woocommerce_after_single_product_summary', [$this, 'display_hotel_rooms'], 5);
        add_action('wp_footer', [$this, 'enqueue_scripts_and_styles']);

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

    /**
     * دریافت اتاق‌های یک هتل
     */
    public function get_hotel_rooms($product_id) {
        $rooms = get_post_meta($product_id, '_hotel_rooms', true);
        return is_array($rooms) ? $rooms : [];
    }

    /**
     * اضافه کردن متاباکس برای مدیریت اتاق‌ها
     */
    public function add_hotel_rooms_metabox() {
        add_meta_box(
            'hotel_rooms_metabox',
            '🏨 مدیریت اتاق‌های هتل',
            [$this, 'render_hotel_rooms_metabox'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_hotel_rooms_metabox($post) {
        wp_nonce_field('hotel_rooms_nonce', 'hotel_rooms_nonce_field');
        $rooms = $this->get_hotel_rooms($post->ID);
        ?>
        <div class="hotel-admin-panel">
            <div style="background:#f0f8ff;padding:15px;border-radius:8px;margin-bottom:20px;border-left:4px solid #2271b1;">
                <h4 style="margin:0 0 10px 0;">📖 راهنما</h4>
                <p style="margin:0;color:#666;">این محصول یک <strong>هتل</strong> است. در این بخش می‌توانید اتاق‌های مختلف هتل را تعریف کنید. هر اتاق دارای نام، ظرفیت، قیمت پایه و تقویم قیمت‌گذاری مخصوص خودش است.</p>
            </div>

            <button type="button" class="button button-primary button-large" id="add-new-room" style="margin-bottom:20px;">
                ➕ افزودن اتاق جدید
            </button>

            <div id="rooms-container">
                <?php if (!empty($rooms)): ?>
                    <?php foreach ($rooms as $index => $room): ?>
                        <?php $this->render_room_item($index, $room); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="no-rooms-message" style="<?php echo !empty($rooms) ? 'display:none;' : ''; ?>background:#fff3cd;padding:20px;border-radius:8px;text-align:center;">
                <p style="margin:0;">⚠️ هنوز اتاقی اضافه نشده است. روی دکمه "افزودن اتاق جدید" کلیک کنید.</p>
            </div>
        </div>

        <style>
        .hotel-admin-panel { padding: 10px; }
        .room-item {
            background: #fff;
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
        }
        .room-item-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            margin: -20px -20px 20px -20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-item-header h3 { margin: 0; }
        .room-fields { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; }
        .room-field { display: flex; flex-direction: column; }
        .room-field label { font-weight: 600; margin-bottom: 5px; color: #333; }
        .room-field input, .room-field textarea { padding: 10px; border: 2px solid #ddd; border-radius: 5px; }
        .room-field textarea { min-height: 80px; resize: vertical; }
        .remove-room { background: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
        .date-configs { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px; }
        .date-config-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 10px;
            align-items: center;
            border: 1px solid #ddd;
        }
        .toggle-dates { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 10px; }
        .add-date-config { background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
        .remove-date-config { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var roomCounter = <?php echo count($rooms); ?>;

            // افزودن اتاق جدید
            $('#add-new-room').on('click', function() {
                var html = generateRoomHTML(roomCounter, {
                    name: '',
                    capacity: 1,
                    base_price: '',
                    description: '',
                    custom_fields: [],
                    date_configs: []
                });
                $('#rooms-container').append(html);
                $('#no-rooms-message').hide();
                roomCounter++;
            });

            // حذف اتاق
            $(document).on('click', '.remove-room', function() {
                if (confirm('آیا مطمئن هستید که می‌خواهید این اتاق را حذف کنید؟')) {
                    $(this).closest('.room-item').remove();
                    if ($('.room-item').length === 0) {
                        $('#no-rooms-message').show();
                    }
                }
            });

            // نمایش/مخفی کردن تقویم قیمت‌گذاری
            $(document).on('click', '.toggle-dates', function() {
                $(this).siblings('.date-configs').slideToggle();
            });

            // افزودن تاریخ
            $(document).on('click', '.add-date-config', function() {
                var container = $(this).closest('.date-configs').find('.date-configs-list');
                var html = '<div class="date-config-item">' +
                    '<input type="text" placeholder="1403/09/15" style="padding:8px;border:1px solid #ddd;border-radius:4px;">' +
                    '<input type="number" placeholder="قیمت (تومان)" step="1000" style="padding:8px;border:1px solid #ddd;border-radius:4px;">' +
                    '<label><input type="checkbox"> غیرفعال</label>' +
                    '<button type="button" class="remove-date-config">حذف</button>' +
                    '</div>';
                container.append(html);
            });

            // حذف تاریخ
            $(document).on('click', '.remove-date-config', function() {
                $(this).closest('.date-config-item').remove();
            });

            // تابع تولید HTML اتاق
            function generateRoomHTML(index, data) {
                var html = '<div class="room-item" data-index="' + index + '">' +
                    '<div class="room-item-header">' +
                        '<h3>🚪 اتاق شماره ' + (index + 1) + '</h3>' +
                        '<button type="button" class="remove-room">🗑️ حذف اتاق</button>' +
                    '</div>' +
                    '<div class="room-fields">' +
                        '<div class="room-field">' +
                            '<label>نام اتاق *</label>' +
                            '<input type="text" name="rooms[' + index + '][name]" value="' + (data.name || '') + '" placeholder="مثال: اتاق دو تخته VIP" required>' +
                        '</div>' +
                        '<div class="room-field">' +
                            '<label>ظرفیت (تعداد اتاق) *</label>' +
                            '<input type="number" name="rooms[' + index + '][capacity]" value="' + (data.capacity || 1) + '" min="1" required>' +
                        '</div>' +
                        '<div class="room-field">' +
                            '<label>قیمت پایه (تومان/شب) *</label>' +
                            '<input type="number" name="rooms[' + index + '][base_price]" value="' + (data.base_price || '') + '" step="1000" required>' +
                        '</div>' +
                        '<div class="room-field">' +
                            '<label>توضیحات اتاق</label>' +
                            '<textarea name="rooms[' + index + '][description]" placeholder="امکانات و توضیحات اتاق...">' + (data.description || '') + '</textarea>' +
                        '</div>' +
                    '</div>' +
                    '<div style="margin-top:15px;">' +
                        '<button type="button" class="toggle-dates">📅 قیمت‌گذاری تاریخ‌های خاص</button>' +
                        '<div class="date-configs" style="display:none;">' +
                            '<p style="margin:10px 0;color:#666;">برای ایام خاص (تعطیلات، آخر هفته) می‌توانید قیمت متفاوت تعیین کنید.</p>' +
                            '<button type="button" class="add-date-config">➕ افزودن تاریخ</button>' +
                            '<div class="date-configs-list" style="margin-top:10px;"></div>' +
                            '<input type="hidden" name="rooms[' + index + '][date_configs]" class="date-configs-data" value="">' +
                        '</div>' +
                    '</div>' +
                '</div>';
                return html;
            }

            // ذخیره تاریخ‌ها قبل از submit
            $('form#post').on('submit', function() {
                $('.room-item').each(function() {
                    var dateConfigs = [];
                    $(this).find('.date-config-item').each(function() {
                        var date = $(this).find('input[type="text"]').val();
                        var price = $(this).find('input[type="number"]').val();
                        var disabled = $(this).find('input[type="checkbox"]').is(':checked');
                        if (date) {
                            dateConfigs.push({
                                date: date,
                                price: price || 0,
                                is_disabled: disabled
                            });
                        }
                    });
                    $(this).find('.date-configs-data').val(JSON.stringify(dateConfigs));
                });
            });
        });
        </script>
        <?php
    }

    private function render_room_item($index, $room) {
        ?>
        <div class="room-item" data-index="<?php echo $index; ?>">
            <div class="room-item-header">
                <h3>🚪 اتاق: <?php echo esc_html($room['name'] ?? 'اتاق ' . ($index + 1)); ?></h3>
                <button type="button" class="remove-room">🗑️ حذف اتاق</button>
            </div>

            <div class="room-fields">
                <div class="room-field">
                    <label>نام اتاق *</label>
                    <input type="text" name="rooms[<?php echo $index; ?>][name]" value="<?php echo esc_attr($room['name'] ?? ''); ?>" placeholder="مثال: اتاق دو تخته VIP" required>
                </div>

                <div class="room-field">
                    <label>ظرفیت (تعداد اتاق) *</label>
                    <input type="number" name="rooms[<?php echo $index; ?>][capacity]" value="<?php echo esc_attr($room['capacity'] ?? 1); ?>" min="1" required>
                </div>

                <div class="room-field">
                    <label>قیمت پایه (تومان/شب) *</label>
                    <input type="number" name="rooms[<?php echo $index; ?>][base_price]" value="<?php echo esc_attr($room['base_price'] ?? ''); ?>" step="1000" required>
                </div>

                <div class="room-field">
                    <label>توضیحات اتاق</label>
                    <textarea name="rooms[<?php echo $index; ?>][description]" placeholder="امکانات و توضیحات اتاق..."><?php echo esc_textarea($room['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <div style="margin-top:15px;">
                <button type="button" class="toggle-dates">📅 قیمت‌گذاری تاریخ‌های خاص</button>
                <div class="date-configs" style="display:none;">
                    <p style="margin:10px 0;color:#666;">برای ایام خاص (تعطیلات، آخر هفته) می‌توانید قیمت متفاوت تعیین کنید.</p>
                    <button type="button" class="add-date-config">➕ افزودن تاریخ</button>
                    <div class="date-configs-list" style="margin-top:10px;">
                        <?php if (!empty($room['date_configs'])): ?>
                            <?php foreach ($room['date_configs'] as $config): ?>
                                <div class="date-config-item">
                                    <input type="text" value="<?php echo esc_attr($config['date']); ?>" placeholder="1403/09/15" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                                    <input type="number" value="<?php echo esc_attr($config['price'] ?? ''); ?>" placeholder="قیمت (تومان)" step="1000" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                                    <label><input type="checkbox" <?php checked($config['is_disabled'] ?? false, true); ?>> غیرفعال</label>
                                    <button type="button" class="remove-date-config">حذف</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="rooms[<?php echo $index; ?>][date_configs]" class="date-configs-data" value="">
                </div>
            </div>
        </div>
        <?php
    }

    public function save_hotel_rooms($post_id) {
        if (!isset($_POST['hotel_rooms_nonce_field']) || !wp_verify_nonce($_POST['hotel_rooms_nonce_field'], 'hotel_rooms_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $rooms = [];
        if (isset($_POST['rooms']) && is_array($_POST['rooms'])) {
            foreach ($_POST['rooms'] as $index => $room_data) {
                if (!empty($room_data['name']) && !empty($room_data['base_price'])) {
                    $date_configs = [];
                    if (!empty($room_data['date_configs'])) {
                        $decoded = json_decode(stripslashes($room_data['date_configs']), true);
                        if (is_array($decoded)) {
                            $date_configs = $decoded;
                        }
                    }

                    $rooms[] = [
                        'id' => 'room_' . $index . '_' . time(),
                        'name' => sanitize_text_field($room_data['name']),
                        'capacity' => intval($room_data['capacity'] ?? 1),
                        'base_price' => floatval($room_data['base_price']),
                        'description' => sanitize_textarea_field($room_data['description'] ?? ''),
                        'date_configs' => $date_configs
                    ];
                }
            }
        }

        update_post_meta($post_id, '_hotel_rooms', $rooms);
    }

    /**
     * نمایش لیست اتاق‌ها در صفحه محصول
     */
    public function display_hotel_rooms() {
        global $post;
        if (!$post || get_post_type($post->ID) !== 'product') return;

        $rooms = $this->get_hotel_rooms($post->ID);
        if (empty($rooms)) return;

        ?>
        <div class="hotel-rooms-section" style="margin:40px 0;padding:30px;background:#f8f9fa;border-radius:15px;">
            <h2 style="text-align:center;margin-bottom:30px;color:#333;font-size:28px;">🏨 اتاق‌های موجود</h2>

            <div class="rooms-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
                <?php foreach ($rooms as $room): ?>
                    <?php
                    $min_price = $room['base_price'];
                    // پیدا کردن کمترین قیمت
                    if (!empty($room['date_configs'])) {
                        foreach ($room['date_configs'] as $config) {
                            if (!empty($config['price']) && $config['price'] < $min_price) {
                                $min_price = $config['price'];
                            }
                        }
                    }
                    ?>
                    <div class="room-card" style="background:white;border-radius:12px;padding:20px;box-shadow:0 4px 15px rgba(0,0,0,0.1);transition:transform 0.3s;" data-room-id="<?php echo esc_attr($room['id']); ?>" data-room='<?php echo esc_attr(json_encode($room)); ?>'>
                        <div style="margin-bottom:15px;">
                            <h3 style="margin:0 0 10px 0;color:#667eea;font-size:20px;">🚪 <?php echo esc_html($room['name']); ?></h3>
                            <?php if (!empty($room['description'])): ?>
                                <p style="color:#666;font-size:14px;margin:0 0 10px 0;"><?php echo esc_html($room['description']); ?></p>
                            <?php endif; ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                <span style="color:#999;font-size:13px;">ظرفیت: <?php echo $room['capacity']; ?> اتاق</span>
                            </div>
                        </div>

                        <div style="border-top:2px solid #f0f0f0;padding-top:15px;display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <span style="color:#999;font-size:12px;">قیمت از:</span>
                                <div style="font-size:24px;font-weight:bold;color:#28a745;">
                                    <?php echo number_format($min_price); ?> <span style="font-size:14px;">تومان/شب</span>
                                </div>
                            </div>
                            <button type="button" class="room-reserve-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;padding:12px 25px;border-radius:8px;cursor:pointer;font-weight:bold;font-size:16px;">
                                رزرو
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- تقویم Modal -->
        <div id="hotel-booking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;align-items:center;justify-content:center;">
            <div style="background:white;border-radius:15px;max-width:600px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 10px 50px rgba(0,0,0,0.5);">
                <div style="padding:20px;border-bottom:2px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:15px 15px 0 0;">
                    <h3 style="margin:0;" id="modal-room-title">📅 رزرو اتاق</h3>
                    <button type="button" id="close-booking-modal" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;font-size:18px;">✕</button>
                </div>

                <div style="padding:25px;">
                    <!-- راهنما -->
                    <div id="booking-guide" style="background:#e3f2fd;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;font-size:14px;">
                        <strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>
                    </div>

                    <!-- هدر تقویم -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <button type="button" id="prev-month" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;font-weight:bold;">❮</button>
                        <div id="current-month" style="font-weight:bold;font-size:18px;"></div>
                        <button type="button" id="next-month" style="background:#667eea;color:white;border:none;padding:10px 15px;border-radius:5px;cursor:pointer;font-weight:bold;">❯</button>
                    </div>

                    <!-- روزهای هفته -->
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;">
                        <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                    </div>

                    <!-- روزهای ماه -->
                    <div id="calendar-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;"></div>

                    <!-- اطلاعات انتخاب شده -->
                    <div id="booking-info" style="display:none;background:#f0f8ff;padding:20px;border-radius:10px;margin-top:20px;">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin-bottom:15px;">
                            <div>
                                <small style="color:#666;">تاریخ ورود</small>
                                <div style="font-weight:bold;color:#667eea;" id="selected-checkin">-</div>
                            </div>
                            <div>
                                <small style="color:#666;">تاریخ خروج</small>
                                <div style="font-weight:bold;color:#11998e;" id="selected-checkout">-</div>
                            </div>
                        </div>
                        <div style="margin-bottom:15px;">
                            <strong style="color:#28a745;">✓ تعداد شب‌ها:</strong> <span id="booking-nights">0</span> شب
                        </div>
                        <div style="margin-bottom:15px;">
                            <strong style="color:#28a745;">💰 قیمت کل:</strong> <span id="booking-price">0</span> تومان
                        </div>
                        <div id="price-breakdown" style="font-size:13px;color:#666;max-height:120px;overflow-y:auto;"></div>
                    </div>

                    <div id="availability-warning" style="display:none;background:#dc3545;color:white;padding:15px;border-radius:8px;margin-top:15px;text-align:center;font-weight:bold;">
                        ⚠️ تاریخ‌های انتخابی موجود نیست
                    </div>

                    <!-- دکمه افزودن به سبد -->
                    <button type="button" id="add-to-cart-btn" style="display:none;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:15px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;width:100%;margin-top:20px;">
                        ✓ افزودن به سبد خرید
                    </button>
                </div>
            </div>
        </div>

        <style>
        .room-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .room-reserve-btn:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        #hotel-booking-modal { display: none; }
        #hotel-booking-modal.active { display: flex !important; }
        .calendar-day {
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
            border: 2px solid transparent;
            font-weight: 500;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .calendar-day:hover:not(.disabled):not(.past):not(.empty) {
            background: #e3f2fd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .calendar-day.disabled {
            background: #f5f5f5;
            color: #ccc;
            cursor: not-allowed;
            text-decoration: line-through;
        }
        .calendar-day.past {
            background: #fafafa;
            color: #999;
            cursor: not-allowed;
        }
        .calendar-day.selected-checkin {
            background: linear-gradient(135deg,#667eea,#764ba2);
            color: white;
            font-weight: bold;
            border-color: #667eea;
        }
        .calendar-day.selected-checkout {
            background: linear-gradient(135deg,#11998e,#38ef7d);
            color: white;
            font-weight: bold;
            border-color: #11998e;
        }
        .calendar-day.in-range {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .calendar-day.empty {
            background: transparent;
            cursor: default;
        }
        </style>
        <?php
    }

    public function enqueue_scripts_and_styles() {
        if (!is_product()) return;

        global $post;
        if (!$post) return;

        $rooms = $this->get_hotel_rooms($post->ID);
        if (empty($rooms)) return;

        ?>
        <script>
        jQuery(document).ready(function($) {
            var currentRoom = null;
            var currentYear, currentMonth;
            var selectedCheckIn = null;
            var selectedCheckOut = null;
            var selectingMode = 'checkin';
            var disabledDates = [];

            var persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

            // تبدیل تاریخ
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
            function formatJalaliDate(y, m, d) { return y + '/' + pad(m) + '/' + pad(d); }

            function getTodayJalali() {
                var now = new Date();
                return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            }

            function getDaysInJalaliMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                var breaks = [1, 5, 9, 13, 17, 22, 26, 30];
                var leap = 0;
                for (var i = 0; i < breaks.length; i++) {
                    if ((year % 33) === breaks[i]) { leap = 1; break; }
                }
                return leap ? 30 : 29;
            }

            function getFirstDayOfJalaliMonth(year, month) {
                var greg = jalaliToGregorian(year, month, 1);
                var date = new Date(greg[0], greg[1] - 1, greg[2]);
                var day = date.getDay();
                return (day + 1) % 7;
            }

            function isDateDisabled(dateStr) {
                if (!currentRoom || !currentRoom.date_configs) return false;
                for (var i = 0; i < currentRoom.date_configs.length; i++) {
                    if (currentRoom.date_configs[i].date === dateStr && currentRoom.date_configs[i].is_disabled) {
                        return true;
                    }
                }
                return disabledDates.indexOf(dateStr) !== -1;
            }

            function isDateInPast(year, month, day) {
                var today = getTodayJalali();
                var todayStr = formatJalaliDate(today[0], today[1], today[2]);
                var checkStr = formatJalaliDate(year, month, day);
                return checkStr < todayStr;
            }

            function renderCalendar() {
                var daysInMonth = getDaysInJalaliMonth(currentYear, currentMonth);
                var firstDay = getFirstDayOfJalaliMonth(currentYear, currentMonth);

                $('#current-month').text(persianMonths[currentMonth - 1] + ' ' + currentYear);

                var html = '';
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="calendar-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = formatJalaliDate(currentYear, currentMonth, day);
                    var classes = ['calendar-day'];
                    var disabled = false;

                    if (isDateInPast(currentYear, currentMonth, day)) {
                        classes.push('past');
                        disabled = true;
                    } else if (isDateDisabled(dateStr)) {
                        classes.push('disabled');
                        disabled = true;
                    }

                    if (selectedCheckIn && dateStr === selectedCheckIn) classes.push('selected-checkin');
                    if (selectedCheckOut && dateStr === selectedCheckOut) classes.push('selected-checkout');
                    if (selectedCheckIn && selectedCheckOut && dateStr > selectedCheckIn && dateStr < selectedCheckOut) {
                        classes.push('in-range');
                    }

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '" data-disabled="' + disabled + '">' + day + '</div>';
                }

                $('#calendar-days').html(html);
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

                        $('#booking-nights').text(data.nights);
                        $('#booking-price').text(data.total.toLocaleString('fa-IR'));

                        var breakdown = '';
                        data.breakdown.forEach(function(item) {
                            breakdown += '<div>' + item.date + ': ' + item.price.toLocaleString('fa-IR') + ' تومان</div>';
                        });
                        $('#price-breakdown').html(breakdown);

                        $('#booking-info').show();

                        if (data.available) {
                            $('#availability-warning').hide();
                            $('#add-to-cart-btn').show();
                        } else {
                            $('#availability-warning').show().text('⚠️ ظرفیت کافی در تاریخ‌های انتخابی وجود ندارد');
                            $('#add-to-cart-btn').hide();
                        }
                    }
                });
            }

            // باز کردن مودال رزرو
            $('.room-reserve-btn').on('click', function() {
                var card = $(this).closest('.room-card');
                currentRoom = JSON.parse(card.attr('data-room'));

                $('#modal-room-title').text('📅 رزرو ' + currentRoom.name);

                var today = getTodayJalali();
                currentYear = today[0];
                currentMonth = today[1];
                selectedCheckIn = null;
                selectedCheckOut = null;
                selectingMode = 'checkin';
                disabledDates = [];

                $('#booking-guide').html('<strong>🎯 ابتدا تاریخ ورود را انتخاب کنید</strong>');
                $('#booking-info').hide();
                $('#availability-warning').hide();
                $('#add-to-cart-btn').hide();

                renderCalendar();
                $('#hotel-booking-modal').addClass('active');
            });

            // بستن مودال
            $('#close-booking-modal, #hotel-booking-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#hotel-booking-modal').removeClass('active');
                }
            });

            // کلیک روی روز
            $(document).on('click', '.calendar-day', function() {
                if ($(this).data('disabled') || $(this).hasClass('empty')) return;

                var dateStr = $(this).data('date');

                if (selectingMode === 'checkin') {
                    selectedCheckIn = dateStr;
                    selectedCheckOut = null;
                    selectingMode = 'checkout';
                    $('#selected-checkin').text(dateStr);
                    $('#selected-checkout').text('-');
                    $('#booking-guide').html('<strong>🎯 حالا تاریخ خروج را انتخاب کنید</strong>');
                    $('#booking-info').hide();
                    $('#add-to-cart-btn').hide();
                    renderCalendar();
                } else {
                    if (dateStr <= selectedCheckIn) {
                        alert('تاریخ خروج باید بعد از تاریخ ورود باشد');
                        return;
                    }
                    selectedCheckOut = dateStr;
                    $('#selected-checkout').text(dateStr);
                    renderCalendar();
                    calculatePrice();
                }
            });

            // ماه قبل/بعد
            $('#prev-month').on('click', function() {
                currentMonth--;
                if (currentMonth < 1) { currentMonth = 12; currentYear--; }
                renderCalendar();
            });

            $('#next-month').on('click', function() {
                currentMonth++;
                if (currentMonth > 12) { currentMonth = 1; currentYear++; }
                renderCalendar();
            });

            // افزودن به سبد خرید
            $('#add-to-cart-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('در حال افزودن...');

                $.post(woocommerce_params.ajax_url, {
                    action: 'hotel_add_room_to_cart',
                    product_id: <?php echo get_the_ID(); ?>,
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    nonce: '<?php echo wp_create_nonce("hotel_add_cart"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('✓ اتاق با موفقیت به سبد خرید اضافه شد!');
                        $('#hotel-booking-modal').removeClass('active');
                        // به‌روزرسانی شمارنده سبد خرید
                        $(document.body).trigger('wc_fragment_refresh');
                    } else {
                        alert('خطا: ' + response.data.message);
                    }
                    btn.prop('disabled', false).text('✓ افزودن به سبد خرید');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: چک کردن موجودی اتاق
     */
    public function ajax_check_availability() {
        check_ajax_referer('hotel_booking', 'nonce');

        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);

        // محاسبه قیمت
        $pricing = $this->calculate_room_price($room_data, $check_in, $check_out);

        // چک موجودی (ساده‌شده - شما می‌توانید پیچیده‌تر کنید)
        $availability = ['available' => true];

        wp_send_json_success(array_merge($availability, $pricing));
    }

    /**
     * محاسبه قیمت اتاق
     */
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
            if (!empty($room['date_configs'])) {
                foreach ($room['date_configs'] as $config) {
                    if ($config['date'] === $current_date_str && !empty($config['price'])) {
                        $night_price = floatval($config['price']);
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

    /**
     * AJAX: افزودن اتاق به سبد خرید
     */
    public function ajax_add_room_to_cart() {
        check_ajax_referer('hotel_add_cart', 'nonce');

        $product_id = intval($_POST['product_id']);
        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);

        // اضافه کردن به سبد خرید
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

    /**
     * به‌روزرسانی قیمت در سبد خرید
     */
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

    /**
     * نمایش اطلاعات در سبد خرید
     */
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
        if (isset($cart_item['hotel_room_data']) && isset($cart_item['hotel_check_in']) && isset($cart_item['hotel_check_out'])) {
            $pricing = $this->calculate_room_price(
                $cart_item['hotel_room_data'],
                $cart_item['hotel_check_in'],
                $cart_item['hotel_check_out']
            );
            $item_data[] = ['name' => 'تعداد شب', 'value' => $pricing['nights'] . ' شب'];
        }
        return $item_data;
    }

    /**
     * ذخیره اطلاعات رزرو در سفارش
     */
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

    // توابع کمکی تبدیل تاریخ
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
