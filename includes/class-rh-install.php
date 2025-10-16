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
        self::create_tables();
        self::set_default_options();
        self::create_log_directory();
        self::schedule_cron_jobs();
        
        flush_rewrite_rules();
        
        rh_log('Plugin activated', ['version' => RH_VERSION], 'info');
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('rh_daily_hotel_sync');
        wp_clear_scheduled_hook('rh_sync_regions');
        wp_clear_scheduled_hook('rh_cleanup_expired_prebooks');
        
        flush_rewrite_rules();
        
        rh_log('Plugin deactivated', [], 'info');
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table 1: Hotels (Static Data)
        $table_name = $wpdb->prefix . 'rh_hotels';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            hid bigint(20) NOT NULL COMMENT 'Ratehawk Hotel ID',
            ratehawk_id varchar(100) DEFAULT NULL,
            name varchar(255) NOT NULL,
            name_en varchar(255) DEFAULT NULL,
            name_fr varchar(255) DEFAULT NULL,
            region_id int(11) DEFAULT NULL,
            region_name varchar(255) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            city varchar(255) DEFAULT NULL,
            address text,
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            star_rating tinyint DEFAULT NULL,
            hotel_type varchar(50) DEFAULT NULL,
            description_en text,
            description_fr text,
            amenities text COMMENT 'JSON array',
            images text COMMENT 'JSON array',
            rooms_data longtext COMMENT 'JSON - room groups',
            metadata longtext COMMENT 'JSON - full static data',
            is_active tinyint(1) DEFAULT 1,
            st_hotel_post_id bigint(20) DEFAULT NULL COMMENT 'Linked Traveler post',
            last_sync datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_hid (hid),
            KEY idx_region (region_id),
            KEY idx_country (country_code),
            KEY idx_city (city),
            KEY idx_stars (star_rating),
            KEY idx_active (is_active),
            KEY idx_post_id (st_hotel_post_id)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 2: Regions Cache
        $table_name = $wpdb->prefix . 'rh_regions';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            region_id int(11) NOT NULL,
            name varchar(255) NOT NULL,
            name_en varchar(255) DEFAULT NULL,
            name_fr varchar(255) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            region_type varchar(50) DEFAULT NULL COMMENT 'City, Region, Country',
            iata varchar(10) DEFAULT NULL,
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            hotels_count int(11) DEFAULT 0,
            is_popular tinyint(1) DEFAULT 0,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_region_id (region_id),
            KEY idx_country (country_code),
            KEY idx_popular (is_popular)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 3: Bookings
        $table_name = $wpdb->prefix . 'rh_bookings';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_order_id varchar(100) NOT NULL,
            ratehawk_order_id varchar(100) DEFAULT NULL,
            user_id bigint(20) NOT NULL,
            hotel_hid bigint(20) NOT NULL,
            hotel_name varchar(255),
            checkin date NOT NULL,
            checkout date NOT NULL,
            nights tinyint NOT NULL,
            adults tinyint NOT NULL,
            children_ages text COMMENT 'JSON array',
            room_name varchar(255),
            meal_type varchar(50),
            total_price decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            book_hash varchar(255),
            prebook_id varchar(255),
            prebook_expires_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'pending' COMMENT 'pending, confirmed, cancelled, failed',
            payment_status varchar(20) DEFAULT 'pending',
            payment_method varchar(50),
            guest_data longtext COMMENT 'JSON - guest information',
            booking_data longtext COMMENT 'JSON - full API response',
            voucher_url text,
            cancellation_policy text COMMENT 'JSON',
            special_requests text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_partner_order (partner_order_id),
            KEY idx_user_id (user_id),
            KEY idx_hotel (hotel_hid),
            KEY idx_status (status),
            KEY idx_dates (checkin, checkout),
            KEY idx_ratehawk_order (ratehawk_order_id)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 4: Search Cache
        $table_name = $wpdb->prefix . 'rh_search_cache';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            search_type varchar(50) NOT NULL COMMENT 'region, hotels, geo',
            search_params text COMMENT 'JSON',
            results longtext COMMENT 'JSON',
            hotels_count int(11) DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_cache_key (cache_key),
            KEY idx_expires (expires_at),
            KEY idx_search_type (search_type)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Table 5: Logs
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
        
        rh_log('Database tables created/updated', [], 'info');
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        $defaults = [
            'rh_api_key_id' => '',
            'rh_api_key' => '',
            'rh_environment' => 'sandbox',
            'rh_currency' => 'USD',
            'rh_language' => 'en',
            'rh_sync_mode' => 'test',
            'rh_prebook_ttl' => 900,
            'rh_price_tolerance' => 10,
            'rh_require_price_approval' => 1,
            'rh_enable_cache' => 1,
            'rh_cache_duration' => 300,
            'rh_enable_logging' => 1,
            'rh_log_retention_days' => 30,
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
            
            $htaccess = $log_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
            
            $index = $log_dir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Schedule cron jobs
     */
    private static function schedule_cron_jobs() {
        // Daily hotel sync at 3 AM
        if (!wp_next_scheduled('rh_daily_hotel_sync')) {
            wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', 'rh_daily_hotel_sync');
        }
        
        // Weekly region sync
        if (!wp_next_scheduled('rh_sync_regions')) {
            wp_schedule_event(time(), 'weekly', 'rh_sync_regions');
        }
        
        // Cleanup expired prebooks every hour
        if (!wp_next_scheduled('rh_cleanup_expired_prebooks')) {
            wp_schedule_event(time(), 'hourly', 'rh_cleanup_expired_prebooks');
        }
    }
}