<?php
/**
 * BRCC Constants Class
 * 
 * Defines constants used throughout the BRCC Inventory Tracker plugin
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Constants {
    /**
     * Time buffer in minutes for considering two times as matching
     */
    const TIME_BUFFER_MINUTES = 30;
    
    /**
     * Toronto timezone string
     */
    const TORONTO_TIMEZONE = 'America/Toronto';
    
    /**
     * Debug log levels
     */
    const LOG_LEVEL_INFO = 'info';
    const LOG_LEVEL_WARNING = 'warning';
    const LOG_LEVEL_ERROR = 'error';
    
    /**
     * Date formats supported for parsing
     */
    const DATE_FORMAT_YMD = 'Y-m-d';
    const DATE_FORMAT_DMY = 'd-m-Y';
    const DATE_FORMAT_MDY = 'm-d-Y';
}
