<?php

namespace GrocersList\Support;

/**
 * Sends the visitor their response before work that should not keep them
 * waiting, where the server can: PHP-FPM's fastcgi_finish_request() and
 * LiteSpeed's litespeed_finish_request() send what PHP has output and close
 * the connection, and PHP carries on.
 *
 * An object of its own rather than calls inline so that tests stand in for
 * it: once a test defines fastcgi_finish_request(), Patchwork cannot take it
 * away again (tests/Support/ElevatedUserRemediationTest.php), so every later
 * test would find it there and call it — and the finish must never run under
 * PHPUnit.
 */
class RequestFinisher
{
    public function available(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    public function finish(): void
    {
        // First: once the response is gone the visitor can close the tab, and
        // nothing after this may stop the work it was sent early for.
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Whether this request is a WP-Cron run, which nobody waits on.
     * wp_doing_cron() is WordPress 4.8+, and also answers its filter.
     */
    public function inCron(): bool
    {
        if (function_exists('wp_doing_cron')) {
            return (bool) wp_doing_cron();
        }

        return defined('DOING_CRON') && DOING_CRON;
    }
}
