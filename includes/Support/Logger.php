<?php

namespace GrocersList\Support;

/**
 * Logger class for handling debug logging in a way that can be disabled in production.
 */
class Logger
{
    /**
     * Whether debug logging is enabled.
     *
     * @var bool
     */
    private static bool $enabled = false;

    /**
     * Enable debug logging.
     *
     * @return void
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable debug logging.
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if debug logging is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Log a debug message if logging is enabled.
     *
     * @param string $message The message to log.
     * @return void
     */
    public static function debug(string $message): void
    {
        if (self::$enabled) {
            // phpcs:disable WordPress.PHP.DevelopmentFunctions
            error_log($message);
            // phpcs:enable
        }
    }
}