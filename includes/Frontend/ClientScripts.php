<?php

namespace GrocersList\Frontend;

use GrocersList\Service\IApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\Hooks;

class ClientScripts
{
    private Hooks $hooks;
    private IApiClient $api;
    private PluginSettings $settings;

    public function __construct(Hooks $hooks, IApiClient $api, PluginSettings $pluginSettings) {
        $this->hooks = $hooks;
        $this->api = $api;
        $this->settings = $pluginSettings;
    }

    public function register(): void
    {
        $this->hooks->addAction('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        $this->hooks->addAction('wp_head', [$this, 'addPreloadHints']);
    }

    public function enqueueScripts(): void
    {
        // Set cache control headers for 1 hour
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=3600');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        }

        $assetBase = plugin_dir_url(__FILE__) . '../../client-ui/dist/';
        
        // Use WordPress's built-in versioning system
        $version = Config::getPluginVersion();
        
        wp_enqueue_script('grocers-list-client', $assetBase . 'bundle.js', [], $version, true);

        $creatorSettings = $this->api->getCreatorSettings($this->settings->getApiKey());

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
            'settings' => $creatorSettings->settings,
            'provisioning' => $creatorSettings->provisioning
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

        $externalJsUrl = Config::getExternalJsUrl();
        if (!empty($externalJsUrl)) {
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $version, false);
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
        $externalJsUrl = Config::getExternalJsUrl();
        if (!empty($externalJsUrl)) {
            $version = Config::getPluginVersion();
            $versionedUrl = add_query_arg('ver', $version, $externalJsUrl);
            echo '<link rel="preload" href="' . esc_url($versionedUrl) . '" as="script">' . "\n";
        }
    }
}
