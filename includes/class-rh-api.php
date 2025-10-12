<?php
/**
 * Ratehawk API Client (FIXED)
 * File: includes/class-rh-api.php
 * 
 * Changes:
 * - Fixed get_hotel_info() to use POST instead of GET
 * - Fixed request headers
 * - Improved error handling
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_API {
    
    private static $instance = null;
    private $api_key_id;
    private $api_key;
    private $base_url;
    
    /**
     * Rate Limits per endpoint
     */
    private $rate_limits = [
        'search/serp/hotels' => ['limit' => 150, 'period' => 60],
        'search/serp/region' => ['limit' => 10, 'period' => 60],
        'search/serp/geo' => ['limit' => 10, 'period' => 60],
        'search/hp' => ['limit' => 10, 'period' => 60],
        'hotel/prebook' => ['limit' => 30, 'period' => 60],
        'hotel/order/booking/form' => ['limit' => 30, 'period' => 60],
        'hotel/order/booking/finish' => ['limit' => 30, 'period' => 60],
        'hotel/order/booking/finish/status' => ['limit' => 30, 'period' => 60],
        'hotel/order/cancel' => ['limit' => 30, 'period' => 60],
        'hotel/order/info' => ['limit' => 30, 'period' => 60],
        'hotel/info' => ['limit' => 30, 'period' => 60],
        'hotel/info/dump' => ['limit' => 100, 'period' => 86400],
        'hotel/static' => ['limit' => 100, 'period' => 86400],
    ];
    
    /**
     * Singleton
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->api_key_id = get_option('rh_api_key_id');
        $this->api_key = get_option('rh_api_key');
        $this->base_url = RH_API_BASE_URL . RH_API_VERSION;
    }
    
    /**
     * Main request method (FIXED)
     */
    private function request($endpoint, $method = 'POST', $data = null, $timeout = 30) {
        // Check credentials
        if (empty($this->api_key_id) || empty($this->api_key)) {
            throw new Exception(__('API credentials not configured', 'ratehawk-traveler'));
        }
        
        // Build full URL
        $url = $this->base_url . $endpoint;
        
        // Check rate limit
        $this->check_rate_limit($endpoint);
        
        // Prepare headers
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->api_key_id . ':' . $this->api_key),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'RatehawkTraveler/' . RH_VERSION . ' WordPress/' . get_bloginfo('version')
        ];
        
        // Prepare request args
        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'headers' => $headers,
            'sslverify' => true,
            'httpversion' => '1.1'
        ];
        
        // Add body for POST requests
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        // Log request (masked)
        $this->log_request($endpoint, $method, $data);
        
        // Send request
        $start_time = microtime(true);
        $response = wp_remote_request($url, $args);
        $execution_time = microtime(true) - $start_time;
        
        // Check for WordPress errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error($endpoint, $error_message);
            throw new Exception($error_message);
        }
        
        // Get response details
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Try to decode JSON
        $result = json_decode($body, true);
        
        // If JSON decode failed
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error($endpoint, "JSON Decode Error: " . json_last_error_msg(), ['body' => substr($body, 0, 500)]);
            throw new Exception("Invalid JSON response from API");
        }
        
        // Log response
        $this->log_response($endpoint, $http_code, $result, $execution_time);
        
        // Handle errors
        if ($http_code >= 400) {
            $error = $result['error'] ?? $result['message'] ?? 'Unknown error';
            $error_details = $result['error_details'] ?? '';
            
            $this->log_error($endpoint, "HTTP $http_code: $error", [
                'error_details' => $error_details,
                'full_response' => $result
            ]);
            
            // Special handling for rate limit
            if ($http_code === 429) {
                throw new Exception(__('Rate limit exceeded. Please try again later.', 'ratehawk-traveler'));
            }
            
            throw new Exception("API Error ($http_code): $error" . ($error_details ? " - $error_details" : ""));
        }
        
        // Record successful request for rate limiting
        $this->record_request($endpoint);
        
        return $result;
    }
    
    /**
     * Check rate limit before request
     */
    private function check_rate_limit($endpoint) {
        $limit_key = null;
        foreach ($this->rate_limits as $key => $limit) {
            if (strpos($endpoint, $key) !== false) {
                $limit_key = $key;
                break;
            }
        }
        
        if (!$limit_key) {
            return;
        }
        
        $limit_data = $this->rate_limits[$limit_key];
        $transient_key = 'rh_rate_limit_' . md5($limit_key);
        
        $requests = get_transient($transient_key) ?: [];
        $now = time();
        
        // Remove old requests
        $requests = array_filter($requests, function($timestamp) use ($now, $limit_data) {
            return ($now - $timestamp) < $limit_data['period'];
        });
        
        // Check if limit reached
        if (count($requests) >= $limit_data['limit']) {
            $oldest = min($requests);
            $wait_time = $limit_data['period'] - ($now - $oldest);
            
            throw new Exception(sprintf(
                __('Rate limit reached. Please wait %d seconds.', 'ratehawk-traveler'),
                $wait_time
            ));
        }
    }
    
    /**
     * Record request for rate limiting
     */
    private function record_request($endpoint) {
        $limit_key = null;
        foreach ($this->rate_limits as $key => $limit) {
            if (strpos($endpoint, $key) !== false) {
                $limit_key = $key;
                break;
            }
        }
        
        if (!$limit_key) {
            return;
        }
        
        $limit_data = $this->rate_limits[$limit_key];
        $transient_key = 'rh_rate_limit_' . md5($limit_key);
        
        $requests = get_transient($transient_key) ?: [];
        $requests[] = time();
        
        set_transient($transient_key, $requests, $limit_data['period']);
    }
    
    /**
     * Log request
     */
    private function log_request($endpoint, $method, $data) {
        if (!get_option('rh_enable_logging', true)) {
            return;
        }
        
        rh_log('API Request', [
            'endpoint' => $endpoint,
            'method' => $method,
            'data' => $this->mask_sensitive_data($data)
        ], 'api_request');
    }
    
    /**
     * Log response
     */
    private function log_response($endpoint, $http_code, $result, $execution_time) {
        if (!get_option('rh_enable_logging', true)) {
            return;
        }
        
        rh_log('API Response', [
            'endpoint' => $endpoint,
            'http_code' => $http_code,
            'execution_time' => round($execution_time, 3) . 's',
            'status' => $result['status'] ?? 'unknown'
        ], 'api_response');
    }
    
    /**
     * Log error
     */
    private function log_error($endpoint, $message, $data = []) {
        rh_log('API Error', [
            'endpoint' => $endpoint,
            'message' => $message,
            'data' => $data
        ], 'api_error');
    }
    
    /**
     * Mask sensitive data
     */
    private function mask_sensitive_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = ['email', 'phone', 'first_name', 'last_name', 'credit_card'];
        
        foreach ($sensitive_keys as $key) {
            if (isset($data[$key])) {
                $data[$key] = '[MASKED]';
            }
        }
        
        // Recursive for nested arrays
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask_sensitive_data($value);
            }
        }
        
        return $data;
    }
    
    // ==========================================
    // PUBLIC API METHODS
    // ==========================================
    
    /**
     * Test connection - Get test hotel info (FIXED)
     */
    public function test_connection() {
        return $this->get_hotel_info(RH_TEST_HOTEL_ID, 'en');
    }
    
    /**
     * Get hotel static information (FIXED - Now uses POST)
     */
    public function get_hotel_info($hotel_id, $language = 'en') {
        $data = [
            'id' => $hotel_id,
            'language' => $language
        ];
        
        return $this->request('/hotel/info/', 'POST', $data);
    }
    
    /**
     * Search hotels by IDs
     */
    public function search_by_hotel_ids($params) {
        $default = [
            'ids' => [],
            'checkin' => date('Y-m-d', strtotime('+1 day')),
            'checkout' => date('Y-m-d', strtotime('+2 days')),
            'guests' => [['adults' => 2, 'children' => []]],
            'residency' => 'us',
            'language' => rh_get_current_language(),
            'currency' => 'USD'
        ];
        
        $data = wp_parse_args($params, $default);
        
        return $this->request('/search/serp/hotels/', 'POST', $data);
    }
    
    /**
     * Search hotels by region
     */
    public function search_by_region($params) {
        $default = [
            'region_id' => null,
            'checkin' => date('Y-m-d', strtotime('+1 day')),
            'checkout' => date('Y-m-d', strtotime('+2 days')),
            'guests' => [['adults' => 2, 'children' => []]],
            'residency' => 'us',
            'language' => rh_get_current_language(),
            'currency' => 'USD'
        ];
        
        $data = wp_parse_args($params, $default);
        
        return $this->request('/search/serp/region/', 'POST', $data);
    }
    
    /**
     * Search hotels by geo coordinates
     */
    public function search_by_geo($params) {
        $default = [
            'latitude' => null,
            'longitude' => null,
            'checkin' => date('Y-m-d', strtotime('+1 day')),
            'checkout' => date('Y-m-d', strtotime('+2 days')),
            'guests' => [['adults' => 2, 'children' => []]],
            'residency' => 'us',
            'language' => rh_get_current_language(),
            'currency' => 'USD'
        ];
        
        $data = wp_parse_args($params, $default);
        
        return $this->request('/search/serp/geo/', 'POST', $data);
    }
    
    /**
     * Get hotel page (rates for specific hotel)
     */
    public function get_hotel_page($params) {
        $default = [
            'id' => null,
            'checkin' => date('Y-m-d', strtotime('+1 day')),
            'checkout' => date('Y-m-d', strtotime('+2 days')),
            'guests' => [['adults' => 2, 'children' => []]],
            'residency' => 'us',
            'language' => rh_get_current_language(),
            'currency' => 'USD'
        ];
        
        $data = wp_parse_args($params, $default);
        
        return $this->request('/search/hp/', 'POST', $data);
    }
    
    /**
     * Prebook - Check rate availability
     */
    public function prebook($book_hash, $price_increase_percent = null) {
        if ($price_increase_percent === null) {
            $price_increase_percent = get_option('rh_price_tolerance', 10);
        }
        
        $data = [
            'book_hash' => $book_hash,
            'price_increase_percent' => (int) $price_increase_percent
        ];
        
        return $this->request('/hotel/prebook/', 'POST', $data);
    }
    
    /**
     * Create booking - Step 1
     */
    public function create_booking($book_hash, $partner_order_id) {
        $data = [
            'book_hash' => $book_hash,
            'partner' => [
                'partner_order_id' => $partner_order_id
            ]
        ];
        
        return $this->request('/hotel/order/booking/form/', 'POST', $data);
    }
    
    /**
     * Finish booking - Step 2
     */
    public function finish_booking($params) {
        $required = ['partner_order_id', 'book_hash', 'user', 'rooms'];
        
        foreach ($required as $field) {
            if (!isset($params[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        $data = [
            'partner' => [
                'partner_order_id' => $params['partner_order_id']
            ],
            'book_hash' => $params['book_hash'],
            'language' => rh_get_current_language(),
            'user' => $params['user'],
            'rooms' => $params['rooms'],
            'payment_type' => $params['payment_type'] ?? [
                'type' => 'deposit'
            ]
        ];
        
        return $this->request('/hotel/order/booking/finish/', 'POST', $data);
    }
    
    /**
     * Check booking status
     */
    public function check_booking_status($partner_order_id) {
        $data = [
            'partner_order_id' => $partner_order_id
        ];
        
        return $this->request('/hotel/order/booking/finish/status/', 'POST', $data, 60);
    }
    
    /**
     * Get order information
     */
    public function get_order_info($order_id) {
        $data = [
            'order_id' => $order_id
        ];
        
        return $this->request('/hotel/order/info/', 'POST', $data);
    }
    
    /**
     * Cancel booking
     */
    public function cancel_booking($order_id) {
        $data = [
            'order_id' => $order_id
        ];
        
        return $this->request('/hotel/order/cancel/', 'POST', $data);
    }
    
    /**
     * Get hotel dump (GET method is correct for dumps)
     */
    public function get_hotel_dump($language = 'en') {
        $endpoint = "/hotel/info/dump/?language=" . urlencode($language);
        return $this->request($endpoint, 'GET', null, 300);
    }
    
    /**
     * Get hotel static data (GET method is correct)
     */
    public function get_hotel_static($language = 'en') {
        $endpoint = "/hotel/static/?language=" . urlencode($language);
        return $this->request($endpoint, 'GET', null, 300);
    }
}