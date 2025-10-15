<?php
/**
 * Hotel Rates Display
 * File: includes/class-rh-hotel-rates.php
 * 
 * نمایش قیمت‌های زنده از Ratehawk در صفحه هتل Traveler
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Hotel_Rates {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('st_after_hotel_content', [$this, 'display_rates_section'], 10);
        add_filter('the_content', [$this, 'append_rates_to_content']);
        
        add_action('wp_ajax_rh_get_hotel_rates', [$this, 'ajax_get_rates']);
        add_action('wp_ajax_nopriv_rh_get_hotel_rates', [$this, 'ajax_get_rates']);
    }
    
    public function enqueue_assets() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            return;
        }
        
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
            [],
            '5.15.4'
        );
        
        wp_enqueue_style(
            'rh-rates',
            RH_PLUGIN_URL . 'public/assets/css/rates.css',
            [],
            RH_VERSION
        );
        
        wp_enqueue_script(
            'rh-rates',
            RH_PLUGIN_URL . 'public/assets/js/rates.js',
            ['jquery'],
            RH_VERSION,
            true
        );
        
        wp_localize_script('rh-rates', 'rhRates', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rh_rates_nonce'),
            'hotel_id' => get_the_ID(),
            'loading_text' => __('Loading rates...', 'ratehawk-traveler'),
            'error_text' => __('Unable to load rates. Please try again.', 'ratehawk-traveler'),
            'no_rates_text' => __('No rates available for selected dates.', 'ratehawk-traveler'),
        ]);
    }
    
    public function display_rates_section() {
        if (!is_singular('st_hotel')) {
            return;
        }
        
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            return;
        }
        
        $this->render_rates_html();
    }
    
    public function append_rates_to_content($content) {
        if (!is_singular('st_hotel') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        
        if (!rh_is_ratehawk_hotel(get_the_ID())) {
            return $content;
        }
        
        if (did_action('st_after_hotel_content')) {
            return $content;
        }
        
        ob_start();
        $this->render_rates_html();
        $rates_html = ob_get_clean();
        
        return $content . $rates_html;
    }
    
    private function render_rates_html() {
        $hotel_id = get_the_ID();
        $hid = rh_get_hotel_hid($hotel_id);
        
        if (!$hid) {
            return;
        }
        
        $checkin = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : date('Y-m-d', strtotime('+1 day'));
        $checkout = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : date('Y-m-d', strtotime('+2 days'));
        $adults = isset($_GET['adults']) ? absint($_GET['adults']) : 2;
        
        ?>
        <div id="rh-rates-section" class="rh-rates-wrapper" data-hid="<?php echo esc_attr($hid); ?>">
            
            <div class="rh-rates-header">
                <h2><?php _e('Check Availability & Rates', 'ratehawk-traveler'); ?></h2>
                <p><?php _e('Live rates from Ratehawk', 'ratehawk-traveler'); ?></p>
            </div>
            
            <div class="rh-rates-search-form">
                <form id="rh-rates-form">
                    <div class="rh-form-grid">
                        <div class="rh-form-field">
                            <label><?php _e('Check-in', 'ratehawk-traveler'); ?></label>
                            <input type="date" 
                                   name="checkin" 
                                   value="<?php echo esc_attr($checkin); ?>" 
                                   min="<?php echo date('Y-m-d'); ?>"
                                   required>
                        </div>
                        
                        <div class="rh-form-field">
                            <label><?php _e('Check-out', 'ratehawk-traveler'); ?></label>
                            <input type="date" 
                                   name="checkout" 
                                   value="<?php echo esc_attr($checkout); ?>" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                                   required>
                        </div>
                        
                        <div class="rh-form-field">
                            <label><?php _e('Adults', 'ratehawk-traveler'); ?></label>
                            <select name="adults">
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected($adults, $i); ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="rh-form-field">
                            <label><?php _e('Children', 'ratehawk-traveler'); ?></label>
                            <select name="children_count" id="rh-children-count">
                                <option value="0">0</option>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="rh-form-field rh-form-submit">
                            <button type="submit" class="rh-search-btn">
                                <span class="rh-btn-text"><?php _e('Search', 'ratehawk-traveler'); ?></span>
                                <span class="rh-btn-loading" style="display:none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </span>
                            </button>
                        </div>
                    </div>
                    
                    <div id="rh-children-ages" style="display:none;"></div>
                </form>
            </div>
            
            <div id="rh-rates-results">
                <div class="rh-rates-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <?php _e('Loading available rates...', 'ratehawk-traveler'); ?>
                </div>
            </div>
            
        </div>
        <?php
    }
    
    public function ajax_get_rates() {
        // Debug: Log request
        rh_log('AJAX Request Received', $_POST, 'debug');
        
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rh_rates_nonce')) {
            rh_log('Nonce verification failed', ['nonce' => $_POST['nonce'] ?? 'missing'], 'error');
            wp_send_json_error(__('Security check failed', 'ratehawk-traveler'));
        }
        
        $hotel_id = absint($_POST['hotel_id'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        $children = isset($_POST['children']) ? array_map('absint', (array)$_POST['children']) : [];
        
        rh_log('AJAX Parameters', [
            'hotel_id' => $hotel_id,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'adults' => $adults,
            'children' => $children
        ], 'debug');
        
        if (!$hotel_id || !$checkin || !$checkout) {
            wp_send_json_error(__('Invalid parameters', 'ratehawk-traveler'));
        }
        
        if (!rh_is_ratehawk_hotel($hotel_id)) {
            wp_send_json_error(__('Not a Ratehawk hotel', 'ratehawk-traveler'));
        }
        
        $hid = rh_get_hotel_hid($hotel_id);
        if (!$hid) {
            wp_send_json_error(__('Hotel ID not found', 'ratehawk-traveler'));
        }
        
        $cache_key = "rates_{$hid}_{$checkin}_{$checkout}_{$adults}_" . md5(json_encode($children));
        $cached = rh_cache()->get($cache_key, 'hotel_page');
        
        if ($cached !== false) {
            wp_send_json_success([
                'rates' => $cached,
                'cached' => true
            ]);
        }
        
        try {
            $result = rh_api()->get_hotel_page([
                'id' => (string)$hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => [[
                    'adults' => $adults,
                    'children' => $children
                ]],
                'residency' => 'us',
                'language' => rh_get_current_language(),
                'currency' => 'USD'
            ]);
            
            if (!isset($result['data']['hotels'][0])) {
                wp_send_json_error(__('No rates available', 'ratehawk-traveler'));
            }
            
            $rates = $this->process_rates($result['data']['hotels'][0]);
            
            if (empty($rates)) {
                wp_send_json_error(__('No rates available for selected dates', 'ratehawk-traveler'));
            }
            
            rh_cache()->set($cache_key, $rates, 300, 'hotel_page');
            
            wp_send_json_success([
                'rates' => $rates,
                'cached' => false
            ]);
            
        } catch (Exception $e) {
            rh_log('Error fetching rates', [
                'hotel_id' => $hotel_id,
                'error' => $e->getMessage()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    private function process_rates($hotel_data) {
        $rates = [];
        
        if (!isset($hotel_data['rates'])) {
            return $rates;
        }
        
        foreach ($hotel_data['rates'] as $rate) {
            $room_name = $rate['room_data_trans']['main_room_type'] ?? 
                        $rate['room_name'] ?? 
                        __('Standard Room', 'ratehawk-traveler');
            
            $meal = $rate['meal'] ?? 'nomeal';
            $meal_name = $this->get_meal_name($meal);
            
            $payment = $rate['payment_options']['payment_types'][0] ?? [];
            $amount = $payment['show_amount'] ?? 0;
            $currency = $payment['currency_code'] ?? 'USD';
            
            $cancellation = $this->get_cancellation_info($rate);
            $book_hash = $rate['book_hash'] ?? '';
            
            $rates[] = [
                'room_name' => $room_name,
                'meal' => $meal_name,
                'price' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'formatted' => rh_format_price($amount, $currency)
                ],
                'cancellation' => $cancellation,
                'book_hash' => $book_hash,
            ];
        }
        
        usort($rates, function($a, $b) {
            return $a['price']['amount'] <=> $b['price']['amount'];
        });
        
        return $rates;
    }
    
    private function get_meal_name($meal_code) {
        $meals = [
            'nomeal' => __('Room Only', 'ratehawk-traveler'),
            'breakfast' => __('Breakfast Included', 'ratehawk-traveler'),
            'half-board' => __('Half Board', 'ratehawk-traveler'),
            'full-board' => __('Full Board', 'ratehawk-traveler'),
            'all-inclusive' => __('All Inclusive', 'ratehawk-traveler'),
        ];
        
        return $meals[$meal_code] ?? ucfirst(str_replace('-', ' ', $meal_code));
    }
    
    private function get_cancellation_info($rate) {
        $policies = $rate['payment_options']['payment_types'][0]['cancellation_penalties']['policies'] ?? [];
        
        if (empty($policies)) {
            return [
                'type' => 'non-refundable',
                'text' => __('Non-refundable', 'ratehawk-traveler'),
                'icon' => 'times-circle'
            ];
        }
        
        foreach ($policies as $policy) {
            if (($policy['amount_charge']['amount'] ?? 0) == 0) {
                return [
                    'type' => 'free',
                    'text' => sprintf(
                        __('Free cancellation until %s', 'ratehawk-traveler'),
                        date_i18n('M j, Y', strtotime($policy['end_at']))
                    ),
                    'icon' => 'check-circle',
                    'deadline' => $policy['end_at']
                ];
            }
        }
        
        return [
            'type' => 'paid',
            'text' => __('Cancellation with fee', 'ratehawk-traveler'),
            'icon' => 'info-circle'
        ];
    }
}