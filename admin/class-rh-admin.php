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
        
        // AJAX handlers
        add_action('wp_ajax_rh_test_connection', [$this, 'ajax_test_connection']);
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
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2><?php _e('Quick Stats', 'ratehawk-traveler'); ?></h2>
                <p><?php _e('Statistics will appear here after integration is complete.', 'ratehawk-traveler'); ?></p>
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
}