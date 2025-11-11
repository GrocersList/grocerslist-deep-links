<?php

namespace GrocersList\Frontend;

use GrocersList\Admin\PostGating;
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
     * Create a stylesheet that ensures ads are hidden on page load.
     *
     * In practice this looks like the following:
     * - We inject a stylesheet that has dynamically generated classes
     *   based on the adSelectors defined for the account.
     * - These classes take the following shape:
     *   body:not(.grocers-list-ads-processed) .my_ad_selector { display: none !important; }
     * - This is done for every adSelector that's defined
     * - These classes are applied immediately on page load to ensure that no ads are shown
     * - Once we determine the authentication state of the user, we then do the following:
     *   -- Immediately show the ads for unauthenticated users
     *   -- Remove the ads and then apply the class (this is so there is no brief flash of content)
     *
     * @return void
     **/
    public function createAdselectorStylesheet($creatorSettings): void {
        $STYLESHEET_IDENTIFIER= 'grocers-list-ad-selectors';

        $adSelectors = $creatorSettings->adSelectors;
        if ($adSelectors && count($adSelectors) > 0) {
            wp_register_style($STYLESHEET_IDENTIFIER, false);
            wp_enqueue_style($STYLESHEET_IDENTIFIER);

            $prefixedAdSelectors = array_map(static function ($className): string {
                return 'body:not(.grocers-list-ads-processed) ' . $className;
            }, $adSelectors);

            // Add stylesheet with each ad selector prefixed with 'body:not(.grocers-list-ads-processed) '
            // e.g.,: body:not(.grocers-list-ads-processed) .foo, body:not(.grocers-list-ads-processed) .bar { display: none !important; }
            wp_add_inline_style(
                $STYLESHEET_IDENTIFIER,
                implode(',', $prefixedAdSelectors) . ' { display: none !important; } }'
            );
        }
    }

    public function enqueueScripts(): void {
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
            'provisioning' => $creatorSettings->provisioning ?? null,
            'adSelectors' => $creatorSettings->adSelectors ?? null
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

        wp_localize_script('grocers-list-client', 'grocersList', $window_grocersList);

        $membershipsFullyEnabled = $this->creatorSettingsFetcher->getMembershipsFullyEnabled();
        $externalJsUrl = Config::getExternalJsUrl();

        if ($membershipsFullyEnabled && !empty($externalJsUrl)) {
            $this->createAdselectorStylesheet($creatorSettings);
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
