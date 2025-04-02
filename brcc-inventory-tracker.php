<?php

/**
 * Plugin Name: BRCC Inventory Tracker
 * Plugin URI: https://jmplaunch.com
 * Description: Tracks daily sales across WooCommerce and Eventbrite platforms with enhanced date-based inventory
 * Version: 2.0.0
 * Author: JMPLaunch
 * Author URI: https://seospheres.com/
 * Text Domain: brcc-inventory-tracker
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main BRCC Inventory Tracker class
 */
class BRCC_Inventory_Tracker
{
    /**
     * Plugin version
     */
    const VERSION = '2.0.0';

    /**
     * Instance of this class
     */
    protected static $instance = null;

    /**
     * Return an instance of this class
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Constructor - setup plugin hooks
     */
    public function __construct()
    {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active()
    {
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        return false;
    }

    /**
     * Define constants
     */
    private function define_constants()
    {
        define('BRCC_INVENTORY_TRACKER_VERSION', self::VERSION);
        define('BRCC_INVENTORY_TRACKER_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('BRCC_INVENTORY_TRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    /**
     * Include required files
     * 
     * Files are included in order of dependency
     */
    private function includes()
    {
        // Include constants class first
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-constants.php';

        // Include helper class
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-helpers.php';

        // Include product mappings class early since other classes depend on it
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-product-mappings.php';

        // Include core files
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-sales-tracker.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-api-endpoint.php';

        // Include integration files
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-eventbrite-integration.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-square-integration.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-enhanced-mappings.php';

        // Include admin files
        if (is_admin()) {
            require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin.php';
            // Instantiate the admin class
            new BRCC_Admin();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Plugin initialization
        add_action('plugins_loaded', array($this, 'init'));

        // Add CSS customizations for the front-end display
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }

    /**
     * Activation function
     */
    public function activate()
    {
        // Create necessary database tables or options
        $this->create_options();

        // Set up initial scheduling
        $this->setup_scheduling();
    }

    /**
     * Deactivation function
     */
    public function deactivate()
    {
        // Clear any scheduled hooks
        wp_clear_scheduled_hook('brcc_sync_inventory');
    }

    /**
     * Create default options
     */
    private function create_options()
    {
        // Add default option for daily sales if it doesn't exist
        if (!get_option('brcc_daily_sales')) {
            add_option('brcc_daily_sales', array());
        }

        // Add default product summary if it doesn't exist
        if (!get_option('brcc_product_summary')) {
            add_option('brcc_product_summary', array());
        }

        // Add default API settings
        if (!get_option('brcc_api_settings')) {
            add_option('brcc_api_settings', array(
                'api_key' => $this->generate_api_key(),
                'eventbrite_token' => '',
                'sync_interval' => 15, // minutes
                'test_mode' => false,
                'live_logging' => false,
            ));
        }
    }

    /**
     * Generate a random API key
     */
    private function generate_api_key()
    {
        return 'brcc_' . md5(uniqid(rand(), true));
    }

    /**
     * Set up scheduled events
     */
    private function setup_scheduling()
    {
        if (!wp_next_scheduled('brcc_sync_inventory')) {
            $settings = get_option('brcc_api_settings');
            $interval = isset($settings['sync_interval']) ? (int)$settings['sync_interval'] * 60 : 15 * 60; // Convert to seconds

            wp_schedule_event(time(), 'brcc_' . $interval . '_seconds', 'brcc_sync_inventory');
        }
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice()
    {
?>
        <div class="error">
            <p><?php _e('BRCC Inventory Tracker requires WooCommerce to be installed and active.', 'brcc-inventory-tracker'); ?></p>
        </div>
<?php
    }

    /**
     * Enqueue frontend styles
     */
    public function enqueue_frontend_styles()
    {
        wp_enqueue_style(
            'brcc-inventory-tracker-styles',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/front-end.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );
    }

    /**
     * Initialize plugin when WordPress initializes
     */
    public function init()
    {
        // Load textdomain for internationalization
        load_plugin_textdomain('brcc-inventory-tracker', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Register custom interval for cron jobs
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));

        // Register AJAX handlers
        $this->register_ajax_handlers();
    }

    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers()
    {
        // Regenerate API key
        add_action('wp_ajax_brcc_regenerate_api_key', array($this, 'ajax_regenerate_api_key'));

        // Sync inventory now
        add_action('wp_ajax_brcc_sync_inventory_now', array($this, 'ajax_sync_inventory_now'));

        // Save product mappings
        add_action('wp_ajax_brcc_save_product_mappings', array($this, 'ajax_save_product_mappings'));

        // Test product mapping
        add_action('wp_ajax_brcc_test_product_mapping', array($this, 'ajax_test_product_mapping'));

        // Chart data
        add_action('wp_ajax_brcc_get_chart_data', array($this, 'ajax_get_chart_data'));
    }

    /**
     * AJAX: Regenerate API key
     */
    public function ajax_regenerate_api_key()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Generate new API key
        $api_key = $this->generate_api_key();

        // Update settings
        $settings = get_option('brcc_api_settings', array());
        $settings['api_key'] = $api_key;
        update_option('brcc_api_settings', $settings);

        wp_send_json_success(array(
            'message' => __('API key regenerated successfully.', 'brcc-inventory-tracker'),
            'api_key' => $api_key
        ));
    }

    /**
     * AJAX: Sync inventory now
     */
    public function ajax_sync_inventory_now()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Manual Sync',
                __('Manual sync triggered from admin dashboard', 'brcc-inventory-tracker')
            );
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Manual Sync',
                __('Manual sync triggered from admin dashboard (Live Mode)', 'brcc-inventory-tracker')
            );
        }

        // Trigger sync action
        do_action('brcc_sync_inventory');

        // Update last sync time
        update_option('brcc_last_sync_time', time());

        wp_send_json_success(array(
            'message' => __('Inventory synchronized successfully.', 'brcc-inventory-tracker'),
            'timestamp' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }

    /**
     * AJAX: Save product mappings
     */
    public function ajax_save_product_mappings()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Sanitize mappings
        $sanitized_mappings = array();
        foreach ($mappings as $product_id => $mapping) {
            $sanitized_mappings[absint($product_id)] = array(
                'eventbrite_id' => sanitize_text_field($mapping['eventbrite_id'])
            );
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Mappings',
                sprintf(__('Would save %d product mappings', 'brcc-inventory-tracker'), count($sanitized_mappings))
            );

            wp_send_json_success(array(
                'message' => __('Product mappings would be saved in Test Mode.', 'brcc-inventory-tracker') . ' ' .
                    __('(No actual changes made)', 'brcc-inventory-tracker')
            ));
            return;
        }

        // Log in live mode if enabled
        if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Mappings',
                sprintf(__('Saving %d product mappings (Live Mode)', 'brcc-inventory-tracker'), count($sanitized_mappings))
            );
        }

        // Save mappings
        update_option('brcc_product_mappings', $sanitized_mappings);

        wp_send_json_success(array(
            'message' => __('Product mappings saved successfully.', 'brcc-inventory-tracker')
        ));
    }

    /**
     * AJAX: Test product mapping
     */
    public function ajax_test_product_mapping()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $eventbrite_id = isset($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : '';

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Log test action in test mode
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($eventbrite_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s with Eventbrite ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $eventbrite_id
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($eventbrite_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s with Eventbrite ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $eventbrite_id
                    )
                );
            }
        }

        // Basic validation for Eventbrite ID
        if (!empty($eventbrite_id)) {
            $settings = get_option('brcc_api_settings');
            $has_eventbrite_token = !empty($settings['eventbrite_token']);

            if (!$has_eventbrite_token) {
                $results[] = __('Eventbrite configuration incomplete. Please add API Token in plugin settings.', 'brcc-inventory-tracker');
            } else {
                $results[] = sprintf(
                    __('Eventbrite ID "%s" is linked to product "%s". Eventbrite credentials are configured.', 'brcc-inventory-tracker'),
                    $eventbrite_id,
                    $product_name
                );

                // Test the specific Ticket Class ID connection
                if (class_exists('BRCC_Eventbrite_Integration')) {
                    $eventbrite = new BRCC_Eventbrite_Integration();
                    // Use test_ticket_connection to validate the specific ID
                    $ticket_test = $eventbrite->test_ticket_connection($eventbrite_id);

                    if (is_wp_error($ticket_test)) {
                        // If the test returns an error (e.g., "path does not exist")
                        $results[] = __('Eventbrite Ticket Class ID test failed:', 'brcc-inventory-tracker') . ' ' . $ticket_test->get_error_message();
                    } else {
                        // If the test succeeds, show details
                        $results[] = __('Eventbrite Ticket Class ID connection successful!', 'brcc-inventory-tracker');
                        if (isset($ticket_test['event_name'])) {
                            $results[] = sprintf(__('Event: %s', 'brcc-inventory-tracker'), $ticket_test['event_name']);
                        }
                        if (isset($ticket_test['ticket_name'])) {
                            $results[] = sprintf(__('Ticket Type: %s', 'brcc-inventory-tracker'), $ticket_test['ticket_name']);
                        }
                        if (isset($ticket_test['available'])) {
                            $results[] = sprintf(__('Available: %d', 'brcc-inventory-tracker'), $ticket_test['available']);
                        }
                    }
                } else {
                    $results[] = __('Eventbrite Integration class not found.', 'brcc-inventory-tracker');
                }
            }
        }

        if (empty($results)) {
            $results[] = __('No tests performed. Please enter an Eventbrite ID.', 'brcc-inventory-tracker');
        }

        // Add test mode notice
        if (BRCC_Helpers::is_test_mode()) {
            $results[] = __('Note: Tests work normally even in Test Mode.', 'brcc-inventory-tracker');
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $results)
        ));
    }

    /**
     * AJAX: Get chart data
     */
    public function ajax_get_chart_data()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        $days = isset($_POST['days']) ? absint($_POST['days']) : 7;
        $end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : current_time('Y-m-d');

        // Calculate start date
        $start_date = date('Y-m-d', strtotime("-{$days} days", strtotime($end_date)));

        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();

        // Get product summary for this date range
        $product_summary = $sales_tracker->get_product_summary($start_date, $end_date);

        // Prepare chart data
        $chart_data = $this->prepare_chart_data($product_summary, $start_date, $end_date);

        wp_send_json_success(array(
            'chart_data' => $chart_data,
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }

    /**
     * Prepare chart data from sales data
     */
    private function prepare_chart_data($product_summary, $start_date, $end_date)
    {
        // Create date range
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        $date_range = array();

        // Populate date range
        $current = $start_timestamp;
        while ($current <= $end_timestamp) {
            $date_range[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }

        // Initialize datasets
        $datasets = array(
            array(
                'label' => __('All Products', 'brcc-inventory-tracker'),
                'data' => array_fill(0, count($date_range), 0),
                'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                'borderColor' => 'rgba(54, 162, 235, 1)',
                'borderWidth' => 2
            )
        );

        // Add data sets for each product
        $product_colors = array(
            'rgba(255, 99, 132, 0.2)',
            'rgba(75, 192, 192, 0.2)',
            'rgba(255, 206, 86, 0.2)',
            'rgba(153, 102, 255, 0.2)',
            'rgba(255, 159, 64, 0.2)',
        );
        $product_borders = array(
            'rgba(255, 99, 132, 1)',
            'rgba(75, 192, 192, 1)',
            'rgba(255, 206, 86, 1)',
            'rgba(153, 102, 255, 1)',
            'rgba(255, 159, 64, 1)',
        );

        $color_index = 0;
        foreach ($product_summary as $product_id => $data) {
            // Skip products with no date-specific data
            if (empty($data['dates'])) {
                continue;
            }

            // Add a dataset for this product
            $product_dataset = array(
                'label' => $data['name'],
                'data' => array_fill(0, count($date_range), 0),
                'backgroundColor' => $product_colors[$color_index % count($product_colors)],
                'borderColor' => $product_borders[$color_index % count($product_borders)],
                'borderWidth' => 2
            );

            $color_index++;

            // Add data for each date
            foreach ($data['dates'] as $date => $quantity) {
                // Find the index for this date
                $date_index = array_search($date, $date_range);
                if ($date_index !== false) {
                    // Update product dataset
                    $product_dataset['data'][$date_index] = $quantity;

                    // Update overall dataset
                    $datasets[0]['data'][$date_index] += $quantity;
                }
            }

            $datasets[] = $product_dataset;
        }

        // Format date labels
        $labels = array_map(function ($date) {
            return date_i18n('M j', strtotime($date));
        }, $date_range);

        return array(
            'labels' => $labels,
            'datasets' => $datasets
        );
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules)
    {
        $settings = get_option('brcc_api_settings');
        $interval = isset($settings['sync_interval']) ? (int)$settings['sync_interval'] * 60 : 15 * 60; // Convert to seconds

        $schedules['brcc_' . $interval . '_seconds'] = array(
            'interval' => $interval,
            'display'  => sprintf(__('Every %d minutes', 'brcc-inventory-tracker'), $interval / 60),
        );

        return $schedules;
    }
}

// Initialize the plugin
function BRCC_Inventory_Tracker()
{
    return BRCC_Inventory_Tracker::get_instance();
}

// Start the plugin
BRCC_Inventory_Tracker();
