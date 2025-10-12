<?php
/**
 * Hotel Sync Class
 * File: includes/class-rh-hotel-sync.php
 * 
 * Syncs hotels from Ratehawk API to Traveler theme
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Hotel_Sync {
    
    private static $instance = null;
    
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
        // AJAX handlers
        add_action('wp_ajax_rh_sync_test_hotel', [$this, 'ajax_sync_test_hotel']);
        add_action('wp_ajax_rh_sync_single_hotel', [$this, 'ajax_sync_single_hotel']);
        
        // Cron for daily sync (disabled for now - test mode)
        // add_action('rh_daily_hotel_sync', [$this, 'sync_all_hotels']);
    }
    
    /**
     * Sync test hotel
     */
    public function sync_test_hotel() {
        try {
            return $this->sync_hotel_by_id(RH_TEST_HOTEL_ID, RH_TEST_HOTEL_HID);
        } catch (Exception $e) {
            rh_log('Test Hotel Sync Failed', ['error' => $e->getMessage()], 'error');
            throw $e;
        }
    }
    
    /**
     * Sync single hotel by ID
     */
    public function sync_hotel_by_id($hotel_id, $hotel_hid = null) {
        global $wpdb;
        
        rh_log('Starting hotel sync', [
            'hotel_id' => $hotel_id,
            'hotel_hid' => $hotel_hid
        ], 'info');
        
        // Step 1: Get hotel data from API
        $hotel_data = $this->fetch_hotel_data($hotel_id);
        
        if (empty($hotel_data)) {
            throw new Exception('Failed to fetch hotel data from API');
        }
        
        // Extract data
        $hotel_info = $hotel_data['data'] ?? [];
        $hotel_hid = $hotel_hid ?? ($hotel_info['hid'] ?? 0);
        
        if (empty($hotel_hid)) {
            throw new Exception('Hotel HID not found');
        }
        
        // Step 2: Check if hotel already exists
        $existing_post_id = $this->get_existing_hotel($hotel_hid);
        
        if ($existing_post_id) {
            rh_log('Hotel already exists', ['post_id' => $existing_post_id], 'info');
            return $this->update_hotel($existing_post_id, $hotel_info, $hotel_hid);
        }
        
        // Step 3: Create new hotel post
        $post_id = $this->create_hotel_post($hotel_info);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create hotel post: ' . $post_id->get_error_message());
        }
        
        // Step 4: Save hotel metadata
        $this->save_hotel_meta($post_id, $hotel_info);
        
        // Step 5: Save taxonomy terms
        $this->save_hotel_taxonomies($post_id, $hotel_info);
        
        // Step 6: Download and attach images
        $this->attach_hotel_images($post_id, $hotel_info);
        
        // Step 7: Save mapping to database
        $this->save_hotel_mapping($post_id, $hotel_id, $hotel_hid);
        
        rh_log('Hotel synced successfully', [
            'post_id' => $post_id,
            'hotel_id' => $hotel_id,
            'hotel_hid' => $hotel_hid
        ], 'info');
        
        return [
            'success' => true,
            'post_id' => $post_id,
            'hotel_name' => $hotel_info['name'] ?? '',
            'hotel_id' => $hotel_id,
            'hotel_hid' => $hotel_hid,
            'permalink' => get_permalink($post_id)
        ];
    }
    
    /**
     * Fetch hotel data from API
     */
    private function fetch_hotel_data($hotel_id) {
        $language = rh_get_current_language();
        
        // Try cache first
        $cache_key = "hotel_info_{$hotel_id}_{$language}";
        $cached = rh_cache()->get($cache_key, 'hotel_static');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch from API
        $result = rh_api()->get_hotel_info($hotel_id, $language);
        
        // Cache for 24 hours
        if (!empty($result['data'])) {
            rh_cache()->set($cache_key, $result, 86400, 'hotel_static');
        }
        
        return $result;
    }
    
    /**
     * Check if hotel already exists
     */
    private function get_existing_hotel($hotel_hid) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT st_hotel_post_id FROM $table WHERE ratehawk_hid = %d",
            $hotel_hid
        ));
    }
    
    /**
     * Create hotel post
     */
    private function create_hotel_post($hotel_info) {
        $language = rh_get_current_language();
        
        $post_data = [
            'post_type' => 'st_hotel',
            'post_title' => sanitize_text_field($hotel_info['name'] ?? 'Unnamed Hotel'),
            'post_content' => wp_kses_post($hotel_info['description'] ?? ''),
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
            'meta_input' => [
                '_is_ratehawk_hotel' => 1
            ]
        ];
        
        $post_id = wp_insert_post($post_data);
        
        // Set language (Polylang)
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $language);
        }
        
        return $post_id;
    }
    
    /**
     * Save hotel metadata
     */
    private function save_hotel_meta($post_id, $hotel_info) {
        // Basic info
        update_post_meta($post_id, '_ratehawk_hid', $hotel_info['hid'] ?? 0);
        update_post_meta($post_id, '_ratehawk_id', $hotel_info['id'] ?? '');
        update_post_meta($post_id, 'address', sanitize_text_field($hotel_info['address'] ?? ''));
        update_post_meta($post_id, 'map_lat', floatval($hotel_info['latitude'] ?? 0));
        update_post_meta($post_id, 'map_lng', floatval($hotel_info['longitude'] ?? 0));
        
        // Star rating
        $star_rating = intval($hotel_info['star_rating'] ?? 0);
        update_post_meta($post_id, 'star_rate', $star_rating);
        
        // Check-in/out times
        if (!empty($hotel_info['check_in_time'])) {
            update_post_meta($post_id, 'check_in_time', sanitize_text_field($hotel_info['check_in_time']));
        }
        if (!empty($hotel_info['check_out_time'])) {
            update_post_meta($post_id, 'check_out_time', sanitize_text_field($hotel_info['check_out_time']));
        }
        
        // Contact info
        if (!empty($hotel_info['phone'])) {
            update_post_meta($post_id, 'phone', sanitize_text_field($hotel_info['phone']));
        }
        if (!empty($hotel_info['email'])) {
            update_post_meta($post_id, 'email', sanitize_email($hotel_info['email']));
        }
        
        // Policy info (metapolicy_struct)
        if (!empty($hotel_info['metapolicy_struct'])) {
            update_post_meta($post_id, '_rh_metapolicy', wp_json_encode($hotel_info['metapolicy_struct']));
        }
        
        // Store full hotel data for reference
        update_post_meta($post_id, '_rh_full_data', wp_json_encode($hotel_info));
        
        // Last sync time
        update_post_meta($post_id, '_rh_last_sync', current_time('mysql'));
        
        // CRITICAL: Traveler theme required fields (fix Fatal Error)
        update_post_meta($post_id, 'multi_location', 'off');
        update_post_meta($post_id, 'is_instant_booking', 'off');
        update_post_meta($post_id, 'booking_period', 1); // int (not string!)
        update_post_meta($post_id, 'discount_rate', 0);
        update_post_meta($post_id, 'price', 0); // Will be filled by API dynamically
        update_post_meta($post_id, 'price_unit', __('per night', 'ratehawk-traveler'));
        update_post_meta($post_id, 'max_adult', 10);
        update_post_meta($post_id, 'max_child', 5);
        update_post_meta($post_id, 'allow_full_day', 'on');
        update_post_meta($post_id, 'deposit_payment_status', 'off');
        update_post_meta($post_id, 'cancel_booking_fee', 0);
        update_post_meta($post_id, 'hotel_star', $star_rating);
        
        // Video (optional)
        update_post_meta($post_id, 'video', '');
        
        // Enable Ratehawk booking flag
        update_post_meta($post_id, '_is_ratehawk_hotel', 1);
    }
    
    /**
     * Save hotel taxonomies
     */
    private function save_hotel_taxonomies($post_id, $hotel_info) {
        // Facilities
        if (!empty($hotel_info['amenity_groups'])) {
            $facility_ids = [];
            
            foreach ($hotel_info['amenity_groups'] as $group) {
                if (empty($group['amenities'])) continue;
                
                foreach ($group['amenities'] as $amenity) {
                    $term_name = sanitize_text_field($amenity['name'] ?? $amenity);
                    
                    // Get or create term
                    $term = term_exists($term_name, 'hotel-facilities');
                    if (!$term) {
                        $term = wp_insert_term($term_name, 'hotel-facilities');
                    }
                    
                    if (!is_wp_error($term) && isset($term['term_id'])) {
                        $facility_ids[] = intval($term['term_id']);
                    }
                }
            }
            
            if (!empty($facility_ids)) {
                wp_set_object_terms($post_id, $facility_ids, 'hotel-facilities');
            }
        }
        
        // Hotel kind/type
        if (!empty($hotel_info['kind'])) {
            $kind_name = sanitize_text_field($hotel_info['kind']['name'] ?? $hotel_info['kind']);
            
            $term = term_exists($kind_name, 'hotel-theme');
            if (!$term) {
                $term = wp_insert_term($kind_name, 'hotel-theme');
            }
            
            if (!is_wp_error($term) && isset($term['term_id'])) {
                wp_set_object_terms($post_id, [$term['term_id']], 'hotel-theme');
            }
        }
    }
    
    /**
     * Attach hotel images
     */
    private function attach_hotel_images($post_id, $hotel_info) {
        if (empty($hotel_info['images'])) {
            rh_log('No images found for hotel', ['post_id' => $post_id], 'warning');
            return;
        }
        
        // Check if media functions are available
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $images = array_slice($hotel_info['images'], 0, 10); // Limit to 10 images
        $gallery_ids = [];
        $featured_set = false;
        
        foreach ($images as $index => $image_data) {
            // Handle different image formats
            $image_url = '';
            
            if (is_string($image_data)) {
                $image_url = $image_data;
            } elseif (is_array($image_data)) {
                $image_url = $image_data['url'] ?? '';
            }
            
            if (empty($image_url)) {
                continue;
            }
            
            // CRITICAL FIX: Replace {size} placeholder with actual size
            if (strpos($image_url, '{size}') !== false) {
                // Use 1024x768 for high quality
                $image_url = str_replace('{size}', '1024x768', $image_url);
            }
            
            try {
                $attachment_id = $this->download_image($image_url, $post_id);
                
                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $gallery_ids[] = $attachment_id;
                    
                    rh_log('Image downloaded successfully', [
                        'post_id' => $post_id,
                        'attachment_id' => $attachment_id,
                        'index' => $index
                    ], 'info');
                    
                    // Set first image as featured
                    if (!$featured_set) {
                        set_post_thumbnail($post_id, $attachment_id);
                        $featured_set = true;
                    }
                } else {
                    $error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
                    rh_log('Image download failed', [
                        'url' => $image_url,
                        'error' => $error_msg
                    ], 'warning');
                }
            } catch (Exception $e) {
                rh_log('Image download exception', [
                    'url' => $image_url,
                    'error' => $e->getMessage()
                ], 'warning');
            }
        }
        
        // Save gallery
        if (!empty($gallery_ids)) {
            update_post_meta($post_id, 'gallery', implode(',', $gallery_ids));
            rh_log('Gallery saved', [
                'post_id' => $post_id,
                'total_images' => count($gallery_ids)
            ], 'info');
        } else {
            rh_log('No images were downloaded', ['post_id' => $post_id], 'warning');
        }
    }
    
    /**
     * Download and attach image
     */
    private function download_image($url, $post_id) {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid image URL');
        }
        
        // Check if allow_url_fopen is enabled
        if (!ini_get('allow_url_fopen')) {
            throw new Exception('allow_url_fopen is disabled on server');
        }
        
        // Download image
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            throw new Exception($tmp->get_error_message());
        }
        
        // Get file extension from URL
        $file_ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_ext)) {
            $file_ext = 'jpg';
        }
        
        // Prepare file array
        $file_array = [
            'name' => 'hotel-' . $post_id . '-' . time() . '-' . wp_rand(1000, 9999) . '.' . $file_ext,
            'tmp_name' => $tmp
        ];
        
        // Handle sideload
        $attachment_id = media_handle_sideload($file_array, $post_id, '', [
            'test_form' => false
        ]);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception($attachment_id->get_error_message());
        }
        
        return $attachment_id;
    }
    
    /**
     * Save hotel mapping
     */
    private function save_hotel_mapping($post_id, $hotel_id, $hotel_hid) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        
        $wpdb->replace($table, [
            'st_hotel_post_id' => $post_id,
            'ratehawk_hid' => $hotel_hid,
            'ratehawk_id' => $hotel_id,
            'sync_status' => 'completed',
            'last_sync' => current_time('mysql')
        ]);
    }
    
    /**
     * Update existing hotel
     */
    private function update_hotel($post_id, $hotel_info, $hotel_hid) {
        // Update post
        wp_update_post([
            'ID' => $post_id,
            'post_title' => sanitize_text_field($hotel_info['name'] ?? 'Unnamed Hotel'),
            'post_content' => wp_kses_post($hotel_info['description'] ?? '')
        ]);
        
        // Update metadata
        $this->save_hotel_meta($post_id, $hotel_info);
        $this->save_hotel_taxonomies($post_id, $hotel_info);
        
        // Update mapping
        $this->save_hotel_mapping($post_id, $hotel_info['id'], $hotel_hid);
        
        rh_log('Hotel updated', ['post_id' => $post_id], 'info');
        
        return [
            'success' => true,
            'post_id' => $post_id,
            'hotel_name' => $hotel_info['name'] ?? '',
            'updated' => true,
            'permalink' => get_permalink($post_id)
        ];
    }
    
    /**
     * AJAX: Sync test hotel
     */
    public function ajax_sync_test_hotel() {
        check_ajax_referer('ratehawk_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            $result = $this->sync_test_hotel();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Sync single hotel
     */
    public function ajax_sync_single_hotel() {
        check_ajax_referer('ratehawk_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $hotel_id = sanitize_text_field($_POST['hotel_id'] ?? '');
        $hotel_hid = intval($_POST['hotel_hid'] ?? 0);
        
        if (empty($hotel_id)) {
            wp_send_json_error('Hotel ID required');
        }
        
        try {
            $result = $this->sync_hotel_by_id($hotel_id, $hotel_hid);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}