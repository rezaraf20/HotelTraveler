<?php
/**
 * Ratehawk Location Manager
 * 
 * مدیریت کشورها و شهرها در Traveler Location Post Type
 * این کلاس کشورها و شهرها رو از Ratehawk می‌گیره و در location post type ذخیره می‌کنه
 */

if (!defined('ABSPATH')) exit;

class RH_Location_Manager {
    
    private static $instance = null;
    
    // Cache برای جلوگیری از query های تکراری
    private $location_cache = [];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * پیدا کردن یا ساختن Location برای هتل
     * 
     * @param array $region_data داده‌های region از API Ratehawk
     * @return int|false Location ID یا false در صورت خطا
     */
    public function get_or_create_location($region_data) {
        if (empty($region_data)) {
            rh_log('Region data is empty', [], 'warning');
            return false;
        }
        
        // استخراج اطلاعات
        $country_code = $region_data['country_code'] ?? '';
        $city_name = $region_data['name'] ?? '';
        $region_type = $region_data['type'] ?? 'City';
        
        if (empty($country_code) || empty($city_name)) {
            rh_log('Missing country code or city name', $region_data, 'warning');
            return false;
        }
        
        // تبدیل کد کشور به نام کامل
        $country_name = $this->get_country_name($country_code);
        
        rh_log('Processing location', [
            'country_code' => $country_code,
            'country_name' => $country_name,
            'city_name' => $city_name
        ], 'info');
        
        // 1. پیدا کردن یا ساختن کشور
        $country_id = $this->get_or_create_country($country_name, $country_code);
        
        if (!$country_id) {
            rh_log('Failed to create country', ['country_name' => $country_name], 'error');
            return false;
        }
        
        // 2. پیدا کردن یا ساختن شهر (به عنوان child کشور)
        $city_id = $this->get_or_create_city($city_name, $country_id, $region_data);
        
        if (!$city_id) {
            rh_log('Failed to create city', ['city_name' => $city_name], 'error');
            return false;
        }
        
        rh_log('Location processed successfully', [
            'country_id' => $country_id,
            'city_id' => $city_id
        ], 'info');
        
        return $city_id;
    }
    
    /**
     * پیدا کردن یا ساختن کشور
     */
    private function get_or_create_country($country_name, $country_code) {
        // چک کردن cache
        $cache_key = 'country_' . $country_code;
        if (isset($this->location_cache[$cache_key])) {
            return $this->location_cache[$cache_key];
        }
        
        // جستجو در پست‌های موجود
        $existing = $this->find_location_by_name($country_name, 0);
        
        if ($existing) {
            $this->location_cache[$cache_key] = $existing;
            return $existing;
        }
        
        // ساخت کشور جدید
        $post_data = [
            'post_type' => 'location',
            'post_title' => sanitize_text_field($country_name),
            'post_status' => 'publish',
            'post_parent' => 0, // کشور parent نداره
            'post_author' => get_current_user_id() ?: 1
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            rh_log('Failed to create country post', [
                'country_name' => $country_name,
                'error' => $post_id->get_error_message()
            ], 'error');
            return false;
        }
        
        // ذخیره متادیتا
        update_post_meta($post_id, '_rh_country_code', $country_code);
        update_post_meta($post_id, '_rh_location_type', 'country');
        update_post_meta($post_id, 'st_location_type', 'country'); // برای Traveler
        
        // Cache کردن
        $this->location_cache[$cache_key] = $post_id;
        
        rh_log('Country created', [
            'post_id' => $post_id,
            'country_name' => $country_name,
            'country_code' => $country_code
        ], 'info');
        
        return $post_id;
    }
    
    /**
     * پیدا کردن یا ساختن شهر
     */
    private function get_or_create_city($city_name, $country_id, $region_data) {
        // چک کردن cache
        $cache_key = 'city_' . $country_id . '_' . sanitize_title($city_name);
        if (isset($this->location_cache[$cache_key])) {
            return $this->location_cache[$cache_key];
        }
        
        // جستجو در شهرهای موجود (child های این کشور)
        $existing = $this->find_location_by_name($city_name, $country_id);
        
        if ($existing) {
            $this->location_cache[$cache_key] = $existing;
            return $existing;
        }
        
        // ساخت شهر جدید
        $post_data = [
            'post_type' => 'location',
            'post_title' => sanitize_text_field($city_name),
            'post_status' => 'publish',
            'post_parent' => $country_id, // شهر child کشور است
            'post_author' => get_current_user_id() ?: 1
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            rh_log('Failed to create city post', [
                'city_name' => $city_name,
                'error' => $post_id->get_error_message()
            ], 'error');
            return false;
        }
        
        // ذخیره متادیتا
        update_post_meta($post_id, '_rh_location_type', 'city');
        update_post_meta($post_id, 'st_location_type', 'city'); // برای Traveler
        update_post_meta($post_id, '_rh_region_id', $region_data['id'] ?? 0);
        update_post_meta($post_id, '_rh_region_iata', $region_data['iata'] ?? '');
        
        // مختصات جغرافیایی (اگر موجود باشد)
        if (!empty($region_data['latitude'])) {
            update_post_meta($post_id, 'map_lat', floatval($region_data['latitude']));
        }
        if (!empty($region_data['longitude'])) {
            update_post_meta($post_id, 'map_lng', floatval($region_data['longitude']));
        }
        
        // Cache کردن
        $this->location_cache[$cache_key] = $post_id;
        
        rh_log('City created', [
            'post_id' => $post_id,
            'city_name' => $city_name,
            'country_id' => $country_id
        ], 'info');
        
        return $post_id;
    }
    
    /**
     * جستجوی location بر اساس نام و parent
     */
    private function find_location_by_name($name, $parent_id = 0) {
        $args = [
            'post_type' => 'location',
            'title' => $name,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post_parent' => $parent_id,
            'fields' => 'ids'
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        // اگر با title دقیق پیدا نشد، با LIKE جستجو کن
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'location' 
             AND post_status = 'publish'
             AND post_parent = %d
             AND post_title LIKE %s
             LIMIT 1",
            $parent_id,
            '%' . $wpdb->esc_like($name) . '%'
        );
        
        $result = $wpdb->get_var($sql);
        
        return $result ? intval($result) : false;
    }
    
    /**
     * تبدیل کد کشور به نام کامل
     */
    private function get_country_name($country_code) {
        $countries = [
            'HN' => 'Honduras',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'FR' => 'France',
            'DE' => 'Germany',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'JP' => 'Japan',
            'CN' => 'China',
            'IN' => 'India',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'RU' => 'Russia',
            'TR' => 'Turkey',
            'AE' => 'United Arab Emirates',
            'TH' => 'Thailand',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'ID' => 'Indonesia',
            'VN' => 'Vietnam',
            'PH' => 'Philippines',
            'KR' => 'South Korea',
            'EG' => 'Egypt',
            'SA' => 'Saudi Arabia',
            'ZA' => 'South Africa',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'GR' => 'Greece',
            'PT' => 'Portugal',
            'IE' => 'Ireland',
            'NZ' => 'New Zealand',
            'IL' => 'Israel',
            'MA' => 'Morocco',
            'KE' => 'Kenya',
            'NG' => 'Nigeria'
        ];
        
        return $countries[$country_code] ?? $country_code;
    }
    
    /**
     * لینک کردن هتل به location
     */
    public function link_hotel_to_location($hotel_post_id, $location_id) {
    if (!$hotel_post_id || !$location_id) {
        return false;
    }
    
    // ✅ در Traveler باید STRING با کاما باشه، نه array!
    update_post_meta($hotel_post_id, 'id_location', $location_id);
    update_post_meta($hotel_post_id, 'location_id', $location_id);
    update_post_meta($hotel_post_id, 'multi_location', (string)$location_id); // ✅ تبدیل به string
    
    rh_log('Hotel linked to location', [
        'hotel_post_id' => $hotel_post_id,
        'location_id' => $location_id
    ], 'info');
    
    return true;
}
    
    /**
     * Clear کردن cache
     */
    public function clear_cache() {
        $this->location_cache = [];
    }
}

// Helper function
function rh_location_manager() {
    return RH_Location_Manager::instance();
}