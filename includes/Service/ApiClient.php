<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\Logger;

class ApiClient implements IApiClient
{
    private $creatorSettings;

    /**
     * Helper method to pass through response code
     *
     * @param mixed $response The API response (string body or WP_Error)
     * @return void
     */
    public function passResponseCode($response): void
    {
        // Handle WP_Error responses
        if (is_wp_error($response)) {
            wp_send_json_error([
                'error' => $response->get_error_message(),
            ], 500);
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $_body = wp_remote_retrieve_body($response);
        $body = is_string($_body) ? json_decode($_body, true) : $_body;

        // Pass through non-2xx
        if ($status < 200 || $status >= 300) {
            wp_send_json_error($body, $status);
            return;
        }

        // Response appears to be successful
        wp_send_json_success($body);
    }

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
                'x-gl-plugin-version' => Config::getPluginVersion(),
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
        if (!$apiKey) return false;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/validate-api-key", [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error()) {
            Logger::debug("API response could not be parsed: " . wp_remote_retrieve_body($response));
            return false;
        }

        return !!$data['valid'];
    }

    /**
     * Get creator settings for WordPress Plugin settings
     *
     * @param string $apiKey
     * @return array|\WP_Error Returns the response body or WP_Error on failure
     */
    public function getCreatorSettings(string $apiKey)
    {
        if (!$apiKey) return [];

        // we memoize and return creatorSettings if it has already been set to avoid duplicate requests
        if ($this->creatorSettings) return $this->creatorSettings;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/creator-settings", [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $this->creatorSettings = json_decode($response['body'], false);

        return $this->creatorSettings;
    }

    /**
     * Get creator membership settings
     *
     * @param string $apiKey
     * @param string $jwt
     * @param boolean $gated
     * @return string|\WP_Error Returns the response body or WP_Error on failure
     */
    public function getInitMemberships(string $apiKey, string $jwt, bool $gated = false)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');

        $response = wp_remote_get("https://" . Config::getApiBaseDomain()
            . "/api/v1/creator-api/init-memberships" .
            "?gated=" . $gated,
            [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'x-gl-plugin-version' => Config::getPluginVersion(),
                    'Authorization' => $jwt ? "Bearer " . $jwt : null,
                ],
            ]);

        return $response;
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
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'username' => $email,
                'password' => $password,
                'post_url' => $url,
            ])
        ]);

        return $response;
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
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'username' => $email,
                'password' => $password,
            ])
        ]);

        return $response;
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
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'email' => $email,
                'blog_url' => get_bloginfo('url'),
            ])
        ]);

        return $response;
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
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'token' => $token,
                'password' => $password,
            ])
        ]);

        return $response;
    }

    /**
     * Generate Stripe checkout session and redirect to checkout session url
     *
     * @param string $apiKey
     * @param string $jwt
     * @param string $redirectUrl
     * @return string|\WP_Error
     */
    public function checkoutFollower(string $apiKey, string $jwt, string $redirectUrl)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;
        if (!$redirectUrl) return new \WP_Error('missing_param', 'Missing redirectUrl');;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/checkout?redirect=" . urlencode($redirectUrl), [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'Authorization' => $jwt ? "Bearer " . $jwt : null,
            ],
        ]);

        return $response;
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
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'Authorization' => $jwt ? "Bearer " . $jwt : null,
            ],
        ]);

        return $response;
    }

    public function updateMembershipsEnabled(string $apiKey, string $enabled)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;
        if ($enabled === '') return new \WP_Error('missing_param', 'Missing enabled parameter');;
        if (!in_array($enabled, ['0', '1'], true)) return new \WP_Error('invalid_param', 'Invalid enabled parameter - must be "0" or "1"');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/membership-settings", [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'enabled' => !!$enabled
            ])
        ]);

        return $response;
    }
}
