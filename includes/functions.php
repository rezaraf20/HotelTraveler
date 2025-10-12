<?php
/**
 * Helper Functions
 * File: includes/functions.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Cache Instance
 */
function rh_cache() {
    return RH_Cache::instance();
}

/**
 * Check if plugin is configured
 */
function rh_is_configured() {
    $api_key_id = get_option('rh_api_key_id');
    $api_key = get_option('rh_api_key');
    
    return !empty($api_key_id) && !empty($api_key);
}

/**
 * Check if hotel is from Ratehawk
 */
function rh_is_ratehawk_hotel($post_id) {
    return (bool) get_post_meta($post_id, '_is_ratehawk_hotel', true);
}

/**
 * Get Ratehawk HID from post
 */
function rh_get_hotel_hid($post_id) {
    return (int) get_post_meta($post_id, '_ratehawk_hid', true);
}

/**
 * Format price with currency
 */
function rh_format_price($amount, $currency = 'USD') {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'C$',
    ];
    
    $symbol = $symbols[$currency] ?? $currency . ' ';
    
    return $symbol . number_format((float)$amount, 2);
}

/**
 * Generate unique partner order ID
 */
function rh_generate_order_id() {
    return 'RH_' . date('Ymd') . '_' . wp_rand(100000, 999999);
}

/**
 * Check if prebook is expired
 */
function rh_is_prebook_expired($expires_at) {
    if (empty($expires_at)) {
        return true;
    }
    
    return strtotime($expires_at) <= current_time('timestamp');
}

/**
 * Mask sensitive data for logs
 */
function rh_mask_string($string, $visible = 4) {
    if (strlen($string) <= $visible * 2) {
        return str_repeat('*', strlen($string));
    }
    
    return substr($string, 0, $visible) . 
           str_repeat('*', strlen($string) - $visible * 2) . 
           substr($string, -$visible);
}

/**
 * Log function
 */
function rh_log($message, $data = [], $type = 'info') {
    if (!get_option('rh_enable_logging', true)) {
        return;
    }
    
    $log_entry = [
        'timestamp' => current_time('mysql'),
        'type' => $type,
        'message' => $message,
        'data' => $data
    ];
    
    // Write to debug.log if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Ratehawk] ' . $message . ' ' . json_encode($data));
    }
    
    // Store in database
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'rh_logs';
    
    // Check if table exists first
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        $wpdb->insert(
            $table_name,
            [
                'log_type' => $type,
                'message' => $message,
                'log_data' => json_encode($data),
                'created_at' => current_time('mysql')
            ]
        );
    }
}

/**
 * Get current language (Polylang)
 */
function rh_get_current_language() {
    if (function_exists('pll_current_language')) {
        return pll_current_language('slug');
    }
    return 'en'; // Default
}

/**
 * Sanitize search params
 */
function rh_sanitize_search_params($params) {
    return [
        'checkin' => sanitize_text_field($params['checkin'] ?? ''),
        'checkout' => sanitize_text_field($params['checkout'] ?? ''),
        'adults' => absint($params['adults'] ?? 2),
        'children' => isset($params['children']) ? array_map('absint', (array)$params['children']) : [],
        'rooms' => absint($params['rooms'] ?? 1),
        'residency' => sanitize_text_field($params['residency'] ?? 'us'),
        'currency' => sanitize_text_field($params['currency'] ?? 'USD'),
    ];
}

/**
 * Get plugin version
 */
function rh_get_version() {
    return RH_VERSION;
}

/**
 * Check if we're in test mode
 */
function rh_is_test_mode() {
    return RH_SYNC_MODE === 'test';
}

/**
 * Get API base URL
 */
function rh_get_api_url() {
    return RH_API_BASE_URL . RH_API_VERSION;
}