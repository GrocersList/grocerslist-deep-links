<?php

namespace GrocersList\Frontend;

use GrocersList\Support\Config;
use GrocersList\Support\Hooks;

class ClientScripts
{
    private Hooks $hooks;

    public function __construct(Hooks $hooks)
    {
        $this->hooks = $hooks;
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

        wp_localize_script('grocers-list-client', 'grocersList', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => [
                'grocers_list_signup_follower' => wp_create_nonce('grocers_list_signup_follower'),
                'grocers_list_login_follower' => wp_create_nonce('grocers_list_login_follower'),
                'grocers_list_checkout_follower' => wp_create_nonce('grocers_list_checkout_follower'),
                'grocers_list_check_follower_membership_status' => wp_create_nonce('grocers_list_check_follower_membership_status'),
            ],
        ]);

        $externalJsUrl = Config::getExternalJsUrl();
        if (!empty($externalJsUrl)) {
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $version, true);
        }
    }
}
