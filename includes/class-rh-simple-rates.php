<?php
/**
 * Simple Rates Display - FIXED VERSION
 * File: includes/class-rh-simple-rates.php
 * 
 * JavaScript خودش همه کار رو انجام میده!
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
        error_log('========================================');
        error_log('[RH Simple Rates] FIXED VERSION - Class initialized at ' . current_time('mysql'));
        error_log('========================================');
        
        // فقط JS enqueue میکنیم
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX handler
        add_action('wp_ajax_rh_get_room_rates', [$this, 'ajax_get_room_rates']);
        add_action('wp_ajax_nopriv_rh_get_room_rates', [$this, 'ajax_get_room_rates']);
        
        // AJAX handler برای همه rates هتل
        add_action('wp_ajax_rh_get_hotel_rates', [$this, 'ajax_get_hotel_rates']);
        add_action('wp_ajax_nopriv_rh_get_hotel_rates', [$this, 'ajax_get_hotel_rates']);
        
        error_log('[RH Simple Rates] AJAX actions registered');
        
        // Debug footer
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('wp_footer', [$this, 'debug_footer'], 999);
        }
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        if (!is_singular('st_hotel')) {
            error_log('[RH Simple Rates] Not a hotel page, skipping assets');
            return;
        }
        
        error_log('[RH Simple Rates] Checking if RH hotel for post: ' . get_the_ID());
        
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            error_log('[RH Simple Rates] Not a RH hotel, skipping assets');
            return;
        }
        
        error_log('[RH Simple Rates] ✅ Enqueuing assets for RH hotel: ' . get_the_ID());
        
        // Enqueue JS
        wp_enqueue_script(
            'rh-room-rates',
            RH_PLUGIN_URL . 'public/assets/js/room-rates.js',
            ['jquery'],
            RH_VERSION . '-' . time(), // Cache busting
            true
        );
        
        // Localize
        wp_localize_script('rh-room-rates', 'rhRoomRates', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_room_rates'),
            'hotel_id' => get_the_ID(),
            'hid' => rh_get_hotel_hid(get_the_ID()),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);
        
        error_log('[RH Simple Rates] Script enqueued with HID: ' . rh_get_hotel_hid(get_the_ID()));
        
        // Inline CSS
        $this->add_inline_css();
    }
    
    /**
     * Debug footer
     */
    public function debug_footer() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        $is_rh = rh_is_ratehawk_hotel(get_the_ID());
        $hid = rh_get_hotel_hid(get_the_ID());
        
        ?>
        <div id="rh-debug-panel" style="position:fixed;bottom:10px;right:10px;background:#000;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:12px;z-index:99999;max-width:400px;">
            <div style="color:#fff;font-weight:bold;margin-bottom:10px;">🔥 RH Simple Rates Debug</div>
            <div>Post ID: <?php echo get_the_ID(); ?></div>
            <div>Is RH Hotel: <span style="color:<?php echo $is_rh ? '#0f0' : '#f00'; ?>"><?php echo $is_rh ? 'YES ✅' : 'NO ❌'; ?></span></div>
            <div>RH HID: <span style="color:<?php echo $hid ? '#0f0' : '#f00'; ?>"><?php echo $hid ?: 'EMPTY ❌'; ?></span></div>
            <div id="rh-containers-count" style="margin-top:10px;">Containers: <span id="rh-containers-counter" style="color:#ff0;">0</span></div>
            <div style="margin-top:10px;">
                <button onclick="document.getElementById('rh-debug-panel').style.display='none'" style="background:#f00;color:#fff;border:none;padding:5px 10px;cursor:pointer;border-radius:4px;">Close</button>
                <button onclick="rhDebugRefresh()" style="background:#0f0;color:#000;border:none;padding:5px 10px;cursor:pointer;border-radius:4px;margin-left:5px;">Refresh</button>
            </div>
        </div>
        <script>
        console.log('%c🔥 RH Simple Rates Debug Panel Active', 'background:#000;color:#0f0;font-size:16px;padding:10px;');
        
        function rhDebugRefresh() {
            var containers = jQuery('.rh-room-rates-container').length;
            jQuery('#rh-containers-counter').text(containers);
            
            console.log('🔥 Debug Refresh:');
            console.log('  - Containers:', containers);
            console.log('  - All containers:', jQuery('.rh-room-rates-container'));
        }
        
        // Auto refresh every 2 seconds
        setInterval(rhDebugRefresh, 2000);
        
        // Initial check
        jQuery(document).ready(function() {
            setTimeout(rhDebugRefresh, 1000);
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Get room rates
     */
    public function ajax_get_room_rates() {
        error_log('🔥 [RH Simple Rates] AJAX CALLED: rh_get_room_rates');
        error_log('[RH Simple Rates] POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('rh_room_rates', 'nonce');
        
        $hotel_id = absint($_POST['hotel_id'] ?? 0);
        $room_id = absint($_POST['room_id'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        
        error_log('[RH Simple Rates] Params: hotel=' . $hotel_id . ', room=' . $room_id . ', checkin=' . $checkin);
        
        if (!$hotel_id || !$checkin || !$checkout) {
            error_log('[RH Simple Rates] ❌ Invalid parameters');
            wp_send_json_error('Invalid parameters');
        }
        
        $hid = rh_get_hotel_hid($hotel_id);
        error_log('[RH Simple Rates] HID: ' . ($hid ?: 'NOT FOUND'));
        
        if (!$hid) {
            error_log('[RH Simple Rates] ❌ Hotel HID not found');
            wp_send_json_error('Hotel not found');
        }
        
        // Cache
        $cache_key = "room_rates_{$hid}_{$room_id}_{$checkin}_{$checkout}_{$adults}";
        $cached = rh_cache()->get($cache_key, 'hotel_page');
        
        if ($cached !== false) {
            error_log('[RH Simple Rates] ✅ Returning cached data');
            wp_send_json_success($cached);
        }
        
        try {
            error_log('[RH Simple Rates] 🌐 Calling HP API...');
            
            $result = rh_api()->get_hotel_page([
                'id' => (string)$hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => [['adults' => $adults, 'children' => []]],
                'residency' => 'us',
                'language' => 'en',
                'currency' => 'USD'
            ]);
            
            error_log('[RH Simple Rates] API Response: ' . ($result['status'] ?? 'unknown'));
            
            if (!isset($result['data']['hotels'][0]['rates'])) {
                error_log('[RH Simple Rates] ❌ No rates in response');
                wp_send_json_error('No rates available');
            }
            
            $all_rates = $result['data']['hotels'][0]['rates'];
            error_log('[RH Simple Rates] Found ' . count($all_rates) . ' total rates');
            
            // Filter rates for this room
            $room_rates = $this->filter_rates_for_room($all_rates, $room_id);
            
            error_log('[RH Simple Rates] Filtered to ' . count($room_rates) . ' rates for room ' . $room_id);
            
            if (empty($room_rates)) {
                error_log('[RH Simple Rates] ⚠️ No rates matched for this room');
                // اگه هیچ rate ای match نشد، همه rates رو برگردون
                $room_rates = $this->format_all_rates($all_rates);
                error_log('[RH Simple Rates] Returning all ' . count($room_rates) . ' rates instead');
            }
            
            rh_cache()->set($cache_key, $room_rates, 300, 'hotel_page');
            
            error_log('[RH Simple Rates] ✅ Success! Returning ' . count($room_rates) . ' rates');
            wp_send_json_success($room_rates);
            
        } catch (Exception $e) {
            error_log('[RH Simple Rates] ❌ Exception: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Get all hotel rates (برای همه اتاق‌ها یکجا)
     */
    public function ajax_get_hotel_rates() {
        error_log('🔥 [RH Simple Rates] AJAX CALLED: rh_get_hotel_rates');
        error_log('[RH Simple Rates] POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('rh_room_rates', 'nonce');
        
        $hotel_id = absint($_POST['hotel_id'] ?? 0);
        $hid = absint($_POST['hid'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        
        error_log('[RH Simple Rates] Params: hotel=' . $hotel_id . ', hid=' . $hid . ', checkin=' . $checkin);
        
        if (!$hotel_id || !$hid || !$checkin || !$checkout) {
            error_log('[RH Simple Rates] ❌ Invalid parameters');
            wp_send_json_error('Invalid parameters');
        }
        
        // Cache
        $cache_key = "hotel_rates_{$hid}_{$checkin}_{$checkout}_{$adults}";
        $cached = rh_cache()->get($cache_key, 'hotel_page');
        
        if ($cached !== false) {
            error_log('[RH Simple Rates] ✅ Returning cached hotel rates');
            wp_send_json_success($cached);
        }
        
        try {
            error_log('[RH Simple Rates] 🌐 Calling HP API for all rates...');
            
            $result = rh_api()->get_hotel_page([
                'id' => (string)$hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => [['adults' => $adults, 'children' => []]],
                'residency' => 'us',
                'language' => 'en',
                'currency' => 'USD'
            ]);
            
            error_log('[RH Simple Rates] API Response: ' . ($result['status'] ?? 'unknown'));
            
            if (!isset($result['data']['hotels'][0]['rates'])) {
                error_log('[RH Simple Rates] ❌ No rates in response');
                wp_send_json_error('No rates available');
            }
            
            $all_rates = $result['data']['hotels'][0]['rates'];
            error_log('[RH Simple Rates] Found ' . count($all_rates) . ' total rates');
            
            // گرفتن metapolicy از hotel post
            $metapolicy = get_post_meta($hotel_id, '_rh_metapolicy', true);
            if ($metapolicy) {
                $metapolicy = json_decode($metapolicy, true);
            }
            
            $response_data = [
                'rates' => $all_rates,
                'metapolicy' => $metapolicy ?: []
            ];
            
            // Cache
            rh_cache()->set($cache_key, $response_data, 300, 'hotel_page');
            
            error_log('[RH Simple Rates] ✅ Success! Returning ' . count($all_rates) . ' rates with metapolicy');
            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            error_log('[RH Simple Rates] ❌ Exception: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Filter rates for specific room
     */
    private function filter_rates_for_room($all_rates, $room_id) {
        $room_name = get_the_title($room_id);
        
        error_log('[RH Simple Rates] Filtering rates for room: "' . $room_name . '"');
        
        $filtered = [];
        
        foreach ($all_rates as $rate) {
            $rate_room_name = $rate['room_data_trans']['main_room_type'] ?? 
                             $rate['room_name'] ?? '';
            
            if (stripos($rate_room_name, $room_name) !== false || 
                stripos($room_name, $rate_room_name) !== false) {
                
                $filtered[] = $this->format_rate($rate);
            }
        }
        
        usort($filtered, function($a, $b) {
            return $a['price']['amount'] <=> $b['price']['amount'];
        });
        
        return $filtered;
    }
    
    /**
     * Format all rates (اگه filter نتونست match کنه)
     */
    private function format_all_rates($all_rates) {
        $formatted = [];
        
        foreach ($all_rates as $rate) {
            $formatted[] = $this->format_rate($rate);
        }
        
        usort($formatted, function($a, $b) {
            return $a['price']['amount'] <=> $b['price']['amount'];
        });
        
        return array_slice($formatted, 0, 5); // فقط 5 تا ارزون‌ترین
    }
    
    /**
     * Format a single rate
     */
    private function format_rate($rate) {
        $meal = $rate['meal'] ?? 'nomeal';
        $payment = $rate['payment_options']['payment_types'][0] ?? [];
        $amount = $payment['show_amount'] ?? 0;
        $currency = $payment['currency_code'] ?? 'USD';
        
        $cancellation = $this->get_cancellation_info($rate);
        
        return [
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
    
    /**
     * Get cancellation info
     */
    private function get_cancellation_info($rate) {
        $policies = $rate['payment_options']['payment_types'][0]['cancellation_penalties']['policies'] ?? [];
        
        if (empty($policies)) {
            return [
                'is_refundable' => false,
                'text' => 'Non-refundable',
                'class' => 'non-refundable'
            ];
        }
        
        foreach ($policies as $policy) {
            if (($policy['amount_charge']['amount'] ?? 0) == 0) {
                $deadline = $policy['end_at'] ?? '';
                return [
                    'is_refundable' => true,
                    'text' => 'Free cancellation until ' . date('M j', strtotime($deadline)),
                    'class' => 'refundable'
                ];
            }
        }
        
        return [
            'is_refundable' => false,
            'text' => 'Cancellation with fee',
            'class' => 'paid'
        ];
    }
    
    /**
     * Get meal name
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
     * Inline CSS
     */
    public function add_inline_css() {
        ?>
        <style>
        .rh-room-rates-container {
            margin: 15px 0;
            padding: 20px;
            background: #f0f8ff;
            border-radius: 8px;
            border: 2px solid #667eea;
        }
        .rh-room-rates-container h4 {
            margin: 0 0 15px;
            color: #667eea;
            font-size: 18px;
        }
        </style>
        <?php
    }
}