<?php

/**
 * BRCC Admin Class
 * 
 * Handles admin interface for the BRCC Inventory Tracker with date-based inventory support
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin
{
    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Add menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add settings link to plugins page
        add_filter(
            'plugin_action_links_' . plugin_basename(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'brcc-inventory-tracker.php'),
            array($this, 'add_settings_link')
        );

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Handle CSV export
        add_action('admin_init', array($this, 'maybe_export_csv'));

        // Handle clearing logs
        add_action('admin_init', array($this, 'maybe_clear_logs'));

        // Register AJAX handlers
        add_action('wp_ajax_brcc_regenerate_api_key', array($this, 'ajax_regenerate_api_key'));
        add_action('wp_ajax_brcc_sync_inventory_now', array($this, 'ajax_sync_inventory_now'));
        add_action('wp_ajax_brcc_save_product_mappings', array($this, 'ajax_save_product_mappings'));
        add_action('wp_ajax_brcc_test_product_mapping', array($this, 'ajax_test_product_mapping'));
        add_action('wp_ajax_brcc_get_chart_data', array($this, 'ajax_get_chart_data'));

        // Add date-specific mapping AJAX handlers
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates'));
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings'));
        add_action('wp_ajax_brcc_test_product_date_mapping', array($this, 'ajax_test_product_date_mapping'));

        // Add chart initialization script to footer
        add_action('admin_footer', array($this, 'add_chart_init_script'));

        // Add Square AJAX handlers
        add_action('wp_ajax_brcc_test_square_connection', array($this, 'ajax_test_square_connection'));
        add_action('wp_ajax_brcc_get_square_catalog', array($this, 'ajax_get_square_catalog'));
        add_action('wp_ajax_brcc_test_square_mapping', array($this, 'ajax_test_square_mapping'));
        
        // Add Attendee List AJAX handler
        add_action('wp_ajax_brcc_fetch_attendees', array($this, 'ajax_fetch_attendees'));
        // Import History AJAX handler
        add_action('wp_ajax_brcc_import_batch', array($this, 'ajax_import_batch'));

       // Add AJAX handler for suggesting Eventbrite ID
       add_action('wp_ajax_brcc_suggest_eventbrite_id', array($this, 'ajax_suggest_eventbrite_id'));
       add_action('wp_ajax_brcc_suggest_eventbrite_ticket_id_for_date', array($this, 'ajax_suggest_eventbrite_ticket_id_for_date')); // New action

       // Schedule daily attendee list email
       add_action('brcc_daily_attendee_email_cron', array($this, 'send_daily_attendee_email'));
       if (!wp_next_scheduled('brcc_daily_attendee_email_cron')) {
           // Schedule to run daily at a specific time (e.g., 3:00 AM site time)
           wp_schedule_event(strtotime('03:00:00'), 'daily', 'brcc_daily_attendee_email_cron');
       }
   }

    /**
     * Add admin menu items
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-inventory',
            array($this, 'display_dashboard_page'),
            'dashicons-chart-area',
            56
        );

        // Dashboard submenu
        add_submenu_page(
            'brcc-inventory',
            __('Dashboard', 'brcc-inventory-tracker'),
            __('Dashboard', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-inventory',
            array($this, 'display_dashboard_page')
        );

        // Daily Sales submenu
        add_submenu_page(
            'brcc-inventory',
            __('Daily Sales', 'brcc-inventory-tracker'),
            __('Daily Sales', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-daily-sales',
            array($this, 'display_daily_sales_page')
        );

        // Settings submenu
        add_submenu_page(
            'brcc-inventory',
            __('Settings', 'brcc-inventory-tracker'),
            __('Settings', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-settings',
            array($this, 'display_settings_page')
        );

        // Logs submenu
        add_submenu_page(
            'brcc-inventory',
            __('Operation Logs', 'brcc-inventory-tracker'),
            __('Operation Logs', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-operation-logs',
            array($this, 'display_operation_logs')
        );

        // Import Historical Data submenu
        add_submenu_page(
            'brcc-inventory',
            __('Import History', 'brcc-inventory-tracker'),
            __('Import History', 'brcc-inventory-tracker'),
            'manage_options', // Only admins can import
            'brcc-import-history',
            array($this, 'display_import_page') // We will create this function next
        );
        
        // Attendee Lists submenu
        add_submenu_page(
            'brcc-inventory',
            __('Attendee Lists', 'brcc-inventory-tracker'),
            __('Attendee Lists', 'brcc-inventory-tracker'),
            'manage_options', // Or appropriate capability
            'brcc-attendee-lists',
            array($this, 'display_attendee_list_page') // Placeholder callback
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on plugin pages
        if (strpos($hook, 'brcc-') === false) {
            return;
        }

        // Add CSS
        wp_enqueue_style(
            'brcc-admin-css',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );
        wp_enqueue_script(
            'brcc-date-mappings-js',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/date-mappings.js',
            array('jquery'),
            BRCC_INVENTORY_TRACKER_VERSION . '.' . time(),
            true
        );
        // Add Date Mappings CSS
        wp_enqueue_style(
            'brcc-date-mappings-css',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/date-mappings.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );
        // Add JS with version timestamp to prevent caching
        wp_enqueue_script(
            'brcc-admin-js',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            BRCC_INVENTORY_TRACKER_VERSION . '.' . time(),  // Added timestamp to force cache refresh
            true
        );

        // Add date picker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

        // Add Chart.js for the sales chart (only on dashboard page)
        if (isset($_GET['page']) && $_GET['page'] === 'brcc-inventory') {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js',
                array(),
                '3.7.1',
                true
            );
        }

        // Localize script
        wp_localize_script('brcc-admin-js', 'brcc_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url('admin.php'),
            'nonce' => wp_create_nonce('brcc-admin-nonce'),
            'regenerate_key_confirm' => __('Are you sure you want to regenerate the API key? Any existing connections using the current key will stop working.', 'brcc-inventory-tracker'),
            'ajax_error' => __('An error occurred. Please try again.', 'brcc-inventory-tracker'),
            'syncing' => __('Syncing...', 'brcc-inventory-tracker'),
            'sync_now' => __('Sync Now', 'brcc-inventory-tracker'),
            'saving' => __('Saving...', 'brcc-inventory-tracker'),
            'save_mappings' => __('Save Mappings', 'brcc-inventory-tracker'),
            'testing' => __('Testing...', 'brcc-inventory-tracker'),
            'test' => __('Test', 'brcc-inventory-tracker'),
            'chart_labels' => __('Sales', 'brcc-inventory-tracker'),
            'suggest' => __('Suggest', 'brcc-inventory-tracker'), // Added for suggest buttons
            'suggest_tooltip_date' => __('Suggest Eventbrite Ticket ID based on date/time', 'brcc-inventory-tracker'), // Added for date suggest tooltip
            // Attendee List Strings
            'select_product_prompt' => __('Please select a product to fetch attendees.', 'brcc-inventory-tracker'),
            'fetching' => __('Fetching...', 'brcc-inventory-tracker'),
            'loading_attendees' => __('Loading attendee data...', 'brcc-inventory-tracker'),
            'col_name' => __('Name', 'brcc-inventory-tracker'),
            'col_email' => __('Email', 'brcc-inventory-tracker'),
            'col_source' => __('Source', 'brcc-inventory-tracker'),
            'col_purchase_date' => __('Purchase Date', 'brcc-inventory-tracker'),
            'no_attendees_found' => __('No attendees found for this product.', 'brcc-inventory-tracker'),
            'error_fetching_attendees' => __('Error fetching attendees.', 'brcc-inventory-tracker'),
            'fetch_attendees_btn' => __('Fetch Attendees', 'brcc-inventory-tracker')
        ));
    }

    /**
     * Add settings link to plugin page
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=brcc-settings') . '">' . __('Settings', 'brcc-inventory-tracker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        register_setting('brcc_api_settings', 'brcc_api_settings');

        // API Settings section
        add_settings_section(
            'brcc_api_settings_section',
            __('API Settings', 'brcc-inventory-tracker'),
            array($this, 'api_settings_section_callback'),
            'brcc_api_settings'
        );

        // API Key field
        add_settings_field(
            'api_key',
            __('API Key', 'brcc-inventory-tracker'),
            array($this, 'api_key_field_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Eventbrite settings
        add_settings_field(
            'eventbrite_token',
            __('Eventbrite API Token', 'brcc-inventory-tracker'),
            array($this, 'eventbrite_token_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
        
        // Eventbrite Organization ID field
        add_settings_field(
            'eventbrite_org_id',
            __('Eventbrite Organization ID', 'brcc-inventory-tracker'),
            array($this, 'eventbrite_org_id_callback'), // Need to create this callback function
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
        // Square Access Token
        add_settings_field(
            'square_access_token',
            __('Square Access Token', 'brcc-inventory-tracker'),
            array($this, 'square_access_token_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Location ID
        add_settings_field(
            'square_location_id',
            __('Square Location ID', 'brcc-inventory-tracker'),
            array($this, 'square_location_id_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Webhook Signature Key
        add_settings_field(
            'square_webhook_signature_key',
            __('Square Webhook Signature Key', 'brcc-inventory-tracker'),
            array($this, 'square_webhook_signature_key_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Sandbox Mode
        add_settings_field(
            'square_sandbox',
            __('Square Sandbox Mode', 'brcc-inventory-tracker'),
            array($this, 'square_sandbox_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Test Mode field
        add_settings_field(
            'test_mode',
            __('Test Mode', 'brcc-inventory-tracker'),
            array($this, 'test_mode_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Live Logging field
        add_settings_field(
            'live_logging',
            __('Live Mode with Logs', 'brcc-inventory-tracker'),
            array($this, 'live_logging_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Sync interval
        add_settings_field(
            'sync_interval',
            __('Sync Interval (minutes)', 'brcc-inventory-tracker'),
            array($this, 'sync_interval_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
    }
    /**
     * Square Access Token callback
     */
    public function square_access_token_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_access_token']) ? $options['square_access_token'] : '';
?>
        <input type="password" id="square_access_token" name="brcc_api_settings[square_access_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Access Token.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Location ID callback
     */
    public function square_location_id_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_location_id']) ? $options['square_location_id'] : '';
    ?>
        <input type="text" id="square_location_id" name="brcc_api_settings[square_location_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Location ID.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Webhook Signature Key callback
     */
    public function square_webhook_signature_key_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_webhook_signature_key']) ? $options['square_webhook_signature_key'] : '';
    ?>
        <input type="password" id="square_webhook_signature_key" name="brcc_api_settings[square_webhook_signature_key]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Webhook Signature Key for validating incoming webhooks.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Sandbox Mode callback
     */
    public function square_sandbox_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_sandbox']) ? $options['square_sandbox'] : false;
    ?>
        <label>
            <input type="checkbox" id="square_sandbox" name="brcc_api_settings[square_sandbox]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable Square Sandbox mode (for testing)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will use the Square Sandbox environment.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }
    /**
     * Settings section description
     */
    public function api_settings_section_callback()
    {
        echo '<p>' . __('Configure API settings for Eventbrite inventory integration.', 'brcc-inventory-tracker') . '</p>';
    }

    /**
     * API Key field callback
     */
    public function api_key_field_callback()
    {
        $options = get_option('brcc_api_settings');
    ?>
        <input type="text" id="api_key" name="brcc_api_settings[api_key]" value="<?php echo esc_attr($options['api_key']); ?>" class="regular-text" readonly />
        <p class="description"><?php _e('This key is used to authenticate API requests.', 'brcc-inventory-tracker'); ?></p>
        <button type="button" class="button button-secondary" id="regenerate-api-key"><?php _e('Regenerate Key', 'brcc-inventory-tracker'); ?></button>
    <?php
    }

    /**
     * Eventbrite Token callback
     */
    public function eventbrite_token_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['eventbrite_token']) ? $options['eventbrite_token'] : '';
    ?>
        <input type="password" id="eventbrite_token" name="brcc_api_settings[eventbrite_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Eventbrite Private Token (found under Account Settings -> Developer Links -> API Keys).', 'brcc-inventory-tracker'); ?></p>
    <?php
}

/**
 * Eventbrite Organization ID callback
 */
public function eventbrite_org_id_callback() {
    $options = get_option('brcc_api_settings');
    $value = isset($options['eventbrite_org_id']) ? $options['eventbrite_org_id'] : '';
    ?>
    <input type="text" id="eventbrite_org_id" name="brcc_api_settings[eventbrite_org_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
    <p class="description">
        <?php _e('Enter your Eventbrite Organization ID. You can usually find this in the URL of your organizer profile page (e.g., eventbrite.com/o/your-name-XXXXXXXXX).', 'brcc-inventory-tracker'); ?>
        <br/><em><?php _e('This is required for fetching events.', 'brcc-inventory-tracker'); ?></em>
    </p>
    <?php
}

/**
     * Test Mode callback
     */
    public function test_mode_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['test_mode']) ? $options['test_mode'] : false;
    ?>
        <label>
            <input type="checkbox" id="test_mode" name="brcc_api_settings[test_mode]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable test mode (logs operations but does not modify inventory)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, all inventory operations will be logged but no actual inventory changes will be made. Use this to test the plugin on a production site without affecting live inventory.', 'brcc-inventory-tracker'); ?></p>
        <?php if ($value): ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php _e('Test Mode is currently ENABLED.', 'brcc-inventory-tracker'); ?></strong>
                    <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                    <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                </p>
            </div>
        <?php endif; ?>
    <?php
    }

    /**
     * Live Logging callback
     */
    public function live_logging_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['live_logging']) ? $options['live_logging'] : false;
    ?>
        <label>
            <input type="checkbox" id="live_logging" name="brcc_api_settings[live_logging]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable logging in live mode (logs operations while actually making inventory changes)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, inventory operations will be logged while making actual changes to inventory. This helps with troubleshooting in production environments.', 'brcc-inventory-tracker'); ?></p>
        <?php if ($value): ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Live Logging is currently ENABLED.', 'brcc-inventory-tracker'); ?></strong>
                    <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                    <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                </p>
            </div>
        <?php endif; ?>
    <?php
    }

    /**
     * Sync interval callback
     */
    public function sync_interval_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['sync_interval']) ? $options['sync_interval'] : 15;
    ?>
        <input type="number" id="sync_interval" name="brcc_api_settings[sync_interval]" value="<?php echo esc_attr($value); ?>" class="small-text" min="5" step="1" />
        <p class="description"><?php _e('How often should inventory be synchronized (in minutes).', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Display dashboard page with improved UI
     */
    public function display_dashboard_page()
    {
        // Get today's date
        $today = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');

        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();

        // Get today's sales
        $today_sales = $sales_tracker->get_daily_sales($today);

        // Get past week sales
        $past_week = date('Y-m-d', strtotime('-6 days', strtotime($today)));
        $past_week_sales = $sales_tracker->get_total_sales($past_week, $today);

        // Get period summary
        $period_summary = $sales_tracker->get_summary_by_period($past_week, $today);

    ?>
        <div class="wrap">
            <h1><?php _e('BRCC Inventory Dashboard', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a> |
                        <a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>"><?php _e('Disable Test Mode', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="brcc-dashboard-header">
                <div class="brcc-date-filter">
                    <label for="brcc-date-filter"><?php _e('Date:', 'brcc-inventory-tracker'); ?></label>
                    <input type="text" id="brcc-date-filter" class="brcc-datepicker" value="<?php echo esc_attr($today); ?>" />
                    <button type="button" class="button button-primary" id="brcc-update-date"><?php _e('Update', 'brcc-inventory-tracker'); ?></button>
                </div>
            </div>

            <!-- Period Summary Widget -->
            <div class="brcc-dashboard-widgets">
                <div class="brcc-widget brcc-full-width">
                    <h2>
                        <?php _e('Period Summary', 'brcc-inventory-tracker'); ?>
                        <span class="brcc-tooltip">
                            <span class="dashicons dashicons-info-outline"></span>
                            <span class="brcc-tooltip-text"><?php _e('Sales data summarized from the past 7 days', 'brcc-inventory-tracker'); ?></span>
                        </span>
                    </h2>
                    <div class="brcc-period-summary">
                        <table class="brcc-period-summary-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?php echo $period_summary['total_sales']; ?></strong></td>
                                    <td><?php echo $period_summary['woocommerce_sales']; ?></td>
                                    <td><?php echo $period_summary['eventbrite_sales']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="brcc-dashboard-widgets">
                <div class="brcc-widget">
                    <h2><?php _e('Today\'s Sales', 'brcc-inventory-tracker'); ?></h2>
                    <?php $this->display_sales_table($today_sales); ?>
                </div>

                <div class="brcc-widget">
                    <h2>
                        <?php _e('Past 7 Days Sales', 'brcc-inventory-tracker'); ?>
                        <span class="brcc-tooltip">
                            <span class="dashicons dashicons-info-outline"></span>
                            <span class="brcc-tooltip-text"><?php _e('Cumulative sales data for products sold in the past 7 days', 'brcc-inventory-tracker'); ?></span>
                        </span>
                    </h2>
                    <?php $this->display_sales_table($past_week_sales); ?>
                </div>
            </div>

            <div class="brcc-dashboard-footer">
                <p>
                    <?php printf(
                        __('Last synced: %s', 'brcc-inventory-tracker'),
                        get_option('brcc_last_sync_time') ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), get_option('brcc_last_sync_time')) : __('Never', 'brcc-inventory-tracker')
                    ); ?>
                </p>
                <button type="button" class="button button-secondary" id="brcc-sync-now"><?php _e('Sync Now', 'brcc-inventory-tracker'); ?></button>
            </div>

            <!-- Sales Chart -->
            <div class="brcc-dashboard-widgets">
                <div class="brcc-widget brcc-chart-widget">
                    <h2><?php _e('Sales Over Time', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-chart-controls">
                        <label for="brcc-chart-days"><?php _e('Days to display:', 'brcc-inventory-tracker'); ?></label>
                        <select id="brcc-chart-days">
                            <option value="7"><?php _e('7 days', 'brcc-inventory-tracker'); ?></option>
                            <option value="14"><?php _e('14 days', 'brcc-inventory-tracker'); ?></option>
                            <option value="30"><?php _e('30 days', 'brcc-inventory-tracker'); ?></option>
                            <option value="90"><?php _e('90 days', 'brcc-inventory-tracker'); ?></option>
                        </select>
                        <button type="button" class="button button-secondary" id="brcc-update-chart"><?php _e('Update Chart', 'brcc-inventory-tracker'); ?></button>
                    </div>
                    <div class="brcc-chart-container">
                        <canvas id="brcc-sales-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Display daily sales page
     */
    public function display_daily_sales_page()
    {
        // Get date range from query parameters
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-7 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();

        // Get filtered sales data 
        $filtered_sales = array();
        $all_sales = $sales_tracker->get_daily_sales();

        // Filter by date range
        foreach ($all_sales as $date => $products) {
            if ($date >= $start_date && $date <= $end_date) {
                $filtered_sales[$date] = $products;
            }
        }

        // Sort by date (descending)
        krsort($filtered_sales);

        // Get period summary
        $period_summary = $sales_tracker->get_summary_by_period($start_date, $end_date);

    ?>
        <div class="wrap">
            <h1><?php _e('Daily Sales', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a> |
                        <a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>"><?php _e('Disable Test Mode', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="brcc-date-range-filter">
                <label for="brcc-start-date"><?php _e('Start Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-start-date" class="brcc-datepicker" value="<?php echo esc_attr($start_date); ?>" />

                <label for="brcc-end-date"><?php _e('End Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-end-date" class="brcc-datepicker" value="<?php echo esc_attr($end_date); ?>" />

                <button type="button" class="button button-primary" id="brcc-filter-date-range"><?php _e('Filter', 'brcc-inventory-tracker'); ?></button>
            </div>

            <!-- Period Summary Widget -->
            <div class="brcc-dashboard-widgets">
                <div class="brcc-widget brcc-full-width">
                    <h2><?php _e('Period Summary', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-period-summary">
                        <table class="brcc-period-summary-table">
                            <tr>
                                <th><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                            </tr>
                            <tr>
                                <td><strong><?php echo $period_summary['total_sales']; ?></strong></td>
                                <td><?php echo $period_summary['woocommerce_sales']; ?></td>
                                <td><?php echo $period_summary['eventbrite_sales']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="brcc-export-buttons">
                <button type="button" id="brcc-export-csv" class="button button-secondary">
                    <?php _e('Export to CSV', 'brcc-inventory-tracker'); ?>
                </button>

                <!-- Add direct download link -->
                <a href="#" id="brcc-direct-download" class="button button-primary" style="margin-left: 10px;">
                    <?php _e('Download CSV', 'brcc-inventory-tracker'); ?>
                </a>

                <?php if (BRCC_Helpers::is_test_mode()): ?>
                    <span class="description" style="margin-left: 10px;">
                        <?php _e('Note: Exports will work normally even in Test Mode', 'brcc-inventory-tracker'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Add inline script for direct download -->
            <script type="text/javascript">
                (function() {
                    // Get the direct download link
                    var directLink = document.getElementById('brcc-direct-download');
                    if (!directLink) return;

                    // Add click event handler
                    directLink.addEventListener('click', function(e) {
                        // Get date values
                        var startDate = document.getElementById('brcc-start-date').value;
                        var endDate = document.getElementById('brcc-end-date').value;

                        // Validate date inputs
                        if (!startDate || !endDate) {
                            alert('<?php _e('Please select both start and end dates', 'brcc-inventory-tracker'); ?>');
                            e.preventDefault();
                            return false;
                        }

                        // Set the download URL
                        this.href = '<?php echo admin_url('admin.php'); ?>' +
                            '?page=brcc-daily-sales' +
                            '&action=export_csv' +
                            '&start_date=' + encodeURIComponent(startDate) +
                            '&end_date=' + encodeURIComponent(endDate) +
                            '&nonce=<?php echo wp_create_nonce('brcc-admin-nonce'); ?>';

                        // Open in new tab to trigger download
                        this.target = '_blank';
                    });
                })();
            </script>

            <div id="brcc-daily-sales-data">
                <?php foreach ($filtered_sales as $date => $products) : ?>
                    <div class="brcc-daily-sales-card">
                        <h3><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($date))); ?></h3>
                        <?php $this->display_sales_table($products); ?>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($filtered_sales)) : ?>
                    <p><?php _e('No sales data available for the selected date range.', 'brcc-inventory-tracker'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Display operation logs
     */
    public function display_operation_logs()
    {
        $logs = get_option('brcc_operation_logs', []);
    ?>
        <div class="wrap">
            <h1><?php _e('Operation Logs', 'brcc-inventory-tracker'); ?></h1>

            <?php
            // Show notices about current logging modes
            $settings = get_option('brcc_api_settings');
            $test_mode = isset($settings['test_mode']) ? $settings['test_mode'] : false;
            $live_logging = isset($settings['live_logging']) ? $settings['live_logging'] : false;

            if ($test_mode) {
            ?>
                <div class="notice notice-warning">
                    <p><?php _e('Test Mode is currently enabled. All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            } elseif ($live_logging) {
            ?>
                <div class="notice notice-info">
                    <p><?php _e('Live Logging is currently enabled. Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            } else {
            ?>
                <div class="notice notice-info">
                    <p><?php _e('Logging is currently disabled. Enable Test Mode or Live Logging in settings to track inventory operations.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            }

            if (isset($_GET['cleared'])) {
            ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Logs have been cleared.', 'brcc-inventory-tracker'); ?></p>
                </div>
            <?php
            }
            ?>

            <div class="brcc-log-filter">
                <label for="brcc-log-source"><?php _e('Filter by Source:', 'brcc-inventory-tracker'); ?></label>
                <select id="brcc-log-source">
                    <option value=""><?php _e('All Sources', 'brcc-inventory-tracker'); ?></option>
                    <option value="WooCommerce"><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></option>
                    <option value="Eventbrite"><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></option>
                    <option value="Admin"><?php _e('Admin', 'brcc-inventory-tracker'); ?></option>
                    <option value="API"><?php _e('API', 'brcc-inventory-tracker'); ?></option>
                </select>

                <label for="brcc-log-mode"><?php _e('Filter by Mode:', 'brcc-inventory-tracker'); ?></label>
                <select id="brcc-log-mode">
                    <option value=""><?php _e('All Modes', 'brcc-inventory-tracker'); ?></option>
                    <option value="test"><?php _e('Test Mode', 'brcc-inventory-tracker'); ?></option>
                    <option value="live"><?php _e('Live Mode', 'brcc-inventory-tracker'); ?></option>
                </select>

                <button type="button" id="brcc-filter-logs" class="button button-primary"><?php _e('Filter', 'brcc-inventory-tracker'); ?></button>
            </div>

            <?php if (empty($logs)): ?>
                <p><?php _e('No operation logs available.', 'brcc-inventory-tracker'); ?></p>
            <?php else: ?>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=brcc-operation-logs&clear=1'), 'brcc-clear-logs'); ?>" class="button button-secondary">
                            <?php _e('Clear Logs', 'brcc-inventory-tracker'); ?>
                        </a>
                    </div>
                    <br class="clear">
                </div>

                <table class="wp-list-table widefat fixed striped brcc-logs-table">
                    <thead>
                        <tr>
                            <th width="15%"><?php _e('Date/Time', 'brcc-inventory-tracker'); ?></th>
                            <th width="10%"><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                            <th width="10%"><?php _e('Mode', 'brcc-inventory-tracker'); ?></th>
                            <th width="15%"><?php _e('Operation', 'brcc-inventory-tracker'); ?></th>
                            <th><?php _e('Details', 'brcc-inventory-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <tr class="brcc-log-row"
                                data-source="<?php echo esc_attr($log['source']); ?>"
                                data-mode="<?php echo isset($log['test_mode']) && $log['test_mode'] ? 'test' : 'live'; ?>">
                                <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['timestamp']); ?></td>
                                <td><?php echo esc_html($log['source']); ?></td>
                                <td>
                                    <?php if (isset($log['test_mode']) && $log['test_mode']): ?>
                                        <span class="brcc-test-mode-badge"><?php _e('Test', 'brcc-inventory-tracker'); ?></span>
                                    <?php else: ?>
                                        <span class="brcc-live-mode-badge"><?php _e('Live', 'brcc-inventory-tracker'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log['operation']); ?></td>
                                <td><?php echo esc_html($log['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#brcc-filter-logs').on('click', function() {
                            var source = $('#brcc-log-source').val();
                            var mode = $('#brcc-log-mode').val();

                            $('.brcc-log-row').show();

                            if (source) {
                                $('.brcc-log-row').not('[data-source="' + source + '"]').hide();
                            }

                            if (mode) {
                                $('.brcc-log-row').not('[data-mode="' + mode + '"]').hide();
                            }
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Handle clearing logs
     */
    public function maybe_clear_logs()
    {
        if (
            isset($_GET['page']) && $_GET['page'] === 'brcc-operation-logs' &&
            isset($_GET['clear']) && $_GET['clear'] === '1' &&
            isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'brcc-clear-logs')
        ) {

            delete_option('brcc_operation_logs');

            wp_redirect(admin_url('admin.php?page=brcc-operation-logs&cleared=1'));
            exit;
        }
    }

    /**
     * Display settings page
     */
    public function display_settings_page()
    {
    ?>
        <div class="wrap">
            <h1><?php _e('BRCC Inventory Settings', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('brcc_api_settings');
                do_settings_sections('brcc_api_settings');
                submit_button();
                ?>
            </form>

            <hr>

            <?php $this->display_product_mapping_interface(); ?>

            <hr>

            <h2><?php _e('API Documentation', 'brcc-inventory-tracker'); ?></h2>

            <p><?php _e('The BRCC Inventory Tracker exposes the following REST API endpoints:', 'brcc-inventory-tracker'); ?></p>

            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th><?php _e('Endpoint', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Method', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Description', 'brcc-inventory-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/wp-json/brcc/v1/inventory</code></td>
                        <td>GET</td>
                        <td><?php _e('Get current inventory levels including date-based inventory.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/brcc/v1/inventory/update</code></td>
                        <td>POST</td>
                        <td><?php _e('Update inventory levels. Can include a "date" parameter for date-specific inventory.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                </tbody>
            </table>

            <p><?php _e('Authentication is required for all API requests using the API key.', 'brcc-inventory-tracker'); ?></p>

            <h3><?php _e('Example Request with Date Parameter', 'brcc-inventory-tracker'); ?></h3>

            <pre><code>curl -X POST \
https://your-site.com/wp-json/brcc/v1/inventory/update \
-H 'X-BRCC-API-Key: YOUR_API_KEY' \
-H 'Content-Type: application/json' \
-d '{
  "products": [
      {
          "id": 123,
          "date": "2025-03-15",
          "stock": 10
      }
  ]
}'</code></pre>
        </div>

        <?php $this->add_modal_styles(); ?>
        <?php $this->add_date_mappings_js(); ?>
    <?php
    }

    /**
     * Display sales table with date support
     */
    private function display_sales_table($sales_data)
    {
        if (empty($sales_data)) {
            echo '<p>' . __('No sales data available.', 'brcc-inventory-tracker') . '</p>';
            return;
        }

    ?>
        <div class="brcc-sales-table-container">
            <table class="wp-list-table widefat fixed striped brcc-sales-table">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('SKU', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Event Date', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Total Qty', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales_data as $product_key => $product_data) :
                        // Check if this is a product with booking date
                        $booking_date = isset($product_data['booking_date']) ? $product_data['booking_date'] : null;
                    ?>
                        <tr>
                            <td>
                                <?php if (isset($product_data['name'])) : ?>
                                    <?php echo esc_html($product_data['name']); ?>
                                <?php else : ?>
                                    <?php $product_id = isset($product_data['product_id']) ? $product_data['product_id'] : $product_key; ?>
                                    <?php $product = wc_get_product($product_id); ?>
                                    <?php echo $product ? esc_html($product->get_name()) : __('Unknown Product', 'brcc-inventory-tracker') . ' (' . $product_id . ')'; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($product_data['sku'])) : ?>
                                    <?php echo esc_html($product_data['sku']); ?>
                                <?php else : ?>
                                    <?php $product_id = isset($product_data['product_id']) ? $product_data['product_id'] : $product_key; ?>
                                    <?php $product = wc_get_product($product_id); ?>
                                    <?php echo $product ? esc_html($product->get_sku()) : ''; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $booking_date ? esc_html(date_i18n(get_option('date_format'), strtotime($booking_date))) : '—'; ?>
                            </td>
                            <td>
                                <?php if (isset($product_data['quantity'])) : ?>
                                    <?php echo esc_html($product_data['quantity']); ?>
                                <?php else : ?>
                                    <?php echo esc_html($product_data); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($product_data['woocommerce'])) : ?>
                                    <?php echo esc_html($product_data['woocommerce']); ?>
                                <?php else : ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($product_data['eventbrite'])) : ?>
                                    <?php echo esc_html($product_data['eventbrite']); ?>
                                <?php else : ?>
                                    0
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    /**
     * Handle CSV export
     */
    public function maybe_export_csv()
    {
        // Early return if not a CSV export request
        if (
            !isset($_GET['page']) || $_GET['page'] !== 'brcc-daily-sales' ||
            !isset($_GET['action']) || $_GET['action'] !== 'export_csv'
        ) {
            return;
        }

        // Check nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'brcc-admin-nonce')) {
            wp_die(__('Security check failed.', 'brcc-inventory-tracker'));
        }

        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'brcc-inventory-tracker'));
        }

        // Get date parameters with defaults
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-7 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        try {
            // Get sales data
            $sales_tracker = new BRCC_Sales_Tracker();
            $sales_data = $sales_tracker->get_total_sales($start_date, $end_date);

            // Filename with dates
            $filename = 'brcc-sales-' . $start_date . '-to-' . $end_date . '.csv';

            // Set headers for CSV download - IMPORTANT: No output before headers
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Create output stream
            $output = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 compatibility
            fputs($output, "\xEF\xBB\xBF");

            // Add header row
            fputcsv($output, array(
                __('Product', 'brcc-inventory-tracker'),
                __('SKU', 'brcc-inventory-tracker'),
                __('Event Date', 'brcc-inventory-tracker'),
                __('Total Quantity', 'brcc-inventory-tracker'),
                __('WooCommerce', 'brcc-inventory-tracker'),
                __('Eventbrite', 'brcc-inventory-tracker')
            ));

            // Add data rows
            foreach ($sales_data as $product_data) {
                $booking_date = isset($product_data['booking_date']) ? $product_data['booking_date'] : '';
                $formatted_date = $booking_date ? date_i18n(get_option('date_format'), strtotime($booking_date)) : '';

                fputcsv($output, array(
                    isset($product_data['name']) ? $product_data['name'] : 'Unknown',
                    isset($product_data['sku']) ? $product_data['sku'] : '',
                    $formatted_date,
                    isset($product_data['quantity']) ? $product_data['quantity'] : 0,
                    isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0,
                    isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0
                ));
            }

            // Close output stream
            fclose($output);

            // Exit after sending CSV to prevent WordPress from sending additional output
            exit;
        } catch (Exception $e) {
            // Log any errors
            error_log('CSV Export Error: ' . $e->getMessage());
            wp_die('Error generating CSV: ' . $e->getMessage());
        }
    }

    /**
     * Display product mapping with Square support
     */
    public function display_product_mapping_interface()
    {
    ?>
        <h2><?php _e('Product Mapping', 'brcc-inventory-tracker'); ?></h2>

        <p><?php _e('Map your WooCommerce products to Eventbrite and Square items. For products with date-based inventory, you can set up mappings for each date.', 'brcc-inventory-tracker'); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('WooCommerce Product', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('SKU', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Eventbrite ID', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Square Catalog ID', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Dates', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                </tr>
            </thead>
            <tbody id="brcc-product-mapping-table">
                <?php
                // Get product mappings
                $all_mappings = get_option('brcc_product_mappings', array());

                // Get all WooCommerce products
                $products = wc_get_products(array(
                    'limit' => -1,
                    'status' => 'publish',
                ));

                if (!empty($products)) {
                    foreach ($products as $product) {
                        $product_id = $product->get_id();
                        $mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array(
                            'eventbrite_id' => '',
                            'square_id' => ''
                        );

                        // Check if this product has date-based inventory
                        $has_dates = false;
                        if (isset($all_mappings[$product_id . '_dates']) && !empty($all_mappings[$product_id . '_dates'])) {
                            $has_dates = true;
                        }
                ?>
                        <tr>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <td>
                                <div class="brcc-mapping-input-group">
                                    <input type="text"
                                        name="brcc_product_mappings[<?php echo $product_id; ?>][eventbrite_id]"
                                        value="<?php echo esc_attr(isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : ''); ?>"
                                        class="regular-text brcc-eventbrite-id-input"
                                        data-product-id="<?php echo $product_id; ?>"
                                        placeholder="<?php esc_attr_e('Ticket Class ID', 'brcc-inventory-tracker'); ?>" />
                                    <input type="hidden"
                                        name="brcc_product_mappings[<?php echo $product_id; ?>][eventbrite_event_id]"
                                        value="<?php echo esc_attr(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : ''); ?>"
                                        class="brcc-eventbrite-event-id-input" />
                                    <button type="button"
                                        class="button button-secondary brcc-suggest-eventbrite-id"
                                        data-product-id="<?php echo $product_id; ?>"
                                        title="<?php esc_attr_e('Suggest Eventbrite Ticket Class ID based on product name', 'brcc-inventory-tracker'); ?>">
                                        <?php _e('Suggest', 'brcc-inventory-tracker'); ?>
                                    </button>
                                </div>
                                <div class="brcc-suggestion-result" id="brcc-suggestion-<?php echo $product_id; ?>"></div>
                            </td>
                            <td>
                                <input type="text"
                                    name="brcc_product_mappings[<?php echo $product_id; ?>][square_id]"
                                    value="<?php echo esc_attr(isset($mapping['square_id']) ? $mapping['square_id'] : ''); ?>"
                                    class="regular-text" />
                            </td>
                            <td>
                                <button type="button"
                                    class="button brcc-view-dates"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php echo $has_dates ? __('View/Edit Dates', 'brcc-inventory-tracker') : __('Add Date Mappings', 'brcc-inventory-tracker'); ?>
                                </button>
                            </td>
                            <td>
                                <button type="button"
                                    class="button brcc-test-mapping"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php _e('Test', 'brcc-inventory-tracker'); ?>
                                </button>
                                <button type="button"
                                    class="button brcc-test-square-mapping"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php _e('Test Square', 'brcc-inventory-tracker'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="6"><?php _e('No products found.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                <?php
                }
                ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="brcc-save-mappings" class="button button-primary">
                <?php _e('Save Mappings', 'brcc-inventory-tracker'); ?>
            </button>
        </p>

        <div id="brcc-mapping-result" style="display: none;"></div>

        <!-- Test Square Connection Button -->
        <div class="brcc-section-connector">
            <h3><?php _e('Square Connection', 'brcc-inventory-tracker'); ?></h3>
            <p><?php _e('Test your Square connection and view available catalog items.', 'brcc-inventory-tracker'); ?></p>
            <button type="button" id="brcc-test-square-connection" class="button button-secondary">
                <?php _e('Test Square Connection', 'brcc-inventory-tracker'); ?>
            </button>
            <button type="button" id="brcc-fetch-square-catalog" class="button button-secondary">
                <?php _e('View Square Catalog', 'brcc-inventory-tracker'); ?>
            </button>
        </div>

        <!-- Square Catalog Items Container -->
        <div id="brcc-square-catalog-container" style="display: none; margin-top: 20px;">
            <h3><?php _e('Square Catalog Items', 'brcc-inventory-tracker'); ?></h3>
            <div id="brcc-square-catalog-items"></div>
        </div>

        <!-- Date Mappings Modal HTML with Square support -->
        <div id="brcc-date-mappings-modal" style="display: none;">
            <div class="brcc-modal-content">
                <div class="brcc-modal-header">
                    <h2><?php _e('Date & Time Based Mappings', 'brcc-inventory-tracker'); ?></h2>
                    <span class="brcc-modal-close">&times;</span>
                </div>
                <div class="brcc-modal-body">
                    <p><?php _e('Link your WooCommerce product dates and times to Eventbrite and Square by entering the appropriate IDs for each date/time.', 'brcc-inventory-tracker'); ?></p>

                    <!-- Status message area -->
                    <div id="brcc-eventbrite-status" class="notice" style="display: none;">
                        <p></p>
                    </div>

                    <!-- Loading indicator -->
                    <div id="brcc-dates-loading">
                        <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
                        <?php _e('Loading dates...', 'brcc-inventory-tracker'); ?>
                    </div>

                    <!-- Dates table with improved structure -->
                    <div class="brcc-table-container">
                        <table class="wp-list-table widefat fixed striped" id="brcc-dates-table" style="display: none;">
                            <thead>
                                <tr>
                                    <th width="15%"><?php _e('Date', 'brcc-inventory-tracker'); ?></th>
                                    <th width="15%"><?php _e('Time', 'brcc-inventory-tracker'); ?></th>
                                    <th width="8%"><?php _e('Inventory', 'brcc-inventory-tracker'); ?></th>
                                    <th width="25%"><?php _e('Eventbrite Ticket ID', 'brcc-inventory-tracker'); ?></th>
                                    <th width="25%"><?php _e('Square Item ID', 'brcc-inventory-tracker'); ?></th>
                                    <th width="12%"><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="brcc-dates-table-body">
                                <!-- Will be populated via AJAX -->
                            </tbody>
                        </table>
                    </div>

                    <!-- No dates message -->
                    <div id="brcc-no-dates" style="display: none;">
                        <p><?php _e('No dates found for this product. If this product has date-based inventory, please make sure dates are properly set up.', 'brcc-inventory-tracker'); ?></p>
                    </div>
                </div>
                <div class="brcc-modal-footer">
                    <button type="button" class="button button-primary" id="brcc-save-date-mappings">
                        <?php _e('Save Date Mappings', 'brcc-inventory-tracker'); ?>
                    </button>
                    <button type="button" class="button" id="brcc-close-modal">
                        <?php _e('Close', 'brcc-inventory-tracker'); ?>
                    </button>
                </div>
            </div>
        </div>
    <?php
    }
    /**
     * AJAX: Test Square connection
     */
    public function ajax_test_square_connection()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Initialize Square integration
        $square = new BRCC_Square_Integration();

        // Test connection
        $result = $square->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Square API connection failed: %s', 'brcc-inventory-tracker'),
                    $result->get_error_message()
                )
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => __('Square API connection successful!', 'brcc-inventory-tracker')
        ));
    }

    /**
     * AJAX: Get Square catalog
     */
    public function ajax_get_square_catalog()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Initialize Square integration
        $square = new BRCC_Square_Integration();

        // Get catalog items
        $catalog = $square->get_catalog_items();

        if (is_wp_error($catalog)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Failed to retrieve Square catalog: %s', 'brcc-inventory-tracker'),
                    $catalog->get_error_message()
                )
            ));
            return;
        }

        wp_send_json_success(array(
            'catalog' => $catalog
        ));
    }

    /**
     * AJAX: Test Square mapping
     */

    public function ajax_test_square_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $square_id = isset($_POST['square_id']) ? sanitize_text_field($_POST['square_id']) : '';

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Log test action
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($square_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Square Connection',
                    sprintf(
                        __('Testing Square connection for product ID %s with Square ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $square_id
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($square_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Square Connection',
                    sprintf(
                        __('Testing Square connection for product ID %s with Square ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $square_id
                    )
                );
            }
        }

        // Basic validation for Square ID
        if (!empty($square_id)) {
            $settings = get_option('brcc_api_settings');
            $has_square_token = !empty($settings['square_access_token']);
            $has_square_location = !empty($settings['square_location_id']);

            if (!$has_square_token || !$has_square_location) {
                $results[] = __('Square configuration incomplete. Please add Access Token and Location ID in plugin settings.', 'brcc-inventory-tracker');
            } else {
                $results[] = sprintf(
                    __('Square ID "%s" is linked to product "%s". Square credentials are configured.', 'brcc-inventory-tracker'),
                    $square_id,
                    $product_name
                );

                // Test connection if class is available
                if (class_exists('BRCC_Square_Integration')) {
                    $square = new BRCC_Square_Integration();
                    $square_test = $square->test_connection();

                    if (is_wp_error($square_test)) {
                        $results[] = __('Square API test failed:', 'brcc-inventory-tracker') . ' ' . $square_test->get_error_message();
                    } else {
                        $results[] = __('Square API connection successful!', 'brcc-inventory-tracker');

                        // Try to get the specific item
                        $item = $square->get_catalog_item($square_id);
                        if (is_wp_error($item)) {
                            $results[] = __('Square item lookup failed:', 'brcc-inventory-tracker') . ' ' . $item->get_error_message();
                        } else {
                            $results[] = sprintf(
                                __('Successfully found Square item: %s', 'brcc-inventory-tracker'),
                                isset($item['name']) ? $item['name'] : $square_id
                            );
                        }
                    }
                }
            }
        }

        if (empty($results)) {
            $results[] = __('No tests performed. Please enter a Square Catalog ID.', 'brcc-inventory-tracker');
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
     * Add CSS for the date mappings modal
     */
    public function add_modal_styles()
    {
    ?>
        <style type="text/css">
            /* Modal Styles */
            #brcc-date-mappings-modal {
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0, 0, 0, 0.4);
            }

            .brcc-modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 80%;
                max-width: 900px;
                border-radius: 4px;
            }

            .brcc-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #ddd;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }

            .brcc-modal-header h2 {
                margin: 0;
            }

            .brcc-modal-close {
                font-size: 24px;
                font-weight: bold;
                cursor: pointer;
            }

            .brcc-modal-footer {
                margin-top: 20px;
                text-align: right;
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }

            .brcc-modal-footer button {
                margin-left: 10px;
            }

            #brcc-dates-table {
                margin-top: 15px;
            }

            .brcc-date-test-result {
                margin-top: 5px;
                display: none;
            }

            .brcc-test-mode-badge {
                background-color: #f0c33c;
                color: #333;
                font-size: 12px;
                font-weight: bold;
                padding: 2px 8px;
                border-radius: 10px;
            }

            .brcc-live-mode-badge {
                background-color: #46b450;
                color: #fff;
                font-size: 12px;
                font-weight: bold;
                padding: 2px 8px;
                border-radius: 10px;
            }

            .brcc-full-width {
                width: 100% !important;
                flex-basis: 100% !important;
            }

            .brcc-period-summary-table {
                width: 100%;
                border-collapse: collapse;
            }

            .brcc-period-summary-table th,
            .brcc-period-summary-table td {
                padding: 10px;
                text-align: center;
                border: 1px solid #ddd;
            }

            .brcc-period-summary-table th {
                background-color: #f5f5f5;
            }
        </style>
    <?php
    }

    /**
     * Add JavaScript for date-based mappings
     */
    public function add_date_mappings_js()
    {
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var currentProductId = null;
                var datesMappings = {};

                // Open modal when "View/Edit Dates" button is clicked
                $(document).on('click', '.brcc-view-dates', function() {
                    currentProductId = $(this).data('product-id');

                    // Reset modal content
                    $('#brcc-dates-table-body').html('');
                    $('#brcc-dates-table').hide();
                    $('#brcc-no-dates').hide();
                    $('#brcc-dates-loading').show();

                    // Open modal
                    $('#brcc-date-mappings-modal').show();

                    // Load dates for this product
                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_get_product_dates',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId
                        },
                        success: function(response) {
                            $('#brcc-dates-loading').hide();

                            if (response.success && response.data.dates && response.data.dates.length > 0) {
                                // Store date mappings for this product
                                datesMappings[currentProductId] = response.data.dates;

                                // Populate table
                                var html = '';
                                $.each(response.data.dates, function(index, date) {
                                    html += '<tr data-date="' + date.date + '">';
                                    html += '<td>' + date.formatted_date + '</td>';
                                    html += '<td>' + (date.inventory !== null ? date.inventory : 'N/A') + '</td>';
                                    html += '<td><input type="text" class="regular-text date-eventbrite-id" value="' + (date.eventbrite_id || '') + '" /></td>';
                                    html += '<td><button type="button" class="button brcc-test-date-mapping" data-date="' + date.date + '">' + brcc_admin.test + '</button>';
                                    html += '<div class="brcc-date-test-result"></div></td>';
                                    html += '</tr>';
                                });

                                $('#brcc-dates-table-body').html(html);
                                $('#brcc-dates-table').show();
                            } else {
                                $('#brcc-no-dates').show();
                            }
                        },
                        error: function() {
                            $('#brcc-dates-loading').hide();
                            $('#brcc-no-dates').html('<p>' + brcc_admin.ajax_error + '</p>').show();
                        }
                    });
                });

                // Close modal
                $('.brcc-modal-close, #brcc-close-modal').on('click', function() {
                    $('#brcc-date-mappings-modal').hide();
                });

                // Click outside to close
                $(window).on('click', function(event) {
                    if ($(event.target).is('#brcc-date-mappings-modal')) {
                        $('#brcc-date-mappings-modal').hide();
                    }
                });

                // Save date mappings
                $('#brcc-save-date-mappings').on('click', function() {
                    var $button = $(this);
                    $button.prop('disabled', true).text(brcc_admin.saving);

                    // Collect all date mappings for the current product
                    var mappings = [];
                    $('#brcc-dates-table-body tr').each(function() {
                        var $row = $(this);
                        var date = $row.data('date');
                        var eventbriteId = $row.find('.date-eventbrite-id').val();

                        mappings.push({
                            date: date,
                            eventbrite_id: eventbriteId
                        });
                    });

                    // Save via AJAX
                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_save_product_date_mappings',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId,
                            mappings: mappings
                        },
                        success: function(response) {
                            $button.prop('disabled', false).text('<?php _e('Save Date Mappings', 'brcc-inventory-tracker'); ?>');

                            if (response.success) {
                                // Update button text to reflect saved mappings
                                $('.brcc-view-dates[data-product-id="' + currentProductId + '"]').text('<?php _e('View/Edit Dates', 'brcc-inventory-tracker'); ?>');

                                // Show success message
                                alert(response.data.message);

                                // Close modal
                                $('#brcc-date-mappings-modal').hide();
                            } else {
                                alert(response.data.message || brcc_admin.ajax_error);
                            }
                        },
                        error: function() {
                            $button.prop('disabled', false).text('<?php _e('Save Date Mappings', 'brcc-inventory-tracker'); ?>');
                            alert(brcc_admin.ajax_error);
                        }
                    });
                });

                // Test date mapping
                $(document).on('click', '.brcc-test-date-mapping', function() {
                    var $button = $(this);
                    var date = $button.data('date');
                    var $row = $button.closest('tr');
                    var eventbriteId = $row.find('.date-eventbrite-id').val();
                    var $resultContainer = $row.find('.brcc-date-test-result');

                    $button.prop('disabled', true).text(brcc_admin.testing);
                    $resultContainer.hide();

                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_test_product_date_mapping',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId,
                            date: date,
                            eventbrite_id: eventbriteId
                        },
                        success: function(response) {
                            $button.prop('disabled', false).text(brcc_admin.test);

                            if (response.success) {
                                $resultContainer.html(response.data.message).show();
                            } else {
                                $resultContainer.html(response.data.message || brcc_admin.ajax_error).show();
                            }

                            // Hide the result after a few seconds
                            setTimeout(function() {
                                $resultContainer.fadeOut();
                            }, 8000);
                        },
                        error: function() {
                            $button.prop('disabled', false).text(brcc_admin.test);
                            $resultContainer.html(brcc_admin.ajax_error).show();

                            // Hide the result after a few seconds
                            setTimeout(function() {
                                $resultContainer.fadeOut();
                            }, 8000);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX: Get product dates
     */
    public function ajax_get_product_dates()
    {
        // Pass to product mappings class
        $product_mappings = new BRCC_Product_Mappings();
        $product_mappings->ajax_get_product_dates();
    }

    /**
     * AJAX: Save product date mappings
     */
    public function ajax_save_product_date_mappings()
    {
        // Pass to product mappings class
        $product_mappings = new BRCC_Product_Mappings();
        $product_mappings->ajax_save_product_date_mappings();
    }

    /**
     * AJAX: Test product date mapping
     */
    public function ajax_test_product_date_mapping()
    {
        // Pass to product mappings class
        $product_mappings = new BRCC_Product_Mappings();
        $product_mappings->ajax_test_product_date_mapping();
    }

    /**
     * AJAX: Regenerate API key
     */
    public function ajax_regenerate_api_key()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Generate new API key
        $api_key = 'brcc_' . md5(uniqid(rand(), true));

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
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Log sync initiation
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
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Sanitize mappings
        $sanitized_mappings = array();
        foreach ($mappings as $product_id => $mapping) {
            $sanitized_mappings[absint($product_id)] = array(
                'eventbrite_id' => isset($mapping['eventbrite_id']) ? sanitize_text_field($mapping['eventbrite_id']) : '',
                'eventbrite_event_id' => isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : '', // Add event ID
                'square_id' => isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : '' // Add square ID
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
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $eventbrite_id = isset($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : '';

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Log test action
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
            $settings = get_option('brcc_api_settings', array());
            $has_eventbrite_token = !empty($settings['eventbrite_token']);

            if (!$has_eventbrite_token) {
                $results[] = __('Eventbrite configuration incomplete. Please add API Token in plugin settings.', 'brcc-inventory-tracker');
            } else {
                $results[] = sprintf(
                    __('Eventbrite ID "%s" is linked to product "%s". Eventbrite credentials are configured.', 'brcc-inventory-tracker'),
                    $eventbrite_id,
                    $product_name
                );

                // Test connection if class is available
                if (class_exists('BRCC_Eventbrite_Integration')) {
                    $eventbrite = new BRCC_Eventbrite_Integration();
                    $eventbrite_test = $eventbrite->test_connection();

                    if (is_wp_error($eventbrite_test)) {
                        $results[] = __('Eventbrite API test failed:', 'brcc-inventory-tracker') . ' ' . $eventbrite_test->get_error_message();
                    } else {
                        $results[] = __('Eventbrite API connection successful!', 'brcc-inventory-tracker');
                    }
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

        // Get daily sales for date range
        $all_sales = $sales_tracker->get_daily_sales();

        // Prepare chart data structure
        $chart_data = $this->prepare_chart_data($all_sales, $start_date, $end_date);

        wp_send_json_success(array(
            'chart_data' => $chart_data,
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }

    /**
     * Prepare chart data from sales data
     */
    private function prepare_chart_data($all_sales, $start_date, $end_date)
    {
        // Create date range
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);

        $chart_data = array(
            'labels' => array(),
            'datasets' => array(
                array(
                    'label' => __('Total Sales', 'brcc-inventory-tracker'),
                    'data' => array(),
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1
                ),
                array(
                    'label' => __('WooCommerce', 'brcc-inventory-tracker'),
                    'data' => array(),
                    'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                    'borderColor' => 'rgba(255, 99, 132, 1)',
                    'borderWidth' => 1
                ),
                array(
                    'label' => __('Eventbrite', 'brcc-inventory-tracker'),
                    'data' => array(),
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'borderWidth' => 1
                )
            )
        );

        // Create date range
        $current = $start_timestamp;
        while ($current <= $end_timestamp) {
            $date = date('Y-m-d', $current);
            $chart_data['labels'][] = date('M j', $current);

            // Initialize sales counters
            $total_sales = 0;
            $woo_sales = 0;
            $eventbrite_sales = 0;

            // Get sales for this date
            if (isset($all_sales[$date])) {
                foreach ($all_sales[$date] as $product_id => $product_data) {
                    $total_sales += isset($product_data['quantity']) ? $product_data['quantity'] : 0;
                    $woo_sales += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                    $eventbrite_sales += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                }
            }

            // Add sales data for this date
            $chart_data['datasets'][0]['data'][] = $total_sales;
            $chart_data['datasets'][1]['data'][] = $woo_sales;
            $chart_data['datasets'][2]['data'][] = $eventbrite_sales;

            // Move to next day
            $current = strtotime('+1 day', $current);
        }

        return $chart_data;
    }

    /**
     * Add chart initialization script to the admin footer
     */
    public function add_chart_init_script()
    {
        // Only add on our plugin's dashboard page
        if (isset($_GET['page']) && $_GET['page'] === 'brcc-inventory') {
        ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    console.log('Direct chart initialization script running');

                    // Make sure Chart.js is loaded
                    if (typeof Chart === 'undefined') {
                        console.error('Chart.js is not loaded!');
                        return;
                    }

                    // Get the canvas element
                    var chartCanvas = document.getElementById('brcc-sales-chart');
                    if (!chartCanvas) {
                        console.error('Chart canvas element not found!');
                        return;
                    }

                    // Create the chart
                    try {
                        console.log('Creating chart object...');
                        var ctx = chartCanvas.getContext('2d');
                        window.salesChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: ['Loading...'],
                                datasets: [{
                                    label: 'Sales',
                                    data: [0],
                                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Sales'
                                        }

                                    }
                                }
                            }
                        });
                        console.log('Chart object created:', window.salesChart);

                        // Initial data load
                        var initialDays = $('#brcc-chart-days').val() || 7;
                        console.log('Triggering initial chart data load for days:', initialDays);
                        window.loadChartData(initialDays);

                    } catch (error) {
                        console.error('Error creating chart:', error);
                    }
                });
            </script>
        <?php
        }
    }

    /**
     * Display Import Historical Data page
     */
    public function display_import_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Import Historical Sales Data', 'brcc-inventory-tracker'); ?></h1>
            <p><?php _e('Import past sales data from WooCommerce and/or Square to include it in the dashboard and reports.', 'brcc-inventory-tracker'); ?></p>
            <p><strong><?php _e('Important:', 'brcc-inventory-tracker'); ?></strong> <?php _e('Importing historical data will NOT affect your current live inventory on Eventbrite or Square.', 'brcc-inventory-tracker'); ?></p>

            <div id="brcc-import-controls">
                <h3><?php _e('Import Settings', 'brcc-inventory-tracker'); ?></h3>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="brcc-import-start-date"><?php _e('Start Date', 'brcc-inventory-tracker'); ?></label></th>
                            <td><input type="text" id="brcc-import-start-date" name="brcc_import_start_date" class="brcc-datepicker" placeholder="YYYY-MM-DD" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="brcc-import-end-date"><?php _e('End Date', 'brcc-inventory-tracker'); ?></label></th>
                            <td><input type="text" id="brcc-import-end-date" name="brcc_import_end_date" class="brcc-datepicker" placeholder="YYYY-MM-DD" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Data Sources', 'brcc-inventory-tracker'); ?></th>
                            <td>
                                <fieldset>
                                    <label for="brcc-import-source-wc">
                                        <input type="checkbox" id="brcc-import-source-wc" name="brcc_import_sources[]" value="woocommerce" checked="checked" />
                                        <?php _e('WooCommerce Orders', 'brcc-inventory-tracker'); ?>
                                    </label><br>
                                    <label for="brcc-import-source-sq">
                                        <input type="checkbox" id="brcc-import-source-sq" name="brcc_import_sources[]" value="square" />
                                        <?php _e('Square Orders', 'brcc-inventory-tracker'); ?>
                                        <?php
                                        // Check if Square is configured
                                        $settings = get_option('brcc_api_settings');
                                        $square_configured = !empty($settings['square_access_token']) && !empty($settings['square_location_id']);
                                        if (!$square_configured) {
                                            echo ' <span style="color: red;">(' . esc_html__('Square API not configured in Settings', 'brcc-inventory-tracker') . ')</span>'; // Escaped output
                                        }
                                        ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <?php wp_nonce_field('brcc-import-history-nonce', 'brcc_import_nonce'); ?>
                    <button type="button" id="brcc-start-import" class="button button-primary" <?php disabled(!$square_configured, true); ?>>
                        <?php _e('Start Import', 'brcc-inventory-tracker'); ?>
                    </button>
                    <?php if (!$square_configured): ?>
                <p style="color: red;"><?php _e('Square import disabled until API is configured in Settings.', 'brcc-inventory-tracker'); ?></p>
            <?php endif; ?>
            </p>
            </div>

            <div id="brcc-import-status" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;">
                <h3><?php _e('Import Status', 'brcc-inventory-tracker'); ?></h3>
                <div id="brcc-import-progress">
                    <p><?php _e('Import process started. Please do not close this window.', 'brcc-inventory-tracker'); ?></p>
                    <progress id="brcc-import-progress-bar" value="0" max="100" style="width: 100%;"></progress>
                    <p id="brcc-import-status-message"></p>
                </div>
                <div id="brcc-import-log" style="max-height: 300px; overflow-y: auto; background: #fff; border: 1px solid #eee; padding: 10px; margin-top: 10px; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
                    <!-- Log messages will appear here -->
                </div>
                <button type="button" id="brcc-import-complete" class="button button-secondary" style="display: none; margin-top: 10px;"><?php _e('Import Complete - Close', 'brcc-inventory-tracker'); ?></button>
            </div>

        </div><!-- .wrap -->
<?php
    }

    /**
     * AJAX: Process a batch of historical data import
     */
    public function ajax_import_batch() {
        BRCC_Helpers::log_debug('ajax_import_batch: Request received.'); // Log start
        // Security checks
        // Log received POST data for debugging
        BRCC_Helpers::log_debug('ajax_import_batch: POST data', $_POST);

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-import-history-nonce')) {
            BRCC_Helpers::log_error('ajax_import_batch: Nonce check failed.');
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get parameters
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
        $sources = isset($_POST['sources']) && is_array($_POST['sources']) ? array_map('sanitize_text_field', $_POST['sources']) : array();
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = 2; // Process 2 items per batch (Drastically reduced for testing timeout/memory)

        // Validate dates
        if (!$start_date || !$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error(array('message' => __('Invalid date range provided.', 'brcc-inventory-tracker')));
        }
        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(array('message' => __('Start date cannot be after end date.', 'brcc-inventory-tracker')));
        }
        if (empty($sources)) {
            wp_send_json_error(array('message' => __('No data sources selected.', 'brcc-inventory-tracker')));
        }

        $logs = array();
        $processed_count_total = 0;
        $next_offset = null; // Assume completion unless set otherwise
        $progress_message = '';
        $progress = 0;

        try {
            // Get state from POST or initialize
            // The 'offset' parameter from JS contains the state object or 0 for the first call
            $state_param = isset($_POST['offset']) ? $_POST['offset'] : 0;
            $state = is_array($state_param) ? $state_param : array(
                'source_index'    => 0,
                'wc_offset'       => 0,    // Tracks WooCommerce order offset
                'square_cursor'   => null, // Tracks Square pagination cursor
                'total_processed' => 0,
            );

            $current_source_index = $state['source_index'];
            $total_processed      = $state['total_processed'];
            BRCC_Helpers::log_debug('ajax_import_batch: Current State', $state); // Log the state object

            if ($current_source_index >= count($sources)) {
                // Should not happen if JS stops calling, but handle defensively
                wp_send_json_success(array(
                    'message'       => 'Import already completed.',
                    'logs'          => $logs,
                    'progress'      => 100,
                    'next_offset'   => null, // Signal JS to stop
                ));
            }

            // Add check for valid source index before accessing
            if (!isset($sources[$current_source_index])) {
                 $error_msg = 'Error: Invalid source index.';
                 BRCC_Helpers::log_error($error_msg . ' State: ' . print_r($state, true) . ' Sources: ' . print_r($sources, true));
                 throw new Exception($error_msg);
            }
            $current_source = $sources[$current_source_index];
            BRCC_Helpers::log_debug("ajax_import_batch: Processing source: {$current_source} at index {$current_source_index}. Offset/Cursor: " . ($current_source === 'woocommerce' ? $state['wc_offset'] : $state['square_cursor']));
            $logs[] = array('message' => "--- Starting batch for source: {$current_source} ---", 'type' => 'info');

            $batch_result = array(
                'processed_count' => 0,
                'next_offset'     => null, // Contains next WC offset or Square cursor
                'source_complete' => false,
                'logs'            => array()
            );

            // Process batch for the current source
            if ($current_source === 'woocommerce') {
                $offset = $state['wc_offset'];
                $logs[] = array('message' => "Processing WooCommerce batch (Offset: {$offset})...", 'type' => 'info');
                $sales_tracker = new BRCC_Sales_Tracker();
                if (method_exists($sales_tracker, 'import_woocommerce_batch')) {
                    $batch_result = $sales_tracker->import_woocommerce_batch($start_date, $end_date, $offset, $batch_size);
                    $state['wc_offset'] = $batch_result['next_offset']; // Update WC offset for next time
                } else {
                    $logs[] = array('message' => "WooCommerce import logic not found in BRCC_Sales_Tracker.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } elseif ($current_source === 'square') {
                $cursor = $state['square_cursor'];
                $logs[] = array('message' => "Processing Square batch " . ($cursor ? "(Cursor: {$cursor})" : "(First batch)") . "...", 'type' => 'info');
                $square_integration = new BRCC_Square_Integration();
                if (method_exists($square_integration, 'import_square_batch')) {
                    $batch_result = $square_integration->import_square_batch($start_date, $end_date, $cursor, $batch_size);
                    $state['square_cursor'] = $batch_result['next_offset']; // Update Square cursor for next time
                } else {
                    $logs[] = array('message' => "Square import logic not found in BRCC_Square_Integration.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } else {
                $logs[] = array('message' => "Unknown import source: {$current_source}", 'type' => 'error');
                $batch_result['source_complete'] = true; // Skip unknown source
            }

            // Merge logs from the batch
            $logs = array_merge($logs, $batch_result['logs']);
            $total_processed += $batch_result['processed_count'];
            $state['total_processed'] = $total_processed;

            // Determine next state
            $next_state_param = null; // This will be passed back to JS as the 'offset' for the next call
            if ($batch_result['source_complete']) {
                $logs[] = array('message' => "--- Finished processing source: {$current_source} ---", 'type' => 'info');
                $state['source_index']++; // Move to next source index
                // Reset offsets/cursors for the next source (if any)
                $state['wc_offset'] = 0;
                $state['square_cursor'] = null;

                if ($state['source_index'] >= count($sources)) {
                    // All sources are complete
                    $progress = 100;
                    $progress_message = "Import finished. Processed {$total_processed} total items.";
                    $logs[] = array('message' => $progress_message, 'type' => 'success');
                } else {
                    // More sources to process
                    $next_state_param = $state; // Pass the updated state for the next source
                    $progress = min(99, round(($state['source_index'] / count($sources)) * 100));
                    $progress_message = "Finished {$current_source}. Moving to next source (" . $sources[$state['source_index']] . ")...";
                }
            } else {
                // Current source needs more batches
                $next_state_param = $state; // Pass the updated state (with new offset/cursor)
                // Progress estimation is difficult without total counts, use source index for now
                $progress = min(99, round((($state['source_index'] + 0.5) / count($sources)) * 100)); // Estimate mid-source progress
                $progress_message = "Processed batch for {$current_source}. Total items processed so far: {$total_processed}. Continuing...";
            }


            wp_send_json_success(array(
                'message'       => $progress_message,
                'logs'          => $logs,
                'progress'      => $progress,
                'next_offset'   => $next_offset, // Can be null or an object with state
            ));
        } catch (Exception $e) {
            // Log the exception
            BRCC_Helpers::log_error('Import Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An unexpected error occurred during import: ' . $e->getMessage(),
                'logs' => $logs, // Send logs collected so far
            ));
        }
    } // End ajax_import_batch method

    // The closing brace for the class is correctly placed after all methods (line 2490)


    /**
     * AJAX: Suggest Eventbrite ID for a product
     */
    public function ajax_suggest_eventbrite_id() {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Nonce check failed.');
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Insufficient permissions.');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (empty($product_id)) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Product ID missing.');
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
        }
        
        BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID', 'Attempting to suggest ID for Product ID: ' . $product_id);

        $product = wc_get_product($product_id);
        if (!$product) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Product not found for ID: ' . $product_id);
             wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
        }

        // Check if Eventbrite integration class exists
        if (!class_exists('BRCC_Eventbrite_Integration')) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: BRCC_Eventbrite_Integration class not found.');
             wp_send_json_error(array('message' => __('Eventbrite Integration is not available.', 'brcc-inventory-tracker')));
        }
        
        try {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            
            // Get suggestions (passing product object, no specific date/time)
            $suggestions = $eventbrite_integration->suggest_eventbrite_ids_for_product($product, null, null);
            
            // Check if the suggestion function returned an error
            if (is_wp_error($suggestions)) {
                 /** @var WP_Error $suggestions */ // Hint for Intelephense
                 $error_message = $suggestions->get_error_message();
                 BRCC_Helpers::log_error('Suggest Eventbrite ID Error: suggest_eventbrite_ids_for_product returned WP_Error: ' . $error_message);
                 wp_send_json_error(array('message' => $error_message));
            } elseif (empty($suggestions)) {
                 BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID Result', 'No relevant Eventbrite events/tickets found for Product ID: ' . $product_id);
                 wp_send_json_error(array('message' => __('No relevant Eventbrite events/tickets found.', 'brcc-inventory-tracker')));
            } else {
                 // Log the top suggestion details
                 $top_suggestion = $suggestions[0];
                 $log_details = sprintf(
                     'Suggestion found for Product ID %d: Ticket ID %s (Event: "%s", Ticket: "%s", Relevance: %s)',
                     $product_id,
                     $top_suggestion['ticket_id'],
                     $top_suggestion['event_name'],
                     $top_suggestion['ticket_name'],
                     $top_suggestion['relevance']
                 );
                 BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID Result', $log_details);
                 
                 // Return the top suggestion
                 wp_send_json_success(array(
                     'message' => __('Suggestion found.', 'brcc-inventory-tracker'),
                     'suggestion' => $top_suggestion // Send the highest relevance suggestion
                 ));
            }
        } catch (Exception $e) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Exception: ' . $e->getMessage());
             wp_send_json_error(array('message' => 'An unexpected error occurred while suggesting ID.'));
        }
    } // Added missing closing brace for the previous function
       /**
        * AJAX handler for suggesting Eventbrite Ticket ID for a specific date/time.
        */
       public function ajax_suggest_eventbrite_ticket_id_for_date() {
           check_ajax_referer('brcc-admin-nonce', 'nonce');
   
           if (!current_user_can('manage_options')) {
               wp_send_json_error(['message' => __('Permission denied.', 'brcc-inventory-tracker')]);
           }
   
           $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
           $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : null;
           $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : null;
   
           if (!$product_id || !$date) {
               wp_send_json_error(['message' => __('Missing required parameters (product_id, date).', 'brcc-inventory-tracker')]);
           }
   
           $product = wc_get_product($product_id);
           if (!$product) {
               wp_send_json_error(['message' => __('Invalid product ID.', 'brcc-inventory-tracker')]);
           }
   
           try {
               $eventbrite_integration = new BRCC_Eventbrite_Integration();
               // Use the existing suggestion function, passing date and time
               $suggestions = $eventbrite_integration->suggest_eventbrite_ids_for_product($product, $date, $time);
   
               if (is_wp_error($suggestions)) {
                   wp_send_json_error(['message' => $suggestions->get_error_message()]);
               }
   
               // Find the best suggestion (highest relevance, exact date match, close time match)
               if (!empty($suggestions)) {
                   // The function already sorts by relevance, so the first one is usually the best
                   $best_suggestion = $suggestions[0];
                   wp_send_json_success($best_suggestion); // Send the whole suggestion object
               } else {
                   wp_send_json_error(['message' => __('No matching Eventbrite event/ticket found for this date/time.', 'brcc-inventory-tracker')]);
               }
   
           } catch (Exception $e) {
               BRCC_Helpers::log_error('Error in ajax_suggest_eventbrite_ticket_id_for_date: ' . $e->getMessage());
               wp_send_json_error(['message' => __('An unexpected error occurred.', 'brcc-inventory-tracker')]);
           }
       }
       
   /**
    * Display the Attendee Lists page
    */
   public function display_attendee_list_page() {
       ?>
       <div class="wrap">
           <h1><?php _e('Attendee Lists', 'brcc-inventory-tracker'); ?></h1>
           <p><?php _e('Select a product to view combined attendee lists from WooCommerce and Eventbrite.', 'brcc-inventory-tracker'); ?></p>

           <div class="brcc-attendee-filters">
               <label for="brcc-attendee-product-select"><?php _e('Select Product:', 'brcc-inventory-tracker'); ?></label>
               <select id="brcc-attendee-product-select" name="brcc_attendee_product_id">
                   <option value=""><?php _e('-- Select a Product --', 'brcc-inventory-tracker'); ?></option>
                   <?php
                   // Get all published WooCommerce products
                   $products = wc_get_products(array(
                       'limit' => -1,
                       'status' => 'publish',
                       'orderby' => 'title',
                       'order' => 'ASC',
                   ));

                   if (!empty($products)) {
                       foreach ($products as $product) {
                           echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name()) . '</option>';
                       }
                   }
                   ?>
               </select>
               <!-- Placeholder for Date/Time filters if needed later -->
               <span id="brcc-attendee-date-filter-placeholder" style="margin-left: 10px;"></span>
                <button type="button" id="brcc-fetch-attendees" class="button button-primary" disabled><?php _e('Fetch Attendees', 'brcc-inventory-tracker'); ?></button>
           </div>

           <div id="brcc-attendee-list-container" style="margin-top: 20px;">
               <!-- Attendee list table will be loaded here via AJAX -->
               <p><?php _e('Loading attendee data...', 'brcc-inventory-tracker'); ?></p>
           </div>
       </div>
       <?php
   }

   /**
    * AJAX handler to fetch combined attendee list
    */
   public function ajax_fetch_attendees() {
       // Check nonce and capability
       if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
           wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
       }
       if (!current_user_can('manage_options')) {
           wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
       }

       $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

       if (!$product_id) {
           wp_send_json_error(array('message' => __('Invalid Product ID.', 'brcc-inventory-tracker')));
       }

       $attendees = array();
       $errors = array();

       // --- Fetch WooCommerce Orders ---
       try {
           $args = array(
               'limit' => -1, // Get all orders
               'status' => array('wc-processing', 'wc-completed'), // Relevant order statuses
               'meta_query' => array(
                   array(
                       'key' => '_product_id',
                       'value' => $product_id,
                       'compare' => '='
                   )
               )
           );
           $orders = wc_get_orders($args);

           foreach ($orders as $order) {
                // Check if the order contains the specific product ID
                $found_product = false;
                foreach ($order->get_items() as $item_id => $item) {
                    if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                        $found_product = true;
                        break;
                    }
            
            }

                if ($found_product) {
                   $attendees[] = array(
                       'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                       'email' => $order->get_billing_email(),
                       'source' => 'WooCommerce',
                       'purchase_date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                   );
                }
           }
       } catch (Exception $e) {
           $errors[] = "Error fetching WooCommerce orders: " . $e->getMessage();
       }


       // --- Fetch Eventbrite Attendees ---
       try {
           // Get mapping
           $all_mappings = get_option('brcc_product_mappings', array());
           $mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : null;
           $event_id = isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : null;

           if ($event_id) {
               $eventbrite_integration = new BRCC_Eventbrite_Integration();
               // TODO: Call a new method like $eventbrite_integration->get_event_attendees($event_id);
               // This method needs to be created in class-brcc-eventbrite-integration.php
               // For now, add placeholder data or error
                $eventbrite_attendees = $eventbrite_integration->get_event_attendees($event_id); // Placeholder call

                if (is_wp_error($eventbrite_attendees)) {
                     $errors[] = "Error fetching Eventbrite attendees: " . $eventbrite_attendees->get_error_message();
                } elseif (is_array($eventbrite_attendees)) {
                    foreach ($eventbrite_attendees as $attendee) {
                        // Extract relevant data (adjust keys based on actual API response)
                        $attendees[] = array(
                            'name' => isset($attendee['profile']['name']) ? $attendee['profile']['name'] : 'N/A',
                            'email' => isset($attendee['profile']['email']) ? $attendee['profile']['email'] : 'N/A',
                            'source' => 'Eventbrite',
                            'purchase_date' => isset($attendee['created']) ? date('Y-m-d H:i:s', strtotime($attendee['created'])) : '',
                        );
                    }
                }

           } else {
               // Optional: Add a note if no Eventbrite mapping found
               // $errors[] = "No Eventbrite Event ID mapped for this product.";
           }
       } catch (Exception $e) {
           $errors[] = "Error fetching Eventbrite attendees: " . $e->getMessage();
       }

       // --- Combine and Send Response ---
       if (!empty($errors)) {
            // Send partial data with errors, or just errors
            wp_send_json_error(array(
                'message' => implode('; ', $errors),
                'attendees' => $attendees // Optionally send partial data
            ));
       } else {
           wp_send_json_success(array(
               'attendees' => $attendees
           ));
       }
   }

   /**
    * Sends the daily attendee list email via WP-Cron.
    */
   public function send_daily_attendee_email() {
       BRCC_Helpers::log_info('Starting daily attendee email cron job.');

       $target_date = date('Y-m-d', strtotime('+1 day')); // Get tomorrow's date
       $email_subject = sprintf(__('Attendee List for %s - %s', 'brcc-inventory-tracker'), get_bloginfo('name'), $target_date);
       $email_body = '<h1>' . sprintf(__('Attendee List for %s', 'brcc-inventory-tracker'), $target_date) . '</h1>';
       $email_body .= '<p>' . sprintf(__('Generated on: %s', 'brcc-inventory-tracker'), date('Y-m-d H:i:s')) . '</p>';
       $email_body .= '<hr>';

       $found_attendees = false;
       $errors = array();

       // Get all product mappings
       $all_mappings = get_option('brcc_product_mappings', array());
       $eventbrite_integration = new BRCC_Eventbrite_Integration(); // Instantiate once

       foreach ($all_mappings as $product_id => $mapping) {
           // Skip date collections or products without an Eventbrite Event ID
           if (strpos($product_id, '_dates') !== false || empty($mapping['eventbrite_event_id'])) {
               continue;
           }

           $event_id = $mapping['eventbrite_event_id'];
           $product = wc_get_product($product_id);
           if (!$product) continue;

           // Check if the Eventbrite event associated with this product occurs on the target date
           // We need to fetch the event details to check its date
           $event_details = $eventbrite_integration->get_eventbrite_event($event_id);

           if (is_wp_error($event_details)) {
               $errors[] = sprintf(__('Error fetching details for Eventbrite Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $event_details->get_error_message());
               continue;
           }

           $event_start_date = isset($event_details['start']['local']) ? date('Y-m-d', strtotime($event_details['start']['local'])) : null;

           // If the event is not on the target date, skip it
           if ($event_start_date !== $target_date) {
               continue;
           }

           $email_body .= '<h2>' . sprintf(__('Attendees for: %s', 'brcc-inventory-tracker'), esc_html($product->get_name())) . '</h2>';

           // Fetch attendees (using logic similar to ajax_fetch_attendees)
           $attendees = array();

           // Fetch WooCommerce Orders for this product on the target date (more specific if possible)
           // Note: WC Orders don't directly store the 'event date', only purchase date.
           // We might need to rely on product variations or custom meta if filtering by event date is needed here.
           // For now, we'll fetch based on product ID and assume they relate to the event if the event is tomorrow.
           try {
               $args = array(
                   'limit' => -1,
                   'status' => array('wc-processing', 'wc-completed'),
               );
               $orders = wc_get_orders($args);
               $wc_attendees_for_product = 0;

               foreach ($orders as $order) {
                    $found_product = false;
                    foreach ($order->get_items() as $item_id => $item) {
                        if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                            $found_product = true;
                            break;
                        }
                    }
                    if ($found_product) {
                       $attendees[] = array(
                           'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                           'email' => $order->get_billing_email(),
                           'source' => 'WooCommerce',
                           'purchase_date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                       );
                       $wc_attendees_for_product++;
                    }
               }
                if ($wc_attendees_for_product > 0) $found_attendees = true;
           } catch (Exception $e) {
               $errors[] = sprintf(__('Error fetching WooCommerce orders for Product ID %s: %s', 'brcc-inventory-tracker'), $product_id, $e->getMessage());
           }

           // Fetch Eventbrite Attendees
           try {
               $eventbrite_attendees = $eventbrite_integration->get_event_attendees($event_id);

               if (is_wp_error($eventbrite_attendees)) {
                    $errors[] = sprintf(__('Error fetching Eventbrite attendees for Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $eventbrite_attendees->get_error_message());
               } elseif (is_array($eventbrite_attendees)) {
                   if (count($eventbrite_attendees) > 0) $found_attendees = true;
                   foreach ($eventbrite_attendees as $attendee) {
                       $attendees[] = array(
                           'name' => isset($attendee['profile']['name']) ? $attendee['profile']['name'] : 'N/A',
                           'email' => isset($attendee['profile']['email']) ? $attendee['profile']['email'] : 'N/A',
                           'source' => 'Eventbrite',
                           'purchase_date' => isset($attendee['created']) ? date('Y-m-d H:i:s', strtotime($attendee['created'])) : '',
                       );
                   }
               }
           } catch (Exception $e) {
                $errors[] = sprintf(__('Error fetching Eventbrite attendees for Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $e->getMessage());
           }

           // Format table for this product
           if (!empty($attendees)) {
                // Sort by name for easier reading
                usort($attendees, function($a, $b) {
                    return strcmp(strtolower($a['name']), strtolower($b['name']));
                });

                $email_body .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
                $email_body .= '<thead><tr style="background-color: #f2f2f2;"><th>Name</th><th>Email</th><th>Source</th><th>Purchase Date</th></tr></thead>';
                $email_body .= '<tbody>';
                foreach ($attendees as $att) {
                    $email_body .= '<tr>';
                    $email_body .= '<td>' . esc_html($att['name']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['email']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['source']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['purchase_date']) . '</td>';
                    $email_body .= '</tr>';
                }
                $email_body .= '</tbody></table>';
           } else {
               $email_body .= '<p>' . __('No attendees found for this event.', 'brcc-inventory-tracker') . '</p>';
           }
            $email_body .= '<hr>';

       } // End foreach product mapping

       // Add errors to email body if any occurred
       if (!empty($errors)) {
           $email_body .= '<h2>' . __('Errors Encountered:', 'brcc-inventory-tracker') . '</h2>';
           $email_body .= '<ul>';
           foreach ($errors as $error) {
               $email_body .= '<li>' . esc_html($error) . '</li>';
           }
           $email_body .= '</ul>';
           $email_subject .= ' (' . __('Errors Occurred', 'brcc-inventory-tracker') . ')';
       }

       // Only send email if attendees were found or errors occurred
       if ($found_attendees || !empty($errors)) {
           $to = 'webadmin@jmplaunch.com, backroomcomedyclub@gmail.com';
           $headers = array('Content-Type: text/html; charset=UTF-8');

           BRCC_Helpers::log_info('Sending daily attendee email to: ' . $to);
           wp_mail($to, $email_subject, $email_body, $headers);
       } else {
            BRCC_Helpers::log_info('No attendees found for tomorrow or errors occurred. Skipping daily email.');
       }

        BRCC_Helpers::log_info('Finished daily attendee email cron job.');
   }
}
