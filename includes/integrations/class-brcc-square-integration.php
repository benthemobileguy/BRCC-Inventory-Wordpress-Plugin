<?php
/**
 * BRCC Square Integration Class
 * 
 * Handles integration with Square API for ticket sales and inventory synchronization
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Square_Integration {
    /**
     * Square API base URL
     */
    private $api_url = 'https://connect.squareup.com/v2';
    
    /**
     * Square API Access Token
     */
    private $access_token;
    
    /**
     * Square Location ID
     */
    private $location_id;
    
    /**
     * Product mappings instance
     */
    private $product_mappings;
    
    /**
     * Constructor - setup hooks
     */
    public function __construct() {
        // Get settings
        $settings = get_option('brcc_api_settings');
        
        // Set API credentials
        $this->access_token = isset($settings['square_access_token']) ? $settings['square_access_token'] : '';
        $this->location_id = isset($settings['square_location_id']) ? $settings['square_location_id'] : '';
        
        // Set sandbox mode based on settings
        $this->is_sandbox = isset($settings['square_sandbox']) && $settings['square_sandbox'];
        
        // Adjust API URL if in sandbox mode
        if ($this->is_sandbox) {
            $this->api_url = 'https://connect.squareupsandbox.com/v2';
        }
        
        // Initialize product mappings
        $this->product_mappings = new BRCC_Product_Mappings();
        
        // Add hooks if API credentials are configured
        if (!empty($this->access_token) && !empty($this->location_id)) {
            // Hook into inventory sync
            add_action('brcc_sync_inventory', array($this, 'sync_square_orders'));
            
            // Hook to process Square webhook notifications
            add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
            
            // Schedule regular pulls from Square in case webhooks miss anything
            if (!wp_next_scheduled('brcc_square_pull_orders')) {
                wp_schedule_event(time(), 'hourly', 'brcc_square_pull_orders');
            }
            add_action('brcc_square_pull_orders', array($this, 'pull_recent_orders'));
        }
    }

    /**
     * Register webhook endpoint for Square
     */
    public function register_webhook_endpoint() {
        register_rest_route('brcc/v1', '/square-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_webhook'),
            'permission_callback' => array($this, 'verify_webhook_signature'),
        ));
    }
    
    /**
     * Verify Square webhook signature using SHA256
     * 
     * @param WP_REST_Request $request The request object
     * @return bool|WP_Error Whether the signature is valid
     */
    public function verify_webhook_signature($request) {
        // Get Square webhook signature
        $signature = $request->get_header('X-Square-Signature');
        
        if (empty($signature)) {
            return new WP_Error(
                'invalid_signature',
                __('Missing Square webhook signature', 'brcc-inventory-tracker'),
                array('status' => 401)
            );
        }
        
        // Get the request body
        $body = $request->get_body();
        
        // Get Square webhook signature key
        $settings = get_option('brcc_api_settings');
        $signature_key = isset($settings['square_webhook_signature_key']) ? $settings['square_webhook_signature_key'] : '';
        
        // If no signature key is configured, we can't verify
        if (empty($signature_key)) {
            // Log this as a configuration issue
            BRCC_Helpers::log_error(__('Square webhook received but signature verification key is not configured', 'brcc-inventory-tracker'));
            return new WP_Error(
                'missing_signature_key',
                __('Square webhook signature verification key is not configured', 'brcc-inventory-tracker'),
                array('status' => 500)
            );
        }
        
        // Verify the signature using SHA256 for better security
        $computed_signature = base64_encode(hash_hmac('sha256', $body, $signature_key, true));
        
        if (!hash_equals($signature, $computed_signature)) {
            return new WP_Error(
                'invalid_signature',
                __('Invalid Square webhook signature', 'brcc-inventory-tracker'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Process Square webhook
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response
     */
    public function process_webhook($request) {
        // Get the notification type
        $payload = json_decode($request->get_body(), true);
        
        if (empty($payload) || !isset($payload['type'])) {
            return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid payload'), 400);
        }
        
        // Log the webhook receipt
        BRCC_Helpers::log_info(sprintf(
            __('Square webhook received: %s', 'brcc-inventory-tracker'),
            $payload['type']
        ));
        
        // Process based on notification type
        switch ($payload['type']) {
            case 'order.created':
            case 'order.updated':
                if (isset($payload['data']['object']['order'])) {
                    $this->process_square_order($payload['data']['object']['order']);
                }
                break;
                
            default:
                // Ignore other webhook types
                break;
        }
        
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    /**
     * Process a Square order with error handling and rate limit awareness
     * 
     * @param array $order Square order data
     */
    public function process_square_order($order) {
        // Check if order is completed
        if (!isset($order['state']) || $order['state'] !== 'COMPLETED') {
            return;
        }
        
        // Process line items in the order
        if (isset($order['line_items']) && is_array($order['line_items'])) {
            $order_id = isset($order['id']) ? $order['id'] : 'unknown';
            $order_date = isset($order['created_at']) ? $this->convert_to_local_date($order['created_at']) : current_time('Y-m-d');
            
            foreach ($order['line_items'] as $item) {
                try {
                    // Get item name and catalog object ID
                    $item_name = isset($item['name']) ? $item['name'] : '';
                    $catalog_object_id = isset($item['catalog_object_id']) ? $item['catalog_object_id'] : '';
                    $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
                    
                    if (empty($catalog_object_id)) {
                        continue;
                    }
                    
                    // Look up product mapping by Square catalog ID
                    $product_id = $this->find_product_by_square_id($catalog_object_id);
                    
                    if ($product_id) {
                        // Use order date as per client requirements
                        // "But there is no Date attribute. Instead, it will have to go by date purchased."
                        $event_date = $order_date;
                        
                        // Still extract time from item name if available
                        $event_time = BRCC_Helpers::extract_time_from_title($item_name);
                        
                        // Record the sale
                        $this->record_square_sale($product_id, $quantity, $event_date, $event_time, $order_id);
                    }
                } catch (Exception $e) {
                    // Log exceptions but continue processing other items
                    BRCC_Helpers::log_error(sprintf(
                        __('Error processing Square order item: %s', 'brcc-inventory-tracker'),
                        $e->getMessage()
                    ));
                }
            }
        }
    }
    
    /**
     * Convert Square UTC date to local timezone date
     * 
     * @param string $utc_date_string UTC date string from Square API
     * @return string Date in Y-m-d format in site's timezone
     */
    private function convert_to_local_date($utc_date_string) {
        // Create DateTime object from the UTC string
        $utc_date = new DateTime($utc_date_string, new DateTimeZone('UTC'));
        
        // Convert to Toronto timezone as specified by client
        $utc_date->setTimezone(new DateTimeZone(BRCC_Constants::TORONTO_TIMEZONE));
        
        // Return Y-m-d format
        return $utc_date->format('Y-m-d');
    }
    
    /**
     * Find a WooCommerce product by Square catalog ID, checking both regular and date-specific mappings
     * 
     * @param string $square_id Square catalog object ID
     * @return int|null WooCommerce product ID or null if not found
     */
    private function find_product_by_square_id($square_id) {
        $all_mappings = get_option('brcc_product_mappings', array());
        
        // Search in regular mappings
        foreach ($all_mappings as $product_id => $mapping) {
            // Skip date collections
            if (strpos($product_id, '_dates') !== false) {
                continue;
            }
            
            if (isset($mapping['square_id']) && $mapping['square_id'] === $square_id) {
                return intval($product_id);
            }
        }
        
        // Search in date-specific mappings
        foreach ($all_mappings as $key => $value) {
            if (strpos($key, '_dates') !== false) {
                $product_id = str_replace('_dates', '', $key);
                
                foreach ($value as $date_key => $mapping) {
                    if (isset($mapping['square_id']) && $mapping['square_id'] === $square_id) {
                        return intval($product_id);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract date from Square item name with improved pattern matching
     * 
     * @param string $item_name The item name
     * @return string|null Date in Y-m-d format or null if not found
     */
    private function extract_date_from_item_name($item_name) {
        // First, look for full date formats (YYYY-MM-DD, MM/DD/YYYY, etc.)
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $item_name, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/\b(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2,4})\b/', $item_name, $matches)) {
            // Attempt to parse the date
            $day = $matches[1];
            $month = $matches[2];
            $year = $matches[3];
            
            // Handle 2-digit years
            if (strlen($year) == 2) {
                $year = '20' . $year;
            }
            
            // Try to create a valid date (assuming MM/DD/YYYY format)
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if (checkdate($month, $day, $year)) {
                return $date;
            }
            
            // Try DD/MM/YYYY format
            $date = sprintf('%04d-%02d-%02d', $year, $day, $month);
            if (checkdate($day, $month, $year)) {
                return $date;
            }
        }
        
        // Next, look for day names in the item name
        $day_patterns = array(
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 0,
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 0
        );
        
        $item_name_lower = strtolower($item_name);
        
        foreach ($day_patterns as $day_name => $day_number) {
            if (strpos($item_name_lower, $day_name) !== false) {
                // Found a day name, now get the next occurrence of this day
                $today = new DateTime('now', wp_timezone());
                $today_day_number = (int)$today->format('w');
                
                // Calculate days until the target day
                $days_until = ($day_number - $today_day_number + 7) % 7;
                
                // If today is the target day, we assume it's for today
                if ($days_until === 0) {
                    $days_until = 0;
                }
                
                // Get the date of the target day
                $target_date = clone $today;
                $target_date->modify('+' . $days_until . ' days');
                
                return $target_date->format('Y-m-d');
            }
        }
        
        // Look for month names as well
        $month_patterns = array(
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12
        );
        
        foreach ($month_patterns as $month_name => $month_number) {
            if (preg_match('/\b' . $month_name . '\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $item_name_lower, $matches)) {
                $day = intval($matches[1]);
                $year = date('Y'); // Assume current year
                
                // Check if the date has already passed this year
                $current_month = date('n');
                if ($month_number < $current_month || ($month_number == $current_month && $day < date('j'))) {
                    $year++; // Use next year
                }
                
                // Validate the date
                if (checkdate($month_number, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month_number, $day);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract time from Square item name with improved pattern matching
     * 
     * @param string $item_name The item name
     * @return string|null Time in H:i format or null if not found
     */
    private function extract_time_from_item_name($item_name) {
        // Look for time patterns like 8PM, 8:00 PM, etc.
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $item_name, $matches)) {
            $hour = intval($matches[1]);
            $minute = isset($matches[2]) ? intval($matches[2]) : 0;
            $ampm = strtolower($matches[3]);
            
            // Convert to 24-hour format
            if ($ampm === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($ampm === 'am' && $hour === 12) {
                $hour = 0;
            }
            
            return sprintf('%02d:%02d', $hour, $minute);
        }
        
        // Look for 24-hour format like 20:00
        if (preg_match('/\b([01]?[0-9]|2[0-3]):([0-5][0-9])\b/', $item_name, $matches)) {
            return sprintf('%02d:%02d', intval($matches[1]), intval($matches[2]));
        }
        
        return null;
    }
    
    /**
     * Record a sale from Square with better error handling and Eventbrite sync
     * 
     * @param int $product_id WooCommerce product ID
     * @param int $quantity Quantity sold
     * @param string $date Date in Y-m-d format
     * @param string $time Optional time in H:i format
     * @param string $square_order_id Square order ID for reference
     */
    public function record_square_sale($product_id, $quantity, $date, $time = null, $square_order_id = '') {
        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();
        
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            $date_info = $date ? " for date {$date}" : "";
            $time_info = $time ? " time {$time}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Square Order #%s: Product ID: %s, Quantity: %s%s%s. Would record Square sale.', 'brcc-inventory-tracker'), 
                    $square_order_id,
                    $product_id, 
                    $quantity,
                    $date_info,
                    $time_info
                )
            );
            return;
        } else if (BRCC_Helpers::should_log()) {
            $date_info = $date ? " for date {$date}" : "";
            $time_info = $time ? " time {$time}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Square Order #%s: Product ID: %s, Quantity: %s%s%s. Recording Square sale. (Live Mode)', 'brcc-inventory-tracker'), 
                    $square_order_id,
                    $product_id, 
                    $quantity,
                    $date_info,
                    $time_info
                )
            );
        }

        try {
            // Get the current date (WordPress timezone)
            $current_date = current_time('Y-m-d');

            // Get existing daily sales data
            $daily_sales = get_option('brcc_daily_sales', []);
            
            $product = wc_get_product($product_id);
            if (!$product) {
                throw new Exception(sprintf('Product ID %s not found', $product_id));
            }
            
            $product_name = $product->get_name();
            $sku = $product->get_sku();

            // Create unique key for product + booking date
            $product_key = $date ? $product_id . '_' . $date : $product_id;

            // Update daily sales for the product
            if (!isset($daily_sales[$current_date])) {
                $daily_sales[$current_date] = array();
            }
            
            if (isset($daily_sales[$current_date][$product_key])) {
                $daily_sales[$current_date][$product_key]['quantity'] += $quantity;
                // Add or increment Square quantity
                if (!isset($daily_sales[$current_date][$product_key]['square'])) {
                    $daily_sales[$current_date][$product_key]['square'] = $quantity;
                } else {
                    $daily_sales[$current_date][$product_key]['square'] += $quantity;
                }
            } else {
                $daily_sales[$current_date][$product_key] = array(
                    'name' => $product_name,
                    'sku' => $sku,
                    'product_id' => $product_id,
                    'booking_date' => $date,
                    'quantity' => $quantity,
                    'woocommerce' => 0,
                    'eventbrite' => 0,
                    'square' => $quantity
                );
            }

            // Save the updated daily sales data
            update_option('brcc_daily_sales', $daily_sales);
            
            // Also update product summary data
            $product_date_quantities = array(
                $product_key => array(
                    'product_id' => $product_id,
                    'name' => $product_name,
                    'sku' => $sku,
                    'booking_date' => $date,
                    'quantity' => $quantity
                )
            );
            
            // Update the daily product summary
            $sales_tracker->update_daily_product_summary($current_date, $product_date_quantities);
            
            // Trigger inventory sync for this product-date combination
            do_action('brcc_square_sale_recorded', $product_id, $quantity, $date, $time, $square_order_id);
            
            // If we have Eventbrite integration, update Eventbrite ticket availability
            $this->sync_to_eventbrite($product_id, $quantity, $date, $time);
            
            return true;
        } catch (Exception $e) {
            // Log error
            BRCC_Helpers::log_error(sprintf(
                __('Error recording Square sale: %s', 'brcc-inventory-tracker'),
                $e->getMessage()
            ));
            
            return false;
        }
    }
    
    /**
     * Sync sale to Eventbrite if integration is available
     *
     * @param int $product_id Product ID
     * @param int $quantity Quantity sold
     * @param string $date Date in Y-m-d format
     * @param string $time Time in H:i format
     * @param string $square_order_id Square order ID for reference
     * @return bool Success or failure
     */
    private function sync_to_eventbrite($product_id, $quantity, $date, $time = null, $square_order_id = '') {
        // Skip if Eventbrite integration isn't available
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return false;
        }
        
        $eventbrite = new BRCC_Eventbrite_Integration();
        
        // Get the Eventbrite mapping for this product/date/time
        $mapping = $this->product_mappings->get_product_mappings($product_id, $date, $time);
        
        
        // If we have an Eventbrite mapping, update the inventory
        if (!empty($mapping['eventbrite_id'])) {
            if (BRCC_Helpers::is_test_mode()) {
                BRCC_Helpers::log_operation(
                    'Square',
                    'Update Eventbrite',
                    sprintf(__('Would update Eventbrite ticket ID %s due to Square sale of product ID %s (Square Order ID: %s)', 'brcc-inventory-tracker'),
                        $mapping['eventbrite_id'],
                        $product_id,
                        $square_order_id
                    )
                );
                return true;
            }
            
            try {
                // Get current ticket info
                $ticket_info = $eventbrite->get_eventbrite_ticket($mapping['eventbrite_id']);
                
                if (!is_wp_error($ticket_info)) {
                    // Calculate new capacity
                    $current_capacity = isset($ticket_info['capacity_is_custom']) && $ticket_info['capacity_is_custom'] 
                        ? $ticket_info['capacity'] 
                        : $ticket_info['event_capacity'];
                        
                    $sold = isset($ticket_info['quantity_sold']) ? $ticket_info['quantity_sold'] : 0;
                    $new_capacity = $current_capacity - $quantity;
                    
                    // Make sure we don't go below zero or below sold
                    if ($new_capacity < $sold) {
                        $new_capacity = $sold;
                    }
                    
                    // Update ticket capacity
                    $result = $eventbrite->update_eventbrite_ticket_capacity($mapping['eventbrite_id'], $new_capacity);
                    
                    if (!is_wp_error($result)) {
                        BRCC_Helpers::log_info(sprintf(
                            __('Successfully updated Eventbrite ticket ID %s after Square sale of product ID %s (Square Order ID: %s)', 'brcc-inventory-tracker'),
                            $mapping['eventbrite_id'],
                            $product_id,
                            $square_order_id
                        ));
                        return true;
                    } else {
                        BRCC_Helpers::log_error(sprintf(
                            __('Failed to update Eventbrite ticket: %s', 'brcc-inventory-tracker'),
                            $result->get_error_message()
                        ));
                    }
                } else {
                    BRCC_Helpers::log_error(sprintf(
                        __('Error getting Eventbrite ticket info: %s', 'brcc-inventory-tracker'),
                        $ticket_info->get_error_message()
                    ));
                }
            } catch (Exception $e) {
                BRCC_Helpers::log_error(sprintf(
                    __('Exception during Eventbrite sync: %s', 'brcc-inventory-tracker'),
                    $e->getMessage()
                ));
            }
        }
        
        return false;
    }
    
    /**
     * Sync Square orders with WooCommerce inventory
     * Called during the main inventory sync action
     */
    public function sync_square_orders() {
        // We'll use the pull_recent_orders method
        $this->pull_recent_orders();
    }
    
    /**
     * Pull recent orders from Square with rate limit handling
     * This is called via a scheduled event to catch any orders that might have been missed by webhooks
     */
    public function pull_recent_orders() {
        // Check if Square integration is configured
        if (empty($this->access_token) || empty($this->location_id)) {
            return;
        }
        
        // Get the last sync time or default to 24 hours ago
        $last_sync = get_option('brcc_square_last_sync', strtotime('-24 hours'));
        
        // Format for Square API (RFC 3339 format)
        $begin_time = date('c', $last_sync);
        
        // Update the last sync time to now
        update_option('brcc_square_last_sync', time());
        
        // Log the sync operation
        if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Square',
                'Pull Orders',
                sprintf(__('Pulling Square orders since %s', 'brcc-inventory-tracker'), 
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync)
                )
            );
        }
        
        try {
            // Call Square API to get recent orders
            $response = $this->call_square_api('/orders/search', 'POST', array(
                'location_ids' => array($this->location_id),
                'query' => array(
                    'filter' => array(
                        'state' => 'COMPLETED',
                        'date_time_filter' => array(
                            'created_at' => array(
                                'start_at' => $begin_time
                            )
                        )
                    )
                )
            ));
            
            if (is_wp_error($response)) {
                BRCC_Helpers::log_error(sprintf(
                    __('Error pulling Square orders: %s', 'brcc-inventory-tracker'),
                    $response->get_error_message()
                ));
                return;
            }
            
            // Process each order
            if (!empty($response['orders']) && is_array($response['orders'])) {
                $processed_count = 0;
                
                foreach ($response['orders'] as $order) {
                    $this->process_square_order($order);
                    $processed_count++;
                    
                    // Add a small delay between processing orders to avoid overwhelming the system
                    if ($processed_count % 10 === 0) {
                        usleep(500000); // 0.5 second pause every 10 orders
                    }
                }
                
                if (BRCC_Helpers::should_log()) {
                    BRCC_Helpers::log_operation(
                        'Square',
                        'Pull Orders Complete',
                        sprintf(__('Processed %d Square orders', 'brcc-inventory-tracker'), 
                            $processed_count
                        )
                    );
                }
            } else {
                if (BRCC_Helpers::should_log()) {
                    BRCC_Helpers::log_operation(
                        'Square',
                        'Pull Orders Complete',
                        __('No new Square orders found', 'brcc-inventory-tracker')
                    );
                }
            }
        } catch (Exception $e) {
            BRCC_Helpers::log_error(sprintf(
                __('Exception during Square order pull: %s', 'brcc-inventory-tracker'),
                $e->getMessage()
            ));
        }
    }
    
/**
 * Call the Square API with improved error handling and rate limit awareness
 * 
 * @param string $endpoint API endpoint
 * @param string $method HTTP method (GET, POST, etc.)
 * @param array $data Request data
 * @return array|WP_Error Response data or error
 */
public function call_square_api($endpoint, $method = 'GET', $data = array()) {
    $url = $this->api_url . $endpoint;
    
    $args = array(
        'method' => $method,
        'headers' => array(
            'Square-Version' => '2023-09-25', // Use a specific API version
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 30,
    );
    
    if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
        $args['body'] = json_encode($data);
    }
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);
    
    // Handle rate limiting
    if ($code == 429) {
        // Get rate limit reset time from headers
        $rate_limit_reset = wp_remote_retrieve_header($response, 'RateLimit-Reset');
        $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
        
        $wait_time = $retry_after ? intval($retry_after) : 60; // Default to 60 seconds if no header
        
        BRCC_Helpers::log_error(sprintf(
            __('Square API rate limit reached. Need to wait %d seconds before retrying.', 'brcc-inventory-tracker'),
            $wait_time
        ));
        
        // You could implement retry logic here
        // For now, return an error
        return new WP_Error(
            'square_rate_limit',
            sprintf(__('Square API rate limit reached. Please try again in %d seconds.', 'brcc-inventory-tracker'), $wait_time),
            array('status' => 429, 'retry_after' => $wait_time)
        );
    }
    
    if ($code < 200 || $code >= 300) {
        $error_message = isset($body['errors']) && !empty($body['errors']) 
            ? $body['errors'][0]['detail'] 
            : __('Unknown Square API error', 'brcc-inventory-tracker');
            
        return new WP_Error(
            'square_api_error',
            $error_message,
            array('status' => $code, 'errors' => isset($body['errors']) ? $body['errors'] : null)
        );
    }
    
    return $body;
}
   /**
    * Test Square API connection
    * 
    * @return bool|WP_Error True if connection is successful, WP_Error otherwise
    */
   public function test_connection() {
       if (empty($this->access_token)) {
           return new WP_Error(
               'missing_credentials', 
               __('Square access token is not configured.', 'brcc-inventory-tracker')
           );
       }
       
       if (empty($this->location_id)) {
           return new WP_Error(
               'missing_credentials', 
               __('Square location ID is not configured.', 'brcc-inventory-tracker')
           );
       }
       
       // Test the API by getting location details
       $response = $this->call_square_api('/locations/' . $this->location_id);
       
       if (is_wp_error($response)) {
           return $response;
       }
       
       return true;
   }
   /**
 * Extract date from Square item name with Toronto timezone awareness
 * 
 * @param string $item_name The item name
 * @return string|null Date in Y-m-d format or null if not found
 */
private function extract_date_from_square_item($item_name) {
    // First try standard date extraction
    $date = $this->extract_date_from_item_name($item_name);
    if ($date) {
        return $date;
    }
    
    // If today's date matches the event day in the product name, use today's date
    $today = new DateTime('now', new DateTimeZone('America/Toronto'));
    $today_formatted = $today->format('Y-m-d');
    
    // Extract day of week from item name (e.g., "Friday 8pm")
    $day_name = BRCC_Helpers::extract_day_from_title($item_name);
    if ($day_name) {
        $day_of_week = strtolower($today->format('l'));
        if ($day_of_week === strtolower($day_name)) {
            return $today_formatted;
        }
    }
    
    return null;
}

   /**
    * Get Square catalog items
    * Used for mapping products
    * 
    * @return array|WP_Error Array of catalog items or error
    */
   public function get_catalog_items() {
       // Call Square API to get catalog items (specifically products)
       $response = $this->call_square_api('/catalog/list', 'GET');
       
       if (is_wp_error($response)) {
           return $response;
       }
       
       // Filter out non-product items
       $items = array();
       
       if (!empty($response['objects']) && is_array($response['objects'])) {
           foreach ($response['objects'] as $object) {
               if (isset($object['type']) && $object['type'] === 'ITEM' && isset($object['item_data'])) {
                   $items[] = array(
                       'id' => $object['id'],
                       'name' => $object['item_data']['name'],
                       'description' => isset($object['item_data']['description']) ? $object['item_data']['description'] : '',
                       'variations' => $this->extract_variations($object)
                   );
               }
           }
       }
       
       return $items;
   }
   
   /**
    * Extract variations from a catalog item
    * 
    * @param array $item Catalog item
    * @return array Item variations
    */
   private function extract_variations($item) {
       $variations = array();
       
       if (isset($item['item_data']['variations']) && is_array($item['item_data']['variations'])) {
           foreach ($item['item_data']['variations'] as $variation) {
               if (isset($variation['id']) && isset($variation['item_variation_data'])) {
                   $variations[] = array(
                       'id' => $variation['id'],
                       'name' => $variation['item_variation_data']['name'],
                       'price' => isset($variation['item_variation_data']['price_money']) 
                           ? $this->format_money($variation['item_variation_data']['price_money']) 
                           : 0
                   );
               }
           }
       }
       
       return $variations;
   }
   
   /**
    * Format money from Square API
    * 
    * @param array $money_object Square money object
    * @return float Formatted price
    */
   private function format_money($money_object) {
       if (!isset($money_object['amount'])) {
           return 0;
       }
       
       $amount = $money_object['amount'];
       $currency = isset($money_object['currency']) ? $money_object['currency'] : 'USD';
       
       // Square returns amounts in cents
       $formatted = $amount / 100;
       
       return $formatted;
   }
   
   /**
    * Get a specific catalog item from Square
    * 
    * @param string $item_id Square catalog item ID
    * @return array|WP_Error Item details or error
    */
   public function get_catalog_item($item_id) {
       if (empty($item_id)) {
           return new WP_Error(
               'invalid_parameter',
               __('Item ID is required.', 'brcc-inventory-tracker')
           );
       }
       
       // Call Square API to get the specific item
       $response = $this->call_square_api('/catalog/object/' . $item_id, 'GET');
       
       if (is_wp_error($response)) {
           return $response;
       }
       
       // Check if the object is a valid catalog item
       if (!isset($response['object']) || !isset($response['object']['type']) || $response['object']['type'] !== 'ITEM') {
           return new WP_Error(
               'invalid_item_type',
               __('The ID does not correspond to a catalog item.', 'brcc-inventory-tracker')
           );
       }
       
       $item = $response['object'];
       
       // Format the response
       $result = array(
           'id' => $item['id'],
           'name' => isset($item['item_data']['name']) ? $item['item_data']['name'] : '',
           'description' => isset($item['item_data']['description']) ? $item['item_data']['description'] : '',
           'variations' => $this->extract_variations($item)
       );
       
       return $result;
   }

   /**
    * Get orders for a specific date range
    * 
    * @param string $start_date Start date in Y-m-d format
    * @param string $end_date End date in Y-m-d format
    * @return array|WP_Error Orders or error
    */
   public function get_orders_for_date_range($start_date, $end_date) {
       // Convert dates to RFC 3339 format
       $begin_time = date('c', strtotime($start_date . ' 00:00:00'));
       $end_time = date('c', strtotime($end_date . ' 23:59:59'));
       
       // Call Square API to get orders
       $response = $this->call_square_api('/orders/search', 'POST', array(
           'location_ids' => array($this->location_id),
           'query' => array(
               'filter' => array(
                   'state' => 'COMPLETED',
                   'date_time_filter' => array(
                       'created_at' => array(
                           'start_at' => $begin_time,
                           'end_at' => $end_time
                       )
                   )
               )
           )
       ));
       
       if (is_wp_error($response)) {
           return $response;
       }
       
       return isset($response['orders']) ? $response['orders'] : array();
   }

   /**
    * Import historical Square sales data
    * 
    * @param string $start_date Start date in Y-m-d format
    * @param string $end_date End date in Y-m-d format
    * @return boolean Success or failure
    */
   public function import_from_square($start_date, $end_date) {
       // Check if test mode is enabled
       if (BRCC_Helpers::is_test_mode()) {
           BRCC_Helpers::log_operation(
               'Square',
               'Import from Square',
               sprintf(__('Would import Square orders from %s to %s', 'brcc-inventory-tracker'), 
                   $start_date,
                   $end_date
               )
           );
           return true;
       } else if (BRCC_Helpers::should_log()) {
           BRCC_Helpers::log_operation(
               'Square',
               'Import from Square',
               sprintf(__('Importing Square orders from %s to %s (Live Mode)', 'brcc-inventory-tracker'), 
                   $start_date,
                   $end_date
               )
           );
       }
       
       // Get Square orders for the date range
       $orders = $this->get_orders_for_date_range($start_date, $end_date);
       
       if (is_wp_error($orders)) {
           BRCC_Helpers::log_error(sprintf(
               __('Error importing Square orders: %s', 'brcc-inventory-tracker'),
               $orders->get_error_message()
           ));
           return false;
       }
       
       if (empty($orders)) {
           BRCC_Helpers::log_info(__('No Square orders found for this date range.', 'brcc-inventory-tracker'));
           return false;
       }
       
       // Process each order
       $processed = 0;
       foreach ($orders as $order) {
           $this->process_square_order($order);
           $processed++;
           
           // Add a small delay to prevent overwhelming the system
           if ($processed % 10 === 0) {
               usleep(500000); // 0.5 second pause every 10 orders
           }
       }
       
       BRCC_Helpers::log_info(sprintf(
           __('Processed %d Square orders for import.', 'brcc-inventory-tracker'),
           $processed
       ));
       
       return true;
   }
   
   /**
    * Update Square inventory based on WooCommerce changes (Placeholder - Requires Implementation)
    * This function would be called when WC stock changes, potentially via hooks.
    *
    * @param int $product_id WooCommerce product ID
    * @param int $quantity New quantity
    * @param string|null $date Optional date for date-based inventory
    * @param string|null $time Optional time for time-based inventory
    * @return bool Success/Failure
    */
   public function update_square_inventory($product_id, $quantity, $date = null, $time = null) {
       
       // --- Implementation Steps ---
       // 1. Find the corresponding Square Catalog Item Variation ID based on WC product ID, date, and time.
       //    - Use $this->product_mappings->get_product_mappings($product_id, $date, $time)
       //    - Extract the 'square_id' from the mapping. Ensure this ID is the *Variation* ID, not the Item ID.
       $mapping = $this->product_mappings->get_product_mappings($product_id, $date, $time);
       $square_variation_id = isset($mapping['square_id']) ? $mapping['square_id'] : null;
       
       // 2. Check if a valid Square Variation ID was found. If not, log and exit.
       if (!$square_variation_id) {
           // Log only if needed, might be noisy if many products aren't mapped
           // BRCC_Helpers::log_info(sprintf('Square Sync: No Square mapping found for WC Product ID %s%s%s. Cannot update Square inventory.', $product_id, $date ? ' date ' . $date : '', $time ? ' time ' . $time : ''));
           return false; // No mapping found
       }
       
       // 3. Prepare the request body for the Square Inventory API endpoint: /v2/inventory/batch-change
       //    Ref: https://developer.squareup.com/reference/square/inventory-api/batch-change-inventory
       $idempotency_key = uniqid('brcc_sync_', true); // Important for preventing duplicate updates
       $changes = array(
           array(
               'type' => 'PHYSICAL_COUNT', // Or 'ADJUSTMENT' depending on desired logic
               'physical_count' => array(
                   'catalog_object_id' => $square_variation_id,
                   'state' => 'IN_STOCK', // Assuming we're setting in-stock quantity
                   'location_id' => $this->location_id,
                   'quantity' => (string)$quantity, // Quantity must be a string
                   'occurred_at' => gmdate("Y-m-d\TH:i:s\Z"), // Current UTC time
               )
           )
       );
       
       $request_body = array(
           'idempotency_key' => $idempotency_key,
           'changes' => $changes,
           'ignore_unchanged_counts' => true // Optional: set to false if you want updates even if quantity is the same
       );
       
       // 4. Call the Square API using $this->call_square_api().
       //    Need to handle potential rate limiting and errors from the API call.
       /* --- Example API Call (Uncomment and adapt when ready) ---
       $response = $this->call_square_api('/inventory/batch-change', 'POST', $request_body);
       
       if (is_wp_error($response)) {
           BRCC_Helpers::log_error(sprintf('Square Sync Error: Failed to update inventory for Variation ID %s. Error: %s', $square_variation_id, $response->get_error_message()));
           return false;
       }
       
       // Check for errors within the Square response body if needed
       if (!empty($response['errors'])) {
            $error_details = json_encode($response['errors']);
            BRCC_Helpers::log_error(sprintf('Square Sync Error: API returned errors for Variation ID %s. Details: %s', $square_variation_id, $error_details));
            return false;
       }
       
       BRCC_Helpers::log_info(sprintf('Square Sync Success: Updated inventory for Variation ID %s to quantity %d.', $square_variation_id, $quantity));
       return true;
       */
       
       // --- Placeholder Logging ---
       // Log the placeholder action until the API call is implemented
       BRCC_Helpers::log_operation(
           'Square Sync',
           'Update Inventory (Placeholder)',
           sprintf('Would update Square Variation ID %s (mapped to WC Product %s%s%s) to quantity %d',
               $square_variation_id,
               $product_id,
               $date ? ' date ' . $date : '',
               $time ? ' time ' . $time : '',
               $quantity
           )
       );
       
       return true; // Placeholder return - change when API call is implemented
   }

    /**
     * Import a batch of historical Square orders
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param string|null $cursor Pagination cursor from previous batch
     * @param int $limit Number of orders per batch
     * @return array Result array with processed_count, next_offset (cursor), source_complete, logs
     */
    public function import_square_batch($start_date, $end_date, $cursor, $limit) {
        $logs = array();
        $processed_count = 0;
        $source_complete = false;
        $next_cursor = null;

        // Ensure Square is configured
        if (empty($this->access_token) || empty($this->location_id)) {
            $logs[] = array('message' => 'Square API not configured. Skipping Square import.', 'type' => 'error');
            return array(
                'processed_count' => 0,
                'next_offset'     => null, // Use next_offset consistently
                'source_complete' => true,
                'logs'            => $logs,
            );
        }

        $logs[] = array('message' => "Querying Square orders from {$start_date} to {$end_date}" . ($cursor ? " (cursor: {$cursor})" : "") . ", limit {$limit}...", 'type' => 'info');

        // Format dates for Square API (RFC 3339 format, using site's timezone for start/end of day, then converting to UTC)
        try {
            $timezone = wp_timezone(); // Use WordPress timezone
            $start_dt = new DateTime($start_date . ' 00:00:00', $timezone);
            $end_dt = new DateTime($end_date . ' 23:59:59', $timezone);
            
            // Convert to UTC for Square API query
            $start_dt->setTimezone(new DateTimeZone('UTC'));
            $end_dt->setTimezone(new DateTimeZone('UTC'));

            $start_iso = $start_dt->format(DateTime::RFC3339);
            $end_iso = $end_dt->format(DateTime::RFC3339);

        } catch (Exception $e) {
             $logs[] = array('message' => 'Error formatting dates for Square API: ' . $e->getMessage(), 'type' => 'error');
             // Stop processing this source if dates are invalid
             return array('processed_count' => 0, 'next_offset' => null, 'source_complete' => true, 'logs' => $logs);
        }

        // Prepare API request body
        $body = array(
            'limit' => $limit,
            'location_ids' => array($this->location_id),
            'query' => array(
                'filter' => array(
                    'date_time_filter' => array(
                        'closed_at' => array( // Use closed_at for completed orders
                            'start_at' => $start_iso,
                            'end_at' => $end_iso,
                        ),
                    ),
                    'state_filter' => array(
                        'states' => array('COMPLETED'),
                    ),
                ),
                'sort' => array(
                    'sort_field' => 'CLOSED_AT',
                    'sort_order' => 'ASC',
                ),
            ),
        );

        if ($cursor) {
            $body['cursor'] = $cursor;
        }

        // Call Square API
        $response = $this->call_square_api('/orders/search', 'POST', $body);

        if (is_wp_error($response)) {
            $logs[] = array('message' => 'Square API Error during import: ' . $response->get_error_message(), 'type' => 'error');
            // Stop processing this source on API error.
            $source_complete = true;
        } elseif (isset($response['orders']) && is_array($response['orders'])) {
            $orders = $response['orders'];
            $logs[] = array('message' => "Found " . count($orders) . " Square order(s) in this batch.", 'type' => 'info');
            
            $sales_tracker = new BRCC_Sales_Tracker(); // Instantiate tracker

            foreach ($orders as $order) {
                 $order_id = isset($order['id']) ? $order['id'] : 'unknown';
                 $sale_date = null;
                 if (isset($order['closed_at'])) {
                     try {
                         // Convert closed_at (UTC) to local date using helper
                         $sale_date = $this->convert_to_local_date($order['closed_at']);
                     } catch (Exception $e) {
                         $logs[] = array('message' => "Error parsing date for Square order {$order_id}: " . $e->getMessage(), 'type' => 'warning');
                         continue; // Skip if date is invalid
                     }
                 } else {
                      $logs[] = array('message' => "Skipping Square order {$order_id} due to missing closed_at date.", 'type' => 'warning');
                      continue; // Skip if no completion date
                 }

                if (isset($order['line_items']) && is_array($order['line_items'])) {
                    foreach ($order['line_items'] as $item) {
                        $catalog_object_id = isset($item['catalog_object_id']) ? $item['catalog_object_id'] : null;
                        $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                        $item_name = isset($item['name']) ? $item['name'] : '';

                        if (!$catalog_object_id || $quantity <= 0) {
                            continue;
                        }

                        // Find corresponding WC product ID
                        // Assuming find_product_by_square_id returns only the product ID
                        // If it needs to return date/time info for specific mappings, this needs adjustment
                        $product_id = $this->find_product_by_square_id($catalog_object_id);

                        if ($product_id && !is_array($product_id)) {
                            // Use sale date as booking date for historical import, per client requirement
                            $booking_date = $sale_date;
                            
                            // Record historical sale using the tracker's method
                            // Ensure the tracker method exists and handles the source correctly
                            if (method_exists($sales_tracker, 'record_historical_sale')) {
                                $sales_tracker->record_historical_sale('Square', $product_id, $quantity, $sale_date, $booking_date, $order_id);
                                $processed_count++;
                            } else {
                                 $logs[] = array('message' => "Error: record_historical_sale method not found in BRCC_Sales_Tracker.", 'type' => 'error');
                                 // Potentially stop import here if method is missing
                            }
                        } elseif (is_array($product_id)) {
                             // Handling date-specific mappings found via Square ID during historical import is complex.
                             // For now, we log and skip, assuming the primary goal is tracking overall sales per day.
                             $logs[] = array('message' => "Skipping date-specific Square item mapping ({$item_name}) during historical import.", 'type' => 'warning');
                        } else {
                             $logs[] = array('message' => "No WooCommerce mapping found for Square item ID {$catalog_object_id} ({$item_name}) in order {$order_id}.", 'type' => 'warning');
                        }
                    }
                }
                 $logs[] = array('message' => "Processed Square order #{$order_id} (Sale Date: {$sale_date}).", 'type' => 'info');
            }

            // Check for next cursor
            if (isset($response['cursor'])) {
                $next_cursor = $response['cursor'];
            } else {
                $logs[] = array('message' => "Last batch processed for Square in this date range.", 'type' => 'info');
                $source_complete = true;
            }

        } else {
            $logs[] = array('message' => 'Unexpected response format from Square Orders API during import.', 'type' => 'error');
            $source_complete = true; // Stop processing on unexpected format
        }

        return array(
            'processed_count' => $processed_count,
            'next_offset'     => $next_cursor, // Pass cursor back as the offset for the next call
            'source_complete' => $source_complete,
            'logs'            => $logs,
        );
    }

} 
