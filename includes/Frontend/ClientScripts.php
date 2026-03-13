<?php

namespace GrocersList\Frontend;

use GrocersList\Admin\PostGating;
use GrocersList\Admin\PageGating;
use GrocersList\Admin\CategoryGating;
use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Service\MemberService;
use GrocersList\Support\Config;

class ClientScripts
{
    private CreatorSettingsFetcher $creatorSettingsFetcher;
    private MemberService $memberService;

    private string $cacheBustingString;

    // cache busting string comprised of version and timestamp
    private function get_cache_busting_string(): string {
        if (empty($this->cacheBustingString)) {
            // TODO: cache for 5 min?
            $this->cacheBustingString = Config::getPluginVersion() . "_" . time();
        }

        return $this->cacheBustingString;
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
        add_filter('body_class', [$this, 'add_ad_removal_classes']);
        // Inject HTML to disable mediavine ads if applicable
        add_action('wp_footer', [$this, 'mediavine_disable_ads']);
    }

    public function add_ad_removal_classes(): array {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();
        list($email, , $is_paid) = $this->memberService->getMemberData($creatorSettings->creatorAccountId);

        if (!$email || !$is_paid) {
            return [];
        }

        $classes = ['adthrive-disable-all', 'gl-paid-member'];
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
            <div id="mediavine-settings" data-blocklist-all="1"></div>
EOD;

        echo $mediavine_element;
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

        list($email, $subscription_status, $is_paid_member, $is_past_due, $subscription_management_link) = $this->memberService->getMemberData($creatorSettings->creatorAccountId);

        $window_grocersList = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'theme' => $theme_data ?? null,
            'settings' => $creatorSettings->settings ?? null,
            'provisioning' => $creatorSettings->provisioning ?? null,
            'creatorId' => $creatorSettings->creatorAccountId ?? null,
            'wpUserEnabled' => true,
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
