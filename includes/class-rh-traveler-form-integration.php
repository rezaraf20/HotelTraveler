<?php
/**
 * Ratehawk Traveler Form Integration
 * 
 * این کلاس به فرم Check Availability قالب Traveler متصل می‌شود
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
        // Hook به فرم Traveler
        add_action('st_hotel_after_check_availability_form', array($this, 'inject_ratehawk_handler'));
        
        // AJAX برای دریافت قیمت‌ها
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
            'loadingText' => __('Loading rates...', 'ratehawk-traveler'),
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
     * تزریق Handler به فرم Traveler
     */
    public function inject_ratehawk_handler() {
        $hotel_id = get_the_ID();
        $hid = rh_get_hotel_hid($hotel_id);
        
        if (!$hid) {
            return; // این هتل از Ratehawk نیست
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            console.log('🔥 Ratehawk Form Handler Loaded');
            
            // Hook به فرم Traveler
            var $form = $('.form-check-availability-hotel');
            
            if ($form.length === 0) {
                console.warn('⚠️ Traveler form not found');
                return;
            }
            
            console.log('✅ Found Traveler form:', $form);
            
            // Override submit event
            $form.off('submit').on('submit', function(e) {
                e.preventDefault();
                console.log('🚀 Form submitted - Fetching Ratehawk rates...');
                
                // دریافت داده‌های فرم
                var formData = {
                    action: 'rh_get_room_rates',
                    nonce: rhTravelerForm.nonce,
                    hotel_id: rhTravelerForm.hotelId,
                    checkin: $('input[name="start"]').val(),
                    checkout: $('input[name="end"]').val(),
                    adults: parseInt($('input[name="adult_number"]').val()) || 1,
                    children: parseInt($('input[name="child_number"]').val()) || 0,
                    rooms: parseInt($('input[name="room_num_search"]').val()) || 1
                };
                
                console.log('📤 Sending request:', formData);
                
                // نمایش loading
                showLoading();
                
                // ارسال درخواست AJAX
                $.ajax({
                    url: rhTravelerForm.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        console.log('📥 Response received:', response);
                        
                        if (response.success) {
                            displayRates(response.data);
                        } else {
                            showError(response.data || 'Unknown error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ AJAX Error:', error);
                        showError('Network error: ' + error);
                    }
                });
                
                return false;
            });
            
            /**
             * نمایش loading
             */
            function showLoading() {
                var html = '<div class="rh-rates-loading">' +
                           '<i class="fa fa-spinner fa-spin"></i> ' +
                           rhTravelerForm.loadingText +
                           '</div>';
                
                $('#rh-rates-container').remove();
                $form.after('<div id="rh-rates-container">' + html + '</div>');
            }
            
            /**
             * نمایش قیمت‌ها
             */
            function displayRates(rates) {
                console.log('🎨 Displaying rates:', rates);
                
                if (!rates || rates.length === 0) {
                    showError(rhTravelerForm.noRatesText);
                    return;
                }
                
                var html = '<div class="rh-rates-results">';
                html += '<h3 class="rh-rates-title">✅ ' + rates.length + ' Available Rates</h3>';
                html += '<div class="rh-rates-grid">';
                
                rates.forEach(function(rate) {
                    html += buildRateCard(rate);
                });
                
                html += '</div>';
                html += '</div>';
                
                $('#rh-rates-container').html(html);
                
                // Smooth scroll به نتایج
                $('html, body').animate({
                    scrollTop: $('#rh-rates-container').offset().top - 100
                }, 500);
            }
            
            /**
             * ساخت کارت قیمت
             */
            function buildRateCard(rate) {
                var priceDisplay = formatPrice(rate.price, rate.currency);
                var nights = rate.nights || 1;
                var pricePerNight = rate.price / nights;
                
                var html = '<div class="rh-rate-card">';
                
                // Header
                html += '<div class="rh-rate-header">';
                html += '<h4 class="rh-room-name">' + escapeHtml(rate.room_name) + '</h4>';
                if (rate.meal && rate.meal !== 'nomeal') {
                    html += '<span class="rh-meal-badge">' + getMealLabel(rate.meal) + '</span>';
                }
                html += '</div>';
                
                // Features
                if (rate.room_features && rate.room_features.length > 0) {
                    html += '<ul class="rh-rate-features">';
                    rate.room_features.slice(0, 3).forEach(function(feature) {
                        html += '<li><i class="fa fa-check"></i> ' + escapeHtml(feature) + '</li>';
                    });
                    html += '</ul>';
                }
                
                // Price
                html += '<div class="rh-rate-price">';
                html += '<div class="rh-price-main">' + priceDisplay + '</div>';
                html += '<div class="rh-price-detail">' + formatPrice(pricePerNight, rate.currency) + ' per night × ' + nights + ' nights</div>';
                html += '</div>';
                
                // Cancellation
                if (rate.cancellation_info) {
                    html += '<div class="rh-cancellation">';
                    if (rate.cancellation_info.free_cancellation_before) {
                        html += '<i class="fa fa-shield-alt"></i> Free cancellation until ' + 
                                formatDate(rate.cancellation_info.free_cancellation_before);
                    } else {
                        html += '<i class="fa fa-ban"></i> Non-refundable';
                    }
                    html += '</div>';
                }
                
                // Book Button
                html += '<button class="rh-book-button" data-book-hash="' + rate.book_hash + '" ' +
                        'data-price="' + rate.price + '">';
                html += '<span class="rh-btn-icon">🛎️</span> Book Now';
                html += '</button>';
                
                html += '</div>';
                
                return html;
            }
            
            /**
             * نمایش خطا
             */
            function showError(message) {
                var html = '<div class="rh-rates-error">' +
                           '<i class="fa fa-exclamation-triangle"></i> ' +
                           escapeHtml(message) +
                           '</div>';
                
                $('#rh-rates-container').html(html);
            }
            
            /**
             * فرمت کردن قیمت
             */
            function formatPrice(amount, currency) {
                var symbol = getCurrencySymbol(currency);
                return symbol + parseFloat(amount).toFixed(2);
            }
            
            /**
             * دریافت سیمبل ارز
             */
            function getCurrencySymbol(currency) {
                var symbols = {
                    'USD': '$',
                    'EUR': '€',
                    'GBP': '£',
                    'JPY': '¥'
                };
                return symbols[currency] || currency + ' ';
            }
            
            /**
             * لیبل وعده غذایی
             */
            function getMealLabel(meal) {
                var labels = {
                    'nomeal': 'Room Only',
                    'breakfast': '🍳 Breakfast',
                    'half_board': '🍽️ Half Board',
                    'full_board': '🍴 Full Board',
                    'all_inclusive': '⭐ All Inclusive'
                };
                return labels[meal] || meal;
            }
            
            /**
             * فرمت تاریخ
             */
            function formatDate(dateString) {
                var date = new Date(dateString);
                return date.toLocaleDateString();
            }
            
            /**
             * Escape HTML
             */
            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            // Event delegation برای دکمه Book
            $(document).on('click', '.rh-book-button', function() {
                var $btn = $(this);
                var bookHash = $btn.data('book-hash');
                var price = $btn.data('price');
                
                console.log('🎯 Book button clicked:', {bookHash, price});
                
                // Start Prebook Process
                startPrebookProcess(bookHash, price);
            });
            
            /**
             * شروع فرآیند Prebook
             */
            function startPrebookProcess(bookHash, price) {
                // این قسمت در مرحله بعد پیاده‌سازی می‌شود
                console.log('🚀 Starting prebook process...');
                
                // فعلاً فقط یک alert نمایش می‌دهیم
                alert('Prebook process will be implemented in next step.\n\n' +
                      'Book Hash: ' + bookHash + '\n' +
                      'Price: $' + price);
            }
        });
        </script>
        <?php
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
            $checkin = sanitize_text_field($_POST['checkin'] ?? '');
            $checkout = sanitize_text_field($_POST['checkout'] ?? '');
            $adults = absint($_POST['adults'] ?? 2);
            $children = absint($_POST['children'] ?? 0);
            $rooms = absint($_POST['rooms'] ?? 1);
            
            // Validation
            if (!$hotel_id || !$checkin || !$checkout) {
                throw new Exception('Missing required parameters');
            }
            
            // تبدیل تاریخ از فرمت Traveler به فرمت Ratehawk
            $checkin = $this->convert_date($checkin);
            $checkout = $this->convert_date($checkout);
            
            // دریافت HID
            $hid = rh_get_hotel_hid($hotel_id);
            if (!$hid) {
                throw new Exception('Hotel not found in Ratehawk');
            }
            
            rh_log('Fetching rates from form', [
                'hotel_id' => $hotel_id,
                'hid' => $hid,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'adults' => $adults,
                'children' => $children,
                'rooms' => $rooms
            ], 'info');
            
            // Cache key
            $cache_key = "form_rates_{$hid}_{$checkin}_{$checkout}_{$adults}_{$children}_{$rooms}";
            $cached = rh_cache()->get($cache_key, 'hotel_page');
            
            if ($cached !== false) {
                rh_log('Returning cached rates', ['count' => count($cached)], 'debug');
                wp_send_json_success($cached);
            }
            
            // ساخت guests array
            $guests = [];
            for ($i = 0; $i < $rooms; $i++) {
                $guests[] = [
                    'adults' => $adults,
                    'children' => [] // فعلاً بدون بچه
                ];
            }
            
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
                throw new Exception('No rates available');
            }
            
            // پردازش rates
            $rates = $this->process_rates($result['data']['hotels'][0]['rates'], $checkin, $checkout);
            
            if (empty($rates)) {
                throw new Exception('No rates found after processing');
            }
            
            // ذخیره در cache
            rh_cache()->set($cache_key, $rates, 300, 'hotel_page');
            
            rh_log('Rates processed successfully', ['count' => count($rates)], 'info');
            
            wp_send_json_success($rates);
            
        } catch (Exception $e) {
            rh_log('Error fetching rates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * تبدیل تاریخ از فرمت Traveler به Y-m-d
     */
    private function convert_date($date_string) {
        // فرمت Traveler: 2025/10/17 یا 17/10/2025
        $date_string = trim($date_string);
        
        // تلاش برای parse
        $timestamp = strtotime($date_string);
        
        if ($timestamp === false) {
            // تلاش با فرمت‌های مختلف
            $formats = ['Y/m/d', 'd/m/Y', 'Y-m-d', 'd-m-Y'];
            
            foreach ($formats as $format) {
                $date = DateTime::createFromFormat($format, $date_string);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }
            
            throw new Exception('Invalid date format: ' . $date_string);
        }
        
        return date('Y-m-d', $timestamp);
    }
    
    /**
     * پردازش rates برای نمایش
     */
    private function process_rates($raw_rates, $checkin, $checkout) {
        $processed = [];
        
        // محاسبه تعداد شب‌ها
        $nights = $this->calculate_nights($checkin, $checkout);
        
        foreach ($raw_rates as $rate) {
            $payment = $rate['payment_options']['payment_types'][0] ?? null;
            
            if (!$payment) {
                continue;
            }
            
            $amount = floatval($payment['show_amount'] ?? 0);
            
            if ($amount <= 0) {
                continue;
            }
            
            // استخراج نام اتاق
            $room_name = $rate['room_data_trans']['main_room_type'] ?? 
                        $rate['room_name'] ?? 
                        'Standard Room';
            
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
            
            $processed[] = [
                'book_hash' => $rate['book_hash'] ?? '',
                'room_name' => $room_name,
                'price' => $amount,
                'currency' => $payment['currency_code'] ?? 'USD',
                'nights' => $nights,
                'meal' => $meal,
                'cancellation_info' => $cancellation_info,
                'room_features' => $room_features,
                'match_hash' => $rate['match_hash'] ?? ''
            ];
        }
        
        // مرتب‌سازی بر اساس قیمت
        usort($processed, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });
        
        return $processed;
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