<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Support\Config;
use GrocersList\Support\Logger;
use GrocersList\Settings\PluginSettings;

class ApiClient implements IApiClient
{
    public function postAppLinks(array $urls): LinkResponse
    {
        // Use PluginSettings to get API key with proper prefix handling
        $settings = new PluginSettings();
        $api_key = $settings->getApiKey();

        if (!$api_key) {
            return new LinkResponse([]);
        }

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/links", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode(['urls' => $urls]),
        ]);

        if (is_wp_error($response)) {
            return new LinkResponse([]);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            Logger::debug("API response could not be parsed: " . wp_remote_retrieve_body($response));
            return new LinkResponse([]);
        }
        return new LinkResponse($data);
    }

    /**
     * Validate API key and get creator configuration
     *
     * @param string $apiKey
     * @return string|\WP_Error Returns the response body or WP_Error on failure
     */
    public function validateApiKey(string $apiKey)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/validate-api-key", [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
        ]);

        if (is_wp_error($response)) return $response;

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        // Surface non-2xx as an error so callers can propagate it to the client
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('remote_http_error', 'Validation request failed', [
                'status' => $status,
                'body'   => $body,
            ]);
        }

        return $body;
    }

    /**
     * Signup a follower
     *
     * @param string $apiKey
     * @param string $email
     * @param string $password
     * @param string $url
     * @return string|\WP_Error
     */
    public function signupFollower(string $apiKey, string $email, string $password, string $url)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/signup", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode([
                'username' => $email,
                'password' => $password,
                'post_url' => $url,
            ])
        ]);

		return wp_remote_retrieve_body($response);
    }

    /**
     * Login a follower
     *
     * @param string $apiKey
     * @param string $email
     * @param string $password
     * @return string|\WP_Error
     */
    public function loginFollower(string $apiKey, string $email, string $password)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/login", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode([
                'username' => $email,
                'password' => $password,
            ])
        ]);

		return wp_remote_retrieve_body($response);
    }

    /**
     * Send password reset email
     *
     * @param string $apiKey
     * @param string $email
     * @return string|\WP_Error
     */
    public function forgotPassword(string $apiKey, string $email)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/forgot-password", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode([
                'email' => $email,
                'blog_url' => get_bloginfo('url'),
            ])
        ]);

        return wp_remote_retrieve_body($response);
    }

    /**
     * Reset password
     *
     * @param string $apiKey
     * @param string $email
     * @return string|\WP_Error
     */
    public function resetPassword(string $apiKey, string $token, string $password)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/reset-password", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode([
                'token' => $token,
                'password' => $password,
            ])
        ]);

        return wp_remote_retrieve_body($response);
    }

    /**
     * Generate Stripe checkout session and redirect to checkout session url
     *
     * @param string $apiKey
     * @param string $jwt
     * @return string|\WP_Error
     */
    public function checkoutFollower(string $apiKey, string $jwt, string $redirectUrl)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;
        if (!$redirectUrl) return new \WP_Error('missing_param', 'Missing redirectUrl');;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/checkout?redirect=" . urlencode($redirectUrl), [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
                'Authorization' => "Bearer " . $jwt,
            ],
        ]);

        return wp_remote_retrieve_body($response);
    }

    /**
     * Check follower membership status
     *
     * @param string $apiKey
     * @param string $jwt
     * @return string|\WP_Error
     */
    public function checkFollowerMembershipStatus(string $apiKey, string $jwt, string $redirectUrl)
    {
	    if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;
        if (!$redirectUrl) return new \WP_Error('missing_param', 'Missing redirectUrl');;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/me?redirect=" . urlencode($redirectUrl), [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
                'Authorization' => "Bearer " . $jwt,
            ],
        ]);

	    return wp_remote_retrieve_body($response);
    }

    /**
     * Record a MembershipEvent
     *
     * @param string $apiKey
     * @param string $type
     * @param string $occurredAt
     * @param string $url
     * @return string|\WP_Error
     */
    public function recordMembershipEvent(string $apiKey, string $type, string $occurredAt, string $url)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/membership-events", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => GROCERS_LIST_VERSION,
            ],
            'body' => json_encode([
                'type' => $type,
                'occurred_at' => $occurredAt,
                'post_url' => $url,
            ])
        ]);

        return wp_remote_retrieve_body($response);
    }
}
