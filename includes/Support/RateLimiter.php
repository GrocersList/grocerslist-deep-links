<?php

namespace GrocersList\Support;

/**
 * A fixed-window request count per key that holds under concurrent requests:
 * each hit is counted by one atomic write, and the answer comes from that
 * write, never from a read made before it.
 */
class RateLimiter
{
    private const CACHE_GROUP = 'grocerslist';

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix time now; time() by default.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Counts one request against $key in the window it falls in. Windows run
     * back to back from the epoch, so every key's resets at the same moment.
     *
     * @param int $limit Requests allowed per window, at least 1.
     * @param int $window Seconds, at least 1.
     * @return int|null Null when the request is within the limit, else the
     *                  seconds until its window resets, for Retry-After.
     */
    public function hit(string $key, int $limit, int $window): ?int
    {
        $now = (int) ($this->clock)();
        $bucket = intdiv($now, $window);
        $resetAt = ($bucket + 1) * $window;
        $name = $key . '_' . $bucket;

        $allowed = wp_using_ext_object_cache()
            ? $this->hitObjectCache($name, $limit, $window)
            : $this->hitOptionsTable($name, $limit, $resetAt);

        return $allowed ? null : max(1, $resetAt - $now);
    }

    private function hitObjectCache(string $name, int $limit, int $window): bool
    {
        try {
            // add() only ever starts a count; incr() is the cache's own atomic
            // increment, so its result is this request's place in the window.
            wp_cache_add($name, 0, self::CACHE_GROUP, $window);
            $count = wp_cache_incr($name, 1, self::CACHE_GROUP);

            if ($count === false) {
                // Evicted between the two calls.
                wp_cache_add($name, 0, self::CACHE_GROUP, $window);
                $count = wp_cache_incr($name, 1, self::CACHE_GROUP);
            }
        } catch (\Throwable $e) {
            $count = false;
        }

        if ($count === false) {
            // A cache that cannot count is no reason to turn a visitor away.
            Logger::debug('RateLimiter: the object cache could not count a request; letting it through');

            return true;
        }

        return (int) $count <= $limit;
    }

    /**
     * Without a persistent object cache the count is a transient-named row, with
     * a timeout row at the window's end, so WordPress's own expired-transient
     * cleanup deletes both. Nothing reads them through get_option(), so there
     * is no cached copy to go stale.
     */
    private function hitOptionsTable(string $name, int $limit, int $resetAt): bool
    {
        global $wpdb;

        $option = '_transient_' . $name;

        // "+ 0" so SQLite compares numbers: against the text column, a bare
        // option_value < 10 compares strings and stops the count at 2.
        $claim = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value + 0 < %d",
            $option,
            $limit
        );

        if ((int) $wpdb->query($claim) === 1) {
            return true;
        }

        // No row yet, or a full one. option_name is unique, so exactly one
        // request creates the row, with its own hit counted; any other gets a
        // duplicate-key error and claims again. Not add_option(): it upserts,
        // resetting a count that other requests have already taken slots in.
        $suppressed = $wpdb->suppress_errors(true);
        $created = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s), (%s, %s, %s)",
            $option,
            '1',
            'no',
            '_transient_timeout_' . $name,
            (string) $resetAt,
            'no'
        ));
        $wpdb->suppress_errors($suppressed);

        if ($created || (int) $wpdb->query($claim) === 1) {
            return true;
        }

        // Full, unless the writes themselves failed: no row, because the INSERT
        // failed for some other reason, or a count still under the limit, because
        // the UPDATE did. A database that cannot count is no reason to turn a
        // visitor away either.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option
        ));
        if ($count !== null && (int) $count >= $limit) {
            return false;
        }

        Logger::debug('RateLimiter: the options table could not count a request; letting it through');

        return true;
    }
}
