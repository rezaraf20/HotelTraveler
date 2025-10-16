<?php
/**
 * Search Integration
 * File: includes/class-rh-search.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Search {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook به فرم جستجوی Traveler
        add_action('wp_footer', [$this, 'add_search_enhancement']);
        
        // AJAX handlers
        add_action('wp_ajax_rh_autocomplete', [$this, 'ajax_autocomplete']);
        add_action('wp_ajax_nopriv_rh_autocomplete', [$this, 'ajax_autocomplete']);
        
        add_action('wp_ajax_rh_search_hotels', [$this, 'ajax_search_hotels']);
        add_action('wp_ajax_nopriv_rh_search_hotels', [$this, 'ajax_search_hotels']);
        
        // Shortcode
        add_shortcode('ratehawk_search', [$this, 'search_form_shortcode']);
    }
    
    /**
     * Shortcode: فرم جستجو
     */
    public function search_form_shortcode($atts) {
        ob_start();
        ?>
        
        <div class="rh-search-container">
            <form id="rh-search-form" class="rh-search-form">
                
                <div class="rh-search-row">
                    <!-- Destination -->
                    <div class="rh-field">
                        <label>Where to?</label>
                        <input type="text" 
                               id="rh-destination" 
                               name="destination" 
                               placeholder="City, region, or hotel" 
                               autocomplete="off"
                               required>
                        <div id="rh-autocomplete-results"></div>
                        <input type="hidden" id="rh-region-id" name="region_id">
                    </div>
                    
                    <!-- Check-in -->
                    <div class="rh-field">
                        <label>Check-in</label>
                        <input type="date" 
                               name="checkin" 
                               min="<?php echo date('Y-m-d'); ?>" 
                               value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"
                               required>
                    </div>
                    
                    <!-- Check-out -->
                    <div class="rh-field">
                        <label>Check-out</label>
                        <input type="date" 
                               name="checkout" 
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                               value="<?php echo date('Y-m-d', strtotime('+8 days')); ?>"
                               required>
                    </div>
                    
                    <!-- Guests -->
                    <div class="rh-field">
                        <label>Guests</label>
                        <select name="adults">
                            <option value="1">1 Adult</option>
                            <option value="2" selected>2 Adults</option>
                            <?php for($i=3; $i<=6; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?> Adults</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <!-- Search Button -->
                    <div class="rh-field">
                        <button type="submit" class="rh-search-btn">
                            <span class="rh-btn-text">Search</span>
                            <span class="rh-btn-loading" style="display:none;">...</span>
                        </button>
                    </div>
                </div>
                
            </form>
            
            <!-- Results -->
            <div id="rh-search-results"></div>
        </div>
        
        <style>
        .rh-search-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 20px;
        }
        .rh-search-form {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .rh-search-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        .rh-field {
            position: relative;
        }
        .rh-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        .rh-field input,
        .rh-field select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .rh-search-btn {
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .rh-search-btn:hover {
            background: #5568d3;
        }
        #rh-autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .rh-autocomplete-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .rh-autocomplete-item:hover {
            background: #f8f9fa;
        }
        #rh-search-results {
            margin-top: 30px;
        }
        .rh-hotel-card {
            display: grid;
            grid-template-columns: 250px 1fr auto;
            gap: 20px;
            padding: 20px;
            margin: 15px 0;
            background: white;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            align-items: center;
        }
        .rh-hotel-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .rh-hotel-image img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 6px;
        }
        .rh-hotel-info h3 {
            margin: 0 0 10px;
        }
        .rh-hotel-price {
            text-align: right;
        }
        .rh-price-amount {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        @media (max-width: 768px) {
            .rh-search-row {
                grid-template-columns: 1fr;
            }
            .rh-hotel-card {
                grid-template-columns: 1fr;
            }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            
            // Autocomplete
            let searchTimeout;
            $('#rh-destination').on('input', function() {
                const query = $(this).val();
                
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    $('#rh-autocomplete-results').hide();
                    return;
                }
                
                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        data: {
                            action: 'rh_autocomplete',
                            query: query
                        },
                        success: function(response) {
                            if (response.success && response.data.length > 0) {
                                let html = '';
                                response.data.forEach(function(item) {
                                    html += '<div class="rh-autocomplete-item" data-id="' + item.id + '">';
                                    html += '<strong>' + item.name + '</strong><br>';
                                    html += '<small>' + item.country + '</small>';
                                    html += '</div>';
                                });
                                $('#rh-autocomplete-results').html(html).show();
                            } else {
                                $('#rh-autocomplete-results').hide();
                            }
                        }
                    });
                }, 300);
            });
            
            // Select from autocomplete
            $(document).on('click', '.rh-autocomplete-item', function() {
                const name = $(this).find('strong').text();
                const id = $(this).data('id');
                
                $('#rh-destination').val(name);
                $('#rh-region-id').val(id);
                $('#rh-autocomplete-results').hide();
            });
            
            // Search form submit
            $('#rh-search-form').on('submit', function(e) {
                e.preventDefault();
                
                const regionId = $('#rh-region-id').val();
                if (!regionId) {
                    alert('Please select a destination from the list');
                    return;
                }
                
                const btn = $(this).find('.rh-search-btn');
                btn.prop('disabled', true);
                btn.find('.rh-btn-text').hide();
                btn.find('.rh-btn-loading').show();
                
                $('#rh-search-results').html('<div style="text-align:center;padding:40px;">Searching...</div>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: {
                        action: 'rh_search_hotels',
                        region_id: regionId,
                        checkin: $('[name="checkin"]').val(),
                        checkout: $('[name="checkout"]').val(),
                        adults: $('[name="adults"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            displayResults(response.data);
                        } else {
                            $('#rh-search-results').html('<div class="error">' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        $('#rh-search-results').html('<div class="error">Search failed. Please try again.</div>');
                    },
                    complete: function() {
                        btn.prop('disabled', false);
                        btn.find('.rh-btn-text').show();
                        btn.find('.rh-btn-loading').hide();
                    }
                });
            });
            
            function displayResults(hotels) {
                if (hotels.length === 0) {
                    $('#rh-search-results').html('<div style="text-align:center;padding:40px;">No hotels found</div>');
                    return;
                }
                
                let html = '<h2>Found ' + hotels.length + ' hotels</h2>';
                
                hotels.forEach(function(hotel) {
                    html += '<div class="rh-hotel-card">';
                    html += '<div class="rh-hotel-image">';
                    html += '<img src="' + hotel.image + '" alt="' + hotel.name + '">';
                    html += '</div>';
                    html += '<div class="rh-hotel-info">';
                    html += '<h3>' + hotel.name + '</h3>';
                    html += '<div>' + '⭐'.repeat(hotel.stars) + '</div>';
                    html += '<p>' + hotel.rates_count + ' room options available</p>';
                    html += '</div>';
                    html += '<div class="rh-hotel-price">';
                    html += '<div class="rh-price-amount">' + hotel.price + '</div>';
                    html += '<div>per night</div>';
                    html += '<a href="' + hotel.url + '" class="rh-search-btn">View Rates</a>';
                    html += '</div>';
                    html += '</div>';
                });
                
                $('#rh-search-results').html(html);
            }
        });
        </script>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX: Autocomplete
     */
    public function ajax_autocomplete() {
        $query = sanitize_text_field($_GET['query'] ?? '');
        
        if (strlen($query) < 2) {
            wp_send_json_error();
        }
        
        try {
            $result = rh_api()->search_by_region([
                'region_id' => 2114, // Paris as example - باید autocomplete API بزنیم
                'checkin' => date('Y-m-d', strtotime('+7 days')),
                'checkout' => date('Y-m-d', strtotime('+8 days')),
                'guests' => [['adults' => 2, 'children' => []]],
                'residency' => 'us',
                'language' => 'en',
                'currency' => 'USD'
            ]);
            
            // Mock data - باید از multicomplete API استفاده کنیم
            $suggestions = [
                ['id' => 2114, 'name' => 'Paris', 'country' => 'France'],
                ['id' => 4898, 'name' => 'Dubai', 'country' => 'UAE'],
                ['id' => 6156, 'name' => 'Bangkok', 'country' => 'Thailand'],
            ];
            
            wp_send_json_success($suggestions);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Search Hotels
     */
    public function ajax_search_hotels() {
        $region_id = absint($_POST['region_id'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        
        if (!$region_id || !$checkin || !$checkout) {
            wp_send_json_error('Invalid parameters');
        }
        
        try {
            $result = rh_api()->search_by_region([
                'region_id' => $region_id,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => [['adults' => $adults, 'children' => []]],
                'residency' => 'us',
                'language' => 'en',
                'currency' => 'USD'
            ]);
            
            if (!isset($result['data']['hotels']) || empty($result['data']['hotels'])) {
                wp_send_json_error('No hotels found');
            }
            
            $hotels = [];
            foreach ($result['data']['hotels'] as $hotel) {
                $hotels[] = [
                    'name' => $hotel['name'],
                    'stars' => $hotel['star_rating'] ?? 0,
                    'image' => $this->get_hotel_image($hotel),
                    'price' => $this->get_cheapest_price($hotel),
                    'rates_count' => isset($hotel['rates']) ? count($hotel['rates']) : 0,
                    'url' => '#' // باید URL هتل درست کنیم
                ];
            }
            
            wp_send_json_success($hotels);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    private function get_hotel_image($hotel) {
        if (isset($hotel['images'][0])) {
            return str_replace('{size}', '320x240', $hotel['images'][0]);
        }
        return 'https://via.placeholder.com/320x240?text=No+Image';
    }
    
    private function get_cheapest_price($hotel) {
        if (isset($hotel['rates'][0])) {
            $rate = $hotel['rates'][0];
            $amount = $rate['payment_options']['payment_types'][0]['show_amount'] ?? 0;
            $currency = $rate['payment_options']['payment_types'][0]['currency_code'] ?? 'USD';
            return rh_format_price($amount, $currency);
        }
        return 'N/A';
    }
    
    public function add_search_enhancement() {
        // اگه نیاز بود
    }
}