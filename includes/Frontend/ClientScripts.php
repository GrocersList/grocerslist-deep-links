<?php

namespace GrocersList\Frontend;

use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Support\Config;

class ClientScripts
{
    private CreatorSettingsFetcher $creatorSettingsFetcher;

    private string $cacheBustingString;

    // cache busting string comprised of version and timestamp
    private function get_cache_busting_string(): string {
        if (empty($this->cacheBustingString)) {
            // TODO: cache for 5 min?
            $this->cacheBustingString = Config::getPluginVersion() . "_" . time();
        }

        return $this->cacheBustingString;
    }

    public function __construct(CreatorSettingsFetcher $creatorSettingsFetcher) {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_head', [$this, 'addPreloadHints']);
    }

    /**
     * Set no-cache headers to prevent caching by browsers, proxies, and 3rd party widgets
     *
     * @return void
     */
    private function setNoCacheHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate, private');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('ETag: "' . md5(uniqid()) . '"');
            header('X-Response-Time: ' . date('Y-m-d H:i:s'));
            header('X-Request-ID: ' . uniqid());
        }
    }

    public function enqueueScripts(): void
    {
        // Set comprehensive no-cache headers to prevent any caching
        $this->setNoCacheHeaders();

        $assetBase = plugin_dir_url(__FILE__) . '../../client-ui/dist/';
        
        wp_enqueue_script('grocers-list-client', $assetBase . 'bundle.js', [], $this->get_cache_busting_string(), true);

        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();

        $window_grocersList = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => [
                'grocers_list_get_init_memberships' => wp_create_nonce('grocers_list_get_init_memberships'),
                'grocers_list_record_membership_event' => wp_create_nonce('grocers_list_record_membership_event'),
                'grocers_list_signup_follower' => wp_create_nonce('grocers_list_signup_follower'),
                'grocers_list_login_follower' => wp_create_nonce('grocers_list_login_follower'),
                'grocers_list_forgot_password' => wp_create_nonce('grocers_list_forgot_password'),
                'grocers_list_reset_password' => wp_create_nonce('grocers_list_reset_password'),
                'grocers_list_checkout_follower' => wp_create_nonce('grocers_list_checkout_follower'),
                'grocers_list_check_follower_membership_status' => wp_create_nonce('grocers_list_check_follower_membership_status'),
            ],
            'settings' => $creatorSettings->settings ?? null,
            'WP_CLICK_TOKEN_MAX_AGE_MS' => $creatorSettings->WP_CLICK_TOKEN_MAX_AGE_MS ?? null,
        ];

        if (is_singular('post')) {
            $postId = get_the_ID();

            $window_grocersList['postId'] = get_the_ID();
            $window_grocersList['postGatingConfig'] = [
                'postGated' => get_post_meta($postId, 'grocers_list_post_gated', true) === '1',
                'recipeCardGated' => get_post_meta($postId, 'grocers_list_recipe_card_gated', true) === '1',
            ];
        }

        wp_localize_script('grocers-list-client', 'grocersList', $window_grocersList);

        $membershipsFullyEnabled = $this->creatorSettingsFetcher->getMembershipsFullyEnabled();
        $externalJsUrl = Config::getExternalJsUrl();

        if ($membershipsFullyEnabled && !empty($externalJsUrl)) {
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $this->get_cache_busting_string(), array('strategy' => 'async', 'in_footer' => false));
        }
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
            $versionedUrl = add_query_arg('ver', $this->get_cache_busting_string(), $externalJsUrl);
            echo '<link rel="preload" href="' . esc_url($versionedUrl) . '" as="script">' . "\n";
        }
    }
}
