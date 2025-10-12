<?php
/**
 * Cache Manager Class
 * File: includes/class-rh-cache.php
 * 
 * Uses WordPress Transients API + LiteSpeed Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Cache {
    
    private static $instance = null;
    private $enabled;
    private $prefix = 'rh_cache_';
    
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
        $this->enabled = (bool) get_option('rh_enable_cache', true);
    }
    
    /**
     * Get cached data
     */
    public function get($key, $group = 'general') {
        if (!$this->enabled) {
            return false;
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Try wp_cache first (LiteSpeed Object Cache)
        $value = wp_cache_get($cache_key, 'ratehawk');
        
        if ($value !== false) {
            return $value;
        }
        
        // Fallback to transients
        return get_transient($cache_key);
    }
    
    /**
     * Set cache data
     */
    public function set($key, $value, $expiration = null, $group = 'general') {
        if (!$this->enabled) {
            return false;
        }
        
        if (is_null($expiration)) {
            $expiration = $this->get_default_expiration($group);
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Set in wp_cache (LiteSpeed)
        wp_cache_set($cache_key, $value, 'ratehawk', $expiration);
        
        // Also set in transients as fallback
        set_transient($cache_key, $value, $expiration);
        
        return true;
    }
    
    /**
     * Delete cache
     */
    public function delete($key, $group = 'general') {
        $cache_key = $this->build_key($key, $group);
        
        // Delete from wp_cache
        wp_cache_delete($cache_key, 'ratehawk');
        
        // Delete from transients
        delete_transient($cache_key);
        
        return true;
    }
    
    /**
     * Clear all cache for a group
     */
    public function clear_group($group) {
        global $wpdb;
        
        $pattern = $this->prefix . $group . '_%';
        
        // Delete from transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                '_transient_' . $pattern,
                '_transient_timeout_' . $pattern
            )
        );
        
        // Purge LiteSpeed cache
        if (method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        }
        
        return true;
    }
    
    /**
     * Clear all Ratehawk cache
     */
    public function clear_all() {
        global $wpdb;
        
        $pattern = $this->prefix . '%';
        
        // Delete from transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                '_transient_' . $pattern,
                '_transient_timeout_' . $pattern
            )
        );
        
        // Purge LiteSpeed cache
        if (method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        }
        
        rh_log('All cache cleared', [], 'info');
        
        return true;
    }
    
    /**
     * Build cache key
     */
    private function build_key($key, $group) {
        $lang = rh_get_current_language();
        return $this->prefix . $group . '_' . $lang . '_' . md5($key);
    }
    
    /**
     * Get default expiration for group
     */
    private function get_default_expiration($group) {
        $expirations = [
            'search' => 300,        // 5 minutes
            'hotel_page' => 300,    // 5 minutes
            'hotel_static' => 86400, // 24 hours
            'regions' => 604800,    // 7 days
            'general' => 3600,      // 1 hour
        ];
        
        return $expirations[$group] ?? 3600;
    }
    
    /**
     * Get cache statistics
     */
    public function get_stats() {
        global $wpdb;
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_rh_cache_%'"
        );
        
        $size = $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_rh_cache_%'"
        );
        
        return [
            'total_items' => (int) $total,
            'total_size' => size_format((int) $size),
            'enabled' => $this->enabled
        ];
    }
    
    /**
     * Enable cache
     */
    public function enable() {
        $this->enabled = true;
        update_option('rh_enable_cache', 1);
    }
    
    /**
     * Disable cache
     */
    public function disable() {
        $this->enabled = false;
        update_option('rh_enable_cache', 0);
    }
    
    /**
     * Check if cache is enabled
     */
    public function is_enabled() {
        return $this->enabled;
    }
}