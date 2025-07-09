<?php

namespace GrocersList\Frontend;

use GrocersList\Service\ApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;

class PublicAjaxController
{
    private PluginSettings $settings;
    private ApiClient $api;
    private Hooks $hooks;

    public function __construct(
        PluginSettings $settings,
        ApiClient $api,
        Hooks $hooks
    ) {
        $this->settings = $settings;
        $this->api = $api;
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $actions = [
            'grocers_list_validate_api_key' => 'validateApiKey',
            'grocers_list_login_follower' => 'loginFollower',
            'grocers_list_check_follower_membership_status' => 'checkFollowerMembershipStatus',
        ];

        foreach ($actions as $hook => $method) {
            add_action("wp_ajax_public_{$hook}", [$this, $method]);
            add_action("wp_ajax_nopriv_public_{$hook}", [$this, $method]);
        }
    }

    public function validateApiKey(): void
    {
        $api_key = $this->settings->getApiKey();

        if (empty($api_key)) {
            wp_send_json_error(['error' => 'No API key configured in plugin settings'], 401);
            return;
        }

        $is_valid = $this->api->validateApiKey($api_key);

        wp_send_json_success(['is_valid' => $is_valid]);
    }

    public function loginFollower(): void
    {
        check_ajax_referer('grocers_list_login_follower_nonce', 'security');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';

        if (empty($email) || empty($password)) {
            wp_send_json_error(['error' => 'Email and password are required'], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->loginFollower($api_key, $email, $password);

        wp_send_json_success($response);
    }

    public function checkFollowerMembershipStatus(): void
    {
        check_ajax_referer('grocers_list_check_follower_membership_status_nonce', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = $this->settings->getApiKey();

        $response = $this->api->checkFollowerMembershipStatus($api_key, $jwt);

        wp_send_json_success($response);
    }
}
