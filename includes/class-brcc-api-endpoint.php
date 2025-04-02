<?php

/**
 * BRCC API Endpoint Class
 * 
 * Handles the REST API endpoints for the BRCC Inventory Tracker with date-based inventory support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_API_Endpoint
{
    /**
     * Product mappings instance
     */
    private $product_mappings;

    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Initialize product mappings
        $this->product_mappings = new BRCC_Product_Mappings();

        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        register_rest_route('brcc/v1', '/inventory', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_inventory'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route('brcc/v1', '/inventory/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_inventory'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
    }

    /**
     * Check API key for authentication
     */
    public function check_api_key($request)
    {
        $api_key = $request->get_header('X-BRCC-API-Key');

        if (!$api_key) {
            return new WP_Error(
                'missing_api_key',
                __('API key is required.', 'brcc-inventory-tracker'),
                array('status' => 401)
            );
        }

        $settings = get_option('brcc_api_settings');

        if ($api_key !== $settings['api_key']) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid API key.', 'brcc-inventory-tracker'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Get inventory levels
     */
    public function get_inventory($request)
    {
        $products = wc_get_products(array(
            'limit' => -1,
            'status' => 'publish',
        ));

        $inventory = array();

        foreach ($products as $product) {
            $product_id = $product->get_id();

            // Check if this product has date-based inventory
            $product_dates = $this->product_mappings->get_product_dates($product_id);

            if (!empty($product_dates)) {
                // Add each date as a separate inventory item
                foreach ($product_dates as $date_data) {
                    $date = $date_data['date'];
                    $inventory_level = $date_data['inventory'];

                    // Get mapping for this product and date
                    $mapping = $this->product_mappings->get_product_mappings($product_id, $date);

                    $inventory[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'date' => $date,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                        'stock' => $inventory_level,
                        'eventbrite_id' => isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : '',
                        'square_id' => isset($mapping['square_id']) ? $mapping['square_id'] : '',
                    );
                }
            } else {
                // Regular product without dates
                $product_data = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'stock' => $product->get_stock_quantity(),
                    'manage_stock' => $product->get_manage_stock(),
                    'stock_status' => $product->get_stock_status(),
                );

                // Get product mappings
                $mapping = $this->product_mappings->get_product_mappings($product_id);

                if (!empty($mapping)) {
                    $product_data['eventbrite_id'] = isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : '';
                    $product_data['square_id'] = isset($mapping['square_id']) ? $mapping['square_id'] : '';
                }

                $inventory[] = $product_data;
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'inventory' => $inventory
        ));
    }

    /**
     * Update inventory levels
     */
    public function update_inventory($request)
    {
        $params = $request->get_json_params();

        if (!isset($params['products']) || !is_array($params['products'])) {
            return new WP_Error(
                'invalid_request',
                __('Products data is required.', 'brcc-inventory-tracker'),
                array('status' => 400)
            );
        }

        $updates = array();
        $errors = array();

        // Initialize product mappings helper if not already done
        if (!$this->product_mappings) {
            $this->product_mappings = new BRCC_Product_Mappings();
        }

        foreach ($params['products'] as $product_data) {
            if (!isset($product_data['id']) && !isset($product_data['sku'])) {
                $errors[] = __('Product ID or SKU is required.', 'brcc-inventory-tracker');
                continue;
            }

            if (!isset($product_data['stock'])) {
                $errors[] = __('Stock quantity is required.', 'brcc-inventory-tracker');
                continue;
            }

            // Find the product
            $product = null;

            if (isset($product_data['id'])) {
                $product = wc_get_product($product_data['id']);
            } else if (isset($product_data['sku'])) {
                $product_id = wc_get_product_id_by_sku($product_data['sku']);

                if ($product_id) {
                    $product = wc_get_product($product_id);
                }
            }

            if (!$product) {
                $errors[] = sprintf(
                    __('Product not found: %s', 'brcc-inventory-tracker'),
                    isset($product_data['id']) ? $product_data['id'] : $product_data['sku']
                );
                continue;
            }

            $product_id = $product->get_id();
            $new_stock = intval($product_data['stock']);

            // Check if this has a date parameter
            $date = isset($product_data['date']) ? sanitize_text_field($product_data['date']) : null;

            if ($date) {
                // Date-based inventory update - use appropriate method to update date-specific inventory
                $result = $this->update_date_specific_inventory($product_id, $date, $new_stock);

                if ($result) {
                    $updates[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'date' => $date,
                        'old_stock' => null, // We don't know the old stock without querying
                        'new_stock' => $new_stock,
                    );
                } else {
                    $errors[] = sprintf(
                        __('Failed to update date-based inventory for product: %s, date: %s', 'brcc-inventory-tracker'),
                        $product->get_name(),
                        $date
                    );
                }
            } else {
                // Regular product inventory update
                if ($product->get_manage_stock()) {
                    $old_stock = $product->get_stock_quantity();

                    // Check if test mode is enabled
                    if (BRCC_Helpers::is_test_mode()) {
                        BRCC_Helpers::log_operation(
                            'API',
                            'Update Inventory',
                            sprintf(
                                __('Would update WooCommerce stock for product ID %s from %s to %s', 'brcc-inventory-tracker'),
                                $product_id,
                                $old_stock,
                                $new_stock
                            )
                        );
                    } else {
                        // Update stock in live mode
                        if (BRCC_Helpers::should_log()) {
                            BRCC_Helpers::log_operation(
                                'API',
                                'Update Inventory',
                                sprintf(
                                    __('Updating WooCommerce stock for product ID %s from %s to %s (Live Mode)', 'brcc-inventory-tracker'),
                                    $product_id,
                                    $old_stock,
                                    $new_stock
                                )
                            );
                        }

                        $product->set_stock_quantity($new_stock);
                        $product->save();
                    }

                    $updates[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'old_stock' => $old_stock,
                        'new_stock' => $new_stock,
                    );
                } else {
                    $errors[] = sprintf(
                        __('Stock management not enabled for product: %s', 'brcc-inventory-tracker'),
                        $product->get_name()
                    );
                }
            }
        }

        // Record the last sync time
        update_option('brcc_last_sync_time', time());

        return rest_ensure_response(array(
            'success' => true,
            'updates' => $updates,
            'errors' => $errors,
        ));
    }

    /**
     * Update date-specific inventory for a product
     * 
     * @param int $product_id Product ID
     * @param string $date Date in Y-m-d format
     * @param int $quantity New inventory level
     * @return boolean Success or failure
     */
    private function update_date_specific_inventory($product_id, $date, $quantity)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'API',
                'Update Date Inventory',
                sprintf(
                    __('Would update date-specific inventory for product ID %s date %s to %s', 'brcc-inventory-tracker'),
                    $product_id,
                    $date,
                    $quantity
                )
            );
            return true;
        }

        // Log in live mode if enabled
        if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'API',
                'Update Date Inventory',
                sprintf(
                    __('Updating date-specific inventory for product ID %s date %s to %s (Live Mode)', 'brcc-inventory-tracker'),
                    $product_id,
                    $date,
                    $quantity
                )
            );
        }

        // Try to find FooEvents date inventory first
        if (BRCC_Helpers::is_fooevents_active()) {
            $fooevents_updated = $this->update_fooevents_inventory($product_id, $date, $quantity);
            if ($fooevents_updated) {
                return true;
            }
        }

        // Different booking plugins store their inventory data differently
        // Try to handle various formats

        // Try to get booking slots meta
        $slots = $product->get_meta('_booking_slots');
        if (!empty($slots) && is_array($slots) && isset($slots[$date])) {
            $slots[$date]['inventory'] = $quantity;
            $product->update_meta_data('_booking_slots', $slots);
            $product->save();
            return true;
        }

        // Try to find the date in _product_booking_slots
        $bookings = $product->get_meta('_product_booking_slots');
        if (!empty($bookings) && is_array($bookings)) {
            $updated = false;
            foreach ($bookings as $key => $booking) {
                if (isset($booking['date']) && $booking['date'] === $date) {
                    $bookings[$key]['stock'] = $quantity;
                    $updated = true;
                    break;
                }
            }

            if ($updated) {
                $product->update_meta_data('_product_booking_slots', $bookings);
                $product->save();
                return true;
            }
        }

        // For products that use a different schema, check alternative meta keys
        foreach (array('_wc_slots', '_event_slots', '_bookings', '_event_dates') as $meta_key) {
            $slots_data = $product->get_meta($meta_key);
            if (!empty($slots_data) && is_array($slots_data)) {
                $updated = false;

                foreach ($slots_data as $key => $slot) {
                    if (isset($slot['date']) && $slot['date'] === $date) {
                        // Update stock/inventory field depending on what's available
                        if (isset($slot['stock'])) {
                            $slots_data[$key]['stock'] = $quantity;
                            $updated = true;
                        } elseif (isset($slot['inventory'])) {
                            $slots_data[$key]['inventory'] = $quantity;
                            $updated = true;
                        }
                    }
                }

                if ($updated) {
                    $product->update_meta_data($meta_key, $slots_data);
                    $product->save();
                    return true;
                }
            }
        }

        // If product is variable, check for variations with date attributes
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            foreach ($variations as $variation) {
                $variation_id = $variation['variation_id'];
                $variation_obj = wc_get_product($variation_id);

                // Look for date-related attributes
                $attributes = $variation_obj->get_attributes();
                $is_date_variation = false;

                foreach ($attributes as $attr_name => $attr_value) {
                    $lower_name = strtolower($attr_name);
                    if (strpos($lower_name, 'date') !== false || strpos($lower_name, 'day') !== false) {
                        // Try to parse the date from the attribute
                        $date_value = BRCC_Helpers::parse_date_value($attr_value);
                        if ($date_value === $date) {
                            $is_date_variation = true;
                            break;
                        }
                    }
                }

                if ($is_date_variation) {
                    // This variation matches our date - update its stock
                    $variation_obj->set_stock_quantity($quantity);
                    $variation_obj->save();
                    return true;
                }
            }
        }

        // If we couldn't find a way to update date-specific inventory,
        // log this for the admin to address
        BRCC_Helpers::log_error(sprintf(
            __('Could not update date-based inventory for product ID %s date %s - no compatible inventory storage found', 'brcc-inventory-tracker'),
            $product_id,
            $date
        ));

        return false;
    }

    /**
     * Update FooEvents inventory for a specific date
     * 
     * @param int $product_id Product ID
     * @param string $date Date in Y-m-d format
     * @param int $quantity New inventory level
     * @return boolean Success or failure
     */
    private function update_fooevents_inventory($product_id, $date, $quantity)
    {
        // Check if this is a FooEvents product
        $is_fooevents = get_post_meta($product_id, 'fooevents_event', true);
        if (!$is_fooevents) {
            return false;
        }

        // Check what type of event this is
        $event_type = get_post_meta($product_id, 'fooevents_event_type', true);

        // Single date event
        $event_date = get_post_meta($product_id, 'fooevents_event_date', true);
        if (!empty($event_date)) {
            $parsed_date = BRCC_Helpers::parse_date_value($event_date);
            if ($parsed_date === $date) {
                // Update stock in WooCommerce
                $product = wc_get_product($product_id);
                if ($product) {
                    $product->set_stock_quantity($quantity);
                    $product->save();
                    return true;
                }
            }
        }

        // Multi-day event
        $event_dates = get_post_meta($product_id, 'fooevents_event_dates', true);
        if (!empty($event_dates) && is_array($event_dates)) {
            // For multiple dates, check if the date exists
            $date_found = false;
            $updated_dates = [];

            foreach ($event_dates as $event_date) {
                $parsed_date = BRCC_Helpers::parse_date_value($event_date);
                if ($parsed_date === $date) {
                    $date_found = true;
                }
                $updated_dates[] = $event_date;
            }

            if ($date_found) {
                // Update day-specific slots if they exist
                $day_slots = get_post_meta($product_id, 'fooevents_event_day_slots', true);
                if (!empty($day_slots) && is_array($day_slots)) {
                    if (isset($day_slots[$date])) {
                        $day_slots[$date]['stock'] = $quantity;
                        update_post_meta($product_id, 'fooevents_event_day_slots', $day_slots);
                        return true;
                    }
                }

                // If there's no day-specific slots, set the overall stock
                $product = wc_get_product($product_id);
                if ($product) {
                    $product->set_stock_quantity($quantity);
                    $product->save();
                    return true;
                }
            }
        }

        // Serialized event dates
        $serialized_dates = get_post_meta($product_id, 'fooevents_event_dates_serialized', true);
        if (!empty($serialized_dates)) {
            $unserialized_dates = maybe_unserialize($serialized_dates);
            if (is_array($unserialized_dates)) {
                foreach ($unserialized_dates as $key => $event_date) {
                    $parsed_date = BRCC_Helpers::parse_date_value($event_date);
                    if ($parsed_date === $date) {
                        // Update product stock
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product->set_stock_quantity($quantity);
                            $product->save();
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}

// Initialize the API endpoint
new BRCC_API_Endpoint();
