<?php

namespace GrocersList\Frontend;

use GrocersList\Admin\PostGating;
use GrocersList\Admin\PageGating;
use GrocersList\Admin\CategoryGating;
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
        // Add script to bottom of body for detecting and disabling ads
        add_action('wp_footer', [$this, 'disable_ads_inline_script']);
    }

    public function disable_ads_inline_script(): void {
        $inline_script = <<<EOD
            <script id="gl-disable-ads">
                (function() {
                    const isPaidMember = localStorage.getItem('gl_is_paid_member');

                    if (isPaidMember === 'true') {
                        document.body.classList.add('adthrive-disable-all', 'gl-paid-member');
                    }
                })();
            </script>
        EOD;

        echo $inline_script;
    }

    public function enqueueScripts(): void {
        $assetBase = plugin_dir_url(__FILE__) . '../../client-ui/dist/';

        wp_enqueue_script('grocers-list-client', $assetBase . 'bundle.js', [], $this->get_cache_busting_string(), true);

        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        $theme_root = get_stylesheet_directory();
        $theme_json_path = trailingslashit( $theme_root ) . 'theme.json';

        if ( file_exists( $theme_json_path ) ) {
            $theme_json_content = file_get_contents( $theme_json_path );
            $theme_data = json_decode( $theme_json_content, true );
        }

        $window_grocersList = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'theme' => $theme_data ?? null,
            'settings' => $creatorSettings->settings ?? null,
            'provisioning' => $creatorSettings->provisioning ?? null,
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
