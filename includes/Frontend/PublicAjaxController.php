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
        ApiClient      $api,
        Hooks          $hooks
    )
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $actions = [
            'grocers_list_get_membership_settings' => 'getMembershipSettings',
            'grocers_list_signup_follower' => 'signupFollower',
            'grocers_list_login_follower' => 'loginFollower',
            'grocers_list_forgot_password' => 'forgotPassword',
            'grocers_list_reset_password' => 'resetPassword',
            'grocers_list_checkout_follower' => 'checkoutFollower',
            'grocers_list_check_follower_membership_status' => 'checkFollowerMembershipStatus',
            'grocers_list_get_post_gating_options' => 'getPostGatingOptions',
        ];

        foreach ($actions as $hook => $method) {
            add_action("wp_ajax_public_{$hook}", [$this, $method]);
            add_action("wp_ajax_nopriv_public_{$hook}", [$this, $method]);
        }
    }

    public function getMembershipSettings(): void
    {
        check_ajax_referer('grocers_list_get_membership_settings', 'security');

        $api_key = $this->settings->getApiKey();

        if (empty($api_key)) {
            wp_send_json_error(['error' => 'No API key configured in plugin settings'], 401);
            return;
        }

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $redirectUrl = wp_get_referer();

        $gating_options = $this->fetchPostGatingOptions();
        $gated = isset($gating_options) && ($gating_options['postGated'] || $gating_options['recipeCardGated']);

        $response = $this->api->getMembershipSettings($api_key, $jwt, $redirectUrl, $gated);

        $this->api->passResponseCode($response);
    }

    public function signupFollower(): void
    {
        check_ajax_referer('grocers_list_signup_follower', 'security');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';
        $url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';

        if (empty($email) || empty($password)) {
            wp_send_json_error([
                'type' => 'about:blank',
                'title' => 'Missing params',
                'detail' => 'Email and password are required',
                'status' => 400,
            ], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->signupFollower($api_key, $email, $password, $url);

        $this->api->passResponseCode($response);
    }

    public function loginFollower(): void
    {
        check_ajax_referer('grocers_list_login_follower', 'security');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';

        if (empty($email) || empty($password)) {
            wp_send_json_error([
                'type' => 'about:blank',
                'title' => 'Missing params',
                'detail' => 'Email and password are required',
                'status' => 400,
            ], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->loginFollower($api_key, $email, $password);

        $this->api->passResponseCode($response);
    }

    public function forgotPassword(): void
    {
        check_ajax_referer('grocers_list_forgot_password', 'security');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (empty($email)) {
            wp_send_json_error([
                'type' => 'about:blank',
                'title' => 'Missing params',
                'detail' => 'Email is required',
                'status' => 400,
            ], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->forgotPassword($api_key, $email);

        $this->api->passResponseCode($response);
    }

    public function resetPassword(): void
    {
        check_ajax_referer('grocers_list_reset_password', 'security');

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';

        if (empty($token)) {
            wp_send_json_error([
                'type' => 'about:blank',
                'title' => 'Missing params',
                'detail' => 'Token is required',
                'status' => 400,
            ], 400);
            return;
        }

        if (empty($password)) {
            wp_send_json_error([
                'type' => 'about:blank',
                'title' => 'Missing params',
                'detail' => 'Password is required',
                'status' => 400,
            ], 400);
            return;
        }

        $api_key = $this->settings->getApiKey();

        $response = $this->api->resetPassword($api_key, $token, $password);

        $this->api->passResponseCode($response);
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

        $this->api->passResponseCode($response);
    }

    public function checkFollowerMembershipStatus(): void
    {
        check_ajax_referer('grocers_list_check_follower_membership_status', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = $this->settings->getApiKey();

        $redirectUrl = wp_get_referer();
        $response = $this->api->checkFollowerMembershipStatus($api_key, $jwt, $redirectUrl);

        $this->api->passResponseCode($response);
    }

    function fetchPostGatingOptions()
    {
        if (!isset($_POST['postId'])) {
            return null;
        }

        $post_id_raw = sanitize_text_field(wp_unslash($_POST['postId']));

        if (!is_numeric($post_id_raw)) {
            return null;
        }

        $post_id = intval($post_id_raw);

        if (!get_post($post_id)) {
            return null;
        }

        $post_gated = get_post_meta($post_id, 'grocers_list_post_gated', true) === '1';
        $recipe_card_gated = get_post_meta($post_id, 'grocers_list_recipe_card_gated', true) === '1';

        return [
            'postGated' => $post_gated,
            'recipeCardGated' => $recipe_card_gated,
        ];
    }

    public function getPostGatingOptions(): void
    {
        check_ajax_referer('grocers_list_get_post_gating_options', 'security');

        $gating_options = $this->fetchPostGatingOptions();

        if (!$gating_options) {
            wp_send_json_error(['error' => 'Invalid post ID'], 400);
            return;
        }

        wp_send_json_success($gating_options);
    }
}
