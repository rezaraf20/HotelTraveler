<?php
/**
 * Ratehawk Prebook Handler
 * 
 * مدیریت فرآیند Prebook:
 * 1. کلیک روی دکمه Book Now
 * 2. فراخوانی API Prebook
 * 3. نمایش تایمر 15 دقیقه‌ای
 * 4. بررسی تغییر قیمت
 * 5. هدایت به صفحه پرداخت
 */

if (!defined('ABSPATH')) exit;

class RH_Prebook {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_rh_start_prebook', array($this, 'ajax_start_prebook'));
        add_action('wp_ajax_nopriv_rh_start_prebook', array($this, 'ajax_start_prebook'));
        
        add_action('wp_ajax_rh_check_prebook_status', array($this, 'ajax_check_prebook_status'));
        add_action('wp_ajax_nopriv_rh_check_prebook_status', array($this, 'ajax_check_prebook_status'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Cleanup expired prebooks (every hour)
        add_action('rh_cleanup_expired_prebooks', array($this, 'cleanup_expired_prebooks'));
        if (!wp_next_scheduled('rh_cleanup_expired_prebooks')) {
            wp_schedule_event(time(), 'hourly', 'rh_cleanup_expired_prebooks');
        }
    }
    
    /**
     * اضافه کردن اسکریپت‌ها
     */
    public function enqueue_scripts() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        wp_enqueue_script(
            'rh-prebook',
            RH_PLUGIN_URL . 'public/assets/js/prebook.js',
            array('jquery'),
            RH_VERSION,
            true
        );
        
        // تشخیص URL checkout بر اساس قالب Traveler
        $checkout_url = home_url('/'); // پیش‌فرض
        
        // اگر Traveler دارد صفحه checkout خاص خودش را داشته باشد
        if (function_exists('st_get_option')) {
            $checkout_page = st_get_option('page_checkout');
            if ($checkout_page) {
                $checkout_url = get_permalink($checkout_page);
            }
        }
        
        // اگر WooCommerce نصب است
        if (function_exists('wc_get_checkout_url')) {
            $checkout_url = wc_get_checkout_url();
        }
        
        wp_localize_script('rh-prebook', 'rhPrebook', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_prebook'),
            'checkoutUrl' => $checkout_url,
            'strings' => array(
                'processing' => __('Processing...', 'ratehawk-traveler'),
                'priceChanged' => __('Price has changed!', 'ratehawk-traveler'),
                'priceIncreased' => __('Price increased by', 'ratehawk-traveler'),
                'priceDecreased' => __('Price decreased by', 'ratehawk-traveler'),
                'timeRemaining' => __('Time remaining:', 'ratehawk-traveler'),
                'expired' => __('Booking session expired', 'ratehawk-traveler'),
                'error' => __('An error occurred', 'ratehawk-traveler'),
                'tryAgain' => __('Please try again', 'ratehawk-traveler')
            )
        ));
        
        wp_enqueue_style(
            'rh-prebook',
            RH_PLUGIN_URL . 'public/assets/css/prebook.css',
            array(),
            RH_VERSION
        );
    }
    
    /**
     * AJAX: شروع Prebook
     */
    public function ajax_start_prebook() {
        try {
            // بررسی nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rh_prebook')) {
                throw new Exception('Invalid security token');
            }
            
            // دریافت پارامترها
            $book_hash = sanitize_text_field($_POST['book_hash'] ?? '');
            $original_price = floatval($_POST['price'] ?? 0);
            $currency = sanitize_text_field($_POST['currency'] ?? 'USD');
            
            if (empty($book_hash)) {
                throw new Exception('Missing book hash');
            }
            
            rh_log('Starting prebook', [
                'book_hash' => $book_hash,
                'original_price' => $original_price,
                'currency' => $currency,
                'user_id' => get_current_user_id()
            ], 'info');
            
            // دریافت tolerance از تنظیمات
            $price_tolerance = absint(get_option('rh_price_tolerance', 10));
            
            // فراخوانی API Prebook
            $result = rh_api()->prebook($book_hash, $price_tolerance);
            
            rh_log('Prebook API response', [
                'status' => $result['status'] ?? 'unknown',
                'has_data' => isset($result['data'])
            ], 'debug');
            
            if ($result['status'] !== 'ok' || !isset($result['data'])) {
                throw new Exception('Prebook failed: ' . ($result['error'] ?? 'Unknown error'));
            }
            
            $prebook_data = $result['data'];
            
            // بررسی تغییر قیمت
            $new_price = floatval($prebook_data['payment_options']['payment_types'][0]['show_amount'] ?? 0);
            $price_changed = false;
            $price_change_percent = 0;
            
            if ($new_price > 0 && abs($new_price - $original_price) > 0.01) {
                $price_changed = true;
                $price_change_percent = (($new_price - $original_price) / $original_price) * 100;
            }
            
            // ذخیره در session/transient
            $prebook_id = $this->save_prebook_session([
                'book_hash' => $book_hash,
                'prebook_data' => $prebook_data,
                'original_price' => $original_price,
                'new_price' => $new_price,
                'currency' => $currency,
                'price_changed' => $price_changed,
                'price_change_percent' => $price_change_percent,
                'created_at' => time(),
                'expires_at' => time() + (15 * 60), // 15 minutes
                'user_id' => get_current_user_id()
            ]);
            
            rh_log('Prebook session created', [
                'prebook_id' => $prebook_id,
                'expires_at' => date('Y-m-d H:i:s', time() + (15 * 60)),
                'price_changed' => $price_changed,
                'new_price' => $new_price
            ], 'info');
            
            // آماده‌سازی response
            $response_data = [
                'prebook_id' => $prebook_id,
                'book_hash' => $book_hash,
                'original_price' => $original_price,
                'new_price' => $new_price,
                'currency' => $currency,
                'price_changed' => $price_changed,
                'price_change_percent' => round($price_change_percent, 2),
                'expires_at' => time() + (15 * 60),
                'room_name' => $prebook_data['room_data_trans']['main_room_type'] ?? 'Room',
                'hotel_name' => $prebook_data['hotel']['name'] ?? 'Hotel',
                'cancellation_info' => $this->extract_cancellation_info($prebook_data)
            ];
            
            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            rh_log('Prebook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * AJAX: بررسی وضعیت Prebook
     */
    public function ajax_check_prebook_status() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rh_prebook')) {
                throw new Exception('Invalid security token');
            }
            
            $prebook_id = sanitize_text_field($_POST['prebook_id'] ?? '');
            
            if (empty($prebook_id)) {
                throw new Exception('Missing prebook ID');
            }
            
            $session = $this->get_prebook_session($prebook_id);
            
            if (!$session) {
                throw new Exception('Prebook session not found or expired');
            }
            
            $time_remaining = $session['expires_at'] - time();
            
            if ($time_remaining <= 0) {
                $this->delete_prebook_session($prebook_id);
                throw new Exception('Prebook session expired');
            }
            
            wp_send_json_success([
                'time_remaining' => $time_remaining,
                'expires_at' => $session['expires_at']
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * ذخیره session prebook
     */
    private function save_prebook_session($data) {
        $prebook_id = 'prebook_' . uniqid() . '_' . time();
        
        // ذخیره در transient (15 minutes + 5 minutes buffer)
        set_transient($prebook_id, $data, 20 * 60);
        
        // اگر کاربر لاگین است، ذخیره در user meta
        if ($data['user_id'] > 0) {
            update_user_meta($data['user_id'], '_rh_active_prebook', $prebook_id);
        }
        
        return $prebook_id;
    }
    
    /**
     * دریافت session prebook
     */
    public function get_prebook_session($prebook_id) {
        return get_transient($prebook_id);
    }
    
    /**
     * حذف session prebook
     */
    private function delete_prebook_session($prebook_id) {
        delete_transient($prebook_id);
        
        // حذف از user meta اگر وجود دارد
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $active_prebook = get_user_meta($user_id, '_rh_active_prebook', true);
            if ($active_prebook === $prebook_id) {
                delete_user_meta($user_id, '_rh_active_prebook');
            }
        }
    }
    
    /**
     * استخراج اطلاعات کنسلی
     */
    private function extract_cancellation_info($prebook_data) {
        $cancellation = [
            'free_cancellation' => false,
            'free_cancellation_before' => null,
            'penalties' => []
        ];
        
        if (isset($prebook_data['payment_options']['cancellation_penalties'])) {
            $penalties = $prebook_data['payment_options']['cancellation_penalties'];
            
            if (!empty($penalties['free_cancellation_before'])) {
                $cancellation['free_cancellation'] = true;
                $cancellation['free_cancellation_before'] = $penalties['free_cancellation_before'];
            }
            
            if (isset($penalties['policies'])) {
                $cancellation['penalties'] = $penalties['policies'];
            }
        }
        
        return $cancellation;
    }
    
    /**
     * پاکسازی prebook های منقضی شده
     */
    public function cleanup_expired_prebooks() {
        global $wpdb;
        
        // پاکسازی transients منقضی شده
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_prebook_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_prebook_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        rh_log('Cleaned up expired prebooks', [], 'info');
    }
}

// Initialize
RH_Prebook::instance();