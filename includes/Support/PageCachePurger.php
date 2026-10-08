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
    ];

    /** The global Kinsta's must-use plugin keeps its cache object in. */
    protected const KINSTA_GLOBAL = 'kinsta_cache';

    public function purgeAll(string $hash = ''): void
    {
        $purged = [];

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

        $kinsta = $GLOBALS[static::KINSTA_GLOBAL] ?? null;
        $kinstaPurge = is_object($kinsta) && isset($kinsta->kinsta_cache_purge) ? $kinsta->kinsta_cache_purge : null;
        if (is_object($kinstaPurge) && method_exists($kinstaPurge, 'purge_complete_caches')
            && $this->attempt('Kinsta', [$kinstaPurge, 'purge_complete_caches'])) {
            $purged[] = 'Kinsta';
        }

        // LiteSpeed Cache 3+ purges on this action rather than through
        // LiteSpeed_Cache_API; with the plugin absent nothing listens.
        $this->attempt('LiteSpeed Cache', function () {
            do_action('litespeed_purge_all');
        });

        Logger::debug(
            'PageCachePurger: ' . ($hash !== '' ? 'form ' . $hash . ' changed' : 'purge requested') . '; purged '
            . ($purged ? implode(', ', $purged) : 'no known page cache')
        );

        $this->attempt('grocerslist_forms_invalidated', function () use ($hash) {
            do_action('grocerslist_forms_invalidated', $hash);
        });
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
