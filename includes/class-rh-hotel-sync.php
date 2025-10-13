<?php
/**
 * Hotel Sync Class (Complete Version)
 * File: includes/class-rh-hotel-sync.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Hotel_Sync {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_rh_sync_test_hotel', [$this, 'ajax_sync_test_hotel']);
        add_action('wp_ajax_rh_sync_single_hotel', [$this, 'ajax_sync_single_hotel']);
    }
    
    public function sync_test_hotel() {
        try {
            return $this->sync_hotel_by_id(RH_TEST_HOTEL_ID, RH_TEST_HOTEL_HID);
        } catch (Exception $e) {
            rh_log('Test Hotel Sync Failed', ['error' => $e->getMessage()], 'error');
            throw $e;
        }
    }
    
    public function sync_hotel_by_id($hotel_id, $hotel_hid = null) {
        global $wpdb;
        
        rh_log('Starting hotel sync', [
            'hotel_id' => $hotel_id,
            'hotel_hid' => $hotel_hid
        ], 'info');
        
        $hotel_data = $this->fetch_hotel_data($hotel_id);
        
        if (empty($hotel_data)) {
            throw new Exception('Failed to fetch hotel data from API');
        }
        
        $hotel_info = $hotel_data['data'] ?? [];
        $hotel_hid = $hotel_hid ?? ($hotel_info['hid'] ?? 0);
        
        if (empty($hotel_hid)) {
            throw new Exception('Hotel HID not found');
        }
        
        $existing_post_id = $this->get_existing_hotel($hotel_hid);
        
        if ($existing_post_id) {
            rh_log('Hotel already exists', ['post_id' => $existing_post_id], 'info');
            return $this->update_hotel($existing_post_id, $hotel_info, $hotel_hid);
        }
        
        $post_id = $this->create_hotel_post($hotel_info);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create hotel post: ' . $post_id->get_error_message());
        }
        
        $this->save_hotel_meta($post_id, $hotel_info);
        $this->save_hotel_taxonomies($post_id, $hotel_info);
        $this->attach_hotel_images($post_id, $hotel_info);
        $this->sync_hotel_rooms($post_id, $hotel_info);
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
    
    private function fetch_hotel_data($hotel_id) {
        $language = rh_get_current_language();
        $cache_key = "hotel_info_{$hotel_id}_{$language}";
        $cached = rh_cache()->get($cache_key, 'hotel_static');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $result = rh_api()->get_hotel_info($hotel_id, $language);
        
        if (!empty($result['data'])) {
            rh_cache()->set($cache_key, $result, 86400, 'hotel_static');
        }
        
        return $result;
    }
    
    private function get_existing_hotel($hotel_hid) {
        global $wpdb;
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT st_hotel_post_id FROM $table WHERE ratehawk_hid = %d",
            $hotel_hid
        ));
    }
    
    private function create_hotel_post($hotel_info) {
        $language = rh_get_current_language();
        
        // Format description from description_struct (structured)
        $description = $this->format_description($hotel_info);
        
        $post_data = [
            'post_type' => 'st_hotel',
            'post_title' => sanitize_text_field($hotel_info['name'] ?? 'Unnamed Hotel'),
            'post_content' => $description,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
            'meta_input' => [
                '_is_ratehawk_hotel' => 1
            ]
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $language);
        }
        
        return $post_id;
    }
    
    /**
     * Format description from description_struct
     */
    private function format_description($hotel_info) {
        $description = '';
        
        // Try description_struct first (better format)
        if (!empty($hotel_info['description_struct'])) {
            foreach ($hotel_info['description_struct'] as $section) {
                if (!empty($section['title'])) {
                    $description .= '<h3>' . esc_html($section['title']) . '</h3>' . "\n";
                }
                
                if (!empty($section['paragraphs'])) {
                    foreach ($section['paragraphs'] as $paragraph) {
                        $description .= '<p>' . esc_html($paragraph) . '</p>' . "\n";
                    }
                }
            }
        }
        
        // Fallback to simple description
        if (empty($description) && !empty($hotel_info['description'])) {
            $description = '<p>' . esc_html($hotel_info['description']) . '</p>';
        }
        
        return $description;
    }
    
    private function save_hotel_meta($post_id, $hotel_info) {
        // Basic info
        update_post_meta($post_id, '_ratehawk_hid', $hotel_info['hid'] ?? 0);
        update_post_meta($post_id, '_ratehawk_id', $hotel_info['id'] ?? '');
        update_post_meta($post_id, 'address', sanitize_text_field($hotel_info['address'] ?? ''));
        update_post_meta($post_id, 'map_lat', floatval($hotel_info['latitude'] ?? 0));
        update_post_meta($post_id, 'map_lng', floatval($hotel_info['longitude'] ?? 0));
        
        $star_rating = intval($hotel_info['star_rating'] ?? 0);
        update_post_meta($post_id, 'star_rate', $star_rating);
        
        if (!empty($hotel_info['check_in_time'])) {
            update_post_meta($post_id, 'check_in_time', sanitize_text_field($hotel_info['check_in_time']));
        }
        if (!empty($hotel_info['check_out_time'])) {
            update_post_meta($post_id, 'check_out_time', sanitize_text_field($hotel_info['check_out_time']));
        }
        
        if (!empty($hotel_info['phone'])) {
            update_post_meta($post_id, 'phone', sanitize_text_field($hotel_info['phone']));
        }
        if (!empty($hotel_info['email'])) {
            update_post_meta($post_id, 'email', sanitize_email($hotel_info['email']));
        }
        
        // Region/Location
        if (!empty($hotel_info['region'])) {
            update_post_meta($post_id, '_rh_region', wp_json_encode($hotel_info['region']));
            
            // Save separately for easy access
            update_post_meta($post_id, '_rh_country', $hotel_info['region']['country_code'] ?? '');
            update_post_meta($post_id, '_rh_city', $hotel_info['region']['name'] ?? '');
        }
        
        // Policies (structured)
        if (!empty($hotel_info['policy_struct'])) {
            update_post_meta($post_id, '_rh_policy_struct', wp_json_encode($hotel_info['policy_struct']));
            
            // Also save as readable text for Traveler "Hotel policy" field
            $policy_text = $this->format_policies($hotel_info['policy_struct']);
            update_post_meta($post_id, 'hotel_policy', $policy_text);
        }
        
        // Metapolicy
        if (!empty($hotel_info['metapolicy_struct'])) {
            update_post_meta($post_id, '_rh_metapolicy', wp_json_encode($hotel_info['metapolicy_struct']));
        }
        
        // Extra info
        if (!empty($hotel_info['metapolicy_extra_info'])) {
            update_post_meta($post_id, '_rh_extra_info', sanitize_textarea_field($hotel_info['metapolicy_extra_info']));
        }
        
        // Payment methods
        if (!empty($hotel_info['payment_methods'])) {
            update_post_meta($post_id, '_rh_payment_methods', wp_json_encode($hotel_info['payment_methods']));
        }
        
        // Keys pickup info
        if (!empty($hotel_info['keys_pickup'])) {
            update_post_meta($post_id, '_rh_keys_pickup', wp_json_encode($hotel_info['keys_pickup']));
        }
        
        // Store full hotel data
        update_post_meta($post_id, '_rh_full_data', wp_json_encode($hotel_info));
        update_post_meta($post_id, '_rh_last_sync', current_time('mysql'));
        
        // Traveler required fields
        update_post_meta($post_id, 'multi_location', 'off');
        update_post_meta($post_id, 'is_instant_booking', 'off');
        update_post_meta($post_id, 'booking_period', 1);
        update_post_meta($post_id, 'hotel_booking_period', 1);
        update_post_meta($post_id, 'discount_rate', 0);
        update_post_meta($post_id, 'price', 0);
        update_post_meta($post_id, 'price_unit', __('per night', 'ratehawk-traveler'));
        update_post_meta($post_id, 'max_adult', 10);
        update_post_meta($post_id, 'max_child', 5);
        update_post_meta($post_id, 'allow_full_day', 'on');
        update_post_meta($post_id, 'deposit_payment_status', 'off');
        update_post_meta($post_id, 'cancel_booking_fee', 0);
        update_post_meta($post_id, 'hotel_star', $star_rating);
        update_post_meta($post_id, 'video', '');
        update_post_meta($post_id, '_is_ratehawk_hotel', 1);
    }
    
    /**
     * Format policies as readable text
     */
    private function format_policies($policy_struct) {
        $text = '';
        
        foreach ($policy_struct as $policy) {
            if (!empty($policy['title'])) {
                $text .= strtoupper($policy['title']) . "\n\n";
            }
            
            if (!empty($policy['paragraphs'])) {
                foreach ($policy['paragraphs'] as $paragraph) {
                    $text .= '• ' . $paragraph . "\n";
                }
                $text .= "\n";
            }
        }
        
        return $text;
    }
    
    private function save_hotel_taxonomies($post_id, $hotel_info) {
        if (!empty($hotel_info['amenity_groups'])) {
            $facility_ids = [];
            
            foreach ($hotel_info['amenity_groups'] as $group) {
                if (empty($group['amenities'])) continue;
                
                foreach ($group['amenities'] as $amenity) {
                    $term_name = sanitize_text_field($amenity['name'] ?? $amenity);
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
    
    private function attach_hotel_images($post_id, $hotel_info) {
        if (empty($hotel_info['images'])) {
            rh_log('No images found', ['post_id' => $post_id], 'warning');
            return;
        }
        
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $images = array_slice($hotel_info['images'], 0, 10);
        $gallery_ids = [];
        $featured_set = false;
        
        foreach ($images as $index => $image_data) {
            $image_url = is_string($image_data) ? $image_data : ($image_data['url'] ?? '');
            
            if (empty($image_url)) {
                continue;
            }
            
            if (strpos($image_url, '{size}') !== false) {
                $image_url = str_replace('{size}', '1024x768', $image_url);
            }
            
            try {
                $attachment_id = $this->download_image($image_url, $post_id);
                
                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $gallery_ids[] = $attachment_id;
                    
                    if (!$featured_set) {
                        set_post_thumbnail($post_id, $attachment_id);
                        $featured_set = true;
                    }
                    
                    rh_log('Image downloaded', [
                        'post_id' => $post_id,
                        'attachment_id' => $attachment_id
                    ], 'info');
                }
            } catch (Exception $e) {
                rh_log('Image failed', [
                    'url' => substr($image_url, 0, 100),
                    'error' => $e->getMessage()
                ], 'warning');
            }
        }
        
        if (!empty($gallery_ids)) {
            update_post_meta($post_id, 'gallery', implode(',', $gallery_ids));
            rh_log('Gallery saved', ['total' => count($gallery_ids)], 'info');
        }
    }
    
    private function download_image($url, $post_id) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL');
        }
        
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            throw new Exception($tmp->get_error_message());
        }
        
        $file_ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_ext)) {
            $file_ext = 'jpg';
        }
        
        $file_array = [
            'name' => 'hotel-' . $post_id . '-' . time() . '-' . wp_rand(1000, 9999) . '.' . $file_ext,
            'tmp_name' => $tmp
        ];
        
        $attachment_id = media_handle_sideload($file_array, $post_id, '', ['test_form' => false]);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception($attachment_id->get_error_message());
        }
        
        return $attachment_id;
    }
    
    /**
     * Sync hotel rooms
     */
    private function sync_hotel_rooms($hotel_post_id, $hotel_info) {
        if (empty($hotel_info['room_groups'])) {
            rh_log('No room groups', ['hotel_post_id' => $hotel_post_id], 'info');
            return;
        }
        
        rh_log('Starting room sync', [
            'hotel_post_id' => $hotel_post_id,
            'total_rooms' => count($hotel_info['room_groups'])
        ], 'info');
        
        $synced_rooms = [];
        
        foreach ($hotel_info['room_groups'] as $room_group) {
            try {
                $room_post_id = $this->create_or_update_room($hotel_post_id, $room_group);
                if ($room_post_id) {
                    $synced_rooms[] = $room_post_id;
                }
            } catch (Exception $e) {
                rh_log('Room sync failed', [
                    'room_name' => $room_group['name'] ?? 'Unknown',
                    'error' => $e->getMessage()
                ], 'warning');
            }
        }
        
        if (!empty($synced_rooms)) {
            update_post_meta($hotel_post_id, '_rh_synced_rooms', $synced_rooms);
            
            rh_log('Rooms synced', [
                'hotel_post_id' => $hotel_post_id,
                'count' => count($synced_rooms)
            ], 'info');
        }
    }
    
    private function create_or_update_room($hotel_post_id, $room_group) {
        $room_group_id = $room_group['room_group_id'] ?? 0;
        
        if (empty($room_group_id)) {
            throw new Exception('Room group ID missing');
        }
        
        $existing_room = $this->get_existing_room($hotel_post_id, $room_group_id);
        
        if ($existing_room) {
            return $this->update_room($existing_room, $room_group);
        }
        
        return $this->create_room($hotel_post_id, $room_group);
    }
    
    private function get_existing_room($hotel_post_id, $room_group_id) {
        $args = [
            'post_type' => 'hotel_room',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_rh_room_group_id',
                    'value' => $room_group_id
                ],
                [
                    'key' => 'room_parent',
                    'value' => $hotel_post_id
                ]
            ]
        ];
        
        $rooms = get_posts($args);
        return !empty($rooms) ? $rooms[0]->ID : null;
    }
    
    private function create_room($hotel_post_id, $room_group) {
        $language = rh_get_current_language();
        $room_name = sanitize_text_field($room_group['name'] ?? 'Unnamed Room');
        
        $name_struct = $room_group['name_struct'] ?? [];
        $description_parts = [];
        
        if (!empty($name_struct['main_name'])) {
            $description_parts[] = $name_struct['main_name'];
        }
        if (!empty($name_struct['bedding_type'])) {
            $description_parts[] = 'Bedding: ' . $name_struct['bedding_type'];
        }
        if (!empty($name_struct['bathroom'])) {
            $description_parts[] = 'Bathroom: ' . $name_struct['bathroom'];
        }
        
        $post_data = [
            'post_type' => 'hotel_room',
            'post_title' => $room_name,
            'post_content' => implode('. ', $description_parts),
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1
        ];
        
        $room_post_id = wp_insert_post($post_data);
        
        if (is_wp_error($room_post_id)) {
            throw new Exception($room_post_id->get_error_message());
        }
        
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($room_post_id, $language);
        }
        
        $this->save_room_meta($room_post_id, $hotel_post_id, $room_group);
        $this->save_room_facilities($room_post_id, $room_group);
        $this->attach_room_images($room_post_id, $room_group);
        
        rh_log('Room created', [
            'room_post_id' => $room_post_id,
            'room_name' => $room_name
        ], 'info');
        
        return $room_post_id;
    }
    
    private function save_room_meta($room_post_id, $hotel_post_id, $room_group) {
        update_post_meta($room_post_id, 'room_parent', $hotel_post_id);
        update_post_meta($room_post_id, '_rh_room_group_id', $room_group['room_group_id']);
        update_post_meta($room_post_id, '_is_ratehawk_room', 1);
        
        $rg_ext = $room_group['rg_ext'] ?? [];
        $capacity = $rg_ext['capacity'] ?? 2;
        $bedrooms = $rg_ext['bedrooms'] ?? 0;
        
        update_post_meta($room_post_id, 'number_room', 1);
        update_post_meta($room_post_id, 'adult_number', $capacity > 0 ? $capacity : 2);
        update_post_meta($room_post_id, 'children_number', 2);
        update_post_meta($room_post_id, 'bed_number', $bedrooms > 0 ? $bedrooms : 1);
        
        if (!empty($room_group['size'])) {
            update_post_meta($room_post_id, 'room_footage', floatval($room_group['size']));
        }
        
        update_post_meta($room_post_id, 'booking_option', 'enquire');
        update_post_meta($room_post_id, 'price', 0);
        update_post_meta($room_post_id, '_rh_dynamic_pricing', 1);
        update_post_meta($room_post_id, 'is_sale', 'off');
        update_post_meta($room_post_id, 'discount_rate', 0);
        update_post_meta($room_post_id, 'allow_full_day', 'on');
        update_post_meta($room_post_id, 'calendar_default_state', 'available');
        update_post_meta($room_post_id, '_rh_room_full_data', wp_json_encode($room_group));
        update_post_meta($room_post_id, '_rh_last_sync', current_time('mysql'));
    }
    
    private function save_room_facilities($room_post_id, $room_group) {
        if (empty($room_group['room_amenities'])) {
            return;
        }
        
        $facility_ids = [];
        
        foreach ($room_group['room_amenities'] as $amenity) {
            $term_name = ucwords(str_replace(['-', '_'], ' ', sanitize_text_field($amenity)));
            
            $term = term_exists($term_name, 'room-facilities');
            if (!$term) {
                $term = wp_insert_term($term_name, 'room-facilities');
            }
            
            if (!is_wp_error($term) && isset($term['term_id'])) {
                $facility_ids[] = intval($term['term_id']);
            }
        }
        
        if (!empty($facility_ids)) {
            wp_set_object_terms($room_post_id, $facility_ids, 'room-facilities');
        }
    }
    
    private function attach_room_images($room_post_id, $room_group) {
        $images = $room_group['images_ext'] ?? $room_group['images'] ?? [];
        
        if (empty($images)) {
            return;
        }
        
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $images = array_slice($images, 0, 5);
        $gallery_ids = [];
        $featured_set = false;
        
        foreach ($images as $image_data) {
            $image_url = is_string($image_data) ? $image_data : ($image_data['url'] ?? '');
            
            if (empty($image_url)) {
                continue;
            }
            
            if (strpos($image_url, '{size}') !== false) {
                $image_url = str_replace('{size}', '800x600', $image_url);
            }
            
            try {
                $attachment_id = $this->download_image($image_url, $room_post_id);
                
                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $gallery_ids[] = $attachment_id;
                    
                    if (!$featured_set) {
                        set_post_thumbnail($room_post_id, $attachment_id);
                        $featured_set = true;
                    }
                }
            } catch (Exception $e) {
                // Silent fail for room images
            }
        }
        
        if (!empty($gallery_ids)) {
            update_post_meta($room_post_id, 'gallery', implode(',', $gallery_ids));
        }
    }
    
    private function update_room($room_post_id, $room_group) {
        $room_name = sanitize_text_field($room_group['name'] ?? 'Unnamed Room');
        
        wp_update_post([
            'ID' => $room_post_id,
            'post_title' => $room_name
        ]);
        
        $hotel_post_id = get_post_meta($room_post_id, 'room_parent', true);
        $this->save_room_meta($room_post_id, $hotel_post_id, $room_group);
        $this->save_room_facilities($room_post_id, $room_group);
        
        rh_log('Room updated', ['room_post_id' => $room_post_id], 'info');
        
        return $room_post_id;
    }
    
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
    
    private function update_hotel($post_id, $hotel_info, $hotel_hid) {
        $description = $this->format_description($hotel_info);
        
        wp_update_post([
            'ID' => $post_id,
            'post_title' => sanitize_text_field($hotel_info['name'] ?? 'Unnamed Hotel'),
            'post_content' => $description
        ]);
        
        $this->save_hotel_meta($post_id, $hotel_info);
        $this->save_hotel_taxonomies($post_id, $hotel_info);
        $this->sync_hotel_rooms($post_id, $hotel_info);
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
     * Cleanup orphaned mapping records
     */
    private function cleanup_orphaned_mappings() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        $mappings = $wpdb->get_results("SELECT id, st_hotel_post_id FROM $table");
        
        foreach ($mappings as $mapping) {
            $post = get_post($mapping->st_hotel_post_id);
            
            if (!$post || $post->post_status === 'trash') {
                $wpdb->delete($table, ['id' => $mapping->id]);
                
                rh_log('Cleaned orphaned mapping', [
                    'mapping_id' => $mapping->id,
                    'post_id' => $mapping->st_hotel_post_id
                ], 'info');
            }
        }
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
            // Cleanup orphaned records first
            $this->cleanup_orphaned_mappings();
            
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