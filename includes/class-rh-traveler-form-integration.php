<?php
/**
 * Ratehawk Traveler Form Integration
 * 
 * این کلاس به دکمه‌های "Show Price" قالب Traveler متصل می‌شود
 * و قیمت‌های زنده Ratehawk را نمایش می‌دهد
 */

if (!defined('ABSPATH')) exit;

class RH_Traveler_Form_Integration {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX برای دریافت قیمت‌های اتاق
        add_action('wp_ajax_rh_get_room_rates', array($this, 'ajax_get_room_rates'));
        add_action('wp_ajax_nopriv_rh_get_room_rates', array($this, 'ajax_get_room_rates'));
        
        // اضافه کردن اسکریپت‌ها
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * اضافه کردن اسکریپت‌های JavaScript
     */
    public function enqueue_scripts() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        wp_enqueue_script(
            'rh-traveler-form',
            RH_PLUGIN_URL . 'public/assets/js/traveler-form.js',
            array('jquery'),
            RH_VERSION,
            true
        );
        
        wp_localize_script('rh-traveler-form', 'rhTravelerForm', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_room_rates'),
            'hotelId' => get_the_ID(),
            'loadingText' => __('Loading rates from Ratehawk...', 'ratehawk-traveler'),
            'noRatesText' => __('No rates available for selected dates', 'ratehawk-traveler'),
            'errorText' => __('Error loading rates', 'ratehawk-traveler')
        ));
        
        wp_enqueue_style(
            'rh-traveler-form',
            RH_PLUGIN_URL . 'public/assets/css/traveler-form.css',
            array(),
            RH_VERSION
        );
    }
    
    /**
     * AJAX Handler برای دریافت قیمت‌ها
     */
    public function ajax_get_room_rates() {
        try {
            // بررسی nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rh_room_rates')) {
                throw new Exception('Invalid nonce');
            }
            
            // دریافت پارامترها
            $hotel_id = absint($_POST['hotel_id'] ?? 0);
            $room_id = absint($_POST['room_id'] ?? 0);
            $checkin = sanitize_text_field($_POST['checkin'] ?? '');
            $checkout = sanitize_text_field($_POST['checkout'] ?? '');
            $adults = absint($_POST['adults'] ?? 2);
            $children = absint($_POST['children'] ?? 0);
            
            // Validation
            if (!$hotel_id || !$room_id) {
                throw new Exception('Missing hotel or room ID');
            }
            
            if (empty($checkin) || empty($checkout)) {
                throw new Exception('Missing check-in or check-out dates');
            }
            
            // تبدیل تاریخ به فرمت Y-m-d
            $checkin = $this->parse_date($checkin);
            $checkout = $this->parse_date($checkout);
            
            // دریافت HID
            $hid = rh_get_hotel_hid($hotel_id);
            if (!$hid) {
                throw new Exception('This hotel is not available on Ratehawk');
            }
            
            rh_log('Fetching room rates', [
                'hotel_id' => $hotel_id,
                'room_id' => $room_id,
                'hid' => $hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'adults' => $adults
            ], 'info');
            
            // Cache key
            $cache_key = "room_rates_{$hid}_{$room_id}_{$checkin}_{$checkout}_{$adults}_{$children}";
            $cached = rh_cache()->get($cache_key, 'hotel_page');
            
            if ($cached !== false) {
                rh_log('Returning cached rates', ['count' => count($cached)], 'debug');
                wp_send_json_success($cached);
                return;
            }
            
            // ساخت guests array
            $guests = [[
                'adults' => $adults,
                'children' => []
            ]];
            
            // Call API
            $result = rh_api()->search_by_hotel_ids([
                'ids' => [(string)$hid],
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => $guests,
                'residency' => 'us',
                'language' => rh_get_current_language(),
                'currency' => 'USD'
            ]);
            
            rh_log('API Response', [
                'status' => $result['status'] ?? 'unknown',
                'has_hotels' => isset($result['data']['hotels']),
                'hotel_count' => isset($result['data']['hotels']) ? count($result['data']['hotels']) : 0
            ], 'debug');
            
            if (!isset($result['data']['hotels'][0]['rates'])) {
                throw new Exception('No rates available from Ratehawk');
            }
            
            // پردازش rates و فیلتر برای این اتاق
            $all_rates = $result['data']['hotels'][0]['rates'];
            $room_name = get_the_title($room_id);
            
            $filtered_rates = $this->filter_rates_for_room($all_rates, $room_name, $checkin, $checkout);
            
            if (empty($filtered_rates)) {
                throw new Exception('No matching rates found for this room');
            }
            
            // ذخیره در cache
            rh_cache()->set($cache_key, $filtered_rates, 300, 'hotel_page');
            
            rh_log('Rates processed successfully', ['count' => count($filtered_rates)], 'info');
            
            wp_send_json_success($filtered_rates);
            
        } catch (Exception $e) {
            rh_log('Error fetching rates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Parse تاریخ از فرمت‌های مختلف به Y-m-d
     */
    private function parse_date($date_string) {
        // اگر خالی است، تاریخ فردا رو برگردون
        if (empty($date_string)) {
            return date('Y-m-d', strtotime('+1 day'));
        }
        
        $date_string = trim($date_string);
        
        // تلاش با strtotime
        $timestamp = strtotime($date_string);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        // تلاش با فرمت‌های مختلف
        $formats = ['Y/m/d', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $date_string);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // اگر هیچکدوم کار نکرد، فردا رو برگردون
        return date('Y-m-d', strtotime('+1 day'));
    }
    
    /**
     * فیلتر کردن rates برای اتاق خاص
     */
    private function filter_rates_for_room($all_rates, $room_name, $checkin, $checkout) {
        $filtered = [];
        $nights = $this->calculate_nights($checkin, $checkout);
        
        foreach ($all_rates as $rate) {
            $payment = $rate['payment_options']['payment_types'][0] ?? null;
            
            if (!$payment) {
                continue;
            }
            
            $amount = floatval($payment['show_amount'] ?? 0);
            
            if ($amount <= 0) {
                continue;
            }
            
            // استخراج نام اتاق از rate
            $rate_room_name = $rate['room_data_trans']['main_room_type'] ?? 
                             $rate['room_name'] ?? '';
            
            // مقایسه نام‌ها (loose comparison)
            $match = false;
            if (!empty($rate_room_name) && !empty($room_name)) {
                // حذف کاراکترهای اضافی و مقایسه
                $clean_rate_name = strtolower(trim($rate_room_name));
                $clean_room_name = strtolower(trim($room_name));
                
                if (stripos($clean_rate_name, $clean_room_name) !== false || 
                    stripos($clean_room_name, $clean_rate_name) !== false) {
                    $match = true;
                }
            }
            
            // اگر match نشد، همه رو نشون بده (fallback)
            if (!$match && count($filtered) === 0) {
                $match = true;
            }
            
            if ($match) {
                // استخراج meal
                $meal = $rate['meal'] ?? 'nomeal';
                
                // استخراج cancellation info
                $cancellation_info = null;
                if (isset($rate['payment_options']['cancellation_penalties'])) {
                    $penalties = $rate['payment_options']['cancellation_penalties'];
                    if (!empty($penalties['free_cancellation_before'])) {
                        $cancellation_info = [
                            'free_cancellation_before' => $penalties['free_cancellation_before']
                        ];
                    }
                }
                
                // استخراج ویژگی‌های اتاق
                $room_features = [];
                if (isset($rate['room_data_trans']['bedding_type'])) {
                    $room_features[] = $rate['room_data_trans']['bedding_type'];
                }
                if (isset($rate['room_data_trans']['bathroom'])) {
                    $room_features[] = $rate['room_data_trans']['bathroom'];
                }
                
                $filtered[] = [
                    'book_hash' => $rate['book_hash'] ?? '',
                    'room_name' => $rate_room_name,
                    'price' => $amount,
                    'currency' => $payment['currency_code'] ?? 'USD',
                    'nights' => $nights,
                    'meal' => $meal,
                    'cancellation_info' => $cancellation_info,
                    'room_features' => $room_features,
                    'match_hash' => $rate['match_hash'] ?? ''
                ];
            }
        }
        
        // مرتب‌سازی بر اساس قیمت
        usort($filtered, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });
        
        return $filtered;
    }
    
    /**
     * محاسبه تعداد شب‌ها
     */
    private function calculate_nights($checkin, $checkout) {
        $date1 = new DateTime($checkin);
        $date2 = new DateTime($checkout);
        $interval = $date1->diff($date2);
        return max(1, $interval->days);
    }
}

// Initialize
RH_Traveler_Form_Integration::instance();