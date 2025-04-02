<?php

/**
 * BRCC Product Mappings Class
 * 
 * Manages product mappings for date-based and time-based inventory between WooCommerce and Eventbrite
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Product_Mappings
{
    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Admin AJAX handlers
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings'));
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates'));
        add_action('wp_ajax_brcc_test_product_date_mapping', array($this, 'ajax_test_product_date_mapping'));
    }

    /**
     * Get product mappings
     * 
     * @param int $product_id Product ID
     * @param string $date Optional event date in Y-m-d format
     * @param string $time Optional event time in H:i format
     * @return array Product mappings
     */
    /**
     * Get product mappings including Square support
     * 
     * @param int $product_id Product ID
     * @param string $date Optional event date in Y-m-d format
     * @param string $time Optional event time in H:i format
     * @return array Product mappings
     */
    public function get_product_mappings($product_id, $date = null, $time = null)
    {
        $all_mappings = get_option('brcc_product_mappings', array());

        // If no date and time, return default mappings for product
        if (!$date) {
            return isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array(
                'eventbrite_id' => '',
                'square_id' => ''
            );
        }

        // Check for date+time specific mapping first, allowing for time buffer
        if ($time && isset($all_mappings[$product_id . '_dates'])) {
            $date_mappings = $all_mappings[$product_id . '_dates'];
            foreach ($date_mappings as $key => $mapping_data) {
                // Check if the key contains the correct date and a time component
                if (strpos($key, $date . '_') === 0) {
                    $stored_time = substr($key, strlen($date . '_'));
                    // Use is_time_close for comparison
                    if (BRCC_Helpers::is_time_close($time, $stored_time)) {
                        return $mapping_data; // Return the first close match
                    }
                }
            }
        }

        // Check for date-specific mapping (if no time match found or no time provided)
        if (isset($all_mappings[$product_id . '_dates'][$date])) {
            return $all_mappings[$product_id . '_dates'][$date];
        }

        // Fall back to default if no specific mapping exists
        return isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array(
            'eventbrite_id' => '',
            'square_id' => ''
        );
    }
    /**
     * AJAX: Save product date mappings with time support
     */
    public function ajax_save_product_date_mappings()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Check if test mode is enabled
        if (method_exists('BRCC_Helpers', 'is_test_mode') && BRCC_Helpers::is_test_mode()) {
            if (method_exists('BRCC_Helpers', 'log_operation')) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Save Date Mappings',
                    sprintf(
                        __('Would save %d date mappings for product ID %s', 'brcc-inventory-tracker'),
                        count($mappings),
                        $product_id
                    )
                );
            }

            wp_send_json_success(array(
                'message' => __('Product date mappings would be saved in Test Mode.', 'brcc-inventory-tracker') . ' ' .
                    __('(No actual changes made)', 'brcc-inventory-tracker')
            ));
            return;
        }

        // Log in live mode if enabled
        if (method_exists('BRCC_Helpers', 'should_log') && BRCC_Helpers::should_log() && method_exists('BRCC_Helpers', 'log_operation')) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Date Mappings',
                sprintf(
                    __('Saving %d date mappings for product ID %s (Live Mode)', 'brcc-inventory-tracker'),
                    count($mappings),
                    $product_id
                )
            );
        }

        // Get all existing mappings
        $all_mappings = get_option('brcc_product_mappings', array());

        // Initialize date mappings for this product if needed
        if (!isset($all_mappings[$product_id . '_dates'])) {
            $all_mappings[$product_id . '_dates'] = array();
        } else {
            // Clear existing date mappings to replace with new ones
            $all_mappings[$product_id . '_dates'] = array();
        }

        // Process each mapping
        $successful_mappings = 0;
        foreach ($mappings as $mapping) {
            if (isset($mapping['date'])) {
                $date = sanitize_text_field($mapping['date']);
                $time = isset($mapping['time']) && !empty($mapping['time']) ? sanitize_text_field($mapping['time']) : null;
                $eventbrite_id = isset($mapping['eventbrite_id']) ? sanitize_text_field($mapping['eventbrite_id']) : '';
                $square_id = isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : '';

                // Skip empty mappings
                if (empty($eventbrite_id) && empty($square_id)) {
                    continue;
                }

                // Create a key that includes time if available
                $key = $time ? $date . '_' . $time : $date;

                // Save mapping
                $all_mappings[$product_id . '_dates'][$key] = array(
                    'eventbrite_id' => $eventbrite_id,
                    'square_id' => $square_id
                );

                $successful_mappings++;
            }
        }

        // Save all mappings
        update_option('brcc_product_mappings', $all_mappings);

        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully saved %d date mappings for this product.', 'brcc-inventory-tracker'),
                $successful_mappings
            )
        ));
    }
    /**
     * Save product mapping with Square support
     * 
     * @param int $product_id Product ID
     * @param array $mapping Mapping data (eventbrite_id, square_id)
     * @param string $date Optional event date in Y-m-d format
     * @param string $time Optional event time in H:i format
     * @return boolean Success or failure
     */

    public function save_product_mapping($product_id, $mapping, $date = null, $time = null)
    {
        $all_mappings = get_option('brcc_product_mappings', array());

        // Ensure Square ID field exists
        if (!isset($mapping['square_id'])) {
            $mapping['square_id'] = '';
        }

        if (!$date) {
            // Save default mapping (no date)
            $all_mappings[$product_id] = array(
                'eventbrite_id' => sanitize_text_field($mapping['eventbrite_id']),
                'square_id' => sanitize_text_field($mapping['square_id'])
            );
        } else {
            // Save date-specific mapping
            if (!isset($all_mappings[$product_id . '_dates'])) {
                $all_mappings[$product_id . '_dates'] = array();
            }

            // Use date+time as key if time is provided
            $key = $time ? $date . '_' . $time : $date;

            $all_mappings[$product_id . '_dates'][$key] = array(
                'eventbrite_id' => sanitize_text_field($mapping['eventbrite_id']),
                'square_id' => sanitize_text_field($mapping['square_id'])
            );
        }

        return update_option('brcc_product_mappings', $all_mappings);
    }
    /**
     * Get product event dates with time
     * 
     * @param int $product_id Product ID
     * @param bool $intelligent_date_detection
     * @return array Event dates, times and inventory levels
     */
    public function get_product_dates($product_id, $intelligent_date_detection = true)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array();
        }

        // Initialize dates array
        $dates = array();

        // 1. Try to extract FooEvents dates first
        // Note: $this->get_fooevents_dates() needs to be correctly implemented to fetch FooEvents Bookings data
        $fooevents_dates = $this->get_fooevents_dates($product);
        if (!empty($fooevents_dates)) {
            // Assuming get_fooevents_dates returns data in the expected format:
            // array('date' => 'Y-m-d', 'formatted_date' => '...', 'time' => 'H:i', 'formatted_time' => '...', 'inventory' => ...)
            return $fooevents_dates; // Return immediately if FooEvents dates are found
        }

        // 2. Get booking slots from product meta (WC Bookings, generic)
        $booking_slots = $this->get_product_booking_slots($product);
        if (!empty($booking_slots)) {
            $wc_booking_dates = array(); // Use a separate array for clarity
            foreach ($booking_slots as $slot) {
                $wc_booking_dates[] = array(
                    'date' => $slot['date'],
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($slot['date'])),
                    'time' => isset($slot['time']) ? $slot['time'] : '',
                    'formatted_time' => isset($slot['time']) && !empty($slot['time']) ?
                        date('g:i A', strtotime("1970-01-01 " . $slot['time'])) : '',
                    'inventory' => isset($slot['inventory']) ? $slot['inventory'] : null
                );
            }
            return $wc_booking_dates; // Return if we found WC Bookings/generic slots here
        }

        // 3. Try to extract dates using intelligent title approach (Day of Week)
        if ($intelligent_date_detection) {
            $product_name = $product->get_name();
            $day_name = BRCC_Helpers::extract_day_from_title($product_name);
            $time_info = BRCC_Helpers::extract_time_from_title($product_name);

            if ($day_name) {
                // Get upcoming dates for this day of the week
                $upcoming_dates = BRCC_Helpers::get_upcoming_dates_for_day($day_name);

                if (!empty($upcoming_dates)) {
                    $title_dates = array(); // Use a separate array
                    foreach ($upcoming_dates as $date) {
                        $title_dates[] = array(
                            'date' => $date,
                            'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                            'time' => $time_info,
                            'formatted_time' => $time_info ? date('g:i A', strtotime("1970-01-01 $time_info")) : '',
                            'inventory' => null,  // Unknown inventory at this point
                            'is_day_match' => true
                        );
                    }
                    // If we found dates based on the product name, return them
                    return $title_dates;
                }
            }
        }

        // 4. Check Eventbrite integration (also uses title day name as a trigger)
        if (class_exists('BRCC_Eventbrite_Integration')) {
            $eventbrite = new BRCC_Eventbrite_Integration();
            $product_name = $product->get_name(); // Re-get name if needed
            $day_name = BRCC_Helpers::extract_day_from_title($product_name); // Re-extract day if needed

            if ($day_name) {
                // Try to get events for this day from Eventbrite
                $events = $eventbrite->get_events_by_day($day_name);

                if (!empty($events)) {
                    $eventbrite_dates = array(); // Use a separate array
                    foreach ($events as $event) {
                        if (isset($event['start']['local'])) {
                            $date = date('Y-m-d', strtotime($event['start']['local']));
                            $time = date('H:i', strtotime($event['start']['local']));

                            $eventbrite_dates[] = array(
                                'date' => $date,
                                'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                                'time' => $time,
                                'formatted_time' => date('g:i A', strtotime($event['start']['local'])),
                                'inventory' => null,  // Unknown inventory
                                'event_id' => $event['id'],
                                'event_name' => isset($event['name']['text']) ? $event['name']['text'] : '',
                                'venue_name' => isset($event['venue']['name']) ? $event['venue']['name'] : '',
                                'from_eventbrite' => true
                            );
                        }
                    }

                    // Only return Eventbrite dates if no other dates were found by previous methods
                    if (!empty($eventbrite_dates)) {
                        return $eventbrite_dates;
                    }
                }
            }
        }

        // 5. Fallback: If NO dates were found by any method, create a set of upcoming dates
        // We use the $dates variable initialized at the start. If it's still empty here, apply fallback.
        if (empty($dates)) {
            $fallback_dates = array(); // Use a separate array for fallback
            // Generate the next 7 days as a fallback
            $current_date = new DateTime();
            for ($i = 1; $i <= 7; $i++) {
                $date = $current_date->modify('+1 day')->format('Y-m-d');
                $fallback_dates[] = array(
                    'date' => $date,
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                    'time' => '',
                    'formatted_time' => '',
                    'inventory' => null,  // No inventory data
                    'is_fallback' => true
                );
            }
            return $fallback_dates; // Return fallback dates
        }

        // If somehow dates were populated but not returned earlier (shouldn't happen with current logic), return them.
        // Otherwise, this will return an empty array if no dates were found and fallback wasn't triggered (also unlikely).
        return $dates;
    }



    /**
     * Parse time value to H:i format
     *
     * @param mixed $value Time value to parse
     * @return string|null H:i formatted time or null if parsing fails
     */
    private function parse_time_value($value)
    {
        if (empty($value)) {
            return null;
        }

        // If already in H:i format
        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return $value;
        }

        // Common formats: "8:00 PM", "8 PM", "20:00"
        if (preg_match('/(\d{1,2})[:.]?(\d{2})?\s*(am|pm)?/i', $value, $matches)) {
            $hour = intval($matches[1]);
            $minute = isset($matches[2]) && !empty($matches[2]) ? intval($matches[2]) : 0;

            // Adjust for AM/PM if present
            if (isset($matches[3])) {
                $ampm = strtolower($matches[3]);
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        // Try strtotime as last resort
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }

        return null;
    }


    /**
     * Get product event dates from Eventbrite with enhanced time support
     * 
     * @param int $product_id Product ID
     * @param string $eventbrite_id Eventbrite ticket ID
     * @return array Event dates and inventory levels from Eventbrite
     */
    public function get_product_dates_from_eventbrite($product_id, $eventbrite_id)
    {
        $dates = array();

        // Initialize Eventbrite integration
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return $dates;
        }

        $eventbrite = new BRCC_Eventbrite_Integration();

        // Test connection first
        $connection_test = $eventbrite->test_ticket_connection($eventbrite_id);

        if (is_wp_error($connection_test)) {
            // Add error information to the dates array
            $dates[] = array(
                'date' => current_time('Y-m-d'),
                'formatted_date' => date_i18n(get_option('date_format')),
                'time' => '',
                'formatted_time' => '',
                'inventory' => null,
                'error' => $connection_test->get_error_message(),
                'eventbrite_connection_failed' => true
            );
            return $dates;
        }

        // If connection succeeded, add this date
        if (isset($connection_test['event_date']) && !empty($connection_test['event_date'])) {
            $dates[] = array(
                'date' => $connection_test['event_date'],
                'formatted_date' => date_i18n(get_option('date_format'), strtotime($connection_test['event_date'])),
                'time' => $connection_test['event_time'],
                'formatted_time' => !empty($connection_test['event_time']) ?
                    date('g:i A', strtotime("1970-01-01 " . $connection_test['event_time'])) : '',
                'inventory' => $connection_test['available'],
                'eventbrite_id' => $eventbrite_id,
                'eventbrite_event_id' => $connection_test['event_id'],
                'eventbrite_name' => $connection_test['event_name'],
                'eventbrite_venue' => $connection_test['venue_name'],
                'eventbrite_time' => $connection_test['event_time'],
                'capacity' => $connection_test['capacity'],
                'sold' => $connection_test['sold'],
                'available' => $connection_test['available'],
                'from_eventbrite' => true,
                'eventbrite_connection_successful' => true
            );
        }

        // Get event ID from the connection test
        $event_id = isset($connection_test['event_id']) ? $connection_test['event_id'] : '';

        // If we have an event ID, we can check for multiple times/dates
        if ($event_id) {
            // Get the event details to check for series or multiple occurrences
            $event_details = $eventbrite->get_eventbrite_event($event_id);

            if (!is_wp_error($event_details)) {
                // Look for repeating patterns in the event name or details
                $product = wc_get_product($product_id);
                $product_name = $product ? $product->get_name() : '';
                $day_name = BRCC_Helpers::extract_day_from_title($product_name);

                // If this seems to be a recurring event (has day name in title)
                if ($day_name) {
                    // Get events for this day
                    $matched_events = $eventbrite->get_events_by_day($day_name);

                    // Cache existing event IDs to avoid duplicates
                    $existing_event_ids = array();
                    foreach ($dates as $existing_date) {
                        if (isset($existing_date['eventbrite_event_id'])) {
                            $existing_event_ids[] = $existing_date['eventbrite_event_id'];
                        }
                    }

                    // Add any events with the same day that also match the time pattern
                    $time_pattern = BRCC_Helpers::extract_time_from_title($product_name);

                    foreach ($matched_events as $matched_event) {
                        // Skip if already included
                        if (in_array($matched_event['id'], $existing_event_ids)) {
                            continue;
                        }

                        // Check for ticket classes
                        if (isset($matched_event['ticket_classes']) && is_array($matched_event['ticket_classes'])) {
                            foreach ($matched_event['ticket_classes'] as $ticket) {
                                // Skip free tickets
                                if (isset($ticket['free']) && $ticket['free']) {
                                    continue;
                                }

                                // Get event date and time
                                $event_date = isset($matched_event['start']['local']) ?
                                    date('Y-m-d', strtotime($matched_event['start']['local'])) : '';
                                $event_time = isset($matched_event['start']['local']) ?
                                    date('H:i', strtotime($matched_event['start']['local'])) : '';

                                // Only include if time pattern matches (if we have one)
                                if (!$time_pattern || BRCC_Helpers::is_time_close($event_time, $time_pattern)) {
                                    // Calculate inventory
                                    $capacity = isset($ticket['capacity_is_custom']) && $ticket['capacity_is_custom'] ?
                                        $ticket['capacity'] : (isset($matched_event['capacity']) ? $matched_event['capacity'] : 0);
                                    $sold = isset($ticket['quantity_sold']) ? $ticket['quantity_sold'] : 0;
                                    $available = $capacity - $sold;

                                    $dates[] = array(
                                        'date' => $event_date,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
                                        'time' => $event_time,
                                        'formatted_time' => date('g:i A', strtotime($matched_event['start']['local'])),
                                        'inventory' => $available,
                                        'eventbrite_id' => $ticket['id'],
                                        'eventbrite_event_id' => $matched_event['id'],
                                        'eventbrite_name' => isset($matched_event['name']['text']) ? $matched_event['name']['text'] : '',
                                        'eventbrite_venue' => isset($matched_event['venue']['name']) ? $matched_event['venue']['name'] : '',
                                        'capacity' => $capacity,
                                        'sold' => $sold,
                                        'available' => $available,
                                        'from_eventbrite' => true,
                                        'suggested' => true
                                    );
                                }

                                // Only add the first paid ticket for each event to avoid duplicates
                                break;
                            }
                        }
                    }
                }

                // Check for other times on the same day
                if (isset($connection_test['event_date'])) {
                    $date_to_check = $connection_test['event_date'];
                    $events_same_day = $eventbrite->get_events_for_date($date_to_check);

                    foreach ($events_same_day as $same_day_event) {
                        // Skip if already included
                        if (in_array($same_day_event['id'], $existing_event_ids)) {
                            continue;
                        }

                        // Check for title similarity
                        $title_similarity = 0;
                        similar_text(
                            strtolower(isset($same_day_event['name']['text']) ? $same_day_event['name']['text'] : ''),
                            strtolower($product_name),
                            $title_similarity
                        );

                        // Only include if title is reasonably similar (adjust threshold as needed)
                        if ($title_similarity > 60) {
                            if (isset($same_day_event['ticket_classes']) && is_array($same_day_event['ticket_classes'])) {
                                foreach ($same_day_event['ticket_classes'] as $ticket) {
                                    // Skip free tickets
                                    if (isset($ticket['free']) && $ticket['free']) {
                                        continue;
                                    }

                                    // Get event time
                                    $event_time = isset($same_day_event['start']['local']) ?
                                        date('H:i', strtotime($same_day_event['start']['local'])) : '';

                                    // Only add if the time is different from what we already have
                                    $unique_time = true;
                                    foreach ($dates as $existing_date) {
                                        if (
                                            $existing_date['date'] === $date_to_check &&
                                            BRCC_Helpers::is_time_close($existing_date['time'], $event_time)
                                        ) {
                                            $unique_time = false;
                                            break;
                                        }
                                    }

                                    if ($unique_time) {
                                        // Calculate inventory
                                        $capacity = isset($ticket['capacity_is_custom']) && $ticket['capacity_is_custom'] ?
                                            $ticket['capacity'] : (isset($same_day_event['capacity']) ? $same_day_event['capacity'] : 0);
                                        $sold = isset($ticket['quantity_sold']) ? $ticket['quantity_sold'] : 0;
                                        $available = $capacity - $sold;

                                        $dates[] = array(
                                            'date' => $date_to_check,
                                            'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_to_check)),
                                            'time' => $event_time,
                                            'formatted_time' => date('g:i A', strtotime($same_day_event['start']['local'])),
                                            'inventory' => $available,
                                            'eventbrite_id' => $ticket['id'],
                                            'eventbrite_event_id' => $same_day_event['id'],
                                            'eventbrite_name' => isset($same_day_event['name']['text']) ? $same_day_event['name']['text'] : '',
                                            'eventbrite_venue' => isset($same_day_event['venue']['name']) ? $same_day_event['venue']['name'] : '',
                                            'capacity' => $capacity,
                                            'sold' => $sold,
                                            'available' => $available,
                                            'from_eventbrite' => true,
                                            'suggested' => true
                                        );
                                    }

                                    // Only add the first paid ticket for each event
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $dates;
    }
    /**
     * Suggest Eventbrite ID based on product name, date and time
     * 
     * @param int $product_id Product ID
     * @param string $date Date in Y-m-d format
     * @param string $time Time in H:i format
     * @return string Suggested Eventbrite ID or empty string
     */
    public function suggest_eventbrite_id($product_id, $date, $time = null)
    {
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $eventbrite = new BRCC_Eventbrite_Integration();
        $suggestions = $eventbrite->suggest_eventbrite_ids_for_product($product, $date, $time);

        // Return the top suggestion's ticket_id
        if (!empty($suggestions) && isset($suggestions[0]['ticket_id'])) {
            return $suggestions[0]['ticket_id'];
        }

        return '';
    }

    /**
     * Get dates specifically from FooEvents
     * 
     * @param WC_Product $product Product object
     * @return array Array of dates with inventory
     */
    private function get_fooevents_dates($product)
    {
        $dates = array();
        $product_id = $product->get_id();

        // Check if FooEvents is active // Removed temporary debug log
        if (!function_exists('is_plugin_active') || !is_plugin_active('fooevents/fooevents.php')) {
            return $dates;
        }

        // --- START: Check for FooEvents Bookings Serialized Options ---
        // Check specifically for FooEvents Bookings options (serialized JSON)
        $serialized_options = get_post_meta($product_id, 'fooevents_bookings_options_serialized', true);

        if (!empty($serialized_options)) {
            $booking_options = json_decode($serialized_options, true); // Decode JSON string into an associative array

            if (is_array($booking_options)) {
                foreach ($booking_options as $session_id => $session_data) {
                    // Extract time for this session
                    $session_time_string = null;
                    if (isset($session_data['add_time']) && $session_data['add_time'] === 'enabled' && isset($session_data['hour']) && isset($session_data['minute'])) {
                        $hour = intval($session_data['hour']);
                        $minute = intval($session_data['minute']);
                        $period = isset($session_data['period']) ? strtolower($session_data['period']) : '';

                        if ($period === 'p.m.' && $hour < 12) {
                            $hour += 12;
                        } elseif ($period === 'a.m.' && $hour === 12) { // Handle 12 AM
                            $hour = 0;
                        }
                        // Ensure hour is within valid range after adjustment
                        $hour = $hour % 24;
                        $session_time_string = sprintf('%02d:%02d', $hour, $minute);
                    }

                    // Structure 1: Nested 'add_date' array (seen in product 4061 log)
                    if (isset($session_data['add_date']) && is_array($session_data['add_date'])) {
                        foreach ($session_data['add_date'] as $slot_id => $slot_data) {
                            if (isset($slot_data['date'])) {
                                $date_string = BRCC_Helpers::parse_date_value($slot_data['date']); // Use existing date parser
                                if ($date_string) {
                                    $formatted_time = $session_time_string ? date('g:i A', strtotime("1970-01-01 " . $session_time_string)) : '';
                                    $stock = isset($slot_data['stock']) ? intval($slot_data['stock']) : $product->get_stock_quantity();

                                    $dates[] = array(
                                        'date' => $date_string,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                                        'time' => $session_time_string, // Use the session's time
                                        'formatted_time' => $formatted_time,
                                        'inventory' => $stock,
                                        'source' => 'fooevents_bookings_serialized_s1' // Identify the source structure
                                    );
                                }
                            }
                        }
                    }
                    // Structure 2: Flat keys like '*_add_date' (seen in product 11192 log)
                    else {
                        foreach ($session_data as $key => $value) {
                            if (strpos($key, '_add_date') !== false && !empty($value)) { // Check key and ensure value is not empty
                                $date_string = BRCC_Helpers::parse_date_value($value);
                                if ($date_string) {
                                    $stock_key = str_replace('_add_date', '_stock', $key);
                                    $stock = isset($session_data[$stock_key]) ? intval($session_data[$stock_key]) : $product->get_stock_quantity();
                                    $formatted_time = $session_time_string ? date('g:i A', strtotime("1970-01-01 " . $session_time_string)) : '';

                                    $dates[] = array(
                                        'date' => $date_string,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                                        'time' => $session_time_string,
                                        'formatted_time' => $formatted_time,
                                        'inventory' => $stock,
                                        'source' => 'fooevents_bookings_serialized_s2' // Identify the source structure
                                    );
                                }
                            }
                        }
                    }
                }

                // If we found dates from the serialized options, return them
                if (!empty($dates)) {
                    // Sort dates chronologically before returning
                    usort($dates, function ($a, $b) {
                        $time_a = $a['time'] ? strtotime($a['date'] . ' ' . $a['time']) : strtotime($a['date']);
                        $time_b = $b['time'] ? strtotime($b['date'] . ' ' . $b['time']) : strtotime($b['date']);
                        return $time_a <=> $time_b;
                    });
                    return $dates;
                }
            }
        }
        // --- END: Check for FooEvents Bookings Serialized Options ---

        // If no serialized booking options found, proceed with original FooEvents date checks

        // FooEvents stores event dates differently based on type of event
        $event_type = get_post_meta($product_id, 'fooevents_event_type', true);

        // Single date event
        $event_date = get_post_meta($product_id, 'fooevents_event_date', true);
        if (!empty($event_date)) {
            $date_string = BRCC_Helpers::parse_date_value($event_date);
            if ($date_string) {
                $stock = $product->get_stock_quantity();
                // Try to get time
                $event_time = get_post_meta($product_id, 'fooevents_event_time', true);
                $time_string = $this->parse_time_value($event_time);

                $dates[] = array(
                    'date' => $date_string,
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                    'time' => $time_string,
                    'formatted_time' => $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '',
                    'inventory' => $stock,
                    'source' => 'fooevents_single'
                );
            }
        }

        // Multi-day event
        $event_dates = get_post_meta($product_id, 'fooevents_event_dates', true);
        if (!empty($event_dates) && is_array($event_dates)) {
            foreach ($event_dates as $event_date) {
                $date_string = BRCC_Helpers::parse_date_value($event_date);
                if ($date_string) {
                    // Each date may have a time
                    $event_times = get_post_meta($product_id, 'fooevents_event_times', true);
                    $time_string = '';
                    $formatted_time = '';

                    if (is_array($event_times) && isset($event_times[$date_string])) {
                        $time_string = $this->parse_time_value($event_times[$date_string]);
                        $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                    }

                    // Each date shares the same stock quantity in FooEvents
                    $stock = $product->get_stock_quantity();
                    $dates[] = array(
                        'date' => $date_string,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                        'time' => $time_string,
                        'formatted_time' => $formatted_time,
                        'inventory' => $stock,
                        'source' => 'fooevents_multi'
                    );
                }
            }
        }

        // Serialized event dates for multi-day events
        $serialized_dates = get_post_meta($product_id, 'fooevents_event_dates_serialized', true);
        if (!empty($serialized_dates)) {
            $unserialized_dates = maybe_unserialize($serialized_dates);
            if (is_array($unserialized_dates)) {
                foreach ($unserialized_dates as $index => $event_date) {
                    $date_string = BRCC_Helpers::parse_date_value($event_date);
                    if ($date_string) {
                        // Try to get time for this index
                        $serialized_times = get_post_meta($product_id, 'fooevents_event_times_serialized', true);
                        $unserialized_times = maybe_unserialize($serialized_times);
                        $time_string = '';
                        $formatted_time = '';

                        if (is_array($unserialized_times) && isset($unserialized_times[$index])) {
                            $time_string = $this->parse_time_value($unserialized_times[$index]);
                            $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                        }

                        $stock = $product->get_stock_quantity();
                        $dates[] = array(
                            'date' => $date_string,
                            'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                            'time' => $time_string,
                            'formatted_time' => $formatted_time,
                            'inventory' => $stock,
                            'source' => 'fooevents_serialized'
                        );
                    }
                }
            }
        }

        // Individual day inventories (Only in some versions of FooEvents)
        $day_slots = get_post_meta($product_id, 'fooevents_event_day_slots', true);
        if (!empty($day_slots) && is_array($day_slots)) {
            foreach ($day_slots as $date => $slot) {
                $date_string = BRCC_Helpers::parse_date_value($date);
                if ($date_string) {
                    // Try to get time
                    $time_string = '';
                    $formatted_time = '';
                    if (isset($slot['time'])) {
                        $time_string = $this->parse_time_value($slot['time']);
                        $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                    }

                    $dates[] = array(
                        'date' => $date_string,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                        'time' => $time_string,
                        'formatted_time' => $formatted_time,
                        'inventory' => isset($slot['stock']) ? $slot['stock'] : null,
                        'source' => 'fooevents_day_slots'
                    );
                }
            }
        }

        return $dates;
    }

    /**
     * Get booking slots from a product
     * 
     * @param WC_Product $product Product object
     * @return array Array of slots with date, time, and inventory
     */
    private function get_product_booking_slots($product)
    {
        $booking_slots = array();

        // Check if this is a SmartCrawl product with bookings
        $slots = $product->get_meta('_booking_slots');
        if (!empty($slots) && is_array($slots)) {
            foreach ($slots as $date => $slot_data) {
                $time = '';
                // Try to extract time from slot data
                if (isset($slot_data['time'])) {
                    $time = $slot_data['time'];
                }

                $booking_slots[] = array(
                    'date' => $date,
                    'time' => $time,
                    'inventory' => isset($slot_data['inventory']) ? $slot_data['inventory'] : null
                );
            }
            return $booking_slots;
        }

        // Try to get _wc_booking_availability (used by WooCommerce Bookings)
        $availability = $product->get_meta('_wc_booking_availability');
        if (!empty($availability) && is_array($availability)) {
            foreach ($availability as $slot) {
                if (isset($slot['from']) && isset($slot['to'])) {
                    $from_date = new DateTime($slot['from']);
                    $to_date = new DateTime($slot['to']);
                    $interval = new DateInterval('P1D');
                    $date_range = new DatePeriod($from_date, $interval, $to_date);

                    $time = '';
                    if (isset($slot['from_time']) && isset($slot['to_time'])) {
                        $time = $slot['from_time'];
                    }

                    foreach ($date_range as $date) {
                        $date_string = $date->format('Y-m-d');
                        $booking_slots[] = array(
                            'date' => $date_string,
                            'time' => $time,
                            'inventory' => isset($slot['qty']) ? $slot['qty'] : null
                        );
                    }
                }
            }
            return $booking_slots;
        }

        // For SmartCrawl products (from your screenshot)
        $bookings = $product->get_meta('_product_booking_slots');
        if (empty($bookings)) {
            // For products that use a different schema, look for properties like 'bookings' or 'slots'
            foreach (array('_wc_slots', '_event_slots', '_bookings', '_event_dates') as $possible_meta_key) {
                $meta_value = $product->get_meta($possible_meta_key);
                if (!empty($meta_value)) {
                    $bookings = $meta_value;
                    break;
                }
            }
        }

        if (!empty($bookings) && is_array($bookings)) {
            foreach ($bookings as $booking) {
                if (isset($booking['date'])) {
                    $time = '';
                    if (isset($booking['time'])) {
                        $time = $booking['time'];
                    } else if (isset($booking['hour']) && isset($booking['minute'])) {
                        $time = sprintf('%02d:%02d', $booking['hour'], $booking['minute']);
                    }

                    $inventory = null;
                    if (isset($booking['stock'])) {
                        $inventory = $booking['stock'];
                    } elseif (isset($booking['inventory'])) {
                        $inventory = $booking['inventory'];
                    } elseif (isset($booking['quantity'])) {
                        $inventory = $booking['quantity'];
                    }

                    $booking_slots[] = array(
                        'date' => $booking['date'],
                        'time' => $time,
                        'inventory' => $inventory
                    );
                }
            }
            return $booking_slots;
        }

        // If no booking-specific data found, get product variations based on date attributes
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();

            foreach ($variations as $variation_data) {
                $variation_id = $variation_data['variation_id'];
                $variation = wc_get_product($variation_id);

                if (!$variation) continue;

                // Look for date-related attributes
                $attributes = $variation->get_attributes();
                $date_attr = null;
                $time_attr = null;

                foreach ($attributes as $attr_name => $attr_value) {
                    $lower_name = strtolower($attr_name);
                    if (strpos($lower_name, 'date') !== false || strpos($lower_name, 'day') !== false) {
                        $date_attr = BRCC_Helpers::parse_date_value($attr_value);
                    } else if (strpos($lower_name, 'time') !== false || strpos($lower_name, 'hour') !== false) {
                        $time_attr = $this->parse_time_value($attr_value);
                    }
                }

                if ($date_attr) {
                    $booking_slots[] = array(
                        'date' => $date_attr,
                        'time' => $time_attr ?: '',
                        'inventory' => $variation->get_stock_quantity()
                    );
                }
            }

            if (!empty($booking_slots)) {
                return $booking_slots;
            }
        }

        // Check product name for dates as a fallback
        $product_name = $product->get_name();
        $day_name = BRCC_Helpers::extract_day_from_title($product_name);
        $time_info = BRCC_Helpers::extract_time_from_title($product_name);

        if ($day_name) {
            $upcoming_dates = BRCC_Helpers::get_upcoming_dates_for_day($day_name);
            foreach ($upcoming_dates as $date) {
                $booking_slots[] = array(
                    'date' => $date,
                    'time' => $time_info ?: '',
                    'inventory' => null // Can't determine stock this way
                );
            }

            if (!empty($booking_slots)) {
                return $booking_slots;
            }
        }

        return $booking_slots;
    }


    /**
     * AJAX: Get product dates including all time slots
     */
    public function ajax_get_product_dates()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get existing mappings
        $all_mappings = get_option('brcc_product_mappings', array());
        $date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();

        // Get base Eventbrite ID for suggestions
        $base_mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array('eventbrite_id' => '');
        $base_id = !empty($base_mapping['eventbrite_id']) ? $base_mapping['eventbrite_id'] : '';

        // Check if we should fetch from Eventbrite
        $fetch_from_eventbrite = isset($_POST['fetch_from_eventbrite']) && $_POST['fetch_from_eventbrite'] == 'true';

        if ($fetch_from_eventbrite && !empty($base_id)) {
            // Get dates from Eventbrite
            $eventbrite_dates = $this->get_product_dates_from_eventbrite($product_id, $base_id);

            // If we got dates from Eventbrite, use those
            if (!empty($eventbrite_dates)) {
                wp_send_json_success(array(
                    'dates' => $eventbrite_dates,
                    'base_id' => $base_id,
                    'source' => 'eventbrite',
                    'availableTimes' => $this->get_common_times()
                ));
                return;
            }
        }

        // Get product intelligent dates
        $dates = $this->get_product_dates($product_id, true);

        // Add existing time-based mappings if they don't already exist in the dates array
        foreach ($date_mappings as $key => $mapping) {
            // Check if this is a date-time mapping (key contains an underscore)
            if (strpos($key, '_') !== false) {
                list($date, $time) = explode('_', $key, 2);

                // Check if this date already exists in our list
                $date_exists = false;
                foreach ($dates as $existing_date) {
                    if ($existing_date['date'] === $date) {
                        $date_exists = true;
                        break;
                    }
                }

                // If date doesn't exist, add it
                if (!$date_exists) {
                    $dates[] = array(
                        'date' => $date,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                        'time' => $time,
                        'formatted_time' => date('g:i A', strtotime("1970-01-01 $time")),
                        'inventory' => null,
                        'eventbrite_id' => $mapping['eventbrite_id'],
                        'square_id' => isset($mapping['square_id']) ? $mapping['square_id'] : '',
                        'from_mappings' => true
                    );
                }
            }
        }

        // Add mapping data to dates
        foreach ($dates as &$date_data) {
            if (isset($date_data['date'])) {
                $date = $date_data['date'];
                $time = isset($date_data['time']) ? $date_data['time'] : '';
                $key = $time ? $date . '_' . $time : $date;

                if (isset($date_mappings[$key])) {
                    $date_data['eventbrite_id'] = $date_mappings[$key]['eventbrite_id'];
                    $date_data['square_id'] = isset($date_mappings[$key]['square_id']) ? $date_mappings[$key]['square_id'] : '';
                } elseif (isset($date_mappings[$date])) {
                    // Fallback to date-only mapping if time-specific one isn't found
                    $date_data['eventbrite_id'] = $date_mappings[$date]['eventbrite_id'];
                    $date_data['square_id'] = isset($date_mappings[$date]['square_id']) ? $date_mappings[$date]['square_id'] : '';
                } else {
                    // If no mapping exists and we have a base ID, suggest one
                    if (!empty($base_id)) {
                        $date_data['eventbrite_id'] = $this->suggest_eventbrite_id($product_id, $date, $time);

                        // Mark as a suggestion
                        if (!empty($date_data['eventbrite_id'])) {
                            $date_data['suggestion'] = true;
                        }
                    } else {
                        $date_data['eventbrite_id'] = '';
                        $date_data['square_id'] = '';
                    }
                }
            }
        }

        wp_send_json_success(array(
            'dates' => $dates,
            'base_id' => $base_id,
            'source' => 'intelligent',
            'availableTimes' => $this->get_common_times()
        ));
    }
    /**
     * Get common time slots for dropdown
     * 
     * @return array Array of time options
     */
    private function get_common_times()
    {
        $times = array();

        // Add common time slots
        for ($hour = 8; $hour <= 23; $hour++) {
            $hour_12 = $hour % 12;
            if ($hour_12 == 0) $hour_12 = 12;
            $ampm = $hour >= 12 ? 'PM' : 'AM';

            // Add full hour
            $time_24h = sprintf('%02d:00', $hour);
            $time_12h = $hour_12 . ':00 ' . $ampm;
            $times[] = array(
                'value' => $time_24h,
                'label' => $time_12h
            );

            // Add half hour
            $time_24h = sprintf('%02d:30', $hour);
            $time_12h = $hour_12 . ':30 ' . $ampm;
            $times[] = array(
                'value' => $time_24h,
                'label' => $time_12h
            );
        }

        return $times;
    }
    /**
     * AJAX: Test product date mapping with time support
     */
    public function ajax_test_product_date_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
        $eventbrite_id = isset($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : '';

        if (empty($product_id) || empty($date)) {
            wp_send_json_error(array('message' => __('Product ID and date are required.', 'brcc-inventory-tracker')));
            return;
        }

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Add date info
        $date_info = !empty($date) ? " (" . date_i18n(get_option('date_format'), strtotime($date)) . ")" : "";
        if (!empty($time)) {
            $time_obj = DateTime::createFromFormat('H:i', $time);
            if ($time_obj) {
                $date_info .= " " . $time_obj->format('g:i A');
            } else {
                $date_info .= " " . $time;
            }
        }

        // Log test action
        if (function_exists('BRCC_Helpers::is_test_mode') && BRCC_Helpers::is_test_mode()) {
            if (!empty($eventbrite_id) && function_exists('BRCC_Helpers::log_operation')) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s%s with Eventbrite ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $date_info,
                        $eventbrite_id
                    )
                );
            }
        } else if (function_exists('BRCC_Helpers::should_log') && BRCC_Helpers::should_log() && function_exists('BRCC_Helpers::log_operation')) {
            if (!empty($eventbrite_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s%s with Eventbrite ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $date_info,
                        $eventbrite_id
                    )
                );
            }
        }

        // Basic validation for Eventbrite ID
        if (empty($eventbrite_id)) {
            $results[] = __('No Eventbrite ID provided. Please enter an Eventbrite ticket ID to test the connection.', 'brcc-inventory-tracker');

            wp_send_json_success(array(
                'message' => implode('<br>', $results),
                'status' => 'warning'
            ));
            return;
        }

        // Check if Eventbrite is properly configured
        $settings = get_option('brcc_api_settings');
        $has_eventbrite_token = !empty($settings['eventbrite_token']);

        if (!$has_eventbrite_token) {
            $results[] = __('Eventbrite configuration incomplete. Please add API Token in plugin settings.', 'brcc-inventory-tracker');

            wp_send_json_success(array(
                'message' => implode('<br>', $results),
                'status' => 'error'
            ));
            return;
        }

        // Test connection to Eventbrite
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            $results[] = __('Eventbrite integration class not found. Please check your installation.', 'brcc-inventory-tracker');

            wp_send_json_success(array(
                'message' => implode('<br>', $results),
                'status' => 'error'
            ));
            return;
        }

        $eventbrite = new BRCC_Eventbrite_Integration();
        $ticket_test = $eventbrite->test_ticket_connection($eventbrite_id);

        if (is_wp_error($ticket_test)) {
            $results[] = sprintf(
                __('Eventbrite API test failed: %s', 'brcc-inventory-tracker'),
                $ticket_test->get_error_message()
            );

            wp_send_json_success(array(
                'message' => implode('<br>', $results),
                'status' => 'error'
            ));
            return;
        }

        // Connection successful, show ticket details
        $results[] = sprintf(
            __('<strong>Success!</strong> Eventbrite ticket "%s" is linked to product "%s"%s', 'brcc-inventory-tracker'),
            isset($ticket_test['ticket_name']) ? $ticket_test['ticket_name'] : $eventbrite_id,
            $product_name,
            $date_info
        );

        // Add event details
        $results[] = sprintf(
            __('Event: %s', 'brcc-inventory-tracker'),
            isset($ticket_test['event_name']) ? $ticket_test['event_name'] : 'Unknown Event'
        );

        // Add date and venue
        if (isset($ticket_test['event_date']) && !empty($ticket_test['event_date'])) {
            $formatted_date = date_i18n(get_option('date_format'), strtotime($ticket_test['event_date']));
            $formatted_time = isset($ticket_test['event_time']) && !empty($ticket_test['event_time']) ?
                date('g:i A', strtotime("1970-01-01 " . $ticket_test['event_time'])) : '';

            $results[] = sprintf(
                __('Date: %s %s', 'brcc-inventory-tracker'),
                $formatted_date,
                $formatted_time
            );

            // If the time doesn't match the requested time, add a warning
            if (
                !empty($time) && !empty($ticket_test['event_time']) &&
                !BRCC_Helpers::is_time_close($time, $ticket_test['event_time'])
            ) {
                $results[] = sprintf(
                    __('<strong>Warning:</strong> The event time (%s) does not match the mapping time (%s).', 'brcc-inventory-tracker'),
                    date('g:i A', strtotime("1970-01-01 " . $ticket_test['event_time'])),
                    date('g:i A', strtotime("1970-01-01 " . $time))
                );
            }
        }

        if (isset($ticket_test['venue_name']) && !empty($ticket_test['venue_name'])) {
            $results[] = sprintf(
                __('Venue: %s', 'brcc-inventory-tracker'),
                $ticket_test['venue_name']
            );
        }

        // Add inventory details
        if (isset($ticket_test['available'])) {
            $results[] = sprintf(
                __('Available tickets: %d (Capacity: %d, Sold: %d)', 'brcc-inventory-tracker'),
                $ticket_test['available'],
                isset($ticket_test['capacity']) ? $ticket_test['capacity'] : $ticket_test['available'],
                isset($ticket_test['sold']) ? $ticket_test['sold'] : 0
            );
        }

        // Add test mode notice
        if (function_exists('BRCC_Helpers::is_test_mode') && BRCC_Helpers::is_test_mode()) {
            $results[] = __('Note: Tests work normally even in Test Mode.', 'brcc-inventory-tracker');
        }

        // Return success
        wp_send_json_success(array(
            'message' => implode('<br>', $results),
            'status' => 'success',
            'details' => $ticket_test
        ));
    }
}
