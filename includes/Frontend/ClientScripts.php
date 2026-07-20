<?php

namespace GrocersList\Frontend;

use GrocersList\Admin\PostGating;
use GrocersList\Admin\PageGating;
use GrocersList\Admin\CategoryGating;
use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Service\MemberService;
use GrocersList\Support\Config;
use GrocersList\Support\Logger;

class ClientScripts
{
    private const GATED_POST_URLS_TRANSIENT_PREFIX = 'grocerslist_gated_post_urls_';
    private const GATED_POST_URLS_TTL_SECONDS = 5 * 60; // 5 minutes
    private const GATED_POST_URLS_MAX = 500;

    private CreatorSettingsFetcher $creatorSettingsFetcher;
    private MemberService $memberService;

    private string $cacheBustingString;

    // cache busting string comprised of version and timestamp
    public function getCacheBustingString(): string {
        if (empty($this->cacheBustingString)) {
            // TODO: cache for 5 min?
            $this->cacheBustingString = Config::getPluginVersion() . "_" . time();
        }

        return $this->cacheBustingString;
    }

    public function getBundleUrl(): string {
        return plugin_dir_url(__FILE__) . '../../client-ui/dist/bundle.js';
    }

    public function __construct(CreatorSettingsFetcher $creatorSettingsFetcher, MemberService $memberService) {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
        $this->memberService = $memberService;
    }

    public function hide_admin_bar_from_front_end(){
        if (current_user_can('editor') || current_user_can('administrator')) {
            return true;
        }
        return false;
    }

    public function register(): void
    {
        add_filter('show_admin_bar', [$this, 'hide_admin_bar_from_front_end']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_head', [$this, 'addPreloadHints']);
        // Emit inline style before body paint so server-rendered lock icons pick up
        // the configured color immediately, without waiting for the widget bundle.
        add_action('wp_head', [$this, 'outputLockIconBackgroundStyle']);
        add_filter('body_class', [$this, 'add_ad_removal_classes']);
        // Inject HTML to disable mediavine ads if applicable
        add_action('wp_footer', [$this, 'mediavine_disable_ads']);
        // save_post fires on any post save; 5-min TTL bounds staleness beyond that
        add_action('save_post', [$this, 'invalidateGatedPostUrlsCache']);
        // Wrap featured images from the_post_thumbnail() so themes that render server-side
        // still get the overlay marker; the widget's client-side scanner is the primary path
        add_filter('post_thumbnail_html', [$this, 'wrapGatedPostThumbnail'], 10, 2);
    }

    public function add_ad_removal_classes(array $classes): array {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        list($email, , $is_paid) = $this->memberService->getMemberData($creatorSettings->creatorAccountId);

        if (!$email || !$is_paid) {
            return $classes;
        }

        $classes[] = 'adthrive-disable-all';
        $classes[] = 'gl-paid-member';
        return $classes;
    }

    public function mediavine_disable_ads(): void {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        list($email, , $is_paid) = $this->memberService->getMemberData($creatorSettings->creatorAccountId);

        if (!$email || !$is_paid) {
            echo '';
            return;
        }

        $mediavine_element = <<<EOD
            <div id="mediavine-settings" data-blocklist-leaderboard="1" data-blocklist-sidebar-atf="1" data-blocklist-sidebar-btf="1" data-blocklist-content-desktop="1" data-blocklist-content-mobile="1" data-blocklist-adhesion-mobile="1" data-blocklist-adhesion-tablet="1" data-blocklist-adhesion-desktop="1" data-blocklist-recipe="1" data-blocklist-auto-insert-sticky="1" data-blocklist-chicory="1" data-blocklist-zergnet="1" data-blocklist-interstitial-mobile="1" data-blocklist-interstitial-desktop="1" data-blocklist-universal-player-desktop="1" data-blocklist-universal-player-mobile="1"></div>
EOD;

        echo $mediavine_element;
    }

    public function enqueueScripts(): void {
        wp_enqueue_script('grocers-list-client', $this->getBundleUrl(), [], $this->getCacheBustingString(), true);

        $window_grocersList = $this->buildWindowGrocersList();

        wp_localize_script('grocers-list-client', 'grocersList', $window_grocersList);

        $membershipsFullyEnabled = $this->creatorSettingsFetcher->getMembershipsFullyEnabled();
        $externalJsUrl = Config::getExternalJsUrl();

        if ($membershipsFullyEnabled && !empty($externalJsUrl)) {
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $this->getCacheBustingString(), array('strategy' => 'async', 'in_footer' => false));
        }
    }

    public function buildWindowGrocersList(): array {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        $theme_root = get_stylesheet_directory();
        $theme_json_path = trailingslashit( $theme_root ) . 'theme.json';

        if ( file_exists( $theme_json_path ) ) {
            $theme_json_content = file_get_contents( $theme_json_path );
            $theme_data = json_decode( $theme_json_content, true );
        }

        list($email, $subscription_status, $is_paid_member, $is_past_due, $subscription_management_link) = $this->memberService->getMemberData($creatorSettings->creatorAccountId);

        // Skip the gated-post-urls WP_Query work entirely when the creator hasn't
        // opted in — that setting is off for the vast majority of sites and the
        // query is not free.
        $showLockOnGatedPostImages = $this->readShowLockOnGatedPostImages($creatorSettings);
        // Emit the color as null when the toggle is off so the widget's typing
        // stays consistent regardless of the short-circuit above.
        $lockIconBackgroundColor = $showLockOnGatedPostImages
            ? $this->readLockIconBackgroundColor($creatorSettings)
            : null;

        $window_grocersList = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'theme' => $theme_data ?? null,
            'settings' => $creatorSettings->settings ?? null,
            'provisioning' => $creatorSettings->provisioning ?? null,
            'creatorId' => $creatorSettings->creatorAccountId ?? null,
            'wpUserEnabled' => true,
            'showLockOnGatedPostImages' => $showLockOnGatedPostImages,
            'lockIconBackgroundColor' => $lockIconBackgroundColor,
            'gatedPostUrls' => $showLockOnGatedPostImages
                ? $this->getGatedPostUrls($creatorSettings->creatorAccountId ?? null)
                : [],
            'member' => [
                'isWpUser' => !!$email,
                'email' => $email ?: null,
                'isPaidMember' => (bool) $is_paid_member,
                'isPastDue' => (bool) $is_past_due,
                'subscriptionStatus' => $subscription_status ?: null,
                'subscriptionManagementLink' => $subscription_management_link ?: null,
            ]
        ];

        if (is_singular('post')) {
            $postId = get_the_ID();

            // Use effective gating which checks both post-level and category-level settings
            $effectiveGating = PostGating::getEffectiveGating($postId);

            $window_grocersList['postId'] = get_the_ID();
            $window_grocersList['postGatingConfig'] = [
                'postGated' => $effectiveGating['post'],
                'recipeCardGated' => $effectiveGating['recipe'],
            ];
        }

        if (is_singular('page')) {
            $pageId = get_the_ID();
            $effectiveGating = PageGating::getEffectiveGating($pageId);
            $window_grocersList['pageGatingConfig'] = [
                'pageGated' => $effectiveGating['page'],
            ];
        }

        if (is_category()) {
            $category = get_queried_object();
            if ($category instanceof \WP_Term) {
                $effectiveGating = CategoryGating::getEffectiveGating($category->term_id);
                $window_grocersList['categoryGatingConfig'] = [
                    'categoryGated' => $effectiveGating['category'],
                ];
            }
        }

        return $window_grocersList;
    }
    /**
     * Summary of addPreloadHints
     * Adds preload hints for the external JS file
     * to improve the loading performance
     * Note this will not load the external JS file, it will only hint to the browser that it should preload it
     * so the browser can start downloading it early and have it ready when needed
     * @return void
     */
    public function addPreloadHints(): void
    {
        $membershipsFullyEnabled = $this->creatorSettingsFetcher->getMembershipsFullyEnabled();
        $externalJsUrl = Config::getExternalJsUrl();

        if ($membershipsFullyEnabled && !empty($externalJsUrl)) {
            $versionedUrl = add_query_arg('ver', $this->getCacheBustingString(), $externalJsUrl);
            echo '<link rel="preload" href="' . esc_url($versionedUrl) . '" as="script">' . "\n";
        }
    }

    /**
     * Reads the showLockOnGatedPostImages flag from creator settings.
     * Server serializes creator.membershipSettings as settings.memberships,
     * so the flag lands at $creatorSettings->settings->memberships->showLockOnGatedPostImages.
     * Defaults to false when the field (or its parents) is absent.
     */
    private function readShowLockOnGatedPostImages($creatorSettings): bool
    {
        if (!$creatorSettings || !isset($creatorSettings->settings)) {
            return false;
        }

        $settings = $creatorSettings->settings;

        if (!isset($settings->memberships) || !isset($settings->memberships->showLockOnGatedPostImages)) {
            return false;
        }

        return (bool) $settings->memberships->showLockOnGatedPostImages;
    }

    /**
     * Reads the lockIconBackgroundColor from creator settings. Returns null when
     * absent, empty, or malformed. Only 6-digit hex (`#RRGGBB`) is accepted;
     * the server validates on write, so anything else here is defensive.
     */
    private function readLockIconBackgroundColor($creatorSettings): ?string
    {
        if (!$creatorSettings || !isset($creatorSettings->settings)) {
            return null;
        }

        $settings = $creatorSettings->settings;

        if (!isset($settings->memberships) || !isset($settings->memberships->lockIconBackgroundColor)) {
            return null;
        }

        $color = $settings->memberships->lockIconBackgroundColor;

        if (!is_string($color) || $color === '') {
            return null;
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return $color;
    }

    /**
     * Emits an inline `<style>` in wp_head so server-rendered lock icons pick
     * up the configured background color before the widget bundle loads
     * (avoids a FOUC where icons briefly render with the widget's built-in
     * rgba(0,0,0,0.65) default). No output when the toggle is off or the
     * color is unset.
     */
    public function outputLockIconBackgroundStyle(): void
    {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();

        if (!$this->readShowLockOnGatedPostImages($creatorSettings)) {
            return;
        }

        $color = $this->readLockIconBackgroundColor($creatorSettings);

        if ($color === null) {
            return;
        }

        echo '<style id="gl-lock-icon-inline-style">.gl-locked-thumbnail-icon { background: '
            . esc_attr($color)
            . '; }</style>'
            . "\n";
    }

    /**
     * Returns an array of URL pathnames (leading slash) for every published post
     * where PostGating reports effective gating at the post level. Cached in a
     * transient keyed on creator id + plugin version for 5 minutes.
     *
     * @param string|null $creatorAccountId
     * @return string[]
     */
    public function getGatedPostUrls($creatorAccountId): array
    {
        $cacheKey = self::GATED_POST_URLS_TRANSIENT_PREFIX
            . md5(($creatorAccountId ?? 'anon') . '|' . Config::getPluginVersion());

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $urls = $this->queryGatedPostUrls();
        set_transient($cacheKey, $urls, self::GATED_POST_URLS_TTL_SECONDS);
        return $urls;
    }

    /**
     * Composes the gated-post candidate set from BOTH sources:
     *   A. posts whose post-level META_POST_GATED is '1', and
     *   B. posts assigned to a category whose META_CATEGORY_GATING_TYPE is 'post' or 'page'.
     * The union is then re-checked with PostGating::getEffectiveGating(), which is the
     * authoritative filter — it honors per-post META_NO_GATING opt-out and post-level
     * overrides of a category's gating. The GATED_POST_URLS_MAX cap is enforced on
     * the FINAL emitted list so Query A can't starve Query B of the budget.
     *
     * @return string[]
     */
    private function queryGatedPostUrls(): array
    {
        // Query A: posts with the post-level flag set.
        $queryA = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            // Fetch more than the cap so we can detect truncation without a second query
            'posts_per_page' => self::GATED_POST_URLS_MAX + 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => PostGating::META_POST_GATED,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        $postIdsA = isset($queryA->posts) && is_array($queryA->posts) ? $queryA->posts : [];

        // Query B: posts in gated categories (category-inherited gating). Enumerate
        // gated category term ids first; skip Query B entirely if none exist so we
        // don't accidentally issue `category__in => []` (which returns all posts).
        $gatedCategoryTermIds = $this->queryGatedCategoryTermIds();

        $postIdsB = [];
        if (!empty($gatedCategoryTermIds)) {
            $queryB = new \WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => self::GATED_POST_URLS_MAX + 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'category__in' => $gatedCategoryTermIds,
            ]);

            $postIdsB = isset($queryB->posts) && is_array($queryB->posts) ? $queryB->posts : [];
        }

        $candidateIds = array_values(array_unique(array_merge($postIdsA, $postIdsB)));

        $urls = [];
        $truncated = false;
        foreach ($candidateIds as $postId) {
            // Authoritative re-check: honors META_NO_GATING opt-out and
            // resolves per-post overrides of category gating.
            $gating = PostGating::getEffectiveGating((int) $postId);
            if (empty($gating['post'])) {
                continue;
            }

            $permalink = get_permalink($postId);
            if (!$permalink) {
                continue;
            }

            $path = parse_url($permalink, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (count($urls) >= self::GATED_POST_URLS_MAX) {
                $truncated = true;
                break;
            }

            $urls[] = $path;
        }

        if ($truncated) {
            Logger::debug(
                'gatedPostUrls truncated to ' . self::GATED_POST_URLS_MAX
                . ' entries (site has more gated posts than the cap).'
            );
        }

        return $urls;
    }

    /**
     * Returns term_ids of categories whose gating type is 'post' or 'page'.
     * Uses WP_Term_Query so category-inherited gated posts can be discovered
     * without loading every published post.
     *
     * @return int[]
     */
    private function queryGatedCategoryTermIds(): array
    {
        if (!class_exists('WP_Term_Query')) {
            return [];
        }

        $termQuery = new \WP_Term_Query([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => CategoryGating::META_CATEGORY_GATING_TYPE,
                    'value' => ['post', 'page'],
                    'compare' => 'IN',
                ],
            ],
        ]);

        $termIds = isset($termQuery->terms) && is_array($termQuery->terms) ? $termQuery->terms : [];

        return array_map('intval', $termIds);
    }

    /**
     * Invalidates all gated-post-url transients on any post save. The transient
     * key is creator-id-scoped and we can't cheaply enumerate creators from here,
     * so we delete by the known prefix via WP option table (best-effort — the
     * 5-minute TTL bounds the worst-case staleness).
     */
    public function invalidateGatedPostUrlsCache($post_id = null): void
    {
        // Skip autosaves, revisions, and saves of unrelated post types — those
        // fire save_post but can't change the gated-post-urls result set.
        // Preserve the null-id manual-invocation path (tests, cron, etc.).
        if ($post_id !== null) {
            if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
                return;
            }

            if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
                return;
            }

            if (function_exists('get_post_type') && get_post_type($post_id) !== 'post') {
                return;
            }
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $like = '_transient_' . self::GATED_POST_URLS_TRANSIENT_PREFIX . '%';
        $timeoutLike = '_transient_timeout_' . self::GATED_POST_URLS_TRANSIENT_PREFIX . '%';

        // The options table is the source of truth for transients when no external
        // object cache is present; deleting both the value and timeout rows is safe.
        if (method_exists($wpdb, 'prepare') && property_exists($wpdb, 'options')) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeoutLike));
        }
    }

    /**
     * When a gated post's featured image is rendered via the_post_thumbnail(),
     * wrap the output so themes rendering server-side still receive the lock overlay
     * markers the widget uses. Widget's client-side scanner remains the primary path.
     *
     * @param string $html
     * @param int    $post_id
     * @return string
     */
    public function wrapGatedPostThumbnail($html, $post_id): string
    {
        if (!is_string($html) || $html === '') {
            return (string) $html;
        }

        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        if (!$this->readShowLockOnGatedPostImages($creatorSettings)) {
            return $html;
        }

        $gating = PostGating::getEffectiveGating((int) $post_id);
        if (empty($gating['post'])) {
            return $html;
        }

        $iconSpan = '<span class="gl-locked-thumbnail-icon" aria-hidden="true">'
            . self::LOCK_ICON_SVG
            . '</span>';

        // Inject the icon INSIDE the anchor (right before </a>) so it inherits
        // whatever positioning context the theme has already established for
        // the media container. Wrapping around the anchor breaks Blocksy-style
        // aspect-ratio containers where the anchor is position: absolute.
        $modified = preg_replace('/(\s*<\/a>\s*)$/i', $iconSpan . '$1', $html, 1);
        if (is_string($modified) && $modified !== $html) {
            return $modified;
        }

        // No trailing anchor to inject into (e.g. `the_post_thumbnail` didn't
        // wrap in a link). Fall back to the legacy wrapper approach — the
        // widget's JS handles this same shape idempotently.
        return '<div class="gl-locked-thumbnail-wrapper">'
            . $html
            . $iconSpan
            . '</div>';
    }

    // Inlined so the PHP-rendered overlay is fully self-contained and paints
    // without waiting for the widget bundle. Fill is hardcoded on the path
    // rather than inherited from CSS `color`, so host-theme rules like
    // `svg path { fill: … }` can't wash it out. Kept in sync with the
    // identical LOCK_SVG constant in grocerslist-widget src/LockOverlay/.
    private const LOCK_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="#ffffff" d="M12 1a5 5 0 0 0-5 5v3H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V11a2 2 0 0 0-2-2h-2V6a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v3H9V6a3 3 0 0 1 3-3z"/></svg>';
}
