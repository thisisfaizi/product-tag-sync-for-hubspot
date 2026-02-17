<?php
/**
 * Logger utility for the plugin.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Logger
 *
 * Simple logging utility that writes to the WordPress debug log
 * with a [WHPTS] prefix for easy identification.
 *
 * @since 1.0.0
 */
class WHPTS_Logger
{

    /**
     * Log a message to the WordPress debug log.
     *
     * @since 1.0.0
     *
     * @param string $message The message to log.
     * @param string $level   The log level (info, error, warning, debug).
     */
    public static function log($message, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $level_upper = strtoupper($level);
            error_log(sprintf('[WHPTS][%s] %s', $level_upper, $message)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Log an info-level message.
     *
     * @since 1.0.0
     *
     * @param string $message The message to log.
     */
    public static function info($message)
    {
        self::log($message, 'info');
    }

    /**
     * Log an error-level message.
     *
     * @since 1.0.0
     *
     * @param string $message The message to log.
     */
    public static function error($message)
    {
        self::log($message, 'error');
    }

    /**
     * Log a warning-level message.
     *
     * @since 1.0.0
     *
     * @param string $message The message to log.
     */
    public static function warning($message)
    {
        self::log($message, 'warning');
    }
}
