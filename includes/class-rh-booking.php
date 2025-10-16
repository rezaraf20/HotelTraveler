<?php
/**
 * Booking & Prebook Class
 * File: includes/class-rh-booking.php
 * 
 * Handle prebook, payment, and booking process
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Booking {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // AJAX handlers
        add_action('wp_ajax_rh_prebook', [$this, 'ajax_prebook']);
        add_action('wp_ajax_nopriv_rh_prebook', [$this, 'ajax_prebook']);
        
        add_action('wp_ajax_rh_create_booking', [$this, 'ajax_create_booking']);
        add_action('wp_ajax_nopriv_rh_create_booking', [$this, 'ajax_create_booking']);
        
        // Payment hooks (WooCommerce or custom)
        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
        
        // Cleanup expired prebooks
        add_action('rh_cleanup_expired_prebooks', [$this, 'cleanup_expired_prebooks']);
        
        // Shortcode
        add_shortcode('ratehawk_checkout', [$this, 'checkout_shortcode']);
    }
    
    /**
     * AJAX: Prebook
     */
    public function ajax_prebook() {
        check_ajax_referer('rh_booking_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Please login to continue');
        }
        
        $book_hash = sanitize_text_field($_POST['book_hash'] ?? '');
        $hotel_id = absint($_POST['hotel_id'] ?? 0);
        $checkin = sanitize_text_field($_POST['checkin'] ?? '');
        $checkout = sanitize_text_field($_POST['checkout'] ?? '');
        $adults = absint($_POST['adults'] ?? 2);
        
        if (!$book_hash || !$hotel_id) {
            wp_send_json_error('Invalid parameters');
        }
        
        try {
            // Call prebook API
            $price_tolerance = get_option('rh_price_tolerance', 10);
            $result = rh_api()->prebook($book_hash, $price_tolerance);
            
            if ($result['status'] !== 'ok') {
                wp_send_json_error('Prebook failed');
            }
            
            $data = $result['data'];
            
            // Save prebook to database
            $prebook_id = $this->save_prebook([
                'user_id' => get_current_user_id(),
                'hotel_id' => $hotel_id,
                'book_hash' => $book_hash,
                'prebook_data' => $data,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'adults' => $adults,
            ]);
            
            // Check price change
            $price_changed = false;
            $new_price = null;
            
            if (isset($data['price_change'])) {
                $price_changed = true;
                $new_price = $data['final_price'];
            }
            
            wp_send_json_success([
                'prebook_id' => $prebook_id,
                'expires_at' => $data['expires_at'] ?? null,
                'price_changed' => $price_changed,
                'new_price' => $new_price,
                'requires_approval' => $price_changed && get_option('rh_require_price_approval', 1)
            ]);
            
        } catch (Exception $e) {
            rh_log('Prebook error', [
                'book_hash' => $book_hash,
                'error' => $e->getMessage()
            ], 'error');
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Save prebook to database
     */
    private function save_prebook($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_bookings';
        
        $hid = rh_get_hotel_hid($data['hotel_id']);
        $hotel_name = get_the_title($data['hotel_id']);
        
        $prebook_data = $data['prebook_data'];
        $expires_at = isset($prebook_data['expires_at']) ? 
                     date('Y-m-d H:i:s', strtotime($prebook_data['expires_at'])) : 
                     date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        $amount = $prebook_data['amount'] ?? 0;
        $currency = $prebook_data['currency_code'] ?? 'USD';
        
        $nights = (strtotime($data['checkout']) - strtotime($data['checkin'])) / 86400;
        
        $insert_data = [
            'partner_order_id' => rh_generate_order_id(),
            'user_id' => $data['user_id'],
            'hotel_hid' => $hid,
            'hotel_name' => $hotel_name,
            'checkin' => $data['checkin'],
            'checkout' => $data['checkout'],
            'nights' => $nights,
            'adults' => $data['adults'],
            'children_ages' => json_encode([]),
            'total_price' => $amount,
            'currency' => $currency,
            'book_hash' => $data['book_hash'],
            'prebook_expires_at' => $expires_at,
            'status' => 'prebooked',
            'booking_data' => json_encode($prebook_data),
        ];
        
        $wpdb->insert($table, $insert_data);
        
        return $wpdb->insert_id;
    }
    
    /**
     * AJAX: Create booking
     */
    public function ajax_create_booking() {
        check_ajax_referer('rh_booking_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Please login to continue');
        }
        
        $prebook_id = absint($_POST['prebook_id'] ?? 0);
        $guest_data = $_POST['guest_data'] ?? [];
        
        if (!$prebook_id) {
            wp_send_json_error('Invalid prebook ID');
        }
        
        // Get prebook from DB
        $prebook = $this->get_booking($prebook_id);
        
        if (!$prebook) {
            wp_send_json_error('Prebook not found');
        }
        
        // Check if expired
        if (strtotime($prebook->prebook_expires_at) < time()) {
            wp_send_json_error('Prebook expired. Please search again.');
        }
        
        // Validate guest data
        if (empty($guest_data['first_name']) || empty($guest_data['last_name'])) {
            wp_send_json_error('Guest information required');
        }
        
        try {
            // Step 1: Get booking form
            $form_result = rh_api()->create_booking(
                $prebook->book_hash,
                $prebook->partner_order_id
            );
            
            if ($form_result['status'] !== 'ok') {
                throw new Exception('Failed to get booking form');
            }
            
            // Step 2: Finish booking
            $finish_result = rh_api()->finish_booking([
                'partner_order_id' => $prebook->partner_order_id,
                'book_hash' => $prebook->book_hash,
                'user' => [
                    'email' => $guest_data['email'] ?? wp_get_current_user()->user_email,
                    'phone' => $guest_data['phone'] ?? '',
                ],
                'rooms' => [
                    [
                        'guests' => [
                            [
                                'first_name' => $guest_data['first_name'],
                                'last_name' => $guest_data['last_name'],
                                'is_child' => false,
                            ]
                        ]
                    ]
                ],
                'payment_type' => [
                    'type' => 'deposit' // B2B mode
                ]
            ]);
            
            if ($finish_result['status'] === 'ok') {
                // Update booking status
                $this->update_booking_status($prebook_id, [
                    'status' => 'processing',
                    'ratehawk_order_id' => $finish_result['data']['order_id'] ?? null,
                    'guest_data' => json_encode($guest_data),
                ]);
                
                // Poll status
                $final_status = $this->poll_booking_status(
                    $prebook->partner_order_id,
                    $finish_result['data']['order_id'] ?? null
                );
                
                wp_send_json_success([
                    'booking_id' => $prebook_id,
                    'order_id' => $finish_result['data']['order_id'] ?? null,
                    'status' => $final_status
                ]);
                
            } else {
                throw new Exception('Booking failed: ' . ($finish_result['error'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            rh_log('Booking error', [
                'prebook_id' => $prebook_id,
                'error' => $e->getMessage()
            ], 'error');
            
            $this->update_booking_status($prebook_id, ['status' => 'failed']);
            
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Poll booking status
     */
    private function poll_booking_status($partner_order_id, $order_id, $max_attempts = 10) {
        $attempt = 0;
        
        while ($attempt < $max_attempts) {
            sleep(2); // Wait 2 seconds
            
            try {
                $result = rh_api()->check_booking_status($partner_order_id);
                
                if ($result['status'] === 'ok') {
                    $status = $result['data']['status'] ?? 'unknown';
                    
                    if ($status === 'ok' || $status === 'confirmed') {
                        $this->update_booking_status_by_partner_id($partner_order_id, [
                            'status' => 'confirmed',
                            'ratehawk_order_id' => $order_id
                        ]);
                        
                        return 'confirmed';
                    }
                    
                    if (in_array($status, ['failed', 'cancelled', 'soldout'])) {
                        $this->update_booking_status_by_partner_id($partner_order_id, [
                            'status' => 'failed'
                        ]);
                        
                        return 'failed';
                    }
                    
                    // Still processing
                    $attempt++;
                }
                
            } catch (Exception $e) {
                break;
            }
        }
        
        // Timeout - mark as pending
        return 'pending';
    }
    
    /**
     * WooCommerce payment complete hook
     */
    public function on_payment_complete($order_id) {
        // Get prebook_id from order meta
        $prebook_id = get_post_meta($order_id, '_rh_prebook_id', true);
        
        if (!$prebook_id) {
            return;
        }
        
        // Update payment status
        $this->update_booking_status($prebook_id, [
            'payment_status' => 'completed',
            'payment_method' => 'woocommerce'
        ]);
        
        // Trigger booking creation
        $this->process_booking_after_payment($prebook_id);
    }
    
    /**
     * Process booking after payment
     */
    private function process_booking_after_payment($prebook_id) {
        $prebook = $this->get_booking($prebook_id);
        
        if (!$prebook) {
            return;
        }
        
        // Get guest data from user
        $user = get_user_by('id', $prebook->user_id);
        
        $guest_data = json_decode($prebook->guest_data, true) ?: [
            'first_name' => $user->first_name ?: $user->display_name,
            'last_name' => $user->last_name ?: '',
            'email' => $user->user_email,
            'phone' => get_user_meta($user->ID, 'billing_phone', true),
        ];
        
        // Simulate AJAX call
        $_POST['prebook_id'] = $prebook_id;
        $_POST['guest_data'] = $guest_data;
        
        $this->ajax_create_booking();
    }
    
    /**
     * Get booking
     */
    public function get_booking($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_bookings';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Update booking status
     */
    private function update_booking_status($id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_bookings';
        
        $wpdb->update($table, $data, ['id' => $id]);
    }
    
    /**
     * Update booking status by partner order ID
     */
    private function update_booking_status_by_partner_id($partner_order_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_bookings';
        
        $wpdb->update($table, $data, ['partner_order_id' => $partner_order_id]);
    }
    
    /**
     * Cleanup expired prebooks
     */
    public function cleanup_expired_prebooks() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_bookings';
        
        $deleted = $wpdb->query("
            UPDATE $table 
            SET status = 'expired' 
            WHERE status = 'prebooked' 
            AND prebook_expires_at < NOW()
        ");
        
        if ($deleted > 0) {
            rh_log("Cleaned up {$deleted} expired prebooks", [], 'info');
        }
    }
    
    /**
     * Checkout shortcode
     */
    public function checkout_shortcode() {
        ob_start();
        
        if (!is_user_logged_in()) {
            echo '<p>Please <a href="' . wp_login_url(get_permalink()) . '">login</a> to continue.</p>';
            return ob_get_clean();
        }
        
        ?>
        <div id="rh-checkout-container">
            <h2>Complete Your Booking</h2>
            
            <form id="rh-checkout-form">
                <div class="rh-form-section">
                    <h3>Guest Information</h3>
                    
                    <div class="rh-field">
                        <label>First Name *</label>
                        <input type="text" name="first_name" required>
                    </div>
                    
                    <div class="rh-field">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required>
                    </div>
                    
                    <div class="rh-field">
                        <label>Email *</label>
                        <input type="email" name="email" required>
                    </div>
                    
                    <div class="rh-field">
                        <label>Phone</label>
                        <input type="tel" name="phone">
                    </div>
                </div>
                
                <button type="submit" class="rh-btn-primary">Complete Booking</button>
            </form>
        </div>
        <?php
        
        return ob_get_clean();
    }
}