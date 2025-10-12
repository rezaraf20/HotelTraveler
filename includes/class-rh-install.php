<?php
/**
 * Installation and Setup Class
 * File: includes/class-rh-install.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Install {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Create log directory
        self::create_log_directory();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        rh_log('Plugin activated', ['version' => RH_VERSION], 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('rh_daily_hotel_sync');
        wp_clear_scheduled_hook('rh_cleanup_expired_prebooks');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        rh_log('Plugin deactivated', [], 'info');
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table 1: Hotel Mapping
        $table_name = $wpdb->prefix . 'rh_hotel_mapping';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            st_hotel_post_id bigint(20) NOT NULL COMMENT 'Traveler st_hotel post ID',
            ratehawk_hid bigint(20) NOT NULL,
            ratehawk_id varchar(100),
            sync_status varchar(20) DEFAULT 'pending',
            last_sync datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_hid (ratehawk_hid),
            KEY idx_post_id (st_hotel_post_id),
            KEY idx_sync_status (sync_status)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 2: Bookings
        $table_name = $wpdb->prefix . 'rh_bookings';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_order_id varchar(100) NOT NULL,
            ratehawk_order_id varchar(100) DEFAULT NULL,
            traveler_booking_id bigint(20) DEFAULT NULL COMMENT 'Traveler booking ID',
            user_id bigint(20) NOT NULL,
            st_hotel_post_id bigint(20) NOT NULL,
            hotel_name varchar(255),
            checkin date NOT NULL,
            checkout date NOT NULL,
            adults tinyint NOT NULL,
            children_ages text COMMENT 'JSON array',
            total_price decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            prebook_hash varchar(255),
            prebook_expires_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            booking_data longtext COMMENT 'Full JSON response',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_partner_order (partner_order_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_ratehawk_order (ratehawk_order_id),
            KEY idx_prebook_expires (prebook_expires_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 3: Logs
        $table_name = $wpdb->prefix . 'rh_logs';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type varchar(50) NOT NULL,
            message text,
            log_data longtext COMMENT 'JSON',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_log_type (log_type),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        rh_log('Database tables created', [], 'info');
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        $defaults = [
            // API Settings
            'rh_api_key_id' => '',
            'rh_api_key' => '',
            'rh_environment' => 'sandbox',
            
            // General Settings
            'rh_currency' => 'USD',
            'rh_language' => 'en',
            'rh_sync_mode' => 'test',
            
            // Prebook Settings
            'rh_prebook_ttl' => 900, // 15 minutes
            'rh_price_tolerance' => 10, // 10%
            'rh_require_price_approval' => 1,
            
            // Cache Settings
            'rh_enable_cache' => 1,
            'rh_cache_duration' => 300, // 5 minutes for search
            
            // System Settings
            'rh_enable_logging' => 1,
            'rh_log_retention_days' => 30,
            
            // Plugin Version
            'rh_version' => RH_VERSION,
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value, '', 'no');
            }
        }
        
        rh_log('Default options set', [], 'info');
    }
    
    /**
     * Create log directory
     */
    private static function create_log_directory() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/ratehawk-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Create .htaccess to protect logs
            $htaccess = $log_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            
            // Create index.php
            $index = $log_dir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }
}