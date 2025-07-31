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
            'grocers_list_signup_follower' => 'signupFollower',
            'grocers_list_login_follower' => 'loginFollower',
            'grocers_list_checkout_follower' => 'checkoutFollower',
            'grocers_list_check_follower_membership_status' => 'checkFollowerMembershipStatus',
            'grocers_list_get_creator_config' => 'getCreatorConfig',
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

        $response = $this->api->validateApiKey($api_key);

        // Parse the response if it's a string
        $data = is_string($response) ? json_decode($response, true) : $response;
        
        // Handle error or invalid response
        if (!$data || is_wp_error($response)) {
            wp_send_json_success([
                'valid' => false,
                'membershipSettings' => new \stdClass(),
                'membershipsEnabled' => false,
                'creatorAccountId' => ''
            ]);
            return;
        }

        // Extract validation status - check for different possible response formats
        $valid = isset($data['valid']) ? $data['valid'] : 
                 (isset($data['is_valid']) ? $data['is_valid'] : 
                 (isset($data['success']) ? $data['success'] : true));

        // Format response for frontend
        wp_send_json_success([
            'valid' => $valid,
            'membershipSettings' => $data['membershipSettings'] ?? new \stdClass(),
            'membershipsEnabled' => $data['membershipsEnabled'] ?? false,
            'creatorAccountId' => $data['creatorAccountId'] ?? ''
        ]);
    }

    public function getCreatorConfig(): void
    {
        $api_key = $this->settings->getApiKey();
        
        // Since getCreatorConfig doesn't exist in ApiClient, use validateApiKey
        $response = $this->api->validateApiKey($api_key);
        
        // Parse the response if it's a string
        $data = is_string($response) ? json_decode($response, true) : $response;
        
        // Handle error or invalid response
        if (!$data || is_wp_error($response)) {
            wp_send_json_success([
                'membershipSettings' => new \stdClass(),
                'membershipsEnabled' => false,
                'creatorAccountId' => ''
            ]);
            return;
        }
        
        wp_send_json_success($data);
    }

    public function signupFollower(): void
    {
        check_ajax_referer('grocers_list_signup_follower', 'security');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';

        if (empty($email) || empty($password)) {
            wp_send_json_error(['error' => 'Email and password are required'], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->signupFollower($api_key, $email, $password);

        wp_send_json_success($response);
    }

    public function loginFollower(): void
    {
        check_ajax_referer('grocers_list_login_follower', 'security');

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

    public function checkoutFollower(): void
    {
        check_ajax_referer('grocers_list_checkout_follower', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = $this->settings->getApiKey();

        // TODO:
        //  - account for removing url parameters that grocerslist sends like ?failure=
        //  - switch "?failure= for something less likely to collide with 3rd party params
        //      like ?gl-failure= or don't use url params
        $redirectUrl = wp_get_referer();
        $response = $this->api->checkoutFollower($api_key, $jwt, $redirectUrl);

        wp_send_json_success($response);
    }

    public function checkFollowerMembershipStatus(): void
    {
        check_ajax_referer('grocers_list_check_follower_membership_status', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = $this->settings->getApiKey();

        $redirectUrl = wp_get_referer();
        $response = $this->api->checkFollowerMembershipStatus($api_key, $jwt, $redirectUrl);

        wp_send_json_success($response);
    }
}
