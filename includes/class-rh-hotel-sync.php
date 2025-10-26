<?php
/**
 * Hotel Sync Class - COMPLETE VERSION با تمام فیلدهای API
 * File: includes/class-rh-hotel-sync.php
 * 
 * این کلاس همه فیلدهای API رو Parse و ذخیره می‌کنه
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('RH_Hotel_Sync')) {
    return;
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
        
        wp_update_post([
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        
        clean_post_cache($post_id);
        
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
    
    private function format_description($hotel_info) {
        $description = '';
        
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
        
        if (empty($description) && !empty($hotel_info['description'])) {
            $description = '<p>' . esc_html($hotel_info['description']) . '</p>';
        }
        
        return $description;
    }
    
    /**
     * ✅ ذخیره تمام Meta های هتل (کامل شده)
     */
    private function save_hotel_meta($post_id, $hotel_info) {
        // === فیلدهای اصلی ===
        update_post_meta($post_id, '_ratehawk_hid', $hotel_info['hid'] ?? 0);
        update_post_meta($post_id, '_ratehawk_id', $hotel_info['id'] ?? '');
        update_post_meta($post_id, 'address', sanitize_text_field($hotel_info['address'] ?? ''));
        update_post_meta($post_id, 'map_lat', floatval($hotel_info['latitude'] ?? 0));
        update_post_meta($post_id, 'map_lng', floatval($hotel_info['longitude'] ?? 0));
        
        $star_rating = intval($hotel_info['star_rating'] ?? 0);
        update_post_meta($post_id, 'star_rate', $star_rating);
        update_post_meta($post_id, 'hotel_star', $star_rating);
        
        // === Check In/Out ===
        if (!empty($hotel_info['check_in_time'])) {
            update_post_meta($post_id, 'check_in_time', sanitize_text_field($hotel_info['check_in_time']));
        }
        if (!empty($hotel_info['check_out_time'])) {
            update_post_meta($post_id, 'check_out_time', sanitize_text_field($hotel_info['check_out_time']));
        }
        
        // === Contact Info ===
        if (!empty($hotel_info['phone'])) {
            update_post_meta($post_id, 'phone', sanitize_text_field($hotel_info['phone']));
        }
        if (!empty($hotel_info['email'])) {
            $email = str_replace(['<', '>'], '', $hotel_info['email']);
            update_post_meta($post_id, 'email', sanitize_email($email));
        }
        
        // ✅ NEW: Postal Code
        if (!empty($hotel_info['postal_code'])) {
            update_post_meta($post_id, 'postal_code', sanitize_text_field($hotel_info['postal_code']));
        }
        
        // ✅ NEW: Hotel Chain
        if (!empty($hotel_info['hotel_chain'])) {
            update_post_meta($post_id, '_rh_hotel_chain', sanitize_text_field($hotel_info['hotel_chain']));
        }
        
        // ✅ NEW: Status Fields
        update_post_meta($post_id, '_rh_is_closed', !empty($hotel_info['is_closed']) ? 1 : 0);
        update_post_meta($post_id, '_rh_gender_required', !empty($hotel_info['is_gender_specification_required']) ? 1 : 0);
        update_post_meta($post_id, '_rh_deleted', !empty($hotel_info['deleted']) ? 1 : 0);
        
        // ✅ NEW: Distance from Center
        if (isset($hotel_info['distance_center'])) {
            update_post_meta($post_id, '_rh_distance_center', floatval($hotel_info['distance_center']));
        }
        
        // ✅ NEW: Front Desk Hours
        if (!empty($hotel_info['front_desk_time_start'])) {
            update_post_meta($post_id, '_rh_front_desk_start', sanitize_text_field($hotel_info['front_desk_time_start']));
        }
        if (!empty($hotel_info['front_desk_time_end'])) {
            update_post_meta($post_id, '_rh_front_desk_end', sanitize_text_field($hotel_info['front_desk_time_end']));
        }
        
        // ✅ NEW: SERP Filters
        if (!empty($hotel_info['serp_filters'])) {
            update_post_meta($post_id, '_rh_serp_filters', wp_json_encode($hotel_info['serp_filters']));
        }
        
        // ✅ NEW: Star Certificate
        if (!empty($hotel_info['star_certificate'])) {
            update_post_meta($post_id, '_rh_star_certificate', wp_json_encode($hotel_info['star_certificate']));
        }
        
        // ✅ NEW: Facts (کامل)
        if (!empty($hotel_info['facts'])) {
            update_post_meta($post_id, '_rh_facts', wp_json_encode($hotel_info['facts']));
            
            // ذخیره جداگانه برای دسترسی آسان
            if (!empty($hotel_info['facts']['electricity'])) {
                update_post_meta($post_id, '_rh_electricity', wp_json_encode($hotel_info['facts']['electricity']));
            }
            if (!empty($hotel_info['facts']['year_built'])) {
                update_post_meta($post_id, '_rh_year_built', intval($hotel_info['facts']['year_built']));
            }
            if (!empty($hotel_info['facts']['year_renovated'])) {
                update_post_meta($post_id, '_rh_year_renovated', intval($hotel_info['facts']['year_renovated']));
            }
            if (!empty($hotel_info['facts']['floors_number'])) {
                update_post_meta($post_id, '_rh_floors', intval($hotel_info['facts']['floors_number']));
            }
            if (!empty($hotel_info['facts']['rooms_number'])) {
                update_post_meta($post_id, '_rh_total_rooms', intval($hotel_info['facts']['rooms_number']));
            }
        }
        
        // === Region & Location ===
        if (!empty($hotel_info['region'])) {
            update_post_meta($post_id, '_rh_region', wp_json_encode($hotel_info['region']));
            update_post_meta($post_id, '_rh_country', $hotel_info['region']['country_code'] ?? '');
            update_post_meta($post_id, '_rh_city', $hotel_info['region']['name'] ?? '');
            update_post_meta($post_id, '_rh_region_type', $hotel_info['region']['type'] ?? 'City');
            
            // ✅ Link به Location Manager
            if (function_exists('rh_location_manager')) {
                try {
                    $location_id = rh_location_manager()->get_or_create_location($hotel_info['region']);
                    
                    if ($location_id) {
                        rh_location_manager()->link_hotel_to_location($post_id, $location_id);
                        rh_log('Hotel linked to location successfully', [
                            'hotel_id' => $post_id,
                            'location_id' => $location_id
                        ], 'info');
                    }
                } catch (Exception $e) {
                    rh_log('Location linking failed', [
                        'error' => $e->getMessage(),
                        'hotel_id' => $post_id
                    ], 'error');
                }
            }
        }
        
        // === Policy ===
        if (!empty($hotel_info['policy_struct'])) {
            update_post_meta($post_id, '_rh_policy_struct', wp_json_encode($hotel_info['policy_struct']));
            
            // ✅ فرمت Traveler: Array با title و policy_description
            $policy_array = $this->format_policies($hotel_info['policy_struct']);
            update_post_meta($post_id, 'hotel_policy', $policy_array);
        }
        
        // === Metapolicy (کامل) ===
        if (!empty($hotel_info['metapolicy_struct'])) {
            // ذخیره کامل
            update_post_meta($post_id, '_rh_metapolicy', wp_json_encode($hotel_info['metapolicy_struct']));
            
            // ✅ ذخیره جداگانه هر بخش
            $meta = $hotel_info['metapolicy_struct'];
            
            if (!empty($meta['children'])) {
                update_post_meta($post_id, '_rh_children_policy', wp_json_encode($meta['children']));
            }
            if (!empty($meta['children_meal'])) {
                update_post_meta($post_id, '_rh_children_meal', wp_json_encode($meta['children_meal']));
            }
            if (!empty($meta['cot'])) {
                update_post_meta($post_id, '_rh_cot_policy', wp_json_encode($meta['cot']));
            }
            if (!empty($meta['deposit'])) {
                update_post_meta($post_id, '_rh_deposit_policy', wp_json_encode($meta['deposit']));
            }
            if (!empty($meta['extra_bed'])) {
                update_post_meta($post_id, '_rh_extra_bed', wp_json_encode($meta['extra_bed']));
            }
            if (!empty($meta['internet'])) {
                update_post_meta($post_id, '_rh_internet_policy', wp_json_encode($meta['internet']));
            }
            if (!empty($meta['meal'])) {
                update_post_meta($post_id, '_rh_meal_prices', wp_json_encode($meta['meal']));
            }
            if (!empty($meta['no_show'])) {
                update_post_meta($post_id, '_rh_no_show_policy', wp_json_encode($meta['no_show']));
            }
            if (!empty($meta['parking'])) {
                update_post_meta($post_id, '_rh_parking', wp_json_encode($meta['parking']));
            }
            if (!empty($meta['pets'])) {
                update_post_meta($post_id, '_rh_pets_policy', wp_json_encode($meta['pets']));
            }
            if (!empty($meta['shuttle'])) {
                update_post_meta($post_id, '_rh_shuttle', wp_json_encode($meta['shuttle']));
            }
            if (!empty($meta['visa'])) {
                update_post_meta($post_id, '_rh_visa_support', wp_json_encode($meta['visa']));
            }
            if (!empty($meta['add_fee'])) {
                update_post_meta($post_id, '_rh_additional_fees', wp_json_encode($meta['add_fee']));
            }
            if (!empty($meta['check_in_check_out'])) {
                update_post_meta($post_id, '_rh_checkin_checkout_policy', wp_json_encode($meta['check_in_check_out']));
            }
        }
        
        if (!empty($hotel_info['metapolicy_extra_info'])) {
            update_post_meta($post_id, '_rh_extra_info', sanitize_textarea_field($hotel_info['metapolicy_extra_info']));
        }
        
        // === Payment Methods ===
        if (!empty($hotel_info['payment_methods'])) {
            update_post_meta($post_id, '_rh_payment_methods', wp_json_encode($hotel_info['payment_methods']));
        }
        
        // === Keys Pickup (کامل) ===
        if (!empty($hotel_info['keys_pickup'])) {
            update_post_meta($post_id, '_rh_keys_pickup', wp_json_encode($hotel_info['keys_pickup']));
            
            // ✅ ذخیره جداگانه
            $keys = $hotel_info['keys_pickup'];
            if (!empty($keys['type'])) {
                update_post_meta($post_id, '_rh_keys_type', sanitize_text_field($keys['type']));
            }
            if (!empty($keys['is_contactless'])) {
                update_post_meta($post_id, '_rh_keys_contactless', 1);
            }
            if (!empty($keys['apartment_office_address'])) {
                update_post_meta($post_id, '_rh_keys_office_address', sanitize_text_field($keys['apartment_office_address']));
            }
            if (!empty($keys['apartment_extra_information'])) {
                update_post_meta($post_id, '_rh_keys_extra_info', sanitize_textarea_field($keys['apartment_extra_information']));
            }
        }
        
        // ✅ Full Data (برای backup)
        update_post_meta($post_id, '_rh_full_data', wp_json_encode($hotel_info));
        update_post_meta($post_id, '_rh_last_sync', current_time('mysql'));
        
        // === Traveler Required Fields ===
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
        update_post_meta($post_id, 'video', '');
        update_post_meta($post_id, '_is_ratehawk_hotel', 1);
        
        // ✅ Archive & Search Required
        update_post_meta($post_id, 'is_featured', 'on');
        update_post_meta($post_id, 'is_auto_caculate', 'on');
        update_post_meta($post_id, 'hotel_layout_style', '1');
        update_post_meta($post_id, 'map_zoom', '14');
        update_post_meta($post_id, 'map_type', 'roadmap');
    }
    
    /**
     * ✅ فرمت Policy برای Traveler (Array با title و policy_description)
     */
    private function format_policies($policy_struct) {
        $policies_array = [];
        
        foreach ($policy_struct as $policy) {
            if (empty($policy['title'])) {
                continue;
            }
            
            $title = sanitize_text_field($policy['title']);
            $paragraphs = $policy['paragraphs'] ?? [];
            
            // ساخت description از paragraphs
            $description = '';
            if (!empty($paragraphs)) {
                foreach ($paragraphs as $paragraph) {
                    $description .= '• ' . $paragraph . "\n";
                }
            }
            
            $policies_array[] = [
                'title' => $title,
                'policy_description' => trim($description)
            ];
        }
        
        return $policies_array; // ✅ Return array, نه string
    }
    
    /**
     * ✅ ذخیره Taxonomies (با non_free_amenities)
     */
    private function save_hotel_taxonomies($post_id, $hotel_info) {
        if (!empty($hotel_info['amenity_groups'])) {
            $facility_ids = [];
            $non_free_amenities = [];
            
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
                
                // ✅ ذخیره امکانات پولی
                if (!empty($group['non_free_amenities'])) {
                    $non_free_amenities = array_merge($non_free_amenities, $group['non_free_amenities']);
                }
            }
            
            if (!empty($facility_ids)) {
                wp_set_object_terms($post_id, $facility_ids, 'hotel-facilities');
            }
            
            if (!empty($non_free_amenities)) {
                update_post_meta($post_id, '_rh_non_free_amenities', wp_json_encode($non_free_amenities));
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
    
    /**
     * ✅ دانلود تصاویر (با پشتیبانی از images_ext)
     */
    private function attach_hotel_images($post_id, $hotel_info) {
        // ترجیح با images_ext (دارای category)
        $images_source = !empty($hotel_info['images_ext']) ? $hotel_info['images_ext'] : $hotel_info['images'];
        
        if (empty($images_source)) {
            rh_log('No images found', ['post_id' => $post_id], 'warning');
            return;
        }
        
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $images = array_slice($images_source, 0, 10);
        $gallery_ids = [];
        $featured_set = false;
        
        foreach ($images as $index => $image_data) {
            // پشتیبانی از هر دو فرمت
            if (is_array($image_data)) {
                $image_url = $image_data['url'] ?? '';
                $category = $image_data['category_slug'] ?? '';
            } else {
                $image_url = $image_data;
                $category = '';
            }
            
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
                    
                    // ✅ ذخیره category به عنوان alt text
                    if (!empty($category)) {
                        update_post_meta($attachment_id, '_wp_attachment_image_alt', $category);
                        update_post_meta($attachment_id, '_rh_image_category', $category);
                    }
                    
                    if (!$featured_set) {
                        set_post_thumbnail($post_id, $attachment_id);
                        $featured_set = true;
                    }
                    
                    rh_log('Image downloaded', [
                        'post_id' => $post_id,
                        'attachment_id' => $attachment_id,
                        'category' => $category
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
            throw new Exception('Invalid URL: ' . $url);
        }
        
        rh_log('Downloading image', [
            'url' => $url,
            'post_id' => $post_id
        ], 'info');
        
        add_filter('http_request_args', function($args, $request_url) use ($url) {
            if ($request_url === $url) {
                $args['headers'] = [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Referer' => 'https://www.ratehawk.com/',
                    'Origin' => 'https://www.ratehawk.com'
                ];
                $args['timeout'] = 30;
                $args['sslverify'] = true;
            }
            return $args;
        }, 10, 2);
        
        $tmp = download_url($url);
        
        remove_all_filters('http_request_args');
        
        if (is_wp_error($tmp) && strpos($tmp->get_error_message(), 'Bad Request') !== false) {
            rh_log('download_url failed, trying cURL', ['url' => $url], 'info');
            $tmp = $this->download_with_curl($url);
        }
        
        if (is_wp_error($tmp)) {
            $error = $tmp->get_error_message();
            rh_log('Download failed', [
                'url' => $url,
                'error' => $error
            ], 'warning');
            throw new Exception($error);
        }
        
        if (!file_exists($tmp)) {
            throw new Exception('Downloaded file does not exist');
        }
        
        $file_size = filesize($tmp);
        if ($file_size === false || $file_size < 100) {
            @unlink($tmp);
            throw new Exception('File too small: ' . $file_size . ' bytes');
        }
        
        $file_ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_ext) || !in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $file_ext = 'jpg';
        }
        
        $file_array = [
            'name' => 'rh-' . $post_id . '-' . time() . '-' . wp_rand(1000, 9999) . '.' . $file_ext,
            'tmp_name' => $tmp
        ];
        
        $attachment_id = media_handle_sideload($file_array, $post_id, '', ['test_form' => false]);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception($attachment_id->get_error_message());
        }
        
        rh_log('Image downloaded successfully', [
            'url' => substr($url, 0, 80),
            'attachment_id' => $attachment_id,
            'file_size' => size_format($file_size)
        ], 'info');
        
        return $attachment_id;
    }
    
    private function download_with_curl($url) {
        if (!function_exists('curl_init')) {
            return new WP_Error('curl_missing', 'cURL is not available');
        }
        
        $tmp_file = wp_tempnam($url);
        
        $ch = curl_init($url);
        $fp = fopen($tmp_file, 'wb');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://www.ratehawk.com/',
            'Origin: https://www.ratehawk.com'
        ]);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if ($result === false || $http_code >= 400) {
            @unlink($tmp_file);
            return new WP_Error('curl_failed', 'cURL failed: ' . $error . ' (HTTP ' . $http_code . ')');
        }
        
        return $tmp_file;
    }
    
    /**
     * Sync اتاق‌ها
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
        $room_count = 0;
        $max_rooms = 9;
        
        foreach ($hotel_info['room_groups'] as $room_group) {
            if ($room_count >= $max_rooms) {
                rh_log('Max rooms reached', ['count' => $max_rooms], 'info');
                break;
            }
            
            try {
                $room_post_id = $this->create_or_update_room($hotel_post_id, $room_group);
                if ($room_post_id) {
                    $synced_rooms[] = $room_post_id;
                    $room_count++;
                    
                    usleep(200000);
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
    
    /**
     * ✅ ساخت اتاق (کامل با همه فیلدها)
     */
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
        
        wp_update_post([
            'ID' => $room_post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        
        clean_post_cache($room_post_id);
        
        do_action('save_post', $room_post_id, get_post($room_post_id), false);
        do_action('save_post_hotel_room', $room_post_id, get_post($room_post_id), false);
        
        delete_transient('st_hotel_min_price_' . $hotel_post_id);
        wp_cache_delete($hotel_post_id, 'posts');
        wp_cache_delete($hotel_post_id, 'post_meta');
        
        if (function_exists('st_traveler_sync_availability')) {
            st_traveler_sync_availability('hotel_room');
            rh_log('Traveler sync availability called', ['room_id' => $room_post_id], 'info');
        }
        
        rh_log('Room created', [
            'room_post_id' => $room_post_id,
            'room_name' => $room_name
        ], 'info');
        
        return $room_post_id;
    }
    
    /**
     * ✅ ذخیره Meta های اتاق (کامل با rg_ext)
     */
    private function save_room_meta($room_post_id, $hotel_post_id, $room_group) {
        update_post_meta($room_post_id, 'room_parent', $hotel_post_id);
        update_post_meta($room_post_id, '_rh_room_group_id', $room_group['room_group_id']);
        update_post_meta($room_post_id, '_is_ratehawk_room', 1);
        
        // === rg_ext (اطلاعات تکمیلی) ===
        $rg_ext = $room_group['rg_ext'] ?? [];
        $capacity = $rg_ext['capacity'] ?? 2;
        $bedrooms = $rg_ext['bedrooms'] ?? 0;
        
        update_post_meta($room_post_id, 'number_room', 1);
        update_post_meta($room_post_id, 'adult_number', $capacity > 0 ? $capacity : 2);
        update_post_meta($room_post_id, 'children_number', 2);
        update_post_meta($room_post_id, 'child_number', 2);
        update_post_meta($room_post_id, 'bed_number', $bedrooms > 0 ? $bedrooms : 1);
        
        // ✅ ذخیره همه فیلدهای rg_ext
        if (!empty($rg_ext)) {
            update_post_meta($room_post_id, '_rh_rg_ext', wp_json_encode($rg_ext));
            
            // ذخیره جداگانه
            update_post_meta($room_post_id, '_rh_has_balcony', !empty($rg_ext['balcony']) ? 1 : 0);
            update_post_meta($room_post_id, '_rh_bathroom_type', intval($rg_ext['bathroom'] ?? 0)); // 1=shared, 2=private
            update_post_meta($room_post_id, '_rh_bedding_type', intval($rg_ext['bedding'] ?? 0));
            update_post_meta($room_post_id, '_rh_is_club_room', !empty($rg_ext['club']) ? 1 : 0);
            update_post_meta($room_post_id, '_rh_is_family_room', !empty($rg_ext['family']) ? 1 : 0);
            update_post_meta($room_post_id, '_rh_floor_number', intval($rg_ext['floor'] ?? 0));
            update_post_meta($room_post_id, '_rh_quality_level', intval($rg_ext['quality'] ?? 0));
            update_post_meta($room_post_id, '_rh_room_class', intval($rg_ext['class'] ?? 0));
            update_post_meta($room_post_id, '_rh_gender_specific', intval($rg_ext['sex'] ?? 0));
            update_post_meta($room_post_id, '_rh_has_view', !empty($rg_ext['view']) ? 1 : 0);
        }
        
        // === Size ===
        if (!empty($room_group['size'])) {
            update_post_meta($room_post_id, 'room_footage', floatval($room_group['size']));
        }
        
        // === Traveler Required ===
        update_post_meta($room_post_id, 'booking_option', 'enquire');
        update_post_meta($room_post_id, 'price', 0);
        update_post_meta($room_post_id, '_rh_dynamic_pricing', 1);
        update_post_meta($room_post_id, 'is_sale', 'off');
        update_post_meta($room_post_id, 'discount_rate', 0);
        update_post_meta($room_post_id, 'allow_full_day', 'on');
        update_post_meta($room_post_id, 'status', 'publish');
        update_post_meta($room_post_id, 'adult_price', 0);
        update_post_meta($room_post_id, 'child_price', 0);
        update_post_meta($room_post_id, 'calendar_default_state', 'available');
        update_post_meta($room_post_id, 'default_state', 'available');
        update_post_meta($room_post_id, '_rh_room_full_data', wp_json_encode($room_group));
        update_post_meta($room_post_id, '_rh_last_sync', current_time('mysql'));
        
        update_post_meta($room_post_id, '_edit_last', get_current_user_id());
        update_post_meta($room_post_id, '_edit_lock', time() . ':' . get_current_user_id());
        update_post_meta($room_post_id, 'type_service', 'hotel_room');
        update_post_meta($room_post_id, 'room_hotel', $hotel_post_id);
        update_post_meta($room_post_id, 'hotel_id', $hotel_post_id);
        update_post_meta($room_post_id, 'disable_adult_name', 'on');
        update_post_meta($room_post_id, 'disable_children_name', 'on');
        update_post_meta($room_post_id, 'price_unit', 'per_day');
        update_post_meta($room_post_id, 'extra_price_unit', 'per_day');
        update_post_meta($room_post_id, 'deposit_type', 'disallow');
        update_post_meta($room_post_id, 'is_external_booking', 'off');
        update_post_meta($room_post_id, 'allow_cancel', 'on');
        update_post_meta($room_post_id, 'st_booking_option_type', 'instant');
        update_post_meta($room_post_id, 'price_by_per_person', 'off');
        update_post_meta($room_post_id, 'discount_type', 'percent');
        update_post_meta($room_post_id, 'discount_type_no_day', 'percent');
        update_post_meta($room_post_id, 'st_room_external_booking', 'off');
        update_post_meta($room_post_id, 'st_allow_cancel', 'off');
        update_post_meta($room_post_id, 'st_cancel_percent', 0);
        update_post_meta($room_post_id, 'is_meta_payment_gateway_st_submit_form', 'on');
        update_post_meta($room_post_id, 'rate_review', 0);
        update_post_meta($room_post_id, 'multi_location', 'off');
        
        $this->generate_room_calendar($room_post_id);
    }
    
    private function generate_room_calendar($room_post_id) {
        $start_date = strtotime('today');
        $end_date = strtotime('+365 days', $start_date);
        
        $calendar_data = [];
        
        for ($timestamp = $start_date; $timestamp <= $end_date; $timestamp = strtotime('+1 day', $timestamp)) {
            $date_key = date('Y-m-d', $timestamp);
            
            $calendar_data[$date_key] = [
                'status' => 'available',
                'price' => 0,
                'number' => 1,
                'adult' => 2,
                'children' => 2
            ];
        }
        
        update_post_meta($room_post_id, 'room_calendar', $calendar_data);
        update_post_meta($room_post_id, '_room_calendar', serialize($calendar_data));
        update_post_meta($room_post_id, '_has_calendar_data', 1);
        update_post_meta($room_post_id, '_rh_calendar_placeholder', 1);
        
        $this->insert_room_availability($room_post_id);
        
        rh_log('Calendar placeholder generated', [
            'room_post_id' => $room_post_id,
            'days' => count($calendar_data)
        ], 'info');
    }
    
    private function insert_room_availability($room_post_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'st_room_availability';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            rh_log('st_room_availability table does not exist', [
                'room_post_id' => $room_post_id
            ], 'warning');
            return;
        }
        
        $wpdb->delete($table_name, ['post_id' => $room_post_id]);
        
        $number_room = get_post_meta($room_post_id, 'number_room', true) ?: 1;
        $adult_number = get_post_meta($room_post_id, 'adult_number', true) ?: 2;
        $children_number = get_post_meta($room_post_id, 'children_number', true) ?: 2;
        $allow_full_day = get_post_meta($room_post_id, 'allow_full_day', true) ?: 'on';
        $hotel_id = get_post_meta($room_post_id, 'room_hotel', true) ?: 0;
        
        $values = [];
        $start_date = strtotime('today');
        $end_date = strtotime('+365 days', $start_date);
        
        for ($timestamp = $start_date; $timestamp <= $end_date; $timestamp = strtotime('+1 day', $timestamp)) {
            $check_in = $timestamp;
            $check_out = $timestamp + 86400;
            
            $values[] = $wpdb->prepare(
                "(%d, %d, %d, %d, %s, %d, %s, NULL, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d)",
                $room_post_id,
                $check_in,
                $check_out,
                $number_room,
                'hotel_room',
                0,
                'available',
                0,
                $hotel_id,
                $allow_full_day,
                $number_room,
                1,
                1,
                $adult_number,
                $children_number,
                0,
                0
            );
        }
        
        $chunks = array_chunk($values, 100);
        $inserted = 0;
        
        foreach ($chunks as $chunk) {
            $query = "INSERT INTO $table_name 
                (post_id, check_in, check_out, number, post_type, price, status, priority, number_booked, parent_id, allow_full_day, number_end, booking_period, is_base, adult_number, child_number, adult_price, child_price) 
                VALUES " . implode(',', $chunk);
            
            $result = $wpdb->query($query);
            
            if ($result !== false) {
                $inserted += $result;
            } else {
                rh_log('Room availability insert failed', [
                    'room_post_id' => $room_post_id,
                    'error' => $wpdb->last_error
                ], 'error');
            }
        }
        
        rh_log('Room availability inserted', [
            'room_post_id' => $room_post_id,
            'records_inserted' => $inserted
        ], 'info');
        
        return $inserted;
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
    
    /**
     * ✅ دانلود تصاویر اتاق (با پشتیبانی images_ext)
     */
    private function attach_room_images($room_post_id, $room_group) {
        // ترجیح با images_ext
        $images_source = !empty($room_group['images_ext']) ? $room_group['images_ext'] : $room_group['images'];
        
        if (empty($images_source)) {
            rh_log('No room images found', [
                'room_post_id' => $room_post_id,
                'room_name' => $room_group['name'] ?? 'Unknown'
            ], 'warning');
            return;
        }
        
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $images = array_slice($images_source, 0, 5);
        $gallery_ids = [];
        $featured_set = false;
        
        foreach ($images as $index => $image_data) {
            // پشتیبانی از هر دو فرمت
            if (is_array($image_data)) {
                $image_url = $image_data['url'] ?? '';
                $category = $image_data['category_slug'] ?? '';
            } else {
                $image_url = $image_data;
                $category = '';
            }
            
            if (empty($image_url)) {
                continue;
            }
            
            if (strpos($image_url, '{size}') !== false) {
                $valid_sizes = ['1024x768', '640x400', 'orig'];
                $download_success = false;
                
                foreach ($valid_sizes as $size) {
                    $test_url = str_replace('{size}', $size, $image_url);
                    
                    try {
                        $attachment_id = $this->download_image($test_url, $room_post_id);
                        
                        if ($attachment_id && !is_wp_error($attachment_id)) {
                            $gallery_ids[] = $attachment_id;
                            
                            // ✅ ذخیره category
                            if (!empty($category)) {
                                update_post_meta($attachment_id, '_wp_attachment_image_alt', $category);
                                update_post_meta($attachment_id, '_rh_image_category', $category);
                            }
                            
                            if (!$featured_set) {
                                set_post_thumbnail($room_post_id, $attachment_id);
                                $featured_set = true;
                            }
                            
                            $download_success = true;
                            break;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            } else {
                try {
                    $attachment_id = $this->download_image($image_url, $room_post_id);
                    
                    if ($attachment_id && !is_wp_error($attachment_id)) {
                        $gallery_ids[] = $attachment_id;
                        
                        if (!empty($category)) {
                            update_post_meta($attachment_id, '_wp_attachment_image_alt', $category);
                            update_post_meta($attachment_id, '_rh_image_category', $category);
                        }
                        
                        if (!$featured_set) {
                            set_post_thumbnail($room_post_id, $attachment_id);
                            $featured_set = true;
                        }
                    }
                } catch (Exception $e) {
                    // Continue
                }
            }
            
            usleep(100000);
        }
        
        if (!empty($gallery_ids)) {
            update_post_meta($room_post_id, 'gallery', implode(',', $gallery_ids));
            
            rh_log('Room gallery saved', [
                'room_post_id' => $room_post_id,
                'total_images' => count($gallery_ids)
            ], 'info');
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
        
        // ✅ آپدیت جدول st_hotel
        $this->update_st_hotel_table($post_id);
    }
    
    /**
     * ✅ آپدیت جدول wp_st_hotel (cache Traveler)
     */
    private function update_st_hotel_table($post_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'st_hotel';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return;
        }
        
        $multi_location = get_post_meta($post_id, 'multi_location', true);
        $id_location = get_post_meta($post_id, 'id_location', true);
        $address = get_post_meta($post_id, 'address', true);
        $allow_full_day = get_post_meta($post_id, 'allow_full_day', true);
        $hotel_star = get_post_meta($post_id, 'hotel_star', true);
        $hotel_booking_period = get_post_meta($post_id, 'hotel_booking_period', true);
        $map_lat = get_post_meta($post_id, 'map_lat', true);
        $map_lng = get_post_meta($post_id, 'map_lng', true);
        $is_featured = get_post_meta($post_id, 'is_featured', true);
        
        $wpdb->delete($table_name, ['post_id' => $post_id]);
        
        $wpdb->insert($table_name, [
            'post_id' => $post_id,
            'multi_location' => $multi_location ?: '',
            'id_location' => $id_location ?: 0,
            'address' => $address ?: '',
            'allow_full_day' => $allow_full_day ?: 'on',
            'rate_review' => 0,
            'hotel_star' => $hotel_star ?: 0,
            'price_avg' => 0,
            'min_price' => 0,
            'hotel_booking_period' => $hotel_booking_period ?: 1,
            'map_lat' => $map_lat ?: 0,
            'map_lng' => $map_lng ?: 0,
            'is_sale_schedule' => 'off',
            'post_origin' => null,
            'is_featured' => $is_featured ?: 'on'
        ]);
        
        rh_log('st_hotel table updated', ['post_id' => $post_id], 'debug');
    }
    
    private function update_hotel($post_id, $hotel_info, $hotel_hid) {
        // پاک کردن multi_location قدیمی
        delete_post_meta($post_id, 'multi_location');
        
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
        
        // ✅ Trigger Traveler indexing
        $this->trigger_traveler_index($post_id);
        
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
     * ✅ Trigger Traveler indexing & cache clear
     */
    private function trigger_traveler_index($post_id) {
        // Clear all Traveler caches
        delete_transient('st_hotel_location_cache_' . $post_id);
        delete_transient('st_location_search_cache');
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        
        // Trigger Traveler actions
        do_action('st_after_save_hotel', $post_id);
        do_action('save_post_st_hotel', $post_id, get_post($post_id), true);
        
        // Update search index if function exists
        if (function_exists('st_update_hotel_search_index')) {
            st_update_hotel_search_index($post_id);
        }
        
        rh_log('Traveler index triggered', ['post_id' => $post_id], 'debug');
    }
    
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
    
    public function ajax_sync_test_hotel() {
        check_ajax_referer('ratehawk_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            $this->cleanup_orphaned_mappings();
            
            $result = $this->sync_test_hotel();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
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