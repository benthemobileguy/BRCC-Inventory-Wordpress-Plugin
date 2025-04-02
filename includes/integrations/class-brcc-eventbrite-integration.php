<?php
/**
 * BRCC Eventbrite Integration Class
 * 
 * Handles integration with Eventbrite API for ticket updates with enhanced date and time support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Eventbrite_Integration {
    /**
     * Eventbrite API base URL
     */
    private $api_url = 'https://www.eventbriteapi.com/v3';
    
    /**
     * Eventbrite API Token
     */
    private $api_token;
    
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
        
        // Set API token
        $this->api_token = isset($settings['eventbrite_token']) ? $settings['eventbrite_token'] : '';
        
        // Initialize product mappings
        $this->product_mappings = new BRCC_Product_Mappings();
        
        // Add hooks
        if (!empty($this->api_token)) {
            // Hook into product sale with date and time support
            add_action('brcc_product_sold_with_date', array($this, 'update_eventbrite_ticket_with_date'), 10, 4);
            
            // Original action for backward compatibility
            add_action('brcc_product_sold', array($this, 'update_eventbrite_ticket'), 10, 2);
            
            // Hook into inventory sync
            add_action('brcc_sync_inventory', array($this, 'sync_eventbrite_tickets'));
        }
    }

    /**
     * Get all events from Eventbrite for organization
     * 
     * @param string $status Event status ('live', 'draft', 'started', 'ended', 'completed', 'canceled')
     * @param int $page_size Number of events per page
     * @param bool $include_series Whether to include series parent events
     * @return array|WP_Error Array of events or WP_Error on failure
     */
    public function get_organization_events($status = 'live', $page_size = 50, $include_series = true) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        
        // First, get the user's organization ID
        BRCC_Helpers::log_debug('get_organization_events: Calling get_organization_id()');
        $organization_id = $this->get_organization_id();
        BRCC_Helpers::log_debug('get_organization_events: Result of get_organization_id()', $organization_id);
        
        if (is_wp_error($organization_id)) {
            BRCC_Helpers::log_error('get_organization_events: Failed to get Organization ID.', $organization_id);
            return $organization_id; // Return the WP_Error object
        }
        
        // Prepare URL with query parameters
        $url = $this->api_url . '/organizations/' . $organization_id . '/events/';
        $params = array(
            'status' => $status,
            'page_size' => $page_size,
            'expand' => 'venue,ticket_classes',
        );
        
        if ($include_series) {
            $params['include_series'] = 'true';
        }
        
        $url = add_query_arg($params, $url);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15, // Increased timeout for potentially large responses
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        BRCC_Helpers::log_debug('Eventbrite API Response (/organizations/.../events/):', $body); // Log the response body
        
        if (isset($body['error'])) {
            return new WP_Error(
                'eventbrite_api_error',
                isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
            );
        }
        
        // If we don't have events key in the response, something went wrong
        if (!isset($body['events'])) {
            return new WP_Error(
                'eventbrite_invalid_response',
                __('Invalid response from Eventbrite API', 'brcc-inventory-tracker')
            );
        }
        
        // Handle pagination if needed
        $events = $body['events'];
        $pagination = isset($body['pagination']) ? $body['pagination'] : null;
        
        if ($pagination && isset($pagination['has_more_items']) && $pagination['has_more_items'] && isset($pagination['page_number'])) {
            // If there are more pages, we could fetch them recursively
            // However, for performance, we'll just log this and let the user know
            if (BRCC_Helpers::should_log()) {
                BRCC_Helpers::log_info(sprintf(
                    __('Note: There are more Eventbrite events available than returned (%d of %d total). Consider increasing page_size parameter.', 'brcc-inventory-tracker'),
                    count($events),
                    $pagination['object_count']
                ));
            }
        }
        
        return $events;
    }
    
    /**
     * Get the organization ID for the current user
     * 
     * @return string|WP_Error Organization ID or WP_Error on failure
     */
    public function get_organization_id() {
        // 1. Try to get the Organization ID from plugin settings first
        $settings = get_option('brcc_api_settings');
        $org_id_from_settings = isset($settings['eventbrite_org_id']) ? trim($settings['eventbrite_org_id']) : '';

        if (!empty($org_id_from_settings)) {
             BRCC_Helpers::log_debug('get_organization_id: Using Org ID from settings.', $org_id_from_settings);
             return $org_id_from_settings;
        }

        // 2. Fallback: Try to get it from the /users/me/ endpoint (current method, often fails)
        BRCC_Helpers::log_debug('get_organization_id: Org ID not in settings, attempting fallback via /users/me/');
        $user_info = $this->get_user_info();
        
        if (is_wp_error($user_info)) {
             BRCC_Helpers::log_error('get_organization_id: Fallback failed - Error fetching /users/me/', $user_info);
             return $user_info; // Return the error from get_user_info
        }
        
        // Check for an organization ID in the user info response
        if (isset($user_info['organizations']) && is_array($user_info['organizations']) && !empty($user_info['organizations']) && isset($user_info['organizations'][0]['id'])) {
             $org_id_from_api = $user_info['organizations'][0]['id'];
             BRCC_Helpers::log_debug('get_organization_id: Fallback success - Found Org ID via /users/me/', $org_id_from_api);
             return $org_id_from_api;
        }
        
      // 3. If neither method worked, return an error
      $error_message = __('Eventbrite Organization ID not found. Please enter it in the plugin settings.', 'brcc-inventory-tracker');
      BRCC_Helpers::log_error('get_organization_id: Fallback failed - Could not find Org ID in /users/me/ response or settings.');
      return new WP_Error('no_organization', $error_message);
    }
    
    /**
     * Get information about the authenticated user
     * 
     * @return array|WP_Error User info or WP_Error on failure
     */
    public function get_user_info() {
        $url = $this->api_url . '/users/me/';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        BRCC_Helpers::log_debug('Eventbrite API Response (/users/me/):', $body); // Log the response body
        
        if (isset($body['error'])) {
            return new WP_Error(
                'eventbrite_api_error',
                isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
            );
        }
        
      return $body;
  }
  
  /**
   * Get all events that match a certain day of the week
   *
     * @param string $day_name Day name (Sunday, Monday, etc.)
     * @param string $status Event status (live, draft, etc.)
     * @return array Array of events that fall on the specified day
     */
    public function get_events_by_day($day_name, $status = 'live') {
        $events = $this->get_organization_events($status);
        
        if (is_wp_error($events)) {
            return array();
        }
        
        $day_name = strtolower($day_name);
        $matching_events = array();
        
        foreach ($events as $event) {
            if (isset($event['start']['local'])) {
                $event_timestamp = strtotime($event['start']['local']);
                $event_day = strtolower(date('l', $event_timestamp)); // Get day name
                
                // Check if this event is on the requested day
                if ($event_day === $day_name) {
                    $matching_events[] = $event;
                }
            }
        }
        
        return $matching_events;
    }
    
/**
 * Suggest Eventbrite event IDs based on product name, date and time
 * 
 * @param WC_Product $product WooCommerce product
 * @param string $date Date in Y-m-d format
 * @param string $time Optional time in H:i format
 * @return array Array of suggested event IDs with details
 */
public function suggest_eventbrite_ids_for_product($product, $date, $time = null) {
    if (!$product) {
        return array();
    }
    
    $product_name = $product->get_name();
    // Note: product_mappings instance might not have this method anymore after refactoring
    // $day_name = $this->product_mappings->extract_day_from_title($product_name);
    // Use the helper directly
    $day_name = BRCC_Helpers::extract_day_from_title($product_name);
    
    // If no day name found in product title, try to extract day from the date
    if (!$day_name && !empty($date)) {
        $day_name = strtolower(date('l', strtotime($date)));
    }
    
    // Fetch all live and started events for the organization
    BRCC_Helpers::log_operation('Eventbrite Suggest', 'Fetch Events', "Calling get_organization_events('live,started')");
    $all_events = $this->get_organization_events('live,started');
    $suggestions = array();
    $product_name_lower = strtolower($product_name);

    if (is_wp_error($all_events)) {
        BRCC_Helpers::log_error('Suggest Eventbrite IDs: Failed to fetch organization events - ' . $all_events->get_error_message());
        // Log error or return empty if fetching failed
        BRCC_Helpers::log_error('Suggest Eventbrite IDs: Failed to fetch organization events - ' . $all_events->get_error_message());
        return $suggestions; // Return empty array on error
    }

    $event_count = count($all_events);
    BRCC_Helpers::log_operation('Eventbrite Suggest', 'Events Fetched', "Found {$event_count} live/started events.");

    // Format target date if provided
    $formatted_target_date = $date ? date('Y-m-d', strtotime($date)) : null;

    foreach ($all_events as $event) {
        $event_id = $event['id'];
        $event_name = isset($event['name']['text']) ? $event['name']['text'] : '';
        $event_name_lower = strtolower($event_name);
        $event_date = isset($event['start']['local']) ? date('Y-m-d', strtotime($event['start']['local'])) : '';
        $event_time = isset($event['start']['local']) ? date('H:i', strtotime($event['start']['local'])) : '';
        $venue_name = isset($event['venue']['name']) ? $event['venue']['name'] : '';

        // Calculate name similarity score (higher is better)
        $name_similarity = 0;
        if ($event_name_lower && $product_name_lower) {
            similar_text($event_name_lower, $product_name_lower, $name_similarity);
        }

        // Skip if similarity is too low (e.g., less than 30%)
        if ($name_similarity < 30) {
            continue;
        }

        // Check time match if target time is provided
        $time_match = true; // Assume true if no target time
        if ($time && $event_time) {
            $time_match = BRCC_Helpers::is_time_close($time, $event_time);
        }

        // Check date match if target date is provided
        $date_match = true; // Assume true if no target date
        $date_diff = 1000; // High default diff
        if ($formatted_target_date && $event_date) {
            $date_match = ($event_date === $formatted_target_date);
            $date_diff = abs(strtotime($event_date) - strtotime($formatted_target_date)) / 86400; // Difference in days
        }

        // Calculate relevance score - prioritize name similarity, then time match, then date match
        $relevance = $name_similarity * 2; // Weight name similarity higher
        if ($time_match && $time) $relevance += 50; // Boost if time matches target
        if ($date_match && $formatted_target_date) $relevance += 25; // Smaller boost for date match
        $relevance -= $date_diff; // Penalize date difference slightly

        // Process ticket classes for this event
        if (isset($event['ticket_classes']) && is_array($event['ticket_classes'])) {
            foreach ($event['ticket_classes'] as $ticket) {
                if (isset($ticket['free']) && $ticket['free']) continue; // Skip free tickets

                $ticket_id = $ticket['id'];
                $ticket_name = $ticket['name'];

                $suggestions[] = array(
                    'event_id' => $event_id,
                    'ticket_id' => $ticket_id,
                    'event_name' => $event_name,
                    'ticket_name' => $ticket_name,
                    'event_date' => $event_date,
                    'event_time' => $event_time,
                    'venue_name' => $venue_name,
                    'name_similarity' => round($name_similarity, 2),
                    'relevance' => round($relevance, 2),
                    'is_exact_date_match' => $date_match && $formatted_target_date,
                    'is_close_time_match' => $time_match && $time,
                );
            }
        }
    }

    // Sort by relevance (higher first)
    usort($suggestions, function($a, $b) {
        return $b['relevance'] <=> $a['relevance'];
    });

    // Limit suggestions (e.g., top 5)
    $final_suggestions = array_slice($suggestions, 0, 5);
    // Get product ID if not already available in this scope (it was passed into the function)
    $product_id_for_log = $product->get_id();
    BRCC_Helpers::log_operation('Eventbrite Suggest', 'End Suggestion', "Returning " . count($final_suggestions) . " suggestions for Product ID: {$product_id_for_log}.");
    return $final_suggestions;
}

/**
 * Get Eventbrite ticket information
 */
public function get_eventbrite_ticket($ticket_id) {
    // Prepare API request
    $url = $this->api_url . '/ticket_classes/' . $ticket_id . '/';
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ),
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        return new WP_Error(
            'eventbrite_api_error',
            isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
        );
    }
    
    return $body;
}

/**
 * Get Eventbrite event details
 */
public function get_eventbrite_event($event_id) {
    $url = $this->api_url . '/events/' . $event_id . '/?expand=venue,ticket_classes';
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ),
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        return new WP_Error(
            'eventbrite_api_error',
            isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
        );
    }
    
    return $body;
}
/**
 * Get events for a specific date
 * 
 * @param string $date Date in Y-m-d format
 * @param string $status Event status ('live', 'draft', 'started', 'ended', 'completed', 'canceled')
 * @return array Array of events that fall on the specified date
 */
public function get_events_for_date($date, $status = 'live') {
    $events = $this->get_organization_events($status);
    
    if (is_wp_error($events)) {
        return array();
    }
    
    $date = date('Y-m-d', strtotime($date));
    $matching_events = array();
    
    foreach ($events as $event) {
        if (isset($event['start']['local'])) {
            $event_date = date('Y-m-d', strtotime($event['start']['local']));
            
            // Check if this event is on the requested date
            if ($event_date === $date) {
                $matching_events[] = $event;
            }
        }
    }
    
    return $matching_events;
}
/**
 * Convert UTC date to Toronto time
 * 
 * @param string $utc_timestamp UTC timestamp
 * @return DateTime Date in Toronto timezone
 */
private function convert_to_toronto_time($utc_timestamp) {
    $date = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone(BRCC_Constants::TORONTO_TIMEZONE));
    return $date;
}
/**
 * Update Eventbrite ticket with date and time specificity when product is sold
 */
public function update_eventbrite_ticket_with_date($order_id, $product_id, $quantity, $booking_date, $booking_time = null) {
    // If booking_time is not provided, try to extract it from the order
    if ($booking_time === null) {
        $booking_time = $this->extract_time_from_order($order_id, $product_id);
    }
    
    // Get product mapping based on product ID, booking date, and possibly time
    $mapping = $this->product_mappings->get_product_mappings($product_id, $booking_date, $booking_time);
    
    // If no specific mapping found, try without time
    if (empty($mapping['eventbrite_id']) && $booking_time) {
        $mapping = $this->product_mappings->get_product_mappings($product_id, $booking_date);
    }
    
    // Skip if no Eventbrite ID mapping exists
    if (empty($mapping['eventbrite_id'])) {
        return;
    }
    
    $eventbrite_id = $mapping['eventbrite_id'];
    
    // Check if test mode is enabled
    if (BRCC_Helpers::is_test_mode()) {
        $date_info = $booking_date ? " for date {$booking_date}" : "";
        $time_info = $booking_time ? " time {$booking_time}" : "";
        BRCC_Helpers::log_operation(
            'Eventbrite',
            'Update Ticket',
            sprintf(__('Order #%s: Would update Eventbrite ticket for product ID %s%s%s (Ticket ID: %s) reducing by %s units', 'brcc-inventory-tracker'),
                $order_id,
                $product_id,
                $date_info,
                $time_info,
                $eventbrite_id,
                $quantity
            )
        );
        return;
    } else if (BRCC_Helpers::should_log()) {
        $date_info = $booking_date ? " for date {$booking_date}" : "";
        $time_info = $booking_time ? " time {$booking_time}" : "";
        BRCC_Helpers::log_operation(
            'Eventbrite',
            'Update Ticket',
            sprintf(__('Order #%s: Updating Eventbrite ticket for product ID %s%s%s (Ticket ID: %s) reducing by %s units (Live Mode)', 'brcc-inventory-tracker'),
                $order_id,
                $product_id,
                $date_info,
                $time_info,
                $eventbrite_id,
                $quantity
            )
        );
    }
    
    // Get current ticket capacity from Eventbrite
    $ticket_info = $this->get_eventbrite_ticket($eventbrite_id);
    
    if (is_wp_error($ticket_info)) {
        // Log error
        BRCC_Helpers::log_error(sprintf(
            __('Error getting Eventbrite ticket for product ID %s%s%s (Ticket ID: %s): %s', 'brcc-inventory-tracker'),
            $product_id,
            $booking_date ? ' date ' . $booking_date : '',
            $booking_time ? ' time ' . $booking_time : '',
            $eventbrite_id,
            $ticket_info->get_error_message()
        ));
        return;
    }
    
    // Calculate new capacity
    $current_capacity = isset($ticket_info['capacity_is_custom']) && $ticket_info['capacity_is_custom'] 
        ? $ticket_info['capacity'] 
        : $ticket_info['event_capacity'];
        
    $sold = isset($ticket_info['quantity_sold']) ? $ticket_info['quantity_sold'] : 0;
    $available = $current_capacity - $sold;
    $new_capacity = $current_capacity - $quantity;
    
    // Make sure we don't go below zero
    if ($new_capacity < $sold) {
        $new_capacity = $sold;
    }
    
    // Update ticket capacity
    $result = $this->update_eventbrite_ticket_capacity($eventbrite_id, $new_capacity);
    
    if (is_wp_error($result)) {
        // Log error
        BRCC_Helpers::log_error(sprintf(
            __('Error updating Eventbrite ticket for product ID %s%s%s (Ticket ID: %s): %s', 'brcc-inventory-tracker'),
            $product_id,
            $booking_date ? ' date ' . $booking_date : '',
            $booking_time ? ' time ' . $booking_time : '',
            $eventbrite_id,
            $result->get_error_message()
        ));
    } else {
        // Log success
        BRCC_Helpers::log_info(sprintf(
            __('Successfully updated Eventbrite ticket for product ID %s%s%s (Ticket ID: %s): Reduced capacity from %s to %s', 'brcc-inventory-tracker'),
            $product_id,
            $booking_date ? ' date ' . $booking_date : '',
            $booking_time ? ' time ' . $booking_time : '',
            $eventbrite_id,
            $current_capacity,
            $new_capacity
        ));
    }
}

/**
 * Extract time from order for a specific product
 * 
 * @param int $order_id WooCommerce order ID
 * @param int $product_id Product ID
 * @return string|null Time in H:i format or null if not found
 */
private function extract_time_from_order($order_id, $product_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return null;
    }
    
    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $product_id) {
            // Check for time in meta
            $time_meta_keys = array(
                'event_time', 
                'ticket_time', 
                'booking_time', 
                'pa_time', 
                'time', 
                '_event_time', 
                '_booking_time',
                'Event Time',
                'Ticket Time',
                'Show Time',
                'Performance Time'
            );
            
            foreach ($time_meta_keys as $key) {
                $time_value = $item->get_meta($key);
                if (!empty($time_value)) {
                    // Parse time to H:i format
                    $timestamp = strtotime($time_value);
                    if ($timestamp !== false) {
                        return date('H:i', $timestamp);
                    }
                }
            }
            
            // Check if any meta contains 'time'
            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $meta) {
                $data = $meta->get_data();
                if (strpos(strtolower($data['key']), 'time') !== false) {
                    $timestamp = strtotime($data['value']);
                    if ($timestamp !== false) {
                        return date('H:i', $timestamp);
                    }
                }
            }
            
            // Break after finding the right item
            break;
        }
    }
    
    return null;
}

/**
 * Update Eventbrite ticket when product is sold (backward compatibility)
 */
public function update_eventbrite_ticket($order_id, $order) {
    // Extract product information from the order
    $items = $order->get_items();
    
    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        $quantity = $item->get_quantity();
        
        // Get booking date if available
        $booking_date = $this->extract_booking_date($item);
        
        // Get booking time if available
        $booking_time = $this->extract_booking_time($item);
        
        // Call the new date-aware method
        $this->update_eventbrite_ticket_with_date($order_id, $product_id, $quantity, $booking_date, $booking_time);
    }
}

/**
 * Extract booking time from order item
 * 
 * @param WC_Order_Item $item Order item
 * @return string|null Booking time in H:i format or null if not found
 */
private function extract_booking_time($item) {
    // Check for time meta
    $time_meta_keys = array(
        'event_time',
        'ticket_time',
        'booking_time',
        'pa_time',
        'time',
        '_event_time',
        '_booking_time',
        'Event Time',
        'Ticket Time',
        'Show Time',
        'Performance Time'
    );
    
    foreach ($time_meta_keys as $key) {
        $time_value = $item->get_meta($key);
        if (!empty($time_value)) {
            // Try to parse time
            $timestamp = strtotime($time_value);
            if ($timestamp !== false) {
                return date('H:i', $timestamp);
            }
        }
    }
    
    // Check all meta data for time-related fields
    $item_meta = $item->get_meta_data();
    foreach ($item_meta as $meta) {
        $meta_data = $meta->get_data();
        $key = $meta_data['key'];
        $value = $meta_data['value'];
        
        if (strpos(strtolower($key), 'time') !== false || 
            strpos(strtolower($key), 'hour') !== false ||
            strpos(strtolower($key), 'clock') !== false) {
            
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('H:i', $timestamp);
            }
        }
    }
    
    return null;
}

/**
 * Extract booking date from order item
 * 
 * @param WC_Order_Item $item Order item
 * @return string|null Booking date in Y-m-d format or null if not found
 */
private function extract_booking_date($item) {
    // Check for FooEvents specific date meta first
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
    
    return null;
}



/**
 * Sync Eventbrite tickets with date-time-specific handling
 */
public function sync_eventbrite_tickets() {
    // Get all products with their date-time-specific mappings
    $all_mappings = $this->get_all_date_time_specific_mappings();
    
    foreach ($all_mappings as $mapping) {
        $product_id = $mapping['product_id'];
        $eventbrite_id = $mapping['eventbrite_id'];
        $booking_date = $mapping['booking_date'];
        $booking_time = $mapping['booking_time'];
        
        if (empty($eventbrite_id)) {
            continue;
        }
        
        // Get product
        $product = wc_get_product($product_id);
        
        if (!$product) {
            continue;
        }
        
        // Get Eventbrite ticket info
        $ticket_info = $this->get_eventbrite_ticket($eventbrite_id);
        
        if (is_wp_error($ticket_info)) {
            // Log error
            BRCC_Helpers::log_error(sprintf(
                __('Error getting Eventbrite ticket for product ID %s%s%s (Ticket ID: %s): %s', 'brcc-inventory-tracker'),
                $product_id,
                $booking_date ? ' date ' . $booking_date : '',
                $booking_time ? ' time ' . $booking_time : '',
                $eventbrite_id,
                $ticket_info->get_error_message()
            ));
            continue;
        }
        
        // Calculate available tickets
        $capacity = isset($ticket_info['capacity_is_custom']) && $ticket_info['capacity_is_custom'] 
            ? $ticket_info['capacity'] 
            : $ticket_info['event_capacity'];
            
        $sold = isset($ticket_info['quantity_sold']) ? $ticket_info['quantity_sold'] : 0;
        $available = $capacity - $sold;
        
        // Update WooCommerce stock based on date and time
        if ($booking_date) {
            // Get current inventory for this date/time
            $current_stock = $this->get_date_time_specific_inventory($product_id, $booking_date, $booking_time);
            
            if ($current_stock !== $available) {
                // Check if test mode is enabled
                if (BRCC_Helpers::is_test_mode()) {
                    BRCC_Helpers::log_operation(
                        'Eventbrite',
                        'Sync Date Ticket',
                        sprintf(__('Would update WooCommerce stock for product ID %s date %s%s from %s to %s based on Eventbrite availability', 'brcc-inventory-tracker'),
                            $product_id,
                            $booking_date,
                            $booking_time ? ' time ' . $booking_time : '',
                            $current_stock,
                            $available
                        )
                    );
                    continue;
                } else if (BRCC_Helpers::should_log()) {
                    BRCC_Helpers::log_operation(
                        'Eventbrite',
                        'Sync Date Ticket',
                        sprintf(__('Updating WooCommerce stock for product ID %s date %s%s from %s to %s based on Eventbrite availability (Live Mode)', 'brcc-inventory-tracker'),
                            $product_id,
                            $booking_date,
                            $booking_time ? ' time ' . $booking_time : '',
                            $current_stock,
                            $available
                        )
                    );
                }
                
                // Update date-specific inventory
                $this->update_date_time_specific_inventory($product_id, $booking_date, $booking_time, $available);
                
                // Log update
                BRCC_Helpers::log_info(sprintf(
                    __('Updated WooCommerce date-specific stock for product ID %s date %s%s from %s to %s based on Eventbrite availability', 'brcc-inventory-tracker'),
                    $product_id,
                    $booking_date,
                    $booking_time ? ' time ' . $booking_time : '',
                    $current_stock,
                    $available
                ));
            }
        } else {
            // Regular product inventory update
            if ($product->get_manage_stock()) {
                $wc_quantity = $product->get_stock_quantity();
                
                if ($available !== $wc_quantity) {
                    // Check if test mode is enabled
                    if (BRCC_Helpers::is_test_mode()) {
                        BRCC_Helpers::log_operation(
                            'Eventbrite',
                            'Sync Ticket',
                            sprintf(__('Would update WooCommerce stock for product ID %s (%s) from %s to %s based on Eventbrite availability', 'brcc-inventory-tracker'),
                                $product_id,
                                $product->get_name(),
                                $wc_quantity,
                                $available
                            )
                        );
                        continue;
                    } else if (BRCC_Helpers::should_log()) {
                        BRCC_Helpers::log_operation(
                            'Eventbrite',
                            'Sync Ticket',
                            sprintf(__('Updating WooCommerce stock for product ID %s (%s) from %s to %s based on Eventbrite availability (Live Mode)', 'brcc-inventory-tracker'),
                                $product_id,
                                $product->get_name(),
                                $wc_quantity,
                                $available
                            )
                        );
                    }
                    
                    $product->set_stock_quantity($available);
                    $product->save();
                    
                    // Log update
                    BRCC_Helpers::log_info(sprintf(
                        __('Updated WooCommerce stock for product ID %s from %s to %s based on Eventbrite availability', 'brcc-inventory-tracker'),
                        $product_id,
                        $wc_quantity,
                        $available
                    ));
                }
            }
        }
    }
}

/**
 * Get all product mappings with date and time specificity
 * 
 * @return array Array of product mappings with dates and times
 */
private function get_all_date_time_specific_mappings() {
    $all_mappings = get_option('brcc_product_mappings', array());
    $result = array();
    
    // First add normal product mappings
    foreach ($all_mappings as $product_id => $mapping) {
        // Skip date collections
        if (strpos($product_id, '_dates') !== false) {
            continue;
        }
        
        if (!empty($mapping['eventbrite_id'])) {
            $result[] = array(
                'product_id' => $product_id,
                'eventbrite_id' => $mapping['eventbrite_id'],
                'booking_date' => null,
                'booking_time' => null
            );
        }
    }
    
    // Add date-specific and date-time-specific mappings
    foreach ($all_mappings as $key => $value) {
        if (strpos($key, '_dates') !== false) {
            $product_id = str_replace('_dates', '', $key);
            
            foreach ($value as $mapping_key => $mapping) {
                if (!empty($mapping['eventbrite_id'])) {
                    // Check if this is a date-time mapping (key contains an underscore)
                    if (strpos($mapping_key, '_') !== false) {
                        list($date, $time) = explode('_', $mapping_key, 2);
                        $result[] = array(
                            'product_id' => $product_id,
                            'eventbrite_id' => $mapping['eventbrite_id'],
                            'booking_date' => $date,
                            'booking_time' => $time
                        );
                    } else {
                        // Regular date mapping (no time)
                        $result[] = array(
                            'product_id' => $product_id,
                            'eventbrite_id' => $mapping['eventbrite_id'],
                            'booking_date' => $mapping_key,
                            'booking_time' => null
                        );
                    }
                }
            }
        }
    }
    
    return $result;
}

/**
 * Get date and time specific inventory for a product
 * 
 * @param int $product_id Product ID
 * @param string $date Date in Y-m-d format
 * @param string $time Optional time in H:i format
 * @return int|null Current inventory or null if not found
 */
private function get_date_time_specific_inventory($product_id, $date, $time = null) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return null;
    }
    
    // Check if FooEvents is active and this is a FooEvents product
    if (BRCC_Helpers::is_fooevents_active()) {
        $is_fooevents = get_post_meta($product_id, 'fooevents_event', true);
        if ($is_fooevents) {
            // Get day-specific slots if they exist
            $day_slots = get_post_meta($product_id, 'fooevents_event_day_slots', true);
            if (!empty($day_slots) && is_array($day_slots) && isset($day_slots[$date])) {
                // If time is specified, check for time match
                if ($time && isset($day_slots[$date]['times']) && is_array($day_slots[$date]['times'])) {
                    foreach ($day_slots[$date]['times'] as $slot_time => $slot_data) {
                        if (BRCC_Helpers::is_time_close($slot_time, $time)) {
                            return isset($slot_data['stock']) ? $slot_data['stock'] : null;
                        }
                    }
                }
                
                // Return the overall date stock if no time match
                return $day_slots[$date]['stock'];
            }
            
            // For single date events, return the regular stock
            $event_date = get_post_meta($product_id, 'fooevents_event_date', true);
            if (!empty($event_date)) {
                $parsed_date = BRCC_Helpers::parse_date_value($event_date);
                if ($parsed_date === $date) {
                    return $product->get_stock_quantity();
                }
            }
        }
    }
    
    // Try different meta keys where date/time-specific inventory might be stored
    $meta_keys = array(
        '_booking_slots',
        '_product_booking_slots',
        '_wc_slots',
        '_event_slots',
        '_bookings',
        '_event_dates'
    );
    
    foreach ($meta_keys as $meta_key) {
        $slots = $product->get_meta($meta_key);
        
        if (!empty($slots) && is_array($slots)) {
            // Format 1: slots[date] or slots[date_time] => data
            $key = $time ? $date . '_' . $time : $date;
            if (isset($slots[$key])) {
                if (isset($slots[$key]['inventory'])) {
                    return $slots[$key]['inventory'];
                } elseif (isset($slots[$key]['stock'])) {
                    return $slots[$key]['stock'];
                } elseif (isset($slots[$key]['quantity'])) {
                    return $slots[$key]['quantity'];
                }
            } else if (isset($slots[$date])) {
                // Check if there's a date entry with time data
                if (isset($slots[$date]['time']) && $time && BRCC_Helpers::is_time_close($slots[$date]['time'], $time)) {
                    if (isset($slots[$date]['inventory'])) {
                        return $slots[$date]['inventory'];
                    } elseif (isset($slots[$date]['stock'])) {
                        return $slots[$date]['stock'];
                    } elseif (isset($slots[$date]['quantity'])) {
                        return $slots[$date]['quantity'];
                    }
                } else if (!$time) {
                    // Just date matching, no time
                    if (isset($slots[$date]['inventory'])) {
                        return $slots[$date]['inventory'];
                    } elseif (isset($slots[$date]['stock'])) {
                        return $slots[$date]['stock'];
                    } elseif (isset($slots[$date]['quantity'])) {
                        return $slots[$date]['quantity'];
                    }
                }
            }
            
            // Format 2: slots[index] => {date, time, stock/inventory}
            foreach ($slots as $slot) {
                if (is_array($slot) && isset($slot['date']) && $slot['date'] === $date) {
                    // If time is specified, check for match
                    if ($time && isset($slot['time'])) {
                        if (BRCC_Helpers::is_time_close($slot['time'], $time)) {
                            if (isset($slot['stock'])) {
                                return $slot['stock'];
                            } elseif (isset($slot['inventory'])) {
                                return $slot['inventory'];
                            } elseif (isset($slot['quantity'])) {
                                return $slot['quantity'];
                            }
                        }
                    } else if (!$time) {
                        // Just date matching, no time
                        if (isset($slot['stock'])) {
                            return $slot['stock'];
                        } elseif (isset($slot['inventory'])) {
                            return $slot['inventory'];
                        } elseif (isset($slot['quantity'])) {
                            return $slot['quantity'];
                        }
                    }
                }
            }
        }
    }
    
    // Check product variations as a fallback
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $variation = wc_get_product($variation_id);
            
            if (!$variation) continue;
            
            // Check if this variation has attributes matching our date and time
            $attributes = $variation->get_attributes();
            $date_match = false;
            $time_match = true; // Default to true if no time is specified
            
            foreach ($attributes as $attr_name => $attr_value) {
                $lower_name = strtolower($attr_name);
                
                // Check for date attribute
                if (strpos($lower_name, 'date') !== false || strpos($lower_name, 'day') !== false) {
                    $attr_date = BRCC_Helpers::parse_date_value($attr_value);
                    if ($attr_date === $date) {
                        $date_match = true;
                    }
                }
                
                // Check for time attribute if time is specified
                if ($time && (strpos($lower_name, 'time') !== false || strpos($lower_name, 'hour') !== false)) {
                    $attr_time = strtotime($attr_value);
                    if ($attr_time !== false) {
                        $formatted_time = date('H:i', $attr_time);
                        $time_match = BRCC_Helpers::is_time_close($formatted_time, $time);
                    } else {
                        $time_match = false;
                    }
                }
            }
            
            if ($date_match && $time_match) {
                return $variation->get_stock_quantity();
            }
        }
    }
    
    return null;
}

/**
 * Update date and time specific inventory for a product
 * 
 * @param int $product_id Product ID
 * @param string $date Date in Y-m-d format
 * @param string $time Optional time in H:i format
 * @param int $quantity New inventory level
 * @return boolean Success or failure
 */
private function update_date_time_specific_inventory($product_id, $date, $time, $quantity) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return false;
    }
    
    // Check if FooEvents is active and this is a FooEvents product
    if (BRCC_Helpers::is_fooevents_active()) {
        $is_fooevents = get_post_meta($product_id, 'fooevents_event', true);
        if ($is_fooevents) {
            // For FooEvents, try to update day slots
            $day_slots = get_post_meta($product_id, 'fooevents_event_day_slots', true);
            if (!empty($day_slots) && is_array($day_slots)) {
                if (isset($day_slots[$date])) {
                    // If time is specified, check for time-specific slots
                    if ($time && isset($day_slots[$date]['times']) && is_array($day_slots[$date]['times'])) {
                        $updated = false;
                        foreach ($day_slots[$date]['times'] as $slot_time => &$slot_data) {
                            if (BRCC_Helpers::is_time_close($slot_time, $time)) {
                                $slot_data['stock'] = $quantity;
                                $updated = true;
                                break;
                            }
                        }
                        
                        if ($updated) {
                            update_post_meta($product_id, 'fooevents_event_day_slots', $day_slots);
                            return true;
                        }
                    }
                    
                    // Update the overall date stock
                    $day_slots[$date]['stock'] = $quantity;
                    update_post_meta($product_id, 'fooevents_event_day_slots', $day_slots);
                    return true;
                }
            }
            
            // For single date events, update the product stock
            $event_date = get_post_meta($product_id, 'fooevents_event_date', true);
            if (!empty($event_date)) {
                $parsed_date = BRCC_Helpers::parse_date_value($event_date);
                if ($parsed_date === $date) {
                    $product->set_stock_quantity($quantity);
                    $product->save();
                    return true;
                }
            }
        }
    }
    
    // Try different meta keys where date/time-specific inventory might be stored
    $meta_keys = array(
        '_booking_slots',
        '_product_booking_slots',
        '_wc_slots',
        '_event_slots',
        '_bookings',
        '_event_dates'
    );
    
    foreach ($meta_keys as $meta_key) {
        $slots = $product->get_meta($meta_key);
        
        if (!empty($slots) && is_array($slots)) {
            $updated = false;
            
            // Format 1: slots[date] or slots[date_time] => data
            $key = $time ? $date . '_' . $time : $date;
            if (isset($slots[$key])) {
                if (isset($slots[$key]['inventory'])) {
                    $slots[$key]['inventory'] = $quantity;
                    $updated = true;
                } elseif (isset($slots[$key]['stock'])) {
                    $slots[$key]['stock'] = $quantity;
                    $updated = true;
                } elseif (isset($slots[$key]['quantity'])) {
                    $slots[$key]['quantity'] = $quantity;
                    $updated = true;
                }
            } else if (isset($slots[$date])) {
                // Check if there's a date entry with time data
                if (isset($slots[$date]['time']) && $time && BRCC_Helpers::is_time_close($slots[$date]['time'], $time)) {
                    if (isset($slots[$date]['inventory'])) {
                        $slots[$date]['inventory'] = $quantity;
                        $updated = true;
                    } elseif (isset($slots[$date]['stock'])) {
                        $slots[$date]['stock'] = $quantity;
                        $updated = true;
                    } elseif (isset($slots[$date]['quantity'])) {
                        $slots[$date]['quantity'] = $quantity;
                        $updated = true;
                    }
                } else if (!$time) {
                    // Just date matching, no time
                    if (isset($slots[$date]['inventory'])) {
                        $slots[$date]['inventory'] = $quantity;
                        $updated = true;
                    } elseif (isset($slots[$date]['stock'])) {
                        $slots[$date]['stock'] = $quantity;
                        $updated = true;
                    } elseif (isset($slots[$date]['quantity'])) {
                        $slots[$date]['quantity'] = $quantity;
                        $updated = true;
                    }
                }
            }
            
            // Format 2: slots[index] => {date, time, stock/inventory}
            foreach ($slots as $index => $slot) {
                if (is_array($slot) && isset($slot['date']) && $slot['date'] === $date) {
                    // If time is specified, check for match
                    if ($time && isset($slot['time'])) {
                        if (BRCC_Helpers::is_time_close($slot['time'], $time)) {
                            if (isset($slot['stock'])) {
                                $slots[$index]['stock'] = $quantity;
                                $updated = true;
                            } elseif (isset($slot['inventory'])) {
                                $slots[$index]['inventory'] = $quantity;
                                $updated = true;
                            } elseif (isset($slot['quantity'])) {
                                $slots[$index]['quantity'] = $quantity;
                                $updated = true;
                            }
                        }
                    } else if (!$time) {
                        // Just date matching, no time
                        if (isset($slot['stock'])) {
                            $slots[$index]['stock'] = $quantity;
                            $updated = true;
                        } elseif (isset($slot['inventory'])) {
                            $slots[$index]['inventory'] = $quantity;
                            $updated = true;
                        } elseif (isset($slot['quantity'])) {
                            $slots[$index]['quantity'] = $quantity;
                            $updated = true;
                        }
                    }
                }
            }
            
            if ($updated) {
                $product->update_meta_data($meta_key, $slots);
                $product->save();
                return true;
            }
        }
    }
    
    // Check product variations as a fallback
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $variation = wc_get_product($variation_id);
            
            if (!$variation) continue;
            
            // Check if this variation has attributes matching our date and time
            $attributes = $variation->get_attributes();
            $date_match = false;
            $time_match = true; // Default to true if no time is specified
            
            foreach ($attributes as $attr_name => $attr_value) {
                $lower_name = strtolower($attr_name);
                
                // Check for date attribute
                if (strpos($lower_name, 'date') !== false || strpos($lower_name, 'day') !== false) {
                    $attr_date = BRCC_Helpers::parse_date_value($attr_value);
                    if ($attr_date === $date) {
                        $date_match = true;
                    }
                }
                
                // Check for time attribute if time is specified
                if ($time && (strpos($lower_name, 'time') !== false || strpos($lower_name, 'hour') !== false)) {
                    $attr_time = strtotime($attr_value);
                    if ($attr_time !== false) {
                        $formatted_time = date('H:i', $attr_time);
                        $time_match = BRCC_Helpers::is_time_close($formatted_time, $time);
                    } else {
                        $time_match = false;
                    }
                }
            }
            
            if ($date_match && $time_match) {
                $variation->set_stock_quantity($quantity);
                $variation->save();
                return true;
            }
        }
    }
    
    // If no suitable inventory storage found, log this
    BRCC_Helpers::log_error(sprintf(
        __('Could not update date/time-specific inventory for product ID %s date %s%s - no compatible inventory storage found', 'brcc-inventory-tracker'),
        $product_id,
        $date,
        $time ? ' time ' . $time : ''
    ));
    
    return false;
}

/**
 * Check if two times are close enough to be considered matching
 * 
 * @param string $time1 Time in H:i format
 * @param string $time2 Time in H:i format
 * @param int $buffer_minutes Buffer in minutes to consider times close enough
 * @return bool True if times are close enough
 */
private function is_time_close($time1, $time2, $buffer_minutes = BRCC_Constants::TIME_BUFFER_MINUTES) {
    if (empty($time1) || empty($time2)) {
        return true;
    }
    
    // Make sure times are in H:i format
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time1)) {
        $timestamp = strtotime($time1);
        if ($timestamp !== false) {
            $time1 = date('H:i', $timestamp);
        } else {
            return false;
        }
    }
    
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time2)) {
        $timestamp = strtotime($time2);
        if ($timestamp !== false) {
            $time2 = date('H:i', $timestamp);
        } else {
            return false;
        }
    }
    
    // Convert to timestamps for comparison
    $timestamp1 = strtotime("1970-01-01 $time1:00");
    $timestamp2 = strtotime("1970-01-01 $time2:00");
    
    if ($timestamp1 === false || $timestamp2 === false) {
        return false;
    }
    
    // Calculate difference in minutes
    $diff_minutes = abs($timestamp1 - $timestamp2) / 60;
    
    return $diff_minutes <= $buffer_minutes;
}

/**
 * Update Eventbrite ticket capacity
 */
public function update_eventbrite_ticket_capacity($ticket_id, $capacity) {
    // Check if test mode is enabled
    if (BRCC_Helpers::is_test_mode()) {
        BRCC_Helpers::log_operation(
            'Eventbrite',
            'Update Capacity',
            sprintf(__('Would update Eventbrite ticket ID %s to capacity %s', 'brcc-inventory-tracker'),
                $ticket_id,
                $capacity
            )
        );
        return true;
    } else if (BRCC_Helpers::should_log()) {
        BRCC_Helpers::log_operation(
            'Eventbrite',
            'Update Capacity',
            sprintf(__('Updating Eventbrite ticket ID %s to capacity %s (Live Mode)', 'brcc-inventory-tracker'),
                $ticket_id,
                $capacity
            )
        );
    }
    
    // First, get ticket details to get the event ID
    $ticket_info = $this->get_eventbrite_ticket($ticket_id);
    
    if (is_wp_error($ticket_info)) {
        return $ticket_info;
    }
    
    $event_id = $ticket_info['event_id'];
    
    // Prepare API request
    $url = $this->api_url . '/events/' . $event_id . '/ticket_classes/' . $ticket_id . '/';
    
    $data = array(
        'ticket_class' => array(
            'capacity' => $capacity,
            'capacity_is_custom' => true,
        ),
    );
    
    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($data),
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        return new WP_Error(
            'eventbrite_api_error',
            isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
        );
    }
    
    return true;
}

/**
 * Test Eventbrite connection
 */
public function test_connection() {
    // Prepare API request to get user info
    $url = $this->api_url . '/users/me/';
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ),
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        return new WP_Error(
            'eventbrite_api_error',
            isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker')
        );
    }
    
    return $body;
}

/**
 * Test Eventbrite ticket connectivity with time support
 * 
 * @param string $ticket_id Eventbrite ticket ID
 * @return array|WP_Error Connection details or error
 */
public function test_ticket_connection($ticket_id) {
    if (empty($ticket_id)) {
        return new WP_Error(
            'invalid_parameters', 
            __('Ticket ID is required for testing.', 'brcc-inventory-tracker')
        );
    }
    
    // Get ticket information
    $ticket_info = $this->get_eventbrite_ticket($ticket_id);
    
    if (is_wp_error($ticket_info)) {
        return $ticket_info;
    }
    
    // If we got this far, the ticket exists
    $event_id = isset($ticket_info['event_id']) ? $ticket_info['event_id'] : '';
    
    // Get event details
    $event_info = $this->get_eventbrite_event($event_id);
    
    if (is_wp_error($event_info)) {
        return $event_info;
    }
    
    // Calculate inventory
    $capacity = isset($ticket_info['capacity_is_custom']) && $ticket_info['capacity_is_custom'] 
        ? $ticket_info['capacity'] 
        : (isset($ticket_info['event_capacity']) ? $ticket_info['event_capacity'] : 0);
        
    $sold = isset($ticket_info['quantity_sold']) ? $ticket_info['quantity_sold'] : 0;
    $available = $capacity - $sold;
    
    // Extract event details
    $event_name = isset($event_info['name']['text']) ? $event_info['name']['text'] : '';
    $event_date = isset($event_info['start']['local']) ? date('Y-m-d', strtotime($event_info['start']['local'])) : '';
    $event_time = isset($event_info['start']['local']) ? date('H:i', strtotime($event_info['start']['local'])) : '';
    $venue_name = isset($event_info['venue']['name']) ? $event_info['venue']['name'] : '';
    
    return array(
        'success' => true,
        'event_id' => $event_id,
        'ticket_id' => $ticket_id,
        'event_name' => $event_name,
        'event_date' => $event_date,
        'event_time' => $event_time,
        'venue_name' => $venue_name,
        'capacity' => $capacity,
        'sold' => $sold,
        'available' => $available,
        'ticket_name' => isset($ticket_info['name']) ? $ticket_info['name'] : '',
        'is_free' => isset($ticket_info['free']) ? $ticket_info['free'] : false,
        'formatted_time' => !empty($event_time) ? date('g:i A', strtotime("1970-01-01 " . $event_time)) : ''
    );
}

    /**
     * Get attendees for a specific Eventbrite event
     * Handles pagination.
     *
     * @param string $event_id The Eventbrite Event ID.
     * @return array|WP_Error Array of attendee objects or WP_Error on failure.
     */
    public function get_event_attendees($event_id) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        if (empty($event_id)) {
            return new WP_Error('missing_event_id', __('Eventbrite Event ID is required.', 'brcc-inventory-tracker'));
        }

        $all_attendees = array();
        $page = 1;
        $url = $this->api_url . '/events/' . $event_id . '/attendees/';

        do {
            $paged_url = add_query_arg(array('page' => $page), $url);
            BRCC_Helpers::log_debug("Fetching Eventbrite attendees page {$page} for event {$event_id}", $paged_url);

            $response = wp_remote_get($paged_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 20, // Increased timeout for potentially large attendee lists
            ));

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error("Eventbrite API error fetching attendees page {$page} for event {$event_id}", $response);
                // Return error only if it's the first page, otherwise return what we have
                return ($page === 1) ? $response : $all_attendees;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code !== 200 || isset($body['error'])) {
                $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching attendees', 'brcc-inventory-tracker');
                BRCC_Helpers::log_error("Eventbrite API error ({$status_code}) fetching attendees page {$page} for event {$event_id}: {$error_message}", $body);
                 // Return error only if it's the first page, otherwise return what we have
                return ($page === 1) ? new WP_Error('eventbrite_api_error', $error_message) : $all_attendees;
            }

            if (isset($body['attendees']) && is_array($body['attendees'])) {
                $all_attendees = array_merge($all_attendees, $body['attendees']);
            }

            // Check pagination
            $has_more_items = isset($body['pagination']['has_more_items']) ? $body['pagination']['has_more_items'] : false;
            $page++;

        } while ($has_more_items);

        BRCC_Helpers::log_debug("Finished fetching attendees for event {$event_id}. Total found: " . count($all_attendees));
        return $all_attendees;
    }
}

