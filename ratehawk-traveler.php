<?php
/**
 * Plugin Name: Ratehawk for Traveler
 * Plugin URI: https://hamanteccompany.ir
 * Description: یکپارچه‌سازی کامل Ratehawk API با قالب Traveler برای رزرو آنلاین هتل
 * Version: 1.0.1
 * Author: Reza Rafiei
 * Author URI: https://hamanteccompany.ir
 * Text Domain: ratehawk-traveler
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Constants
 */
define('RH_VERSION', '1.0.1'); // <--- بروز شد
define('RH_PLUGIN_FILE', __FILE__);
define('RH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RH_PLUGIN_BASENAME', plugin_basename(__FILE__));

// API Constants
define('RH_API_BASE_URL', 'https://api.worldota.net/api/');
define('RH_API_VERSION', 'b2b/v3');
define('RH_TEST_HOTEL_HID', 8473727);
define('RH_TEST_HOTEL_ID', 'test_hotel_do_not_book');

// Sync Mode: 'test' or 'production'
define('RH_SYNC_MODE', 'test');

/**
 * Helper Functions - Load First!
 */
require_once RH_PLUGIN_DIR . 'includes/functions.php';

/**
 * Main Plugin Class
 */
final class Ratehawk_Traveler {
    
    private static $instance = null;
    
    const MIN_PHP_VERSION = '7.4';
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function check_requirements() {
        // PHP Version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-error">
                    <p>
                        <strong>Ratehawk for Traveler</strong> requires PHP <?php echo self::MIN_PHP_VERSION; ?> or higher. 
                        You are running PHP <?php echo PHP_VERSION; ?>.
                    </p>
                </div>
                <?php
            });
            return false;
        }
        
        // Traveler Theme
        $theme = wp_get_theme();
        if ($theme->get_template() !== 'traveler') {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Ratehawk for Traveler</strong> works best with the Traveler theme.
                    </p>
                </div>
                <?php
            });
        }
        
        return true;
    }
    
    private function load_dependencies() {
        // Core Classes
        require_once RH_PLUGIN_DIR . 'includes/class-rh-install.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-cache.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-api.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-location-manager.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-hotel-sync.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-traveler-form-integration.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-prebook.php';
        

        
        // 🔥 NEW: Load Rates Class for Frontend
        if (!is_admin()) {
            require_once RH_PLUGIN_DIR . 'includes/class-rh-hotel-rates.php';
            require_once RH_PLUGIN_DIR . 'includes/class-rh-search.php';
        }
        
        // Admin
        if (is_admin()) {
            require_once RH_PLUGIN_DIR . 'admin/class-rh-admin.php';
        }
    }
    
    private function init_hooks() {
        register_activation_hook(RH_PLUGIN_FILE, ['RH_Install', 'activate']);
        register_deactivation_hook(RH_PLUGIN_FILE, ['RH_Install', 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init'], 0);
        add_action('init', [$this, 'load_textdomain']);
        
        if (is_admin()) {
            add_action('admin_init', [$this, 'admin_init']);
        }
    }
    
    public function init() {
        // Initialize Admin
        if (is_admin()) {
            RH_Admin::instance();
        } else {
            // 🔥 NEW: Initialize Frontend Rates
            if (rh_is_configured()) {
                RH_Hotel_Rates::instance();
            }
        }
        
        // Initialize Hotel Sync
        RH_Hotel_Sync::instance();
        
        do_action('ratehawk_traveler_init');
    }
    
    public function admin_init() {
        $api_key_id = get_option('rh_api_key_id');
        $api_key = get_option('rh_api_key');
        
        if (empty($api_key_id) || empty($api_key)) {
            add_action('admin_notices', [$this, 'setup_notice']);
        }
    }
    
    public function setup_notice() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_ratehawk') {
            return;
        }
        
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>Ratehawk for Traveler:</strong> 
                Please complete the setup by adding your API credentials.
                <a href="<?php echo admin_url('admin.php?page=ratehawk-settings'); ?>">Go to Settings</a>
            </p>
        </div>
        <?php
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'ratehawk-traveler',
            false,
            dirname(RH_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    public function cache() {
        return RH_Cache::instance();
    }
    
    public function api() {
        return RH_API::instance();
    }
    
    public function hotel_sync() {
        return RH_Hotel_Sync::instance();
    }
}

function ratehawk_traveler() {
    return Ratehawk_Traveler::instance();
}

ratehawk_traveler();

function rh_api() {
    return ratehawk_traveler()->api();
}

function rh_hotel_sync() {
    return ratehawk_traveler()->hotel_sync();
}