<?php

/**
 * BRCC Mappings Class
 * 
 * Provides enhanced date-time mapping functions for Eventbrite integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Enhanced_Mappings
{
    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Register AJAX handlers
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates_enhanced'));
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings_enhanced'));

        // Enqueue enhanced scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_enhanced_scripts'), 20);
    }

    /**
     * Enqueue enhanced scripts and styles
     */
    public function enqueue_enhanced_scripts($hook)
    {
        // Only load on plugin pages
        if (strpos($hook, 'brcc-') === false) {
            return;
        }

        // Add enhanced CSS
        wp_enqueue_style(
            'brcc-date-mappings-enhanced',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/date-mappings-enhanced.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );

        // Add enhanced JS - use version with timestamp to avoid caching
        wp_enqueue_script(
            'brcc-date-mappings-enhanced',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/date-mappings-enhanced.js',
            array('jquery'),
            BRCC_INVENTORY_TRACKER_VERSION . '.' . time(),
            true
        );
    }

    /**
     * Get product date mappings with enhanced title matching
     * 
     * @param int $product_id Product ID
     * @param bool $fetch_from_eventbrite Whether to fetch from Eventbrite
     * @return array Response data
     */
    public function get_product_dates_enhanced($product_id, $fetch_from_eventbrite = false)
    {
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            return array(
                'dates' => array(),
                'message' => __('Product not found.', 'brcc-inventory-tracker')
            );
        }

        // Get product name and extract day/time information
        $product_name = $product->get_name();
        $day_name = BRCC_Helpers::extract_day_from_title($product_name);
        $time_info = BRCC_Helpers::extract_time_from_title($product_name);

        // Initialize Eventbrite integration
        $eventbrite_integration = new BRCC_Eventbrite_Integration();

        // Get product mappings
        $product_mappings = new BRCC_Product_Mappings();

        // First get available dates for this product
        $dates = $product_mappings->get_product_dates($product_id, true);

        // Get the base Eventbrite ID for this product
        $base_mapping = $product_mappings->get_product_mappings($product_id);
        $base_id = isset($base_mapping['eventbrite_id']) ? $base_mapping['eventbrite_id'] : '';

        // If we should fetch from Eventbrite and have a base ID or product has a day name
        if ($fetch_from_eventbrite && ($base_id || $day_name)) {
            // Get Eventbrite events
            if ($base_id) {
                // If we have a base ID, get related events
                $eventbrite_dates = $product_mappings->get_product_dates_from_eventbrite($product_id, $base_id);

                if (!empty($eventbrite_dates)) {
                    // Replace our dates with Eventbrite dates
                    $dates = $eventbrite_dates;
                }
            } else if ($day_name) {
                // Try to get events for this day from Eventbrite
                $day_events = $eventbrite_integration->get_events_by_day($day_name);

                if (!empty($day_events)) {
                    $eventbrite_dates = array();

                    foreach ($day_events as $event) {
                        if (isset($event['start']['local'])) {
                            $event_date = date('Y-m-d', strtotime($event['start']['local']));
                            $event_time = date('H:i', strtotime($event['start']['local']));

                            // Check if this is a relevant event by comparing title
                            $event_name = isset($event['name']['text']) ? $event['name']['text'] : '';
                            $title_similarity = 0;
                            similar_text(strtolower($event_name), strtolower($product_name), $title_similarity);

                            // If time in product name matches event time, boost similarity
                            $time_matches = false;
                            if ($time_info) {
                                $time_diff = abs(strtotime("1970-01-01 $time_info") - strtotime("1970-01-01 $event_time")) / 60;
                                if ($time_diff <= BRCC_Constants::TIME_BUFFER_MINUTES) { // Within buffer minutes
                                    $time_matches = true;
                                    $title_similarity += 20; // Boost similarity score
                                }
                            }

                            // Only include if reasonably similar
                            if ($title_similarity > 60 || $time_matches) {
                                // Look for ticket classes
                                $ticket_id = '';
                                if (isset($event['ticket_classes']) && is_array($event['ticket_classes'])) {
                                    foreach ($event['ticket_classes'] as $ticket) {
                                        // Skip free tickets
                                        if (isset($ticket['free']) && $ticket['free']) {
                                            continue;
                                        }

                                        $ticket_id = $ticket['id'];
                                        break; // Just take the first paid ticket
                                    }
                                }

                                if (!empty($ticket_id)) {
                                    $eventbrite_dates[] = array(
                                        'date' => $event_date,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($event_date)),
                                        'time' => $event_time,
                                        'formatted_time' => date('g:i A', strtotime("1970-01-01 $event_time")),
                                        'inventory' => null,
                                        'eventbrite_id' => $ticket_id,
                                        'eventbrite_name' => $event_name,
                                        'eventbrite_venue' => isset($event['venue']['name']) ? $event['venue']['name'] : '',
                                        'eventbrite_time' => $event_time,
                                        'from_eventbrite' => true,
                                        'similarity' => $title_similarity,
                                        'time_matches' => $time_matches,
                                        'suggestion' => true
                                    );
                                }
                            }
                        }
                    }

                    // If we have Eventbrite dates, use them (possibly combining with existing dates)
                    if (!empty($eventbrite_dates)) {
                        // Sort by similarity
                        usort($eventbrite_dates, function ($a, $b) {
                            return $b['similarity'] - $a['similarity'];
                        });

                        // Add to our dates
                        $dates = array_merge($dates, $eventbrite_dates);
                    }
                }
            }
        }

        // Get all existing mappings for this product
        $all_mappings = get_option('brcc_product_mappings', array());
        $date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();

        // Apply existing mappings to dates
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
                }
            }
        }

        // Add suggestions for automated matching
        $suggestions = array();

        // If we have a day name in the product, suggest matches for upcoming dates
        if ($day_name) {
            // Get upcoming dates for this day
            $upcoming_dates = $product_mappings->get_upcoming_dates_for_day($day_name);

            // Try to find matches for these dates
            foreach ($upcoming_dates as $date) {
                // Get events for this specific date
                $date_events = $eventbrite_integration->get_events_for_date($date);

                foreach ($date_events as $event) {
                    if (isset($event['start']['local'])) {
                        $event_time = date('H:i', strtotime($event['start']['local']));

                        // If we have a time in the product name, check if it's close to the event time
                        if ($time_info) {
                            $time_diff = abs(strtotime("1970-01-01 $time_info") - strtotime("1970-01-01 $event_time")) / 60;

                            // Within buffer minutes
                            if ($time_diff <= BRCC_Constants::TIME_BUFFER_MINUTES) {
                                // Check title similarity
                                $event_name = isset($event['name']['text']) ? $event['name']['text'] : '';
                                $title_similarity = 0;
                                similar_text(strtolower($event_name), strtolower($product_name), $title_similarity);

                                // Only suggest if reasonably similar
                                if ($title_similarity > 60) {
                                    // Look for ticket classes
                                    if (isset($event['ticket_classes']) && is_array($event['ticket_classes'])) {
                                        foreach ($event['ticket_classes'] as $ticket) {
                                            // Skip free tickets
                                            if (isset($ticket['free']) && $ticket['free']) {
                                                continue;
                                            }

                                            // Create a key for this date/time combination
                                            $key = $date . '_' . $event_time;

                                            $suggestions[$key] = array(
                                                'eventbrite_id' => $ticket['id'],
                                                'event_name' => $event_name,
                                                'venue_name' => isset($event['venue']['name']) ? $event['venue']['name'] : '',
                                                'event_time' => $event_time,
                                                'similarity' => $title_similarity
                                            );

                                            break; // Just take the first paid ticket
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return array(
            'dates' => $dates,
            'base_id' => $base_id,
            'source' => $fetch_from_eventbrite ? 'eventbrite' : 'intelligent',
            'suggestions' => $suggestions,
            'availableTimes' => $this->get_common_times()
        );
    }

    /**
     * AJAX handler for fetching product dates with enhanced support
     */
    public function ajax_get_product_dates_enhanced()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Check if we should fetch from Eventbrite
        $fetch_from_eventbrite = isset($_POST['fetch_from_eventbrite']) && $_POST['fetch_from_eventbrite'] == 'true';

        // Get enhanced product dates
        $response = $this->get_product_dates_enhanced($product_id, $fetch_from_eventbrite);

        if (empty($response['dates'])) {
            wp_send_json_error(array('message' => __('No dates found for this product.', 'brcc-inventory-tracker')));
            return;
        }

        wp_send_json_success($response);
    }

    /**
     * AJAX handler for saving product date mappings with enhanced support
     */
    public function ajax_save_product_date_mappings_enhanced()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
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
     * Get common time slots for dropdown
     * 
     * @return array Array of time options
     */
    public function get_common_times()
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
}

// Initialize the enhanced mappings
new BRCC_Enhanced_Mappings();
