<?php
/**
 * BRCC Helpers Class
 * 
 * Provides helper functions for the BRCC Inventory Tracker
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Helpers {
    /**
     * Check if test mode is enabled
     * 
     * @return boolean
     */
    public static function is_test_mode() {
        $settings = get_option('brcc_api_settings');
        return isset($settings['test_mode']) && $settings['test_mode'];
    }
    
    /**
     * Check if live logging is enabled
     * 
     * @return boolean
     */
    public static function is_live_logging() {
        $settings = get_option('brcc_api_settings');
        return isset($settings['live_logging']) && $settings['live_logging'];
    }
    
    /**
     * Check if any logging is enabled (test mode or live logging)
     * 
     * @return boolean
     */
    public static function should_log() {
        return self::is_test_mode() || self::is_live_logging();
    }
    
    /**
     * Log operation in test mode or live logging mode
     * 
     * @param string $source The source of the operation (WooCommerce, Eventbrite)
     * @param string $operation The operation being performed
     * @param string $details Details about the operation
     */
    public static function log_operation($source, $operation, $details) {
        if (!self::should_log()) {
            return;
        }
        
        $logs = get_option('brcc_operation_logs', []);
        
        $logs[] = array(
            'timestamp' => time(),
            'source' => $source,
            'operation' => $operation,
            'details' => $details,
            'test_mode' => self::is_test_mode()
        );
        
        // Limit log size to prevent database bloat
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('brcc_operation_logs', $logs);
    }
    
    /**
     * Get product name by ID
     * 
     * @param int $product_id
     * @return string
     */
    public static function get_product_name($product_id) {
        $product = wc_get_product($product_id);
        return $product ? $product->get_name() : __('Unknown Product', 'brcc-inventory-tracker') . ' (' . $product_id . ')';
    }
    
    /**
     * Get a readable date format
     * 
     * @param string $date Date in Y-m-d format
     * @return string Formatted date
     */
    public static function format_date($date) {
        if (empty($date)) {
            return '';
        }
        
        return date_i18n(get_option('date_format'), strtotime($date));
    }
    
    /**
     * Check if a plugin is active
     *
     * @param string $plugin_file Plugin file path relative to plugins directory
     * @return boolean
     */
    public static function is_plugin_active($plugin_file) {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        return is_plugin_active($plugin_file);
    }
    
    /**
     * Check if FooEvents is active
     *
     * @return boolean
     */
    public static function is_fooevents_active() {
        return self::is_plugin_active('fooevents/fooevents.php') || 
               self::is_plugin_active('fooevents-for-woocommerce/fooevents.php');
    }
    
    /**
     * Log error
     *
     * @param string $message Error message
     */
    public static function log_error($message) {
        $log = get_option('brcc_error_log', array());
        
        $log[] = array(
            'timestamp' => time(),
            'message' => $message,
        );
        
        // Limit log size
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option('brcc_error_log', $log);
    }
    
    /**
     * Log info
     *
     * @param string $message Info message
     */
    public static function log_info($message) {
        $log = get_option('brcc_info_log', array());
        
        $log[] = array(
            'timestamp' => time(),
            'message' => $message,
        );
        
        // Limit log size
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option('brcc_info_log', $log);
    }

    /**
     * Parse time value to H:i format
     *
     * @param mixed $value Time value to parse
     * @return string|null H:i formatted time or null if parsing fails
     */
    public static function parse_time_value($value) {
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
     * Try to parse a date value to Y-m-d format
     * Enhanced to handle more date formats
     *
     * @param mixed $value Date value to parse
     * @return string|null Y-m-d formatted date or null if parsing fails
     */
    public static function parse_date_value($value) {
        // If already in Y-m-d format
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        
        // Handle array values (some plugins store dates as arrays)
        if (is_array($value) && isset($value['date'])) {
            $value = $value['date'];
        } elseif (is_array($value) && isset($value[0])) {
            $value = $value[0];
        }
        
        // Skip empty or non-string values after potential array extraction
        if (empty($value) || !is_string($value)) {
            return null;
        }
        
        // Try to convert various common date formats
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $value)) {
            // MM/DD/YYYY or DD/MM/YYYY format
            $parts = explode('/', $value);
            if (count($parts) === 3) {
                // If the year is 2 digits, assume it's 2000+
                if (strlen($parts[2]) === 2) {
                    $parts[2] = '20' . $parts[2];
                }
                
                // Try both MM/DD/YYYY and DD/MM/YYYY interpretations
                // Check if parts are numeric before using strtotime
                if (is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $date1 = strtotime("{$parts[2]}-{$parts[0]}-{$parts[1]}");
                    $date2 = strtotime("{$parts[2]}-{$parts[1]}-{$parts[0]}");
                    
                    // Use the interpretation that gives a valid date
                    if ($date1 !== false && date('Y-m-d', $date1) === "{$parts[2]}-{$parts[0]}-{$parts[1]}") {
                        return date('Y-m-d', $date1);
                    } elseif ($date2 !== false && date('Y-m-d', $date2) === "{$parts[2]}-{$parts[1]}-{$parts[0]}") {
                        return date('Y-m-d', $date2);
                    }
                }
            }
        }
        
        // Try common European formats (DD.MM.YYYY)
        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $value)) {
            $parts = explode('.', $value);
            if (count($parts) === 3) {
                if (strlen($parts[2]) === 2) {
                    $parts[2] = '20' . $parts[2];
                }
                 if (is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $timestamp = strtotime("{$parts[2]}-{$parts[1]}-{$parts[0]}");
                    if ($timestamp !== false && date('Y-m-d', $timestamp) === "{$parts[2]}-{$parts[1]}-{$parts[0]}") {
                        return date('Y-m-d', $timestamp);
                    }
                }
            }
        }
        
        // Try human readable format (January 1, 2025 or 1 January 2025)
        // Check if it contains letters before trying strtotime
        if (preg_match('/[a-zA-Z]+/', $value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                // Basic validation: check if the formatted date looks reasonable
                // This helps avoid strtotime interpreting numbers like '123' as timestamps
                if (strpos($value, (string)date('Y', $timestamp)) !== false ||
                    strpos(strtolower($value), strtolower(date('F', $timestamp))) !== false ||
                    strpos(strtolower($value), strtolower(date('M', $timestamp))) !== false) {
                    return date('Y-m-d', $timestamp);
                }
            }
        }
        
        // Try to convert using strtotime as a last resort ONLY if it looks like a date
        // Avoid converting plain numbers or unintended strings
        if (strpos($value, '-') !== false || strpos($value, '/') !== false || strpos($value, '.') !== false || preg_match('/\d{4}/', $value)) {
             $timestamp = strtotime($value);
             if ($timestamp !== false) {
                 // Add a check to ensure it's not just interpreting a year or number
                 if (date('Y', $timestamp) > 1970) {
                     return date('Y-m-d', $timestamp);
                 }
             }
        }
        
        return null;
    }

    /**
     * Extract day name from product title
     *
     * @param string $product_title The product title/name
     * @return string|null Day name or null if not found
     */
    public static function extract_day_from_title($product_title) {
        $days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        $product_title = strtolower($product_title);
        
        foreach ($days as $day) {
            if (strpos($product_title, $day) !== false) {
                return $day;
            }
        }
        
        return null;
    }

    /**
     * Extract time from product title
     *
     * @param string $product_title The product title/name
     * @return string|null Time in H:i format or null if not found
     */
    public static function extract_time_from_title($product_title) {
        $product_title = strtolower($product_title);
        
        // Common show time formats like "8pm", "8:00 pm", "8 PM"
        $time_patterns = array(
            '/(\d{1,2})[ :]?([0-5][0-9])?\s*(am|pm)/i', // 8pm, 8:00pm, 8 pm
            '/(\d{1,2})[:.](\d{2})/i',                  // 20:00, 8.00 (Fixed POSIX class warning)
            '/(\d{1,2})\s*o\'?clock/i'                  // 8 o'clock
        );
        
        foreach ($time_patterns as $pattern) {
            if (preg_match($pattern, $product_title, $matches)) {
                $hour = intval($matches[1]);
                $minute = isset($matches[2]) && !empty($matches[2]) ? intval($matches[2]) : 0;
                
                // Adjust for AM/PM if present
                if (isset($matches[3]) && strtolower($matches[3]) === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif (isset($matches[3]) && strtolower($matches[3]) === 'am' && $hour === 12) {
                    $hour = 0;
                }
                
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }
        
        return null;
    }

    /**
     * Convert time string to minutes since midnight
     *
     * @param string $time Time in H:i format
     * @return int Minutes since midnight
     */
    private static function time_to_minutes($time) {
        list($hours, $minutes) = explode(':', $time);
        return (intval($hours) * 60) + intval($minutes);
    }

    /**
     * Check if two times are close enough to be considered matching
     *
     * @param string $time1 Time in H:i format
     * @param string $time2 Time in H:i format
     * @param int $buffer_minutes Buffer in minutes to consider times close enough
     * @return bool True if times are close enough
     */
    public static function is_time_close($time1, $time2, $buffer_minutes = 30) { // Assuming BRCC_Constants::TIME_BUFFER_MINUTES = 30
        if (empty($time1) || empty($time2)) {
            // If one time is missing, consider them not close unless buffer is huge
            // Or perhaps return true if matching any time is desired? Let's assume false.
            return false;
        }
        
        $time1_minutes = self::time_to_minutes($time1);
        $time2_minutes = self::time_to_minutes($time2);
        
        return abs($time1_minutes - $time2_minutes) <= $buffer_minutes;
    }

    /**
     * Get upcoming dates for a specific day of the week
     *
     * @param string $day_name Day name (Sunday, Monday, etc.)
     * @param int $num_dates Number of upcoming dates to return
     * @return array Array of dates in Y-m-d format
     */
    public static function get_upcoming_dates_for_day($day_name, $num_dates = 8) {
        $day_map = array(
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        );
        
        $day_index = isset($day_map[strtolower($day_name)]) ? $day_map[strtolower($day_name)] : -1;
        
        if ($day_index === -1) {
            return array(); // Invalid day name
        }
        
        $upcoming_dates = array();
        try {
            // Use WordPress timezone
            $timezone = wp_timezone();
            $current_date = new DateTime('now', $timezone);
            $current_date->setTime(0, 0, 0); // Reset time part

            // Get current day of week (0 = Sunday, 6 = Saturday)
            $current_day_index = (int)$current_date->format('w');
            
            // Calculate days until the next target day
            $days_until_next = ($day_index - $current_day_index + 7) % 7;
            if ($days_until_next === 0) {
                // If today is the target day, start from next week unless it's the only date requested
                if ($num_dates > 1) {
                    $days_until_next = 7;
                } else {
                    // If only one date requested and it's today, return today
                    $upcoming_dates[] = $current_date->format('Y-m-d');
                    return $upcoming_dates;
                }
            
            }

            // Set to the first upcoming target day
            if ($days_until_next > 0) {
                $current_date->modify('+' . $days_until_next . ' days');
            }

            // Collect upcoming dates
            for ($i = 0; $i < $num_dates; $i++) {
                // Check if we already added the first date (if it was today)
                if ($i === 0 && $days_until_next === 0 && $num_dates > 1) {
                    $current_date->modify('+7 days'); // Jump to next week if first date was today
                } else if ($i > 0) {
                    $current_date->modify('+7 days'); // Jump to the next occurrence of this day
                }
                $upcoming_dates[] = $current_date->format('Y-m-d');
            }
        } catch (Exception $e) {
            // Log error if DateTime fails
            self::log_error('Error calculating upcoming dates: ' . $e->getMessage());
            return array();
        }

        return $upcoming_dates;
    }
    
    /**
     * Get FooEvents date from order item
     *
     * @param WC_Order_Item $item Order item
     * @return string|null Booking date in Y-m-d format or null if not found
     */
    public static function get_fooevents_date_from_item($item) {
        // Check if FooEvents is active
        if (!self::is_fooevents_active()) {
            return null;
        }
        
        // FooEvents specific meta keys
        $fooevents_keys = array(
            'WooCommerceEventsDate',
            'WooCommerceEventsTicketDate',
            'WooCommerceEventsProductDate',
            '_event_date',
            '_event_start_date',
            'fooevents_date',
            'fooevents_ticket_date'
        );
        
        foreach ($fooevents_keys as $key) {
            $date_value = $item->get_meta($key);
            if (!empty($date_value)) {
                $parsed_date = self::parse_date_value($date_value);
                if ($parsed_date) {
                    return $parsed_date;
                }
            }
        }
        
        // Check for multiple day events in FooEvents
        $event_id = $item->get_meta('WooCommerceEventsProductID');
        
        if ($event_id) {
            // Get date from post meta directly
            $event_date = get_post_meta($event_id, 'WooCommerceEventsDate', true);
            if (!empty($event_date)) {
                return self::parse_date_value($event_date);
            } // End if (!empty($event_date))
        } // End if ($event_id)
        
        return null;
    } // End get_fooevents_date_from_item

    /**
     * Log a debug message to the standard PHP error log (and WP debug.log if enabled)
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include (will be print_r'd)
     */
    public static function log_debug($message, $data = null) {
        // Check if WP_DEBUG and WP_DEBUG_LOG are enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_entry = "[" . date('Y-m-d H:i:s') . "] BRCC Debug: " . $message;
            if ($data !== null) {
                // Use var_export for potentially better readability than print_r
                $log_entry .= "\nData: " . var_export($data, true);
            }
            // Ensure error_log is called correctly
            error_log($log_entry);
        }
    } // End log_debug
} // End class BRCC_Helpers
