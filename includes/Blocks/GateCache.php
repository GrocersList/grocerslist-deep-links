<?php

namespace GrocersList\Blocks;

/**
 * What GateContent::render() made for one gate, kept for a few minutes, so a
 * post's subscribers share one render instead of each paying for their own.
 *
 * What a gate hides renders the same for all of them: the recognition cookie
 * says that someone in this browser subscribed, never who (GateCookie), and
 * nothing the render runs reads user state. The request, the ~25 KB answer and
 * its Cache-Control: private, no-store all stay; only the render goes.
 *
 * Only a cookie-holder's reveal is cached. A visitor grocerslist_content_gate_bypass
 * admits gets other HTML — ContentGateBlock::view() renders a gate nested in
 * the content LOCKED for them, glimpse markup and a fresh form included — and
 * an editor's is different again, so GateContent asks for neither.
 *
 * The key is the post, the gate, the post's modified stamp and the plugin
 * version with SCHEMA, so an ordinary edit moves the key instead of needing a
 * purge. register() hooks the saves that change what a gate hides without
 * moving that stamp.
 */
class GateCache
{
    /**
     * Disjoint from GateController's 'grocerslist_gate_rl_' rate-limit rows,
     * which RateLimiter writes straight into wp_options (RateLimiter::hitOptionsTable()):
     * a prefix sweep over 'grocerslist_gate_' would delete every visitor's
     * reveal counter and hand them all a fresh 60.
     */
    private const PREFIX = 'grocerslist_reveal_';

    /** Seconds an entry is kept, before grocerslist_gate_cache_ttl. */
    private const TTL = 300;

    /**
     * Bump this when the revealed output changes within one plugin version.
     * GROCERS_LIST_VERSION is the other half of the key's version segment,
     * so a plugin update (the beta's update to 1.30.0 included) starts a
     * fresh cache by itself; this covers a change to what a reveal renders
     * that ships without one.
     */
    private const SCHEMA = '1';

    /**
     * An object cache's item limit (Memcached's default is 1 MiB) turns a
     * bigger entry into a silent permanent miss plus a wasted serialize.
     */
    private const MAX_BYTES = 524288;

    private string $version;

    /** At most one full sweep per request, however often the action fires. */
    private bool $purged = false;

    /**
     * @param string|null $version The running plugin version; GROCERS_LIST_VERSION by default.
     */
    public function __construct(?string $version = null)
    {
        $this->version = $version ?? (defined('GROCERS_LIST_VERSION') ? (string) GROCERS_LIST_VERSION : '');
    }

    public function register(): void
    {
        // post_modified_gmt moves on an ordinary edit, which is what makes the
        // key invalidate itself. These are the saves that can change what a
        // gate hides while the stamp stays put.
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        add_action('wprm_clear_cache', [$this, 'onWprmClearCache'], 10, 1);
        add_action('grocerslist_forms_invalidated', [$this, 'onFormsInvalidated'], 10, 0);
    }

    /**
     * @param mixed $post The WP_Post the render holds; its post_modified_gmt keys the entry.
     */
    public function get($post, string $gateId): ?string
    {
        $postId = self::postId($post);
        if ($postId <= 0 || $this->ttl($postId, $gateId) <= 0) {
            return null;
        }

        $modified = self::modified($post);
        $entry = get_transient(self::key($postId, $gateId, $modified, $this->version));

        // The envelope, not the bare string: get_transient() cannot tell ''
        // from a miss, and a reused key (an md5 collision, a restored
        // database) would otherwise serve another post's content.
        if (!is_array($entry)
            || !isset($entry['html'])
            || !is_string($entry['html'])
            || ($entry['post'] ?? null) !== $postId
            || ($entry['gate'] ?? null) !== $gateId
            || ($entry['mod'] ?? null) !== $modified
            || ($entry['ver'] ?? null) !== $this->stamp()) {
            return null;
        }

        return $entry['html'];
    }

    /**
     * @param mixed $post The WP_Post the render held.
     */
    public function put($post, string $gateId, string $html): void
    {
        $postId = self::postId($post);
        if ($postId <= 0) {
            return;
        }

        $ttl = $this->ttl($postId, $gateId);
        if ($ttl <= 0 || strlen($html) > self::MAX_BYTES) {
            return;
        }

        // post_modified_gmt has one-second resolution, so a save in the same
        // second as this render deleted the key this render is about to write.
        // Re-read the post (WordPress has it in the object cache from the
        // render) and decline, rather than freeze pre-edit content for the
        // whole TTL.
        $modified = self::modified($post);
        if (self::modified(get_post($postId)) !== $modified) {
            return;
        }

        set_transient(
            self::key($postId, $gateId, $modified, $this->version),
            [
                'html' => $html,
                'post' => $postId,
                'gate' => $gateId,
                'mod'  => $modified,
                'ver'  => $this->stamp(),
            ],
            // Always an expiration: with 0 the row autoloads, and a ~25 KB
            // reveal would land in alloptions on every request.
            $ttl
        );
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     */
    public function onSavePost($postId, $post = null): void
    {
        // clean_post_cache() has run by save_post, so $post is the fresh row.
        $this->forget(is_object($post) ? $post : get_post(self::id($postId)));
    }

    /**
     * $before carries the old content and the old stamp, so it names the exact
     * orphan — including an edit that took the gate out, or unpublished the post.
     *
     * @param mixed $postId
     * @param mixed $after
     * @param mixed $before
     */
    public function onPostUpdated($postId, $after = null, $before = null): void
    {
        $this->forget(is_object($before) ? $before : null);
        $this->forget(is_object($after) ? $after : get_post(self::id($postId)));
    }

    /**
     * WP Recipe Maker purges the parent post's page cache when a recipe in it
     * is saved (class-wprm-recipe-saver.php, through WPRM_Cache::clear()) and
     * never re-saves the post, so post_modified_gmt does not move. Without
     * this, a creator correcting a quantity fixes the public page in seconds
     * and leaves subscribers on the old recipe for the rest of the TTL.
     *
     * @param mixed $postId Can be false, which WPRM means as "every post".
     */
    public function onWprmClearCache($postId = null): void
    {
        $id = self::id($postId);
        if ($id <= 0) {
            return;
        }

        $this->forget(get_post($id));
    }

    /**
     * A form's config changed, so every reveal holding that form is stale and
     * no post id says which. There are no keys to compute, so this is the one
     * sweep: once per request, and never where an external object cache holds
     * the entries (no key list exists there — the TTL is the bound).
     */
    public function onFormsInvalidated(): void
    {
        if ($this->purged) {
            return;
        }

        $this->purged = true;

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'esc_like')) {
            return;
        }

        // esc_like() makes the underscores in the prefix literal, so the
        // pattern cannot reach a 'grocerslist_gate_rl_' rate-limit row.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . self::PREFIX) . '%',
            $wpdb->esc_like('_transient_timeout_' . self::PREFIX) . '%'
        ));
    }

    /**
     * Every gate in $post's content forgotten, at $post's own stamp. Public so
     * support can reach it: wp eval '(new \GrocersList\Blocks\GateCache())->forget(get_post(85));'
     *
     * @param mixed $post
     */
    public function forget($post): void
    {
        $postId = self::postId($post);
        if ($postId <= 0 || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        // The block parser is WordPress 5.0; the plugin's floor is 4.4, where
        // a classic post whose content holds the literal "wp:block" would
        // otherwise fatal here (GatedRecipeLinks::gatedRecipeIds() guards the
        // same way). No post-type filter: a gate lives in any viewable type,
        // pages included.
        if (!function_exists('parse_blocks')) {
            return;
        }

        $content = isset($post->post_content) ? (string) $post->post_content : '';
        $mayHide = strpos($content, GateRegion::DELIMITER) !== false
            || strpos($content, 'wp:block') !== false
            || strpos($content, 'wp:core/block') !== false;
        if (!$mayHide) {
            return;
        }

        $modified = self::modified($post);
        foreach (GateContent::gateIds(parse_blocks($content)) as $gateId) {
            delete_transient(self::key($postId, $gateId, $modified, $this->version));
        }
    }

    private function ttl(int $postId, string $gateId): int
    {
        return (int) apply_filters('grocerslist_gate_cache_ttl', self::TTL, $postId, $gateId);
    }

    private function stamp(): string
    {
        return $this->version . '/' . self::SCHEMA;
    }

    private static function key(int $postId, string $gateId, string $modified, string $version): string
    {
        return self::PREFIX . md5($postId . '|' . $gateId . '|' . $modified . '|' . $version . '/' . self::SCHEMA);
    }

    /**
     * @param mixed $post
     */
    private static function postId($post): int
    {
        return is_object($post) ? self::id($post->ID ?? null) : 0;
    }

    /**
     * The fixtures are bare (object) casts, so the property can be missing.
     *
     * @param mixed $post
     */
    private static function modified($post): string
    {
        return is_object($post) && isset($post->post_modified_gmt) ? (string) $post->post_modified_gmt : '';
    }

    /**
     * @param mixed $value
     */
    private static function id($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
