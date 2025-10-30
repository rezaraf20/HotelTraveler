<?php
/**
 * RH Session-Based Rates System
 * Version: 1.0
 * 
 * استراتژی:
 * 1. کاربر Check Availability میزنه → SERP API فراخوانی میشه
 * 2. تمام rates در Transient ذخیره میشه (15 دقیقه)
 * 3. صفحه Single Room از Transient میخونه (بدون API call)
 * 4. کاربر Book میکنه → از همون session استفاده میشه
 */

if (!defined('ABSPATH')) exit;

class RH_Session_Rates {
    
    private static $instance = null;
    
    // مدت اعتبار session (15 دقیقه)
    const SESSION_TTL = 900;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX: Check Availability
        add_action('wp_ajax_rh_check_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_rh_check_availability', array($this, 'ajax_check_availability'));
        
        // AJAX: Get Session Rates (برای single room)
        add_action('wp_ajax_rh_get_session_rates', array($this, 'ajax_get_session_rates'));
        add_action('wp_ajax_nopriv_rh_get_session_rates', array($this, 'ajax_get_session_rates'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Cleanup expired sessions (hourly)
        add_action('rh_cleanup_expired_sessions', array($this, 'cleanup_expired_sessions'));
        if (!wp_next_scheduled('rh_cleanup_expired_sessions')) {
            wp_schedule_event(time(), 'hourly', 'rh_cleanup_expired_sessions');
        }
    }
    
    /**
     * Enqueue Scripts
     */
    public function enqueue_scripts() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        global $post;
        if (!$post || !rh_is_ratehawk_hotel($post->ID)) {
            return;
        }
        
        // Session-based rates JS
        wp_enqueue_script(
            'rh-session-rates',
            RH_PLUGIN_URL . 'public/assets/js/session-rates.js',
            array('jquery'),
            RH_VERSION,
            true
        );
        
        $hid = rh_get_hotel_hid($post->ID);
        
        wp_localize_script('rh-session-rates', 'rhSession', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_session'),
            'hotel_id' => $post->ID,
            'hid' => $hid,
            'session_id' => $this->get_or_create_session_id()
        ));
    }
    
    /**
     * AJAX: Check Availability
     * این وقتی کاربر روی دکمه Check Availability کلیک میکنه فراخوانی میشه
     */
    public function ajax_check_availability() {
        try {
            check_ajax_referer('rh_session', 'nonce');
            
            // دریافت پارامترها
            $hotel_id = absint($_POST['hotel_id'] ?? 0);
            $checkin = sanitize_text_field($_POST['checkin'] ?? '');
            $checkout = sanitize_text_field($_POST['checkout'] ?? '');
            $adults = absint($_POST['adults'] ?? 2);
            $children = absint($_POST['children'] ?? 0);
            $rooms = absint($_POST['rooms'] ?? 1);
            
            // Validation
            if (!$hotel_id || !$checkin || !$checkout) {
                throw new Exception('Missing required parameters');
            }
            
            $hid = rh_get_hotel_hid($hotel_id);
            
            if (!$hid) {
                throw new Exception('Hotel not configured for RateHawk');
            }
            
            rh_log('Check Availability Request', [
                'hotel_id' => $hotel_id,
                'hid' => $hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'adults' => $adults
            ], 'info');
            
            // ساخت guests array
            $guests = [];
            for ($i = 0; $i < $rooms; $i++) {
                $guests[] = [
                    'adults' => $adults,
                    'children' => [] // TODO: اگه سن بچه‌ها رو داریم اینجا اضافه کنیم
                ];
            }
            
            // Call SERP API
            $result = rh_api()->search_serp_hotels([
                'hids' => [(int)$hid], // ✅ باید integer باشه
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => $guests,
                'language' => 'en',
                'currency' => 'USD',
                'residency' => 'us' // اضافه کردن residency
            ]);
            
            if ($result['status'] !== 'ok') {
                throw new Exception('API request failed: ' . ($result['error'] ?? 'Unknown error'));
            }
            
            if (!isset($result['data']['hotels'][0]['rates'])) {
                throw new Exception('No rates available for selected dates');
            }
            
            // پردازش rates
            $rates = $this->process_serp_rates($result['data']['hotels'][0]['rates']);
            
            // گروه‌بندی بر اساس room type
            $grouped_rates = $this->group_rates_by_room($rates);
            
            // ساخت session data
            $session_data = [
                'hotel_id' => $hotel_id,
                'hid' => $hid,
                'search_params' => [
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                    'adults' => $adults,
                    'children' => $children,
                    'rooms' => $rooms
                ],
                'rates' => $rates,
                'grouped_rates' => $grouped_rates,
                'raw_response' => $result['data'],
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_TTL)
            ];
            
            // ذخیره در session
            $session_id = $this->get_or_create_session_id();
            $this->save_session($session_id, $session_data);
            
            rh_log('Session created', [
                'session_id' => $session_id,
                'rates_count' => count($rates),
                'grouped_rooms' => count($grouped_rates)
            ], 'info');
            
            // Response
            wp_send_json_success([
                'session_id' => $session_id,
                'rates_count' => count($rates),
                'rooms' => $this->prepare_rooms_for_display($grouped_rates),
                'search_params' => $session_data['search_params'],
                'expires_at' => $session_data['expires_at']
            ]);
            
        } catch (Exception $e) {
            rh_log('Check Availability Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Get Session Rates
     * این توی صفحه Single Room فراخوانی میشه
     */
    public function ajax_get_session_rates() {
        try {
            check_ajax_referer('rh_session', 'nonce');
            
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');
            $room_id = absint($_POST['room_id'] ?? 0);
            
            if (!$session_id) {
                throw new Exception('No session ID provided');
            }
            
            // خواندن session
            $session = $this->get_session($session_id);
            
            if (!$session) {
                throw new Exception('Session expired or not found. Please search again.');
            }
            
            // چک کردن expire
            if (strtotime($session['expires_at']) < time()) {
                $this->delete_session($session_id);
                throw new Exception('Session expired. Please search again.');
            }
            
            // فیلتر rates برای این اتاق
            $room_rates = $this->filter_rates_for_room($session['rates'], $room_id);
            
            if (empty($room_rates)) {
                throw new Exception('No rates available for this room');
            }
            
            // گرفتن metapolicy
            $metapolicy = get_post_meta($session['hotel_id'], '_rh_metapolicy', true);
            if ($metapolicy) {
                $metapolicy = json_decode($metapolicy, true);
            }
            
            wp_send_json_success([
                'rates' => $room_rates,
                'search_params' => $session['search_params'],
                'metapolicy' => $metapolicy ?: [],
                'expires_at' => $session['expires_at']
            ]);
            
        } catch (Exception $e) {
            rh_log('Get Session Rates Error', [
                'error' => $e->getMessage()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * پردازش SERP rates
     */
    private function process_serp_rates($raw_rates) {
    $processed = [];
    
    foreach ($raw_rates as $rate) {
        $payment = $rate['payment_options']['payment_types'][0] ?? null;
        
        if (!$payment) {
            continue;
        }
        
        // ✅ استفاده از show_amount و show_currency_code
        $price_total = floatval($payment['show_amount'] ?? 0);
        $currency = $payment['show_currency_code'] ?? 'USD';
        
        if ($price_total <= 0) {
            continue;
        }
        
        // Meal name
        $meal = $rate['meal'] ?? 'nomeal';
        $meal_names = [
            'nomeal' => 'Room Only',
            'breakfast' => 'Breakfast Included',
            'half-board' => 'Half Board',
            'full-board' => 'Full Board',
            'all-inclusive' => 'All Inclusive',
            'lunch' => 'Lunch Included'
        ];
        $meal_name = $meal_names[$meal] ?? ucfirst(str_replace('-', ' ', $meal));
        
        // Cancellation
        $free_cancellation = $payment['cancellation_penalties']['free_cancellation_before'] ?? null;
        $refundable = !empty($free_cancellation);
        
        // استخراج اطلاعات کامل
        $processed[] = [
            // شناسه‌ها
            'match_hash' => $rate['match_hash'] ?? '',
            
            // اتاق
            'room_name' => $rate['room_data_trans']['main_room_type'] ?? '',
            'room_name_full' => $rate['room_name'] ?? '',
            'bathroom' => $rate['room_data_trans']['bathroom'] ?? null,
            'bedding' => $rate['room_data_trans']['bedding_type'] ?? null,
            
            // قیمت با فرمت جدید (show_amount)
            'price' => [
                'total' => $price_total,
                'currency' => $currency
            ],
            
            // برای compatibility
            'price_total' => $price_total,
            'currency' => $currency,
            
            'amount_charge' => floatval($payment['amount'] ?? $price_total),
            'daily_prices' => $rate['daily_prices'] ?? [],
            
            // Meal
            'meal' => $meal,
            'meal_name' => $meal_name,
            'plan_name' => $meal_name,
            'meal_data' => $rate['meal_data'] ?? null,
            
            // Cancellation
            'refundable' => $refundable,
            'cancellation' => [
                'free_cancellation_before' => $free_cancellation,
                'policies' => $payment['cancellation_penalties']['policies'] ?? [],
                'is_refundable' => $refundable,
                'type' => $refundable ? 'free' : 'non-refundable',
                'text' => $refundable ? 'Free cancellation' : 'Non-refundable'
            ],
            
            // Tax
            'taxes' => $this->extract_taxes($payment),
            'vat_data' => $payment['vat_data'] ?? null,
            
            // سایر
            'amenities' => $rate['amenities_data'] ?? [],
            'allotment' => $rate['allotment'] ?? 0,
            'no_show' => $rate['no_show'] ?? null,
            'rg_ext' => $rate['rg_ext'] ?? null,
            
            // برای booking
            'book_hash' => $rate['match_hash'] ?? '',
            
            // برای نمایش
            'payment_options' => [
                'payment_types' => [$payment]
            ]
        ];
    }
    
    // مرتب‌سازی بر اساس قیمت
    usort($processed, function($a, $b) {
        return $a['price_total'] <=> $b['price_total'];
    });
    
    return $processed;
}
    
    /**
     * گروه‌بندی rates بر اساس room type
     */
    private function group_rates_by_room($rates) {
        $grouped = [];
        
        foreach ($rates as $rate) {
            $room_name = $rate['room_name'];
            
            if (!isset($grouped[$room_name])) {
                $grouped[$room_name] = [
                    'room_name' => $room_name,
                    'room_name_full' => $rate['room_name_full'],
                    'bathroom' => $rate['bathroom'],
                    'bedding' => $rate['bedding'],
                    'amenities' => $rate['amenities'],
                    'rg_ext' => $rate['rg_ext'],
                    'rates' => []
                ];
            }
            
            $grouped[$room_name]['rates'][] = $rate;
        }
        
        return array_values($grouped);
    }
    
    /**
     * آماده‌سازی اتاق‌ها برای نمایش
     */
    private function prepare_rooms_for_display($grouped_rates) {
    $rooms = [];
    
    foreach ($grouped_rates as $room) {
        // پیدا کردن ارزان‌ترین قیمت
        $min_price = min(array_column($room['rates'], 'price'));
        
        $rooms[] = [
            'name' => $room['room_name'],
            'name_full' => $room['room_name_full'],
            'min_price' => $min_price,
            'rates_count' => count($room['rates']),
            'bathroom' => $room['bathroom'],
            'bedding' => $room['bedding'],
            'amenities' => $room['amenities'],
            'rates' => $room['rates']  // ✅ اضافه کردن rates
        ];
    }
    
    return $rooms;
}
    
    /**
     * فیلتر rates برای یک اتاق خاص
     */
    private function filter_rates_for_room($rates, $room_id) {
        $room_name = get_the_title($room_id);
        
        $filtered = array_filter($rates, function($rate) use ($room_name) {
            return stripos($rate['room_name'], $room_name) !== false ||
                   stripos($room_name, $rate['room_name']) !== false;
        });
        
        return array_values($filtered);
    }
    
    /**
     * استخراج taxes
     */
    private function extract_taxes($payment) {
        $tax_data = $payment['tax_data'] ?? null;
        
        if (!$tax_data || empty($tax_data['taxes'])) {
            return [];
        }
        
        $taxes = [];
        
        foreach ($tax_data['taxes'] as $tax) {
            $taxes[] = [
                'name' => $tax['name'] ?? '',
                'amount' => floatval($tax['amount'] ?? 0),
                'currency' => $tax['currency_code'] ?? 'USD',
                'included' => (bool)($tax['included_by_supplier'] ?? false)
            ];
        }
        
        return $taxes;
    }
    
    /**
     * ذخیره session
     */
    private function save_session($session_id, $data) {
        $key = 'rh_session_' . $session_id;
        set_transient($key, $data, self::SESSION_TTL);
        
        // ذخیره در user meta (اگه لاگین باشه)
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), '_rh_active_session', $session_id);
        }
    }
    
    /**
     * خواندن session
     */
    private function get_session($session_id) {
        $key = 'rh_session_' . $session_id;
        return get_transient($key);
    }
    
    /**
     * حذف session
     */
    private function delete_session($session_id) {
        $key = 'rh_session_' . $session_id;
        delete_transient($key);
    }
    
    /**
     * دریافت یا ساخت Session ID
     */
    private function get_or_create_session_id() {
        // اگه کاربر لاگین باشه از user meta بخون
        if (is_user_logged_in()) {
            $session_id = get_user_meta(get_current_user_id(), '_rh_active_session', true);
            if ($session_id && $this->get_session($session_id)) {
                return $session_id;
            }
        }
        
        // یا از cookie بخون
        if (isset($_COOKIE['rh_session_id'])) {
            $session_id = sanitize_text_field($_COOKIE['rh_session_id']);
            if ($this->get_session($session_id)) {
                return $session_id;
            }
        }
        
        // یا یه session جدید بساز
        $session_id = 'rhs_' . uniqid() . '_' . time();
        
        // ذخیره در cookie (15 دقیقه)
        setcookie('rh_session_id', $session_id, time() + self::SESSION_TTL, COOKIEPATH, COOKIE_DOMAIN);
        
        return $session_id;
    }
    
    /**
     * پاکسازی sessions منقضی شده
     */
    public function cleanup_expired_sessions() {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_rh_session_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_rh_session_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        if ($deleted > 0) {
            rh_log('Cleaned up expired sessions', ['count' => $deleted], 'info');
        }
    }
}

// Initialize
RH_Session_Rates::instance();