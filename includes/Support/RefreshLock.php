<?php

namespace GrocersList\Support;

/**
 * A lock per key that holds under concurrent requests, so that one request
 * at a time does a piece of work: acquire() takes the lock with one atomic
 * write that only one request can win, and release() hands it back. A lock
 * its holder never releases (a fatal error, a killed process) lapses TTL
 * seconds after it was taken.
 *
 * A store that cannot lock fails open: acquire() answers true, and the work
 * goes ahead unlocked rather than not at all.
 */
final class RefreshLock
{
    private const CACHE_GROUP = 'grocerslist';

    /** @var callable(): int */
    private $clock;

    /** @var array<string, int> When each lock this request holds lapses; release() compares it. */
    private array $held = [];

    /**
     * @param (callable(): int)|null $clock Unix time now; time() by default.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * @param int $ttl Seconds the lock holds without a release, at least 1.
     * @return bool Whether this request may go ahead: it holds the lock, or
     *              the store could not lock at all.
     */
    public function acquire(string $key, int $ttl): bool
    {
        $now = (int) ($this->clock)();
        $ttl = max(1, $ttl);

        return wp_using_ext_object_cache()
            ? $this->acquireObjectCache($key, $now, $ttl)
            : $this->acquireOptionsTable($key, $now, $now + $ttl);
    }

    /**
     * Hands back a lock this request holds, and only while it is still this
     * request's: one that lapsed and was taken over belongs to its new holder.
     */
    public function release(string $key): void
    {
        if (!isset($this->held[$key])) {
            return;
        }

        $expiry = $this->held[$key];
        unset($this->held[$key]);

        if (wp_using_ext_object_cache()) {
            $this->releaseObjectCache($key, $expiry);
        } else {
            $this->releaseOptionsTable($key, $expiry);
        }
    }

    private function acquireObjectCache(string $key, int $now, int $ttl): bool
    {
        $expiry = $now + $ttl;

        try {
            // add() writes only where nothing is stored, and the cache does
            // that atomically: of two requests adding at once, one wins.
            if (wp_cache_add($key, $expiry, self::CACHE_GROUP, $ttl)) {
                $this->held[$key] = $expiry;

                return true;
            }

            // $force: the cache's own value, not this request's copy of it.
            $current = wp_cache_get($key, self::CACHE_GROUP, true);
            if (is_numeric($current) && (int) $current < $now) {
                // A cache that ignores TTLs keeps a lapsed lock: take it away.
                wp_cache_delete($key, self::CACHE_GROUP);
            } elseif ($current !== false) {
                return false;
            }

            // Evicted between the two calls, or lapsed: one more try.
            if (wp_cache_add($key, $expiry, self::CACHE_GROUP, $ttl)) {
                $this->held[$key] = $expiry;

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            // A cache that cannot lock is no reason to stop the work.
            Logger::debug('RefreshLock: the object cache could not lock ' . $key . '; going ahead unlocked');

            return true;
        }
    }

    /**
     * Without a persistent object cache the lock is a transient-named row
     * with a timeout row at its expiry, so WordPress's own expired-transient
     * cleanup deletes both after a holder that never released it. Nothing
     * reads them through get_option(), so there is no cached copy to go stale.
     */
    private function acquireOptionsTable(string $key, int $now, int $expiry): bool
    {
        global $wpdb;

        $timeout = '_transient_timeout_' . $key;

        // option_name is unique, so exactly one request creates the rows; any
        // other gets a duplicate-key error. Not add_option(): it upserts,
        // taking a lock another request holds.
        $suppressed = $wpdb->suppress_errors(true);
        $created = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s), (%s, %s, %s)",
            '_transient_' . $key,
            '1',
            'no',
            $timeout,
            (string) $expiry,
            'no'
        ));
        $wpdb->suppress_errors($suppressed);

        if ($created) {
            $this->held[$key] = $expiry;

            return true;
        }

        // A lapsed lock is taken over by one atomic update, which only one of
        // the requests racing for it can make. "+ 0" so SQLite compares
        // numbers, not the text column's strings.
        $takenOver = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value + 0 < %d",
            (string) $expiry,
            $timeout,
            $now
        ));

        if ((int) $takenOver === 1) {
            $this->held[$key] = $expiry;

            return true;
        }

        // Held, unless the writes themselves failed: no row, because the INSERT
        // failed for some other reason. A database that cannot lock is no
        // reason to stop the work either.
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $timeout
        ));
        if ($current !== null && (int) $current >= $now) {
            return false;
        }

        Logger::debug('RefreshLock: the options table could not lock ' . $key . '; going ahead unlocked');

        return true;
    }

    private function releaseObjectCache(string $key, int $expiry): void
    {
        try {
            // Compared as a number: some caches hand an integer back as a string.
            $current = wp_cache_get($key, self::CACHE_GROUP, true);
            if (is_numeric($current) && (int) $current === $expiry) {
                wp_cache_delete($key, self::CACHE_GROUP);
            }
        } catch (\Throwable $e) {
            Logger::debug('RefreshLock: the object cache could not release ' . $key . '; it lapses on its own');
        }
    }

    private function releaseOptionsTable(string $key, int $expiry): void
    {
        global $wpdb;

        // The value row first, then the timeout row while it still holds this
        // request's expiry. Who holds the lock is the timeout row's to say:
        // acquire() takes nothing while it is there and current. A release
        // that dies between the two leaves the timeout row alone, which the
        // next acquire() takes over once it lapses (and its release deletes);
        // the other way round, a lone value row would make every later
        // acquire() fail open. So the value row of a lock that lapsed and was
        // taken over goes too, and costs its new holder nothing.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_' . $key
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            '_transient_timeout_' . $key,
            (string) $expiry
        ));
    }
}
