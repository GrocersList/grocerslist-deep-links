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
    }

    public function enqueueScripts(): void
    {
        $assetBase = plugin_dir_url(__FILE__) . '../../client-ui/dist/';
        $version = GROCERS_LIST_VERSION;
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
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $version, true);
        }
    }
}
