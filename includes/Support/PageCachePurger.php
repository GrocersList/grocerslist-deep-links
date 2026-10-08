<?php

namespace GrocersList\Support;

/**
 * Best-effort purge of the site's full-page cache after a GRO form changed.
 * The form is rendered into the page, so a cached page keeps showing the old
 * form until its cache entry goes. None of these caching plugins or hosts is
 * required: every call is guarded, and one that fails never stops the rest.
 * A cache without a hook here expires on its own schedule; a site can purge
 * it from the grocerslist_forms_invalidated action.
 */
class PageCachePurger
{
    /** Functions that purge the whole page cache => the plugin that defines them. */
    protected const FUNCTIONS = [
        'rocket_clean_domain'       => 'WP Rocket',
        'w3tc_flush_all'            => 'W3 Total Cache',
        'wp_cache_clear_cache'      => 'WP Super Cache',
        'sg_cachepress_purge_cache' => 'SiteGround Optimizer',
        'wpfc_clear_all_cache'      => 'WP Fastest Cache',
    ];

    /** Static methods that do the same => the plugin or host that defines them. */
    protected const STATIC_METHODS = [
        'LiteSpeed_Cache_API::purge_all'        => 'LiteSpeed Cache',
        'Breeze_PurgeCache::breeze_cache_flush' => 'Breeze',
        'WpeCommon::purge_varnish_cache'        => 'WP Engine',
        // clear_site_cache, not clear_complete_cache: the latter switches to
        // and restarts the cache engine of every site on a multisite network.
        'Cache_Enabler::clear_site_cache'       => 'Cache Enabler',
    ];

    /**
     * An object a plugin hands out, or that we build, before it will purge:
     * `Class::factory` (`__construct` = we build it) => [method, plugin].
     * WP-Optimize's global wpo_cache_flush() is deliberately not used: it
     * flushes the object cache as well, and purge() does not.
     */
    protected const OBJECT_METHODS = [
        'WPO_Page_Cache::instance'                       => ['purge', 'WP-Optimize'],
        'Cloudflare\\APO\\WordPress\\Hooks::__construct' => ['purgeCacheEverything', 'Cloudflare'],
    ];

    /** The OBJECT_METHODS key that needs Cloudflare's plugin constants loaded. */
    protected const CLOUDFLARE_OBJECT_METHOD = 'Cloudflare\\APO\\WordPress\\Hooks::__construct';

    /** Actions a cache plugin purges everything on => the plugin. */
    protected const ACTIONS = [
        'litespeed_purge_all'       => 'LiteSpeed Cache',
        'rt_nginx_helper_purge_all' => 'Nginx Helper',
        'wphb_clear_page_cache'     => 'Hummingbird',
    ];

    /** The global Kinsta's must-use plugin keeps its cache object in. */
    protected const KINSTA_GLOBAL = 'kinsta_cache';

    /** Seconds any one HTTP request a cache plugin makes may take. */
    protected const HTTP_TIMEOUT = 3;

    /**
     * Seconds of HTTP the whole purge may spend before every later request in
     * it is cut to a hundredth of a second. Not a stop: capTimeout() never
     * returns zero, because zero means "no limit" to both WP transports.
     */
    protected const HTTP_BUDGET = 8;

    /** One purge per site per this many seconds; the hook still fires every time. */
    protected const DEBOUNCE_SECONDS = 10;

    /**
     * Option holding the last purge's timestamp. Not a transient: a purge that
     * flushes the object cache would drop a transient kept there.
     */
    protected const DEBOUNCE_OPTION = 'grocerslist_forms_last_purge';

    private static bool $purgedThisRequest = false;

    /**
     * @param bool $force Purge even within the per-site debounce window, so a
     *                    version change or an activation is never debounced
     *                    away. Still at most one purge per request: that cap
     *                    holds for every caller.
     */
    public function purgeAll(string $hash = '', bool $force = false): void
    {
        $purged = [];
        $asked = [];

        if (!self::$purgedThisRequest && ($force || $this->debounceElapsed())) {
            // http_request_args, not http_request_timeout: core applies the
            // timeout filter to its defaults and then merges the caller's args
            // over them, so a plugin that passes its own timeout wins.
            $deadline = $this->now() + static::HTTP_BUDGET;
            $cap = function ($args) use ($deadline) {
                if (is_array($args) && isset($args['timeout'])) {
                    $args['timeout'] = $this->capTimeout($args['timeout'], $deadline);
                }

                return $args;
            };
            add_filter('http_request_args', $cap, PHP_INT_MAX);

            try {
                foreach (static::FUNCTIONS as $function => $name) {
                    if (function_exists($function) && $this->attempt($name, $function)) {
                        $purged[] = $name;
                    }
                }

                foreach (static::STATIC_METHODS as $method => $name) {
                    [$class, $methodName] = explode('::', $method, 2);
                    if (class_exists($class) && method_exists($class, $methodName) && $this->attempt($name, [$class, $methodName])) {
                        $purged[] = $name;
                    }
                }

                // Cloudflare's Hooks constructor reads a file under
                // CLOUDFLARE_PLUGIN_DIR. On PHP 7.4 an undefined constant is a
                // warning, not a Throwable, so attempt() would not catch it.
                $objectMethods = static::OBJECT_METHODS;
                if (!defined('CLOUDFLARE_PLUGIN_DIR')) {
                    unset($objectMethods[static::CLOUDFLARE_OBJECT_METHOD]);
                }

                foreach ($objectMethods as $factory => [$method, $name]) {
                    [$class, $factoryName] = explode('::', $factory, 2);
                    if (!class_exists($class) || !method_exists($class, $method)) {
                        continue;
                    }
                    // method_exists('__construct') is false for a class with no
                    // declared constructor, so that case needs its own branch.
                    if ('__construct' !== $factoryName && !method_exists($class, $factoryName)) {
                        continue;
                    }

                    $purge = function () use ($class, $factoryName, $method) {
                        $object = '__construct' === $factoryName
                            ? new $class()
                            : call_user_func([$class, $factoryName]);
                        if (!is_object($object) || !is_callable([$object, $method])) {
                            throw new \RuntimeException('no callable ' . $method);
                        }
                        // WPO_Page_Cache::purge() returns false when the
                        // filesystem delete fails, so do not log it as a purge.
                        if (false === call_user_func([$object, $method])) {
                            throw new \RuntimeException($method . ' reported failure');
                        }
                    };

                    if ($this->attempt($name, $purge)) {
                        $purged[] = $name;
                    }
                }

                $kinsta = $GLOBALS[static::KINSTA_GLOBAL] ?? null;
                $kinstaPurge = is_object($kinsta) && isset($kinsta->kinsta_cache_purge) ? $kinsta->kinsta_cache_purge : null;
                if (is_object($kinstaPurge) && method_exists($kinstaPurge, 'purge_complete_caches')
                    && $this->attempt('Kinsta', [$kinstaPurge, 'purge_complete_caches'])) {
                    $purged[] = 'Kinsta';
                }

                foreach (static::ACTIONS as $action => $name) {
                    // Read before firing: has_action() only decides whether the
                    // log may name the plugin. The fire itself is
                    // unconditional, as LiteSpeed's always has been, so an
                    // `all` hook still sees it.
                    $listening = (bool) has_action($action);
                    if ($this->attempt($name, function () use ($action) {
                        do_action($action);
                    }) && $listening) {
                        // "asked", not "purged": Nginx Helper registers this
                        // listener even with purging switched off in its
                        // settings, so a purge is not proven here.
                        $asked[] = $name;
                    }
                }
            } finally {
                remove_filter('http_request_args', $cap, PHP_INT_MAX);
            }

            self::$purgedThisRequest = true;
            $this->recordPurge();
        }

        Logger::debug(
            'PageCachePurger: ' . ($hash !== '' ? 'form ' . $hash . ' changed' : 'purge requested')
            . '; purged ' . ($purged ? implode(', ', array_unique($purged)) : 'no known page cache')
            . ($asked ? '; asked ' . implode(', ', array_unique($asked)) : '')
        );

        // Outside the HTTP window and outside the debounce: this is the
        // creator's own code (their CDN recipe), with its own timeout, and it
        // must fire for every invalidation or a CDN purge goes missing.
        $this->attempt('grocerslist_forms_invalidated', function () use ($hash) {
            do_action('grocerslist_forms_invalidated', $hash);
        });
    }

    /**
     * Caps one outbound request at HTTP_TIMEOUT, or at what is left of the
     * whole purge's budget. A timeout of zero or less means "no limit" to both
     * of WordPress's transports, so it is capped too; a shorter timeout another
     * plugin chose is left alone.
     *
     * @param mixed $timeout
     */
    protected function capTimeout($timeout, float $deadline): float
    {
        $t = (float) $timeout;
        $left = max(0.01, $deadline - $this->now());
        $cap = min((float) static::HTTP_TIMEOUT, $left);

        return ($t <= 0.0 || $t > $cap) ? $cap : $t;
    }

    protected function now(): float
    {
        return microtime(true);
    }

    private function debounceElapsed(): bool
    {
        $last = (int) get_option(static::DEBOUNCE_OPTION, 0);

        return ($last + static::DEBOUNCE_SECONDS) <= (int) $this->now();
    }

    private function recordPurge(): void
    {
        // Never autoloaded: nothing reads it on an ordinary page view.
        update_option(static::DEBOUNCE_OPTION, (int) $this->now(), false);
    }

    /**
     * @param callable $purge
     */
    private function attempt(string $name, $purge): bool
    {
        try {
            call_user_func($purge);

            return true;
        } catch (\Throwable $e) {
            Logger::debug('PageCachePurger: ' . $name . ' failed: ' . $e->getMessage());

            return false;
        }
    }
}
