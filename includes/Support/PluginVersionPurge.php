<?php

namespace GrocersList\Support;

/**
 * Purges the site's page cache when the plugin is activated and when the
 * version running differs from the one the cache was last purged for.
 *
 * What a content gate hides is saved in post_content in the clear, and
 * WordPress renders a block it does not know as its saved content: whenever
 * the gate block is not registered — the plugin deactivated, or files half
 * replaced during an update — a page shows what its gates hide, and a page
 * cache can keep that render and go on serving it to every visitor once the
 * block is back. Purging as soon as the plugin runs again bounds that window
 * to the requests made while it was down.
 */
class PluginVersionPurge
{
    /** The plugin version the page cache was last purged for; read on every request, so autoloaded. */
    public const OPTION = 'grocerslist_page_cache_version';

    private PageCachePurger $purger;

    private string $version;

    /**
     * @param string|null $version The running plugin version; GROCERS_LIST_VERSION by default.
     */
    public function __construct(?PageCachePurger $purger = null, ?string $version = null)
    {
        $this->purger = $purger ?? new PageCachePurger();
        $this->version = $version ?? (defined('GROCERS_LIST_VERSION') ? (string) GROCERS_LIST_VERSION : '');
    }

    public function register(): void
    {
        // Late on init: page cache plugins load after this one (by name), so
        // their purge functions and hooks exist by then.
        add_action('init', [$this, 'purgeIfVersionChanged'], 99);
    }

    /**
     * The activation hook's: always, since reactivating keeps the version
     * but ends a window in which gates rendered open.
     */
    public function purgeOnActivation(): void
    {
        $this->purge('activated');
    }

    /**
     * Once per version: the first request an update (or any other change of
     * version) runs in purges; the rest compare one autoloaded option.
     */
    public function purgeIfVersionChanged(): void
    {
        if (get_option(self::OPTION) !== $this->version) {
            $this->purge('updated');
        }
    }

    private function purge(string $why): void
    {
        // Recorded before the purge, so that a purge that dies part-way
        // cannot make every later request purge again.
        update_option(self::OPTION, $this->version, true);

        Logger::debug('PluginVersionPurge: plugin ' . $why . ' (version ' . $this->version . '); purging the page cache');
        // Forced: a purge for a version change or an activation must never be
        // lost to the purger's per-site debounce.
        $this->purger->purgeAll('', true);
    }
}
