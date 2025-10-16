<?php
/**
 * Simple Rates Display
 * File: includes/class-rh-simple-rates.php
 * 
 * نمایش ساده قیمت‌ها بدون AJAX پیچیده
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Simple_Rates {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // نمایش قیمت‌ها در صفحه هتل - با AJAX
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Hook به لیست اتاق‌ها
        add_filter('traveler_room_item_after', [$this, 'add_rates_to_room'], 10, 2);
        
        // Shortcode
        add_shortcode('ratehawk_rates', [$this, 'shortcode_rates']);
        
        // AJAX
        add_action('wp_ajax_rh_get_room_rates', [$this, 'ajax_get_room_rates']);
        add_action('wp_ajax_nopriv_rh_get_room_rates', [$this, 'ajax_get_room_rates']);
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            return;
        }
        
        wp_enqueue_script(
            'rh-room-rates',
            RH_PLUGIN_URL . 'public/assets/js/room-rates.js',
            ['jquery'],
            RH_VERSION,
            true
        );
        
        wp_localize_script('rh-room-rates', 'rhRoomRates', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_room_rates'),
            'hotel_id' => get_the_ID(),
            'hid' => rh_get_hotel_hid(get_the_ID()),
        ]);
        
        $this->add_inline_css();
    }
    
    /**
     * Add rates to each room
     */
    public function add_rates_to_room($room_id, $room_data) {
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            return;
        }
        
        // Get search params from URL
        $checkin = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : '';
        $checkout = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : '';
        $adults = isset($_GET['adults']) ? absint($_GET['adults']) : 2;
        
        ?>
        <div class="rh-room-rates-container" 
             data-room-id="<?php echo esc_attr($room_id); ?>"
             data-checkin="<?php echo esc_attr($checkin); ?>"
             data-checkout="<?php echo esc_attr($checkout); ?>"
             data-adults="<?php echo esc_attr($adults); ?>">
            
            <?php if ($checkin && $checkout): ?>
                <div class="rh-rates-loading">
                    <span class="spinner"></span> Loading rates...
                </div>
            <?php else: ?>
                <div class="rh-rates-notice">
                    <p>Select dates above to see rates</p>
                </div>
            <?php endif; ?>
            
        </div>
        <?php
    }
    
    /**
     * AJAX: Get room rates
     */
    public function ajax_get_room_rates() {
        check_ajax_referer('rh_room_rates', 'nonce');
        
        $hotel_id = absint($_POST['hotel_id'] ?? 0);
        $room_id = absint($_POST['room_id'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        
        if (!$hotel_id || !$checkin || !$checkout) {
            wp_send_json_error('Invalid parameters');
        }
        
        $hid = rh_get_hotel_hid($hotel_id);
        if (!$hid) {
            wp_send_json_error('Hotel not found');
        }
        
        // Cache
        $cache_key = "room_rates_{$hid}_{$room_id}_{$checkin}_{$checkout}_{$adults}";
        $cached = rh_cache()->get($cache_key, 'hotel_page');
        
        if ($cached !== false) {
            wp_send_json_success($cached);
        }
        
        try {
            $result = rh_api()->search_by_hotel_ids([
                'ids' => [(string)$hid],
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => [['adults' => $adults, 'children' => []]],
                'residency' => 'us',
                'language' => 'en',
                'currency' => 'USD'
            ]);
            
            if (!isset($result['data']['hotels'][0]['rates'])) {
                wp_send_json_error('No rates available');
            }
            
            // Filter rates for this room
            $room_rates = $this->filter_rates_for_room($result['data']['hotels'][0]['rates'], $room_id);
            
            if (empty($room_rates)) {
                wp_send_json_error('No rates for this room');
            }
            
            rh_cache()->set($cache_key, $room_rates, 300, 'hotel_page');
            
            wp_send_json_success($room_rates);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Filter rates for specific room
     */
    private function filter_rates_for_room($all_rates, $room_id) {
        // Get room name from Traveler
        $room_name = get_the_title($room_id);
        
        $filtered = [];
        
        foreach ($all_rates as $rate) {
            $rate_room_name = $rate['room_data_trans']['main_room_type'] ?? 
                             $rate['room_name'] ?? '';
            
            // Match by name (loose comparison)
            if (stripos($rate_room_name, $room_name) !== false || 
                stripos($room_name, $rate_room_name) !== false) {
                
                $meal = $rate['meal'] ?? 'nomeal';
                $payment = $rate['payment_options']['payment_types'][0] ?? [];
                $amount = $payment['show_amount'] ?? 0;
                $currency = $payment['currency_code'] ?? 'USD';
                
                $cancellation = $this->get_cancellation_info($rate);
                
                $filtered[] = [
                    'meal' => $this->get_meal_name($meal),
                    'price' => [
                        'amount' => $amount,
                        'currency' => $currency,
                        'formatted' => rh_format_price($amount, $currency)
                    ],
                    'cancellation' => $cancellation,
                    'book_hash' => $rate['book_hash'] ?? ''
                ];
            }
        }
        
        // Sort by price
        usort($filtered, function($a, $b) {
            return $a['price']['amount'] <=> $b['price']['amount'];
        });
        
        return $filtered;
    }
    
    /**
     * اضافه کردن قیمت‌ها به محتوا
     */
    public function add_rates_to_content($content) {
        // فقط برای st_hotel در صفحه single
        if (!is_singular('st_hotel')) {
            return $content;
        }
        
        // فقط برای هتل‌های Ratehawk
        $post_id = get_the_ID();
        if (!$post_id || !rh_is_ratehawk_hotel($post_id)) {
            return $content;
        }
        
        // جلوگیری از تکرار در excerpt و preview
        if (!is_main_query() || is_feed() || is_preview()) {
            return $content;
        }
        
        // دریافت قیمت‌ها
        $rates_html = $this->get_rates_html();
        
        // اضافه کردن به انتهای محتوا
        return $content . $rates_html;
    }
    
    /**
     * دریافت HTML قیمت‌ها
     */
    private function get_rates_html() {
        $hotel_id = get_the_ID();
        $hid = rh_get_hotel_hid($hotel_id);
        
        if (!$hid) {
            return '';
        }
        
        // پارامترهای جستجو
        $checkin = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : date('Y-m-d', strtotime('+30 days'));
        $checkout = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : date('Y-m-d', strtotime('+31 days'));
        $adults = isset($_GET['adults']) ? absint($_GET['adults']) : 2;
        
        ob_start();
        ?>
        
        <div class="rh-rates-section">
            <div class="rh-rates-header">
                <h2>🏨 Check Rates & Availability</h2>
                <p>Live rates from Ratehawk</p>
            </div>
            
            <!-- فرم جستجو -->
            <div class="rh-search-box">
                <form method="get" action="<?php echo get_permalink(); ?>">
                    <div class="rh-form-row">
                        <div>
                            <label>Check-in:</label>
                            <input type="date" name="checkin" value="<?php echo esc_attr($checkin); ?>" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div>
                            <label>Check-out:</label>
                            <input type="date" name="checkout" value="<?php echo esc_attr($checkout); ?>" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>
                        
                        <div>
                            <label>Adults:</label>
                            <select name="adults">
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected($adults, $i); ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div>
                            <button type="submit" class="rh-btn-search">🔍 Search</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php
            // اگر پارامترهای جستجو هست، نمایش قیمت‌ها
            if (isset($_GET['checkin']) && isset($_GET['checkout'])) {
                $this->display_rates($hid, $checkin, $checkout, $adults);
            } else {
                echo '<div class="rh-info-box">';
                echo '<p>👆 Select dates to see available rates</p>';
                echo '</div>';
            }
            ?>
            
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * نمایش قیمت‌ها
     */
    private function display_rates($hid, $checkin, $checkout, $adults) {
        echo '<div class="rh-rates-list">';
        
        try {
            // Validate dates
            if (strtotime($checkin) < strtotime('today') || strtotime($checkout) <= strtotime($checkin)) {
                echo '<div class="rh-error">';
                echo '<p>❌ Invalid dates. Please select valid check-in and check-out dates.</p>';
                echo '</div>';
                echo '</div>';
                return;
            }
            
            // کش چک
            $cache_key = "rates_{$hid}_{$checkin}_{$checkout}_{$adults}";
            $rates = rh_cache()->get($cache_key, 'hotel_page');
            
            if ($rates === false) {
                echo '<div class="rh-loading">⏳ Loading rates...</div>';
                
                // 🔥 FIX: استفاده از search/serp/hotels بجای search/hp
                $result = rh_api()->search_by_hotel_ids([
                    'ids' => [(string)$hid],
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                    'guests' => [[
                        'adults' => (int)$adults,
                        'children' => []
                    ]],
                    'residency' => 'us',
                    'language' => 'en',
                    'currency' => 'USD'
                ]);
                
                rh_log('Search Hotels API Response', [
                    'hid' => $hid,
                    'status' => $result['status'] ?? 'unknown',
                    'has_hotels' => isset($result['data']['hotels']),
                    'hotels_count' => isset($result['data']['hotels']) ? count($result['data']['hotels']) : 0
                ], 'debug');
                
                if (isset($result['data']['hotels'][0]['rates'])) {
                    $rates = $this->process_rates($result['data']['hotels'][0]['rates']);
                    rh_cache()->set($cache_key, $rates, 300, 'hotel_page');
                } else {
                    $rates = [];
                    rh_log('No rates in response', [
                        'hid' => $hid,
                        'checkin' => $checkin,
                        'checkout' => $checkout
                    ], 'error');
                }
            }
            
            if (empty($rates)) {
                echo '<div class="rh-no-rates">';
                echo '<p>😞 No rates available for selected dates.</p>';
                echo '<p>Please try different dates or contact us.</p>';
                echo '</div>';
            } else {
                echo '<h3>✅ Found ' . count($rates) . ' Available Rates:</h3>';
                
                foreach ($rates as $rate) {
                    $this->display_single_rate($rate);
                }
            }
            
        } catch (Exception $e) {
            echo '<div class="rh-error">';
            echo '<p>❌ Error: ' . esc_html($e->getMessage()) . '</p>';
            echo '<p>Please try again later or contact support.</p>';
            echo '</div>';
            
            rh_log('Error displaying rates', [
                'hid' => $hid,
                'error' => $e->getMessage()
            ], 'error');
        }
        
        echo '</div>';
    }
    
    /**
     * پردازش rates
     */
    private function process_rates($api_rates) {
        $rates = [];
        
        foreach ($api_rates as $rate) {
            $room_name = $rate['room_data_trans']['main_room_type'] ?? 
                        $rate['room_name'] ?? 
                        'Standard Room';
            
            $meal = $rate['meal'] ?? 'nomeal';
            $payment = $rate['payment_options']['payment_types'][0] ?? [];
            $amount = $payment['show_amount'] ?? 0;
            $currency = $payment['currency_code'] ?? 'USD';
            
            // کنسلی
            $policies = $payment['cancellation_penalties']['policies'] ?? [];
            $is_refundable = false;
            $cancel_text = 'Non-refundable';
            
            foreach ($policies as $policy) {
                if (($policy['amount_charge']['amount'] ?? 0) == 0) {
                    $is_refundable = true;
                    $cancel_text = 'Free cancellation until ' . date('M j', strtotime($policy['end_at']));
                    break;
                }
            }
            
            $rates[] = [
                'room_name' => $room_name,
                'meal' => $this->get_meal_name($meal),
                'amount' => $amount,
                'currency' => $currency,
                'is_refundable' => $is_refundable,
                'cancel_text' => $cancel_text,
                'book_hash' => $rate['book_hash'] ?? ''
            ];
        }
        
        // مرتب‌سازی بر اساس قیمت
        usort($rates, function($a, $b) {
            return $a['amount'] <=> $b['amount'];
        });
        
        return $rates;
    }
    
    /**
     * نمایش یک rate
     */
    private function display_single_rate($rate) {
        $price = rh_format_price($rate['amount'], $rate['currency']);
        $refund_class = $rate['is_refundable'] ? 'refundable' : 'non-refundable';
        ?>
        
        <div class="rh-rate-card">
            <div class="rh-rate-info">
                <h4><?php echo esc_html($rate['room_name']); ?></h4>
                <span class="rh-meal">🍴 <?php echo esc_html($rate['meal']); ?></span>
                <span class="rh-cancel <?php echo $refund_class; ?>">
                    <?php echo $rate['is_refundable'] ? '✅' : '❌'; ?> 
                    <?php echo esc_html($rate['cancel_text']); ?>
                </span>
            </div>
            
            <div class="rh-rate-price">
                <div class="rh-price-amount"><?php echo $price; ?></div>
                <div class="rh-price-label">Total Price</div>
            </div>
            
            <div class="rh-rate-action">
                <a href="#book-now" class="rh-btn-book" data-hash="<?php echo esc_attr($rate['book_hash']); ?>">
                    Book Now
                </a>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * نام meal
     */
    private function get_meal_name($meal) {
        $meals = [
            'nomeal' => 'Room Only',
            'breakfast' => 'Breakfast Included',
            'half-board' => 'Half Board',
            'full-board' => 'Full Board',
            'all-inclusive' => 'All Inclusive',
        ];
        
        return $meals[$meal] ?? ucfirst(str_replace('-', ' ', $meal));
    }
    
    /**
     * CSS ساده
     */
    public function add_inline_css() {
        if (!is_singular('st_hotel') || !rh_is_ratehawk_hotel(get_the_ID())) {
            return;
        }
        ?>
        <style>
        .rh-rates-section {
            margin: 40px 0;
            padding: 30px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .rh-rates-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
        }
        .rh-rates-header h2 {
            margin: 0 0 10px;
        }
        .rh-search-box {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .rh-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        .rh-form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .rh-form-row input,
        .rh-form-row select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .rh-btn-search {
            width: 100%;
            padding: 11px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }
        .rh-btn-search:hover {
            background: #5568d3;
        }
        .rh-rate-card {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 20px;
            padding: 20px;
            margin: 15px 0;
            border: 2px solid #e5e5e5;
            border-radius: 8px;
            align-items: center;
        }
        .rh-rate-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102,126,234,0.2);
        }
        .rh-rate-info h4 {
            margin: 0 0 10px;
        }
        .rh-meal {
            display: inline-block;
            padding: 5px 10px;
            background: #e7f3ff;
            border-radius: 4px;
            margin: 5px 5px 5px 0;
            font-size: 13px;
        }
        .rh-cancel {
            display: block;
            margin-top: 8px;
            font-size: 14px;
        }
        .rh-cancel.refundable {
            color: #28a745;
            font-weight: 600;
        }
        .rh-cancel.non-refundable {
            color: #dc3545;
        }
        .rh-price-amount {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        .rh-price-label {
            font-size: 12px;
            color: #999;
        }
        .rh-btn-book {
            padding: 12px 30px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            white-space: nowrap;
        }
        .rh-btn-book:hover {
            background: #218838;
        }
        .rh-info-box,
        .rh-no-rates,
        .rh-error {
            text-align: center;
            padding: 40px 20px;
        }
        .rh-loading {
            text-align: center;
            padding: 40px;
            font-size: 18px;
            color: #667eea;
        }
        @media (max-width: 768px) {
            .rh-rate-card {
                grid-template-columns: 1fr;
                text-align: center;
            }
            .rh-btn-book {
                width: 100%;
            }
        }
        </style>
        <?php
    }
}