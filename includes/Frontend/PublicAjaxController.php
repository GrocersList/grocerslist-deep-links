<?php

namespace GrocersList\Frontend;

use GrocersList\Service\ApiClient;
use GrocersList\Settings\PluginSettings;

class PublicAjaxController
{
    public function register(): void
    {
        $actions = [
            'grocers_list_get_init_memberships' => 'getInitMemberships',
            'grocers_list_record_membership_event' => 'recordMembershipEvent',
            'grocers_list_signup_follower' => 'signupFollower',
            'grocers_list_login_follower' => 'loginFollower',
            'grocers_list_forgot_password' => 'forgotPassword',
            'grocers_list_reset_password' => 'resetPassword',
            'grocers_list_checkout_follower' => 'checkoutFollower',
            'grocers_list_check_follower_membership_status' => 'checkFollowerMembershipStatus',
        ];

        foreach ($actions as $hook => $method) {
            add_action("wp_ajax_public_{$hook}", [$this, $method]);
            add_action("wp_ajax_nopriv_public_{$hook}", [$this, $method]);
        }
    }

    public function getInitMemberships(): void
    {
        check_ajax_referer('grocers_list_get_init_memberships', 'security');

        $api_key = PluginSettings::getApiKey();

        if (empty($api_key)) {
            wp_send_json_error(['error' => 'No API key configured in plugin settings'], 401);
            return;
        }

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';

        $gating_options = $this->fetchPostGatingOptions();
        $gated = isset($gating_options) && ($gating_options['postGated'] || $gating_options['recipeCardGated']);

        $response = ApiClient::getInitMemberships($api_key, $jwt, $gated);

        ApiClient::passResponseCode($response);
    }

    public function recordMembershipEvent(): void
    {
        check_ajax_referer('grocers_list_record_membership_event', 'security');

        $api_key = PluginSettings::getApiKey();

        if (empty($api_key)) {
            wp_send_json_error(['error' => 'No API key configured in plugin settings'], 401);
            return;
        }

        $event = isset($_POST['event']) ? sanitize_text_field(wp_unslash($_POST['event'])) : '';

        $response = ApiClient::recordMembershipEvent($api_key, $event);

        ApiClient::passResponseCode($response);
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

        $api_key = PluginSettings::getApiKey();

        $response = ApiClient::signupFollower($api_key, $email, $password, $url);

        ApiClient::passResponseCode($response);
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

        $api_key = PluginSettings::getApiKey();

        $response = ApiClient::loginFollower($api_key, $email, $password);

        ApiClient::passResponseCode($response);
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

        $api_key = PluginSettings::getApiKey();

        $response = ApiClient::forgotPassword($api_key, $email);

        ApiClient::passResponseCode($response);
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

        $api_key = PluginSettings::getApiKey();

        $response = ApiClient::resetPassword($api_key, $token, $password);

        ApiClient::passResponseCode($response);
    }

    public function checkoutFollower(): void
    {
        check_ajax_referer('grocers_list_checkout_follower', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = PluginSettings::getApiKey();

        // TODO:
        //  - account for removing url parameters that grocerslist sends like ?failure=
        //  - switch "?failure= for something less likely to collide with 3rd party params
        //      like ?gl-failure= or don't use url params
        $redirectUrl = wp_get_referer();
        $response = ApiClient::checkoutFollower($api_key, $jwt, $redirectUrl);

        ApiClient::passResponseCode($response);
    }

    public function checkFollowerMembershipStatus(): void
    {
        check_ajax_referer('grocers_list_check_follower_membership_status', 'security');

        $jwt = isset($_POST['jwt']) ? sanitize_text_field(wp_unslash($_POST['jwt'])) : '';
        $api_key = PluginSettings::getApiKey();

        $redirectUrl = wp_get_referer();
        $response = ApiClient::checkFollowerMembershipStatus($api_key, $jwt, $redirectUrl);

        ApiClient::passResponseCode($response);
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
}
