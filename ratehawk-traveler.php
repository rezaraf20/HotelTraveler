<?php
/**
 * Plugin Name: Ratehawk for Traveler
 * Plugin URI: https://hamanteccompany.ir
 * Description: یکپارچه‌سازی کامل Ratehawk API با قالب Traveler برای رزرو آنلاین هتل
 * Version: 1.0.0
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
define('RH_VERSION', '1.0.0');
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
    
    /**
     * Singleton Instance
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
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Check requirements
     */
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
                <div class="notice notice-error">
                    <p>
                        <strong>Ratehawk for Traveler</strong> requires the Traveler theme to be active.
                    </p>
                </div>
                <?php
            });
            return false;
        }
        
        return true;
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Core Classes
        require_once RH_PLUGIN_DIR . 'includes/class-rh-install.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-cache.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-api.php';
        require_once RH_PLUGIN_DIR . 'includes/class-rh-hotel-sync.php';
        
        // Admin
        if (is_admin()) {
            require_once RH_PLUGIN_DIR . 'admin/class-rh-admin.php';
        }
    }
    
    /**
     * Initialize Hooks
     */
    private function init_hooks() {
        // Activation & Deactivation
        register_activation_hook(RH_PLUGIN_FILE, ['RH_Install', 'activate']);
        register_deactivation_hook(RH_PLUGIN_FILE, ['RH_Install', 'deactivate']);
        
        // Initialize
        add_action('plugins_loaded', [$this, 'init'], 0);
        add_action('init', [$this, 'load_textdomain']);
        
        // Admin
        if (is_admin()) {
            add_action('admin_init', [$this, 'admin_init']);
        }
    }
    
    /**
     * Initialize Plugin
     */
    public function init() {
        // Initialize Admin
        if (is_admin()) {
            RH_Admin::instance();
        }
        
        // Initialize Hotel Sync
        RH_Hotel_Sync::instance();
        
        do_action('ratehawk_traveler_init');
    }
    
    /**
     * Initialize Admin
     */
    public function admin_init() {
        // Check if setup is complete
        $api_key_id = get_option('rh_api_key_id');
        $api_key = get_option('rh_api_key');
        
        if (empty($api_key_id) || empty($api_key)) {
            add_action('admin_notices', [$this, 'setup_notice']);
        }
    }
    
    /**
     * Setup Notice
     */
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
                <a href="<?php echo admin_url('admin.php?page=ratehawk'); ?>">Go to Settings</a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Load Text Domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'ratehawk-traveler',
            false,
            dirname(RH_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Get Cache Instance
     */
    public function cache() {
        return RH_Cache::instance();
    }
    
    /**
     * Get API Instance
     */
    public function api() {
        return RH_API::instance();
    }
    
    /**
     * Get Hotel Sync Instance
     */
    public function hotel_sync() {
        return RH_Hotel_Sync::instance();
    }
}

/**
 * Initialize Plugin
 */
function ratehawk_traveler() {
    return Ratehawk_Traveler::instance();
}

// Start the plugin
ratehawk_traveler();

/**
 * Global Helper Functions
 */
function rh_api() {
    return ratehawk_traveler()->api();
}

function rh_hotel_sync() {
    return ratehawk_traveler()->hotel_sync();
}