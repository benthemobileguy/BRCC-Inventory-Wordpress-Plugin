<?php
/**
 * BRCC Sales Tracker Class
 * 
 * Handles tracking of daily sales data from WooCommerce, Eventbrite, and Square with enhanced support for date-based inventory
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Sales_Tracker {
    /**
     * Constructor - setup hooks
     */
    public function __construct() {
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 4);
        
        // Register shortcode for displaying sales data
        add_shortcode('brcc_sales_data', array($this, 'sales_data_shortcode'));
        
        // Add shortcode for event-specific sales
        add_shortcode('brcc_event_sales', array($this, 'event_sales_shortcode'));
    }

    /**
     * Handle order status changes
     */
    public function order_status_changed($order_id, $old_status, $new_status, $order) {
        // Only log completed orders
        if ($new_status !== 'completed') {
            return;
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Order Completed',
                sprintf(__('Order #%s completed. Would update sales tracking.', 'brcc-inventory-tracker'), $order_id)
            );
            
            // Still trigger product sold action in test mode to allow other test logs
            do_action('brcc_product_sold', $order_id, $order);
            return;
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Order Completed',
                sprintf(__('Order #%s completed. Updating sales tracking. (Live Mode)', 'brcc-inventory-tracker'), $order_id)
            );
        }

        // Get the current date (WordPress timezone)
        $date = current_time('Y-m-d');

        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);

        // Extract product information from the order
        $items = $order->get_items();
        $product_date_quantities = [];
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            $quantity = $item->get_quantity();
            $product_name = $product->get_name();
            $sku = $product->get_sku();
            
            // Get booking/event date if available - enhanced detection
            $booking_date = $this->get_booking_date_from_item($item);
            
            // Track product-date quantities for summary calculation
            $product_key = $product_id . ($booking_date ? '_' . $booking_date : '');
            if (!isset($product_date_quantities[$product_key])) {
                $product_date_quantities[$product_key] = [
                    'product_id' => $product_id,
                    'name' => $product_name,
                    'sku' => $sku,
                    'booking_date' => $booking_date,
                    'quantity' => 0
                ];
            }
            $product_date_quantities[$product_key]['quantity'] += $quantity;
            
            // Update daily sales for the product
            if (!isset($daily_sales[$date])) {
                $daily_sales[$date] = array();
            }
            
            // Create unique key for product + booking date
            $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
            
            if (isset($daily_sales[$date][$product_key])) {
                $daily_sales[$date][$product_key]['quantity'] += $quantity;
                // Add or increment WooCommerce quantity
                if (!isset($daily_sales[$date][$product_key]['woocommerce'])) {
                    $daily_sales[$date][$product_key]['woocommerce'] = $quantity;
                } else {
                    $daily_sales[$date][$product_key]['woocommerce'] += $quantity;
                }
            } else {
                $daily_sales[$date][$product_key] = array(
                    'name' => $product_name,
                    'sku' => $sku,
                    'product_id' => $product_id,
                    'booking_date' => $booking_date,
                    'quantity' => $quantity,
                    'woocommerce' => $quantity,
                    'eventbrite' => 0,
                    'square' => 0
                );
            }
        }

        // Save the updated daily sales data
        update_option('brcc_daily_sales', $daily_sales);
        
        // Also update product summary data for each day
        $this->update_daily_product_summary($date, $product_date_quantities);
        
        // Trigger inventory sync for each product-date combination
        foreach ($product_date_quantities as $product_key => $data) {
            do_action('brcc_product_sold_with_date', $order_id, $data['product_id'], $data['quantity'], $data['booking_date']);
        }
        
        // Also trigger the original action for backward compatibility
        do_action('brcc_product_sold', $order_id, $order);
    }
    
    /**
     * Update daily product summary for better reporting
     */
    public function update_daily_product_summary($date, $product_date_quantities) {
        $product_summary = get_option('brcc_product_summary', array());
        
        if (!isset($product_summary[$date])) {
            $product_summary[$date] = array();
        }
        
        // Group by product first, then by date
        foreach ($product_date_quantities as $product_key => $data) {
            $product_id = $data['product_id'];
            
            // Create product entry if it doesn't exist
            if (!isset($product_summary[$date][$product_id])) {
                $product_summary[$date][$product_id] = array(
                    'name' => $data['name'],
                    'sku' => $data['sku'],
                    'total_quantity' => 0,
                    'dates' => array()
                );
            }
            
            // Update total quantity for this product
            $product_summary[$date][$product_id]['total_quantity'] += $data['quantity'];
            
            // Update date-specific data
            if ($data['booking_date']) {
                if (!isset($product_summary[$date][$product_id]['dates'][$data['booking_date']])) {
                    $product_summary[$date][$product_id]['dates'][$data['booking_date']] = 0;
                }
                $product_summary[$date][$product_id]['dates'][$data['booking_date']] += $data['quantity'];
            }
        }
        
        update_option('brcc_product_summary', $product_summary);
    }

    /**
     * Get booking date from order item with enhanced detection
     * 
     * @param WC_Order_Item $item Order item
     * @return string|null Booking date in Y-m-d format or null if not found
     */
    private function get_booking_date_from_item($item) {
        // First check for FooEvents specific date meta
        $fooevents_date = BRCC_Helpers::get_fooevents_date_from_item($item);
        if ($fooevents_date) {
            return $fooevents_date;
        }
        
        // Check for booking/event date in item meta
        $item_meta = $item->get_meta_data();
        
        // Common meta keys that might contain date information
        $date_meta_keys = array(
            'event_date',
            'ticket_date',
            'booking_date',
            'pa_date',
            'date',
            '_event_date',
            '_booking_date',
            'Event Date',
            'Ticket Date',
            'Show Date',
            'Performance Date'
        );
        
        // First check the known meta keys
        foreach ($date_meta_keys as $key) {
            $date_value = $item->get_meta($key);
            if (!empty($date_value)) {
                $parsed_date = BRCC_Helpers::parse_date_value($date_value);
                if ($parsed_date) {
                    return $parsed_date;
                }
            }
        }
        
        // If not found in known keys, check all meta data
        foreach ($item_meta as $meta) {
            $meta_data = $meta->get_data();
            $key = $meta_data['key'];
            $value = $meta_data['value'];
            
            // Check for various possible meta keys for event dates
            if (preg_match('/(date|day|event|show|performance|time)/i', $key)) {
                // Try to convert to Y-m-d format if it's a date
                $date_value = BRCC_Helpers::parse_date_value($value);
                if ($date_value) {
                    return $date_value;
                }
            }
        }
        
        // Check product attributes as a fallback
        // This is useful for variable products where the date is an attribute
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            
            if ($parent) {
                $attributes = $product->get_attributes();
                
                foreach ($attributes as $attr_name => $attr_value) {
                    if (preg_match('/(date|day|event|show|performance)/i', $attr_name)) {
                        $date_value = BRCC_Helpers::parse_date_value($attr_value);
                        if ($date_value) {
                            return $date_value;
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Record sales from Eventbrite
     */
    public function record_eventbrite_sale($product_id, $quantity, $booking_date = null) {
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Eventbrite',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Would record Eventbrite sale.', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
            return;
        } else if (BRCC_Helpers::should_log()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Eventbrite',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Recording Eventbrite sale. (Live Mode)', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
        }

        // Get the current date (WordPress timezone)
        $date = current_time('Y-m-d');

        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $product_name = $product->get_name();
        $sku = $product->get_sku();

        // Create unique key for product + booking date
        $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;

        // Update daily sales for the product
        if (!isset($daily_sales[$date])) {
            $daily_sales[$date] = array();
        }
        
        if (isset($daily_sales[$date][$product_key])) {
            $daily_sales[$date][$product_key]['quantity'] += $quantity;
            // Add or increment Eventbrite quantity
            if (!isset($daily_sales[$date][$product_key]['eventbrite'])) {
                $daily_sales[$date][$product_key]['eventbrite'] = $quantity;
            } else {
                $daily_sales[$date][$product_key]['eventbrite'] += $quantity;
            }
        } else {
            $daily_sales[$date][$product_key] = array(
                'name' => $product_name,
                'sku' => $sku,
                'product_id' => $product_id,
                'booking_date' => $booking_date,
                'quantity' => $quantity,
                'woocommerce' => 0,
                'eventbrite' => $quantity,
                'square' => 0
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
                'booking_date' => $booking_date,
                'quantity' => $quantity
            )
        );
        
        $this->update_daily_product_summary($date, $product_date_quantities);
    }
    
    /**
     * Record sales from Square
     */
    public function record_square_sale($product_id, $quantity, $booking_date = null) {
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Would record Square sale.', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
            return;
        } else if (BRCC_Helpers::should_log()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Recording Square sale. (Live Mode)', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
        }

        // Get the current date (WordPress timezone)
        $date = current_time('Y-m-d');

        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $product_name = $product->get_name();
        $sku = $product->get_sku();

        // Create unique key for product + booking date
        $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;

        // Update daily sales for the product
        if (!isset($daily_sales[$date])) {
            $daily_sales[$date] = array();
        }
        
        if (isset($daily_sales[$date][$product_key])) {
            $daily_sales[$date][$product_key]['quantity'] += $quantity;
            // Add or increment Square quantity
            if (!isset($daily_sales[$date][$product_key]['square'])) {
                $daily_sales[$date][$product_key]['square'] = $quantity;
            } else {
                $daily_sales[$date][$product_key]['square'] += $quantity;
            }
        } else {
            $daily_sales[$date][$product_key] = array(
                'name' => $product_name,
                'sku' => $sku,
                'product_id' => $product_id,
                'booking_date' => $booking_date,
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
                'booking_date' => $booking_date,
                'quantity' => $quantity
            )
        );
        
        $this->update_daily_product_summary($date, $product_date_quantities);
    }

    /**
     * Get daily sales data
     */
    public function get_daily_sales($date = null, $product_id = null, $booking_date = null) {
        $daily_sales = get_option('brcc_daily_sales', []);
        
        // If no date is specified, return all data
        if (null === $date) {
            return $daily_sales;
        }
        
        // If date is specified but no product_id, return all products for that date
        if (isset($daily_sales[$date]) && null === $product_id) {
            return $daily_sales[$date];
        }
        
        // If product ID and possibly booking date are specified
        if (isset($daily_sales[$date])) {
            if ($booking_date) {
                $product_key = $product_id . '_' . $booking_date;
                if (isset($daily_sales[$date][$product_key])) {
                    return $daily_sales[$date][$product_key];
                }
            } else {
                // Try to find a matching product ID (without booking date)
                foreach ($daily_sales[$date] as $key => $data) {
                    if (isset($data['product_id']) && $data['product_id'] == $product_id && empty($data['booking_date'])) {
                        return $data;
                    }
                }
                
                // Legacy support: check if product_id exists directly as a key
                if (isset($daily_sales[$date][$product_id])) {
                    return $daily_sales[$date][$product_id];
                }
            }
        }
        
        // If no data found, return empty array
        return array();
    }

    /**
     * Get product summary data with date-specific information
     */
    public function get_product_summary($start_date, $end_date = null) {
        $product_summary = get_option('brcc_product_summary', array());
        $result = array();
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Loop through each date in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            if (isset($product_summary[$date_string])) {
                foreach ($product_summary[$date_string] as $product_id => $data) {
                    if (!isset($result[$product_id])) {
                        $result[$product_id] = array(
                            'name' => $data['name'],
                            'sku' => $data['sku'],
                            'total_quantity' => 0,
                            'dates' => array()
                        );
                    }
                    
                    $result[$product_id]['total_quantity'] += $data['total_quantity'];
                    
                    // Merge date-specific data
                    if (!empty($data['dates'])) {
                        foreach ($data['dates'] as $event_date => $qty) {
                            if (!isset($result[$product_id]['dates'][$event_date])) {
                                $result[$product_id]['dates'][$event_date] = 0;
                            }
                            $result[$product_id]['dates'][$event_date] += $qty;
                        }
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Get total sales for a date range
     */
    public function get_total_sales($start_date, $end_date = null) {
        $daily_sales = get_option('brcc_daily_sales', []);
        $total_sales = array();
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Loop through each date in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            if (isset($daily_sales[$date_string])) {
                foreach ($daily_sales[$date_string] as $product_key => $product_data) {
                    // Check if this is a product with booking date
                    $booking_date = isset($product_data['booking_date']) ? $product_data['booking_date'] : null;
                    $product_id = isset($product_data['product_id']) ? $product_data['product_id'] : $product_key;
                    
                    // Create a unique key for the total sales array
                    $total_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
                    
                    if (!isset($total_sales[$total_key])) {
                        $total_sales[$total_key] = array(
                            'name' => $product_data['name'],
                            'sku' => $product_data['sku'],
                            'product_id' => $product_id,
                            'booking_date' => $booking_date,
                            'quantity' => 0,
                            'woocommerce' => 0,
                            'eventbrite' => 0,
                            'square' => 0
                        );
                    }
                    
                    $total_sales[$total_key]['quantity'] += $product_data['quantity'];
                    
                    // Add source-specific quantities
                    if (isset($product_data['woocommerce'])) {
                        $total_sales[$total_key]['woocommerce'] += $product_data['woocommerce'];
                    }
                    
                    if (isset($product_data['eventbrite'])) {
                        $total_sales[$total_key]['eventbrite'] += $product_data['eventbrite'];
                    }
                    
                    if (isset($product_data['square'])) {
                        $total_sales[$total_key]['square'] += $product_data['square'];
                    }
                }
            }
        }
        
        return $total_sales;
    }
    
    /**
     * Get summary by period with daily breakdowns
     * 
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @return array Summary data including daily breakdowns
     */
    public function get_summary_by_period($start_date, $end_date = null) {
        $daily_sales = $this->get_daily_sales();
        $summary = array(
            'total_sales' => 0,
            'woocommerce_sales' => 0,
            'eventbrite_sales' => 0,
            'square_sales' => 0,
            'days' => array()
        );
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Process each day in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            $day_summary = array(
                'date' => $date_string,
                'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                'total_sales' => 0,
                'woocommerce_sales' => 0,
                'eventbrite_sales' => 0,
                'square_sales' => 0,
                'products' => array()
            );
            
            if (isset($daily_sales[$date_string])) {
                foreach ($daily_sales[$date_string] as $product_key => $product_data) {
                    // Add to day summary
                    // Check if $product_data is an array and has 'quantity' key
                    if (is_array($product_data) && isset($product_data['quantity'])) {
                        $day_summary['total_sales'] += $product_data['quantity'];
                    } else {
                        // Log or handle the case where data is not as expected
                        // For now, just skip adding to total to prevent warning
                    }
                    $day_summary['woocommerce_sales'] += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                    $day_summary['eventbrite_sales'] += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                    $day_summary['square_sales'] += isset($product_data['square']) ? $product_data['square'] : 0;
                    
                    // Add product details
                    $day_summary['products'][$product_key] = $product_data;
                    
                    // Add to overall summary
                    // Check if $product_data is an array and has 'quantity' key
                    if (is_array($product_data) && isset($product_data['quantity'])) {
                         $summary['total_sales'] += $product_data['quantity'];
                    } else {
                         // Log or handle the case where data is not as expected
                         // For now, just skip adding to total to prevent warning
                         BRCC_Helpers::log_error('get_product_summary: Unexpected data format for product_key ' . $product_key . ' on date ' . $date_string . '. Expected array with quantity.', $product_data);
                    }
                    $summary['woocommerce_sales'] += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                    $summary['eventbrite_sales'] += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                    $summary['square_sales'] += isset($product_data['square']) ? $product_data['square'] : 0;
                }
            }
            
            $summary['days'][$date_string] = $day_summary;
            }
            
            return $summary;
            }
            
            /**
             * Import historical sales data
             * 
             * @param array $historical_data Array of historical sales data
             * @return boolean Success or failure
             */
            public function import_historical_sales($historical_data) {
                // Check if test mode is enabled
                if (BRCC_Helpers::is_test_mode()) {
                    BRCC_Helpers::log_operation(
                        'WooCommerce',
                        'Import Historical',
                        sprintf(__('Would import historical sales data for %d dates', 'brcc-inventory-tracker'), 
                            count($historical_data)
                        )
                    );
                    return true;
                } else if (BRCC_Helpers::should_log()) {
                    BRCC_Helpers::log_operation(
                        'WooCommerce',
                        'Import Historical',
                        sprintf(__('Importing historical sales data for %d dates (Live Mode)', 'brcc-inventory-tracker'), 
                            count($historical_data)
                        )
                    );
                }
                
                // Get existing daily sales data
                $daily_sales = get_option('brcc_daily_sales', []);
                
                // Loop through historical data and add to daily sales
                foreach ($historical_data as $date => $products) {
                    if (!isset($daily_sales[$date])) {
                        $daily_sales[$date] = array();
                    }
                    
                    $product_date_quantities = array();
                    
                    foreach ($products as $product_key => $product_data) {
                        // Ensure product_id is set
                        if (!isset($product_data['product_id'])) {
                            $product_data['product_id'] = is_numeric($product_key) ? $product_key : null;
                        }
                        
                        // Track for summary update
                        $product_date_quantities[$product_key] = array(
                            'product_id' => $product_data['product_id'],
                            'name' => $product_data['name'],
                            'sku' => isset($product_data['sku']) ? $product_data['sku'] : '',
                            'booking_date' => isset($product_data['booking_date']) ? $product_data['booking_date'] : null,
                            'quantity' => $product_data['quantity']
                        );
                        
                        if (isset($daily_sales[$date][$product_key])) {
                            // Update existing entry
                            $daily_sales[$date][$product_key]['quantity'] += $product_data['quantity'];
                            
                            if (isset($product_data['woocommerce'])) {
                                if (isset($daily_sales[$date][$product_key]['woocommerce'])) {
                                    $daily_sales[$date][$product_key]['woocommerce'] += $product_data['woocommerce'];
                                } else {
                                    $daily_sales[$date][$product_key]['woocommerce'] = $product_data['woocommerce'];
                                }
                            }
                            
                            if (isset($product_data['eventbrite'])) {
                                if (isset($daily_sales[$date][$product_key]['eventbrite'])) {
                                    $daily_sales[$date][$product_key]['eventbrite'] += $product_data['eventbrite'];
                                } else {
                                    $daily_sales[$date][$product_key]['eventbrite'] = $product_data['eventbrite'];
                                }
                            }
                            
                            if (isset($product_data['square'])) {
                                if (isset($daily_sales[$date][$product_key]['square'])) {
                                    $daily_sales[$date][$product_key]['square'] += $product_data['square'];
                                } else {
                                    $daily_sales[$date][$product_key]['square'] = $product_data['square'];
                                }
                            }
                        } else {
                            // Initialize square field if not present
                            if (!isset($product_data['square'])) {
                                $product_data['square'] = 0;
                            }
                            
                            // Add new entry
                            $daily_sales[$date][$product_key] = $product_data;
                        }
                    }
                    
                    // Update product summary for this date
                    $this->update_daily_product_summary($date, $product_date_quantities);
                }
                
                // Save updated daily sales data
                return update_option('brcc_daily_sales', $daily_sales);
            }
            
            /**
             * Import historical WooCommerce orders
             * 
             * @param string $start_date Start date in Y-m-d format
             * @param string $end_date End date in Y-m-d format
             * @return boolean Success or failure
             */
            public function import_from_woocommerce($start_date, $end_date) {
                // Check if test mode is enabled
                if (BRCC_Helpers::is_test_mode()) {
                    BRCC_Helpers::log_operation(
                        'WooCommerce',
                        'Import from WooCommerce',
                        sprintf(__('Would import WooCommerce orders from %s to %s', 'brcc-inventory-tracker'), 
                            $start_date,
                            $end_date
                        )
                    );
                    return true;
                } else if (BRCC_Helpers::should_log()) {
                    BRCC_Helpers::log_operation(
                        'WooCommerce',
                        'Import from WooCommerce',
                        sprintf(__('Importing WooCommerce orders from %s to %s (Live Mode)', 'brcc-inventory-tracker'), 
                            $start_date,
                            $end_date
                        )
                    );
                }
                
                // Get orders in date range
                $args = array(
                    'status' => 'completed',
                    'limit' => -1,
                    'date_created' => $start_date . '...' . $end_date
                );
                
                $orders = wc_get_orders($args);
                
                if (empty($orders)) {
                    return false;
                }
                
                $historical_data = array();
                
                foreach ($orders as $order) {
                    // Get order completion date
                    $date_completed = $order->get_date_completed();
                    if (!$date_completed) {
                        continue;
                    }
                    
                    $date = $date_completed->date('Y-m-d');
                    
                    if (!isset($historical_data[$date])) {
                        $historical_data[$date] = array();
                    }
                    
                    $items = $order->get_items();
                    foreach ($items as $item) {
                        $product_id = $item->get_product_id();
                        $product = wc_get_product($product_id);
                        
                        if (!$product) {
                            continue;
                        }
                        
                        $quantity = $item->get_quantity();
                        $product_name = $product->get_name();
                        $sku = $product->get_sku();
                        
                        // Get booking date if available using enhanced detection
                        $booking_date = $this->get_booking_date_from_item($item);
                        
                        // Create unique key for product + booking date
                        $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
                        
                        if (isset($historical_data[$date][$product_key])) {
                            $historical_data[$date][$product_key]['quantity'] += $quantity;
                            $historical_data[$date][$product_key]['woocommerce'] += $quantity;
                        } else {
                            $historical_data[$date][$product_key] = array(
                                'name' => $product_name,
                                'sku' => $sku,
                                'product_id' => $product_id,
                                'booking_date' => $booking_date,
                                'quantity' => $quantity,
                                'woocommerce' => $quantity,
                                'eventbrite' => 0,
                                'square' => 0
                            );
                        }
                    }
                }
                
                return $this->import_historical_sales($historical_data);
            }
            
            /**
             * Shortcode for displaying sales data
             */
            public function sales_data_shortcode($atts) {
                // Parse shortcode attributes
                $atts = shortcode_atts(array(
                    'date' => current_time('Y-m-d'),
                    'days' => 7,
                    'show_dates' => 'yes', // Show event dates breakdown
                    'show_summary' => 'yes' // Show product summary
                ), $atts, 'brcc_sales_data');
                
                // Calculate start date based on days parameter
                $date = new DateTime($atts['date']);
                $start_date = clone $date;
                $start_date->modify('-' . ($atts['days'] - 1) . ' days');
                
                // Get sales data
                $sales_data = $this->get_total_sales($start_date->format('Y-m-d'), $date->format('Y-m-d'));
                
                // Get summary data if enabled
                $show_summary = filter_var($atts['show_summary'], FILTER_VALIDATE_BOOLEAN);
                $show_dates = filter_var($atts['show_dates'], FILTER_VALIDATE_BOOLEAN);
                
                $product_summary = $show_summary ? $this->get_product_summary($start_date->format('Y-m-d'), $date->format('Y-m-d')) : [];
                $period_summary = $this->get_summary_by_period($start_date->format('Y-m-d'), $date->format('Y-m-d'));
                
                // Generate HTML output
                $output = '<div class="brcc-sales-data">';
                $output .= '<h3>' . sprintf(__('Sales Data (%s to %s)', 'brcc-inventory-tracker'), 
                           $start_date->format('M j, Y'), 
                           $date->format('M j, Y')) . '</h3>';
                
                // Add period summary totals
                $output .= '<div class="brcc-period-summary">';
                $output .= '<h4>' . __('Period Summary', 'brcc-inventory-tracker') . '</h4>';
                $output .= '<table class="brcc-period-summary-table">';
                $output .= '<tr>';
                $output .= '<th>' . __('Total Sales', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('WooCommerce', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Eventbrite', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Square', 'brcc-inventory-tracker') . '</th>';
                $output .= '</tr>';
                $output .= '<tr>';
                $output .= '<td><strong>' . $period_summary['total_sales'] . '</strong></td>';
                $output .= '<td>' . $period_summary['woocommerce_sales'] . '</td>';
                $output .= '<td>' . $period_summary['eventbrite_sales'] . '</td>';
                $output .= '<td>' . $period_summary['square_sales'] . '</td>';
                $output .= '</tr>';
                $output .= '</table>';
                $output .= '</div>';
                
                if (empty($sales_data)) {
                    $output .= '<p>' . __('No sales data available for this period.', 'brcc-inventory-tracker') . '</p>';
                } else {
                    // Show detailed breakdown with dates
                    if ($show_dates) {
                        $output .= '<h4>' . __('Detailed Sales by Event Date', 'brcc-inventory-tracker') . '</h4>';
                        $output .= '<table class="brcc-sales-table">';
                        $output .= '<thead><tr>';
                        $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('SKU', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('Event Date', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('Total Qty', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('WooCommerce', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('Eventbrite', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('Square', 'brcc-inventory-tracker') . '</th>';
                        $output .= '</tr></thead>';
                        $output .= '<tbody>';
                        
                        // Sort by product name first, then by date
                        $sorted_sales = $sales_data;
                        uasort($sorted_sales, function($a, $b) {
                            // Sort by product name
                            $name_compare = strcmp($a['name'], $b['name']);
                            if ($name_compare !== 0) {
                                return $name_compare;
                            }
                            
                            // If same product, sort by date
                            $a_date = isset($a['booking_date']) ? $a['booking_date'] : '';
                            $b_date = isset($b['booking_date']) ? $b['booking_date'] : '';
                            return strcmp($a_date, $b_date);
                        });
                        
                        foreach ($sorted_sales as $product_data) {
                            $output .= '<tr>';
                            $output .= '<td>' . esc_html($product_data['name']) . '</td>';
                            $output .= '<td>' . esc_html($product_data['sku']) . '</td>';
                            $output .= '<td>' . esc_html(isset($product_data['booking_date']) ? date_i18n(get_option('date_format'), strtotime($product_data['booking_date'])) : '—') . '</td>';
                            $output .= '<td>' . esc_html($product_data['quantity']) . '</td>';
                            $output .= '<td>' . esc_html(isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0) . '</td>';
                            $output .= '<td>' . esc_html(isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0) . '</td>';
                            $output .= '<td>' . esc_html(isset($product_data['square']) ? $product_data['square'] : 0) . '</td>';
                            $output .= '</tr>';
                        }
                        
                        $output .= '</tbody></table>';
                    }
                    
                    // Show product summary if enabled
                    if ($show_summary && !empty($product_summary)) {
                        $output .= '<h4>' . __('Sales Summary by Product', 'brcc-inventory-tracker') . '</h4>';
                        $output .= '<table class="brcc-sales-summary-table">';
                        $output .= '<thead><tr>';
                        $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('SKU', 'brcc-inventory-tracker') . '</th>';
                        $output .= '<th>' . __('Total Sales', 'brcc-inventory-tracker') . '</th>';
                        $output .= '</tr></thead>';
                        $output .= '<tbody>';
                        
                        // Sort by product name
                        uasort($product_summary, function($a, $b) {
                            return strcmp($a['name'], $b['name']);
                        });
                        
                        foreach ($product_summary as $product_id => $data) {
                            $output .= '<tr class="brcc-product-row">';
                            $output .= '<td>' . esc_html($data['name']) . '</td>';
                            $output .= '<td>' . esc_html($data['sku']) . '</td>';
                            $output .= '<td>' . esc_html($data['total_quantity']) . '</td>';
                            $output .= '</tr>';
                            
                            // Add date-specific rows if available
                            if (!empty($data['dates'])) {
                                // Sort dates chronologically
                                ksort($data['dates']);
                                
                                foreach ($data['dates'] as $date => $quantity) {
                                    $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
                                    $output .= '<tr class="brcc-date-row">';
                                    $output .= '<td class="brcc-indent">— ' . __('Event Date:', 'brcc-inventory-tracker') . ' ' . esc_html($formatted_date) . '</td>';
                                    $output .= '<td></td>';
                                    $output .= '<td>' . esc_html($quantity) . '</td>';
                                    $output .= '</tr>';
                                }
                            }
                        }
                        
                        $output .= '</tbody></table>';
                    }
                }
                
                $output .= '</div>';
                
                return $output;
            }
            
            /**
             * Shortcode for displaying event-specific sales data
             */
            public function event_sales_shortcode($atts) {
                // Parse shortcode attributes
                $atts = shortcode_atts(array(
                    'start_date' => '', // Start date for events (optional)
                    'end_date' => '',   // End date for events (optional)
                    'product_id' => 0,  // Filter by product ID (optional)
                    'days' => 30,       // Default range if no dates specified
                ), $atts, 'brcc_event_sales');
                
                // Calculate date range
                $end_date = !empty($atts['end_date']) ? $atts['end_date'] : current_time('Y-m-d');
                $start_date = !empty($atts['start_date']) ? $atts['start_date'] : date('Y-m-d', strtotime('-' . $atts['days'] . ' days', strtotime($end_date)));
                
                // Get product summary with date breakdowns
                $product_summary = $this->get_product_summary($start_date, $end_date);
                
                // Filter by product ID if specified
                if (!empty($atts['product_id'])) {
                    if (isset($product_summary[$atts['product_id']])) {
                        $product_summary = array($atts['product_id'] => $product_summary[$atts['product_id']]);
                    } else {
                        $product_summary = array();
                    }
                }
                
                // Generate HTML output
                $output = '<div class="brcc-event-sales">';
                $output .= '<h3>' . sprintf(__('Event Sales Report (%s to %s)', 'brcc-inventory-tracker'), 
                           date_i18n(get_option('date_format'), strtotime($start_date)), 
                           date_i18n(get_option('date_format'), strtotime($end_date))) . '</h3>';
                
                if (empty($product_summary)) {
                    $output .= '<p>' . __('No event sales data available for this period.', 'brcc-inventory-tracker') . '</p>';
                } else {
                    $output .= '<table class="brcc-event-sales-table">';
                    $output .= '<thead><tr>';
                    $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                    $output .= '<th>' . __('Event Date', 'brcc-inventory-tracker') . '</th>';
                    $output .= '<th>' . __('Sales', 'brcc-inventory-tracker') . '</th>';
                    $output .= '</tr></thead>';
                    $output .= '<tbody>';
                    
                    foreach ($product_summary as $product_id => $data) {
                        // Skip products with no date-specific data
                        if (empty($data['dates'])) {
                            continue;
                        }
                        
                        $first_row = true;
                        
                        // Sort dates chronologically
                        ksort($data['dates']);
                        
                        foreach ($data['dates'] as $date => $quantity) {
                            $output .= '<tr>';
                            
                            if ($first_row) {
                                $output .= '<td rowspan="' . count($data['dates']) . '">' . esc_html($data['name']) . '</td>';
                                $first_row = false;
                            }
                            
                            $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
                            $output .= '<td>' . esc_html($formatted_date) . '</td>';
                            $output .= '<td>' . esc_html($quantity) . '</td>';
                            $output .= '</tr>';
                        }
                        
                        // Add a total row for this product
                        $output .= '<tr class="brcc-total-row">';
                        $output .= '<td colspan="2"><strong>' . __('Total for', 'brcc-inventory-tracker') . ' ' . esc_html($data['name']) . '</strong></td>';
                        $output .= '<td><strong>' . esc_html($data['total_quantity']) . '</strong></td>';
                        $output .= '</tr>';
                    }
                    
                    $output .= '</tbody></table>';
                }
                
                $output .= '</div>';
                
                return $output;
            }
            
            /**
             * Import a batch of historical WooCommerce orders
             *
             * @param string $start_date Start date (Y-m-d)
             * @param string $end_date End date (Y-m-d)
             * @param int $offset Number of orders to skip
             * @param int $limit Number of orders per batch
             * @return array Result array with processed_count, next_offset, source_complete, logs
             */
            public function import_woocommerce_batch($start_date, $end_date, $offset, $limit) {
                $logs = array();
                $processed_count = 0;
                $source_complete = false;
            
                $logs[] = array('message' => "Querying WooCommerce orders from {$start_date} to {$end_date}, offset {$offset}, limit {$limit}...", 'type' => 'info');
            
                // Query orders
                $args = array(
                    'limit'        => $limit,
                    'offset'       => $offset,
                    'orderby'      => 'date',
                    'order'        => 'ASC',
                    'status'       => array('wc-completed'), // Only completed orders
                    'date_created' => $start_date . '...' . $end_date, // Filter by date created
                    'return'       => 'ids', // Get only IDs for performance
                );
                BRCC_Helpers::log_debug("import_woocommerce_batch: Querying orders with args:", $args);
                $order_ids = wc_get_orders($args);
                $found_count = is_array($order_ids) ? count($order_ids) : 0; // Handle potential non-array return
                BRCC_Helpers::log_debug("import_woocommerce_batch: Found {$found_count} order IDs.");
            
                if (empty($order_ids)) {
                    $logs[] = array('message' => "No more WooCommerce orders found in this batch/date range.", 'type' => 'info');
                    $source_complete = true;
                } else {
                    BRCC_Helpers::log_debug("import_woocommerce_batch: Starting loop for {$found_count} orders.");
                    $logs[] = array('message' => "Found " . count($order_ids) . " WooCommerce order(s) in this batch.", 'type' => 'info');
                    foreach ($order_ids as $order_id) {
                        $order = wc_get_order($order_id); // This can be resource intensive
                        if (!$order) {
                            $logs[] = array('message' => "Could not retrieve order #{$order_id}.", 'type' => 'warning');
                            continue;
                        }
            
                        $order_date_completed = $order->get_date_completed();
                        // Use date created if completion date is missing (unlikely for completed orders)
                        $sale_date = $order_date_completed ? $order_date_completed->date('Y-m-d') : $order->get_date_created()->date('Y-m-d');
            
                        $items = $order->get_items();
                        foreach ($items as $item_id => $item) {
                            $product_id = $item->get_product_id();
                            $quantity = $item->get_quantity();
                            
                            if (!$product_id || $quantity <= 0) {
                                continue;
                            }
            
                            // Get booking/event date if available
                            $booking_date = $this->get_booking_date_from_item($item);
            
                            // Record the historical sale
                            $this->record_historical_sale('WooCommerce', $product_id, $quantity, $sale_date, $booking_date, $order_id);
                            // $processed_count++; // Count orders, not items
                        } // End foreach $items loop
                    } // End if $order check
                    $processed_count++; // Increment processed order count
                    // Log progress periodically
                    if ($processed_count % 5 === 0) { // Log every 5 orders processed
                        BRCC_Helpers::log_debug("import_woocommerce_batch: Processed {$processed_count}/{$found_count} orders in this batch so far...");
                    }
                    // $logs[] = array('message' => "Processed order #{$order_id} (Date: {$sale_date}).", 'type' => 'info'); // Reduce log noise
                         $logs[] = array('message' => "Processed order #{$order_id} (Date: {$sale_date}).", 'type' => 'info');
            
                    // Check if this was the last batch
                    if (count($order_ids) < $limit) {
                        $logs[] = array('message' => "Last batch processed for WooCommerce in this date range.", 'type' => 'info');
                        $source_complete = true;
                    }
                }
            
                return array(
                    'processed_count' => $processed_count,
                    'next_offset'     => $source_complete ? null : $offset + $found_count, // Use actual count for next offset
                    'source_complete' => $source_complete,
                    'logs'            => $logs
                );
                BRCC_Helpers::log_debug("import_woocommerce_batch: Batch finished. Processed: {$processed_count}. Source Complete: " . ($source_complete ? 'Yes' : 'No') . ". Next Offset: " . ($source_complete ? 'null' : $offset + $found_count));
            }
            
            /**
             * Record a historical sale without triggering live syncs
             *
             * @param string $source 'WooCommerce', 'Square', 'Eventbrite'
             * @param int $product_id
             * @param int $quantity
             * @param string $sale_date Original date of the sale (Y-m-d)
             * @param string|null $booking_date Optional booking/event date (Y-m-d)
             * @param string $order_ref Optional reference ID (WC Order ID, Square Order ID, etc.)
             */
            private function record_historical_sale($source, $product_id, $quantity, $sale_date, $booking_date = null, $order_ref = '') {
                
                // Basic validation
                if (empty($product_id) || empty($quantity) || empty($sale_date)) {
                    return false;
                }
                
                // Get product details
                $product = wc_get_product($product_id);
                if (!$product) {
                     BRCC_Helpers::log_error("Historical Import: Product ID {$product_id} not found for {$source} sale ref {$order_ref}.");
                    return false;
                }
                $product_name = $product->get_name();
                $sku = $product->get_sku();
            
                // Get existing daily sales data
                $daily_sales = get_option('brcc_daily_sales', []);
            
                // Create unique key for product + booking date
                $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
            
                // Ensure the sale date entry exists
                if (!isset($daily_sales[$sale_date])) {
                    $daily_sales[$sale_date] = array();
                }
            
                // Update daily sales for the product on the original sale date
                $source_key = strtolower($source); // 'woocommerce', 'square', 'eventbrite'
                
                if (isset($daily_sales[$sale_date][$product_key])) {
                    // Increment total quantity
                    $daily_sales[$sale_date][$product_key]['quantity'] = ($daily_sales[$sale_date][$product_key]['quantity'] ?? 0) + $quantity;
                    // Increment source-specific quantity
                    $daily_sales[$sale_date][$product_key][$source_key] = ($daily_sales[$sale_date][$product_key][$source_key] ?? 0) + $quantity;
                } else {
                    // Create new entry for this product/booking date on the sale date
                    $daily_sales[$sale_date][$product_key] = array(
                        'name'         => $product_name,
                        'sku'          => $sku,
                        'product_id'   => $product_id,
                        'booking_date' => $booking_date,
                        'quantity'     => $quantity,
                        'woocommerce'  => ($source_key === 'woocommerce' ? $quantity : 0),
                        'eventbrite'   => ($source_key === 'eventbrite' ? $quantity : 0),
                        'square'       => ($source_key === 'square' ? $quantity : 0),
                    );
                }
            
                // Save the updated daily sales data
                update_option('brcc_daily_sales', $daily_sales);
            
                // Also update product summary data for the original sale date
                $product_date_quantities = array(
                    $product_key => array(
                        'product_id'   => $product_id,
                        'name'         => $product_name,
                        'sku'          => $sku,
                        'booking_date' => $booking_date,
                        'quantity'     => $quantity
                    )
                );
                $this->update_daily_product_summary($sale_date, $product_date_quantities);
                
                // DO NOT trigger live sync actions like do_action('brcc_product_sold_with_date', ...)
            
                return true;
            
} // End class BRCC_Sales_Tracker
}
