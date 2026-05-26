<?php
/**
 * Admin Class
 * File: admin/class-rh-admin.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class RH_Admin {
    
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
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_rh_test_connection', [$this, 'ajax_test_connection']);
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        add_menu_page(
            __('Ratehawk', 'ratehawk-traveler'),
            __('Ratehawk', 'ratehawk-traveler'),
            'manage_options',
            'ratehawk',
            [$this, 'dashboard_page'],
            'dashicons-admin-site-alt3',
            30
        );
        
        add_submenu_page(
            'ratehawk',
            __('Dashboard', 'ratehawk-traveler'),
            __('Dashboard', 'ratehawk-traveler'),
            'manage_options',
            'ratehawk',
            [$this, 'dashboard_page']
        );
        
        add_submenu_page(
            'ratehawk',
            __('Settings', 'ratehawk-traveler'),
            __('Settings', 'ratehawk-traveler'),
            'manage_options',
            'ratehawk-settings',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            'ratehawk',
            __('Test API', 'ratehawk-traveler'),
            __('Test API', 'ratehawk-traveler'),
            'manage_options',
            'ratehawk-test-api',
            [$this, 'test_api_page']
        );
        
        add_submenu_page(
            'ratehawk',
            __('Hotel Sync', 'ratehawk-traveler'),
            __('Hotel Sync', 'ratehawk-traveler'),
            'manage_options',
            'ratehawk-hotel-sync',
            [$this, 'hotel_sync_page']
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ratehawk') === false) {
            return;
        }
        
        // Admin CSS
        wp_enqueue_style(
            'ratehawk-admin',
            RH_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            RH_VERSION
        );
        
        // Admin JS
        wp_enqueue_script(
            'ratehawk-admin',
            RH_PLUGIN_URL . 'admin/assets/js/admin.js',
            ['jquery'],
            RH_VERSION,
            true
        );
        
        wp_localize_script('ratehawk-admin', 'rhAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ratehawk_admin_nonce')
        ]);
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        global $wpdb;
        
        // Get stats
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        $synced_hotels = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE sync_status = 'completed'");
        
        $booking_table = $wpdb->prefix . 'rh_bookings';
        $total_bookings = $wpdb->get_var("SELECT COUNT(*) FROM $booking_table");
        $pending_bookings = $wpdb->get_var("SELECT COUNT(*) FROM $booking_table WHERE status = 'pending'");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Ratehawk Dashboard', 'ratehawk-traveler'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Welcome to Ratehawk Integration!', 'ratehawk-traveler'); ?></h2>
                <p><?php _e('Version:', 'ratehawk-traveler'); ?> <?php echo RH_VERSION; ?></p>
                <p><?php _e('Mode:', 'ratehawk-traveler'); ?> <strong><?php echo RH_SYNC_MODE; ?></strong></p>
                
                <?php if (!rh_is_configured()): ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <strong><?php _e('Setup Required:', 'ratehawk-traveler'); ?></strong>
                            <?php _e('Please add your API credentials to get started.', 'ratehawk-traveler'); ?>
                            <a href="<?php echo admin_url('admin.php?page=ratehawk-settings'); ?>" class="button button-primary">
                                <?php _e('Configure Now', 'ratehawk-traveler'); ?>
                            </a>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success inline">
                        <p>
                            <strong><?php _e('✓ Configured', 'ratehawk-traveler'); ?></strong>
                            <?php _e('Your plugin is ready to use!', 'ratehawk-traveler'); ?>
                        </p>
                    </div>
                    
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=ratehawk-test-api'); ?>" class="button">
                            <?php _e('Test API Connection', 'ratehawk-traveler'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=ratehawk-hotel-sync'); ?>" class="button button-primary">
                            <?php _e('Sync Hotels', 'ratehawk-traveler'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="rh-dashboard-stats">
                <div class="stat">
                    <h3><?php echo $synced_hotels; ?></h3>
                    <p><?php _e('Synced Hotels', 'ratehawk-traveler'); ?></p>
                </div>
                <div class="stat">
                    <h3><?php echo $total_bookings; ?></h3>
                    <p><?php _e('Total Bookings', 'ratehawk-traveler'); ?></p>
                </div>
                <div class="stat">
                    <h3><?php echo $pending_bookings; ?></h3>
                    <p><?php _e('Pending Bookings', 'ratehawk-traveler'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        // Save settings
        if (isset($_POST['rh_save_settings']) && check_admin_referer('rh_settings_nonce')) {
            update_option('rh_api_key_id', sanitize_text_field($_POST['api_key_id']));
            update_option('rh_api_key', sanitize_text_field($_POST['api_key']));
            update_option('rh_environment', sanitize_text_field($_POST['environment']));
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'ratehawk-traveler') . '</p></div>';
        }
        
        $api_key_id = get_option('rh_api_key_id');
        $api_key = get_option('rh_api_key');
        $environment = get_option('rh_environment', 'sandbox');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Ratehawk Settings', 'ratehawk-traveler'); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('rh_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="environment"><?php _e('Environment', 'ratehawk-traveler'); ?></label>
                        </th>
                        <td>
                            <select name="environment" id="environment">
                                <option value="sandbox" <?php selected($environment, 'sandbox'); ?>>
                                    <?php _e('Sandbox (Test)', 'ratehawk-traveler'); ?>
                                </option>
                                <option value="production" <?php selected($environment, 'production'); ?>>
                                    <?php _e('Production (Live)', 'ratehawk-traveler'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Use Sandbox for testing, Production for live bookings', 'ratehawk-traveler'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="api_key_id"><?php _e('API Key ID', 'ratehawk-traveler'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="api_key_id" id="api_key_id" 
                                   value="<?php echo esc_attr($api_key_id); ?>" 
                                   class="regular-text" required>
                            <p class="description">
                                <?php _e('Your Ratehawk API Key ID (e.g., 15078)', 'ratehawk-traveler'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php _e('API Key', 'ratehawk-traveler'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="api_key" id="api_key" 
                                   value="<?php echo esc_attr($api_key); ?>" 
                                   class="regular-text" required>
                            <p class="description">
                                <?php _e('Your Ratehawk API Key (keep this secret!)', 'ratehawk-traveler'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="rh_save_settings" class="button button-primary">
                        <?php _e('Save Settings', 'ratehawk-traveler'); ?>
                    </button>
                </p>
            </form>
            
            <hr>
            
            <h2><?php _e('Information', 'ratehawk-traveler'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Plugin Version', 'ratehawk-traveler'); ?></th>
                    <td><?php echo RH_VERSION; ?></td>
                </tr>
                <tr>
                    <th><?php _e('Sync Mode', 'ratehawk-traveler'); ?></th>
                    <td><code><?php echo RH_SYNC_MODE; ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('Test Hotel ID', 'ratehawk-traveler'); ?></th>
                    <td><code><?php echo RH_TEST_HOTEL_ID; ?></code> (HID: <?php echo RH_TEST_HOTEL_HID; ?>)</td>
                </tr>
                <tr>
                    <th><?php _e('Cache System', 'ratehawk-traveler'); ?></th>
                    <td>LiteSpeed Cache + WordPress Transients</td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Test API page
     */
    public function test_api_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Test API Connection', 'ratehawk-traveler'); ?></h1>
            
            <?php if (!rh_is_configured()): ?>
                <div class="notice notice-error">
                    <p>
                        <?php _e('Please configure your API credentials first.', 'ratehawk-traveler'); ?>
                        <a href="<?php echo admin_url('admin.php?page=ratehawk-settings'); ?>">
                            <?php _e('Go to Settings', 'ratehawk-traveler'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <div class="card">
                    <h2><?php _e('Test Connection', 'ratehawk-traveler'); ?></h2>
                    <p><?php _e('Click the button below to test your API connection with the test hotel.', 'ratehawk-traveler'); ?></p>
                    
                    <p>
                        <button type="button" id="test-connection-btn" class="button button-primary">
                            <?php _e('Test Connection', 'ratehawk-traveler'); ?>
                        </button>
                    </p>
                    
                    <div id="test-result" style="margin-top: 20px;"></div>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#test-connection-btn').on('click', function() {
                        var btn = $(this);
                        var result = $('#test-result');
                        
                        btn.prop('disabled', true).text('<?php _e('Testing...', 'ratehawk-traveler'); ?>');
                        result.html('<p><?php _e('Connecting to Ratehawk API...', 'ratehawk-traveler'); ?></p>');
                        
                        $.ajax({
                            url: rhAdmin.ajax_url,
                            method: 'POST',
                            data: {
                                action: 'rh_test_connection',
                                nonce: rhAdmin.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    result.html(
                                        '<div class="notice notice-success inline">' +
                                        '<p><strong>✓ Success!</strong></p>' +
                                        '<p>Hotel Name: ' + response.data.hotel_name + '</p>' +
                                        '<p>Hotel ID: ' + response.data.hotel_id + '</p>' +
                                        '<p>HID: ' + response.data.hid + '</p>' +
                                        '<p>Response Time: ' + response.data.response_time + '</p>' +
                                        '</div>'
                                    );
                                } else {
                                    result.html(
                                        '<div class="notice notice-error inline">' +
                                        '<p><strong>✗ Error:</strong> ' + response.data + '</p>' +
                                        '</div>'
                                    );
                                }
                            },
                            error: function() {
                                result.html(
                                    '<div class="notice notice-error inline">' +
                                    '<p><strong>✗ Connection Error</strong></p>' +
                                    '</div>'
                                );
                            },
                            complete: function() {
                                btn.prop('disabled', false).text('<?php _e('Test Connection', 'ratehawk-traveler'); ?>');
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Hotel Sync page
     */
    public function hotel_sync_page() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rh_hotel_mapping';
        $synced_hotels = $wpdb->get_results("SELECT * FROM $table ORDER BY last_sync DESC LIMIT 20");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Hotel Sync', 'ratehawk-traveler'); ?></h1>
            
            <?php if (!rh_is_configured()): ?>
                <div class="notice notice-error">
                    <p>
                        <?php _e('Please configure your API credentials first.', 'ratehawk-traveler'); ?>
                        <a href="<?php echo admin_url('admin.php?page=ratehawk-settings'); ?>">
                            <?php _e('Go to Settings', 'ratehawk-traveler'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <div class="card">
                    <h2><?php _e('Sync Test Hotel', 'ratehawk-traveler'); ?></h2>
                    <p>
                        <?php _e('Sync the test hotel from Ratehawk API to your Traveler theme.', 'ratehawk-traveler'); ?><br>
                        <strong>Hotel ID:</strong> <?php echo RH_TEST_HOTEL_ID; ?> | 
                        <strong>HID:</strong> <?php echo RH_TEST_HOTEL_HID; ?>
                    </p>
                    
                    <p>
                        <button type="button" id="sync-test-hotel-btn" class="button button-primary">
                            <?php _e('Sync Test Hotel', 'ratehawk-traveler'); ?>
                        </button>
                    </p>
                    
                    <div id="sync-result" style="margin-top: 20px;"></div>
                </div>
                
                <?php if (!empty($synced_hotels)): ?>
                <div class="card" style="margin-top: 20px;">
                    <h2><?php _e('Synced Hotels', 'ratehawk-traveler'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Post ID', 'ratehawk-traveler'); ?></th>
                                <th><?php _e('Hotel Name', 'ratehawk-traveler'); ?></th>
                                <th><?php _e('RH HID', 'ratehawk-traveler'); ?></th>
                                <th><?php _e('Status', 'ratehawk-traveler'); ?></th>
                                <th><?php _e('Last Sync', 'ratehawk-traveler'); ?></th>
                                <th><?php _e('Actions', 'ratehawk-traveler'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($synced_hotels as $hotel): ?>
                                <?php 
                                $post = get_post($hotel->st_hotel_post_id);
                                $hotel_name = $post ? $post->post_title : 'Unknown';
                                ?>
                                <tr>
                                    <td><?php echo $hotel->st_hotel_post_id; ?></td>
                                    <td><?php echo esc_html($hotel_name); ?></td>
                                    <td><?php echo $hotel->ratehawk_hid; ?></td>
                                    <td>
                                        <span class="status-<?php echo $hotel->sync_status; ?>">
                                            <?php echo ucfirst($hotel->sync_status); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $hotel->last_sync; ?></td>
                                    <td>
                                        <a href="<?php echo get_permalink($hotel->st_hotel_post_id); ?>" 
                                           class="button button-small" target="_blank">
                                            <?php _e('View', 'ratehawk-traveler'); ?>
                                        </a>
                                        <a href="<?php echo get_edit_post_link($hotel->st_hotel_post_id); ?>" 
                                           class="button button-small">
                                            <?php _e('Edit', 'ratehawk-traveler'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#sync-test-hotel-btn').on('click', function() {
                        var btn = $(this);
                        var result = $('#sync-result');
                        
                        btn.prop('disabled', true).text('<?php _e('Syncing...', 'ratehawk-traveler'); ?>');
                        result.html('<p><?php _e('Fetching hotel data from Ratehawk API...', 'ratehawk-traveler'); ?></p>');
                        
                        $.ajax({
                            url: rhAdmin.ajax_url,
                            method: 'POST',
                            data: {
                                action: 'rh_sync_test_hotel',
                                nonce: rhAdmin.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    result.html(
                                        '<div class="notice notice-success inline">' +
                                        '<p><strong>✓ Success!</strong></p>' +
                                        '<p>Hotel Name: ' + response.data.hotel_name + '</p>' +
                                        '<p>Post ID: ' + response.data.post_id + '</p>' +
                                        '<p>Hotel ID: ' + response.data.hotel_id + '</p>' +
                                        '<p>HID: ' + response.data.hotel_hid + '</p>' +
                                        '<p><a href="' + response.data.permalink + '" target="_blank" class="button">View Hotel</a> ' +
                                        '<a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + response.data.post_id + '" class="button">Edit Hotel</a></p>' +
                                        '</div>'
                                    );
                                    
                                    // Reload page after 2 seconds to show in table
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    result.html(
                                        '<div class="notice notice-error inline">' +
                                        '<p><strong>✗ Error:</strong> ' + response.data + '</p>' +
                                        '</div>'
                                    );
                                }
                            },
                            error: function() {
                                result.html(
                                    '<div class="notice notice-error inline">' +
                                    '<p><strong>✗ Sync Error</strong></p>' +
                                    '</div>'
                                );
                            },
                            complete: function() {
                                btn.prop('disabled', false).text('<?php _e('Sync Test Hotel', 'ratehawk-traveler'); ?>');
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('ratehawk_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            $start_time = microtime(true);
            $result = rh_api()->test_connection();
            $response_time = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
            
            if (isset($result['data']['id'])) {
                wp_send_json_success([
                    'hotel_name' => $result['data']['name'] ?? 'Unknown',
                    'hotel_id' => $result['data']['id'],
                    'hid' => $result['data']['hid'],
                    'response_time' => $response_time
                ]);
            } else {
                wp_send_json_error('Invalid response from API');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
}