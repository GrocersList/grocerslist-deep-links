<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\Logger;

class ApiClient
{
    /**
     * Helper method to pass through response code
     *
     * @param mixed $response The API response (string body or WP_Error)
     * @return void
     */
    static function passResponseCode($response): void
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

    static function postAppLinks(array $urls): LinkResponse
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
    static function validateApiKey(string $apiKey)
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

        return !!$data;
    }


    /**
     * Get creator membership settings
     *
     * @param string $apiKey
     * @param string $jwt
     * @param boolean $gated
     * @return string|\WP_Error Returns the response body or WP_Error on failure
     */
    static function getInitMemberships(string $apiKey, string $jwt, bool $gated = false)
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
     * Record single membership event
     *
     * @param string $apiKey
     * @param string $event
     * @return string|\WP_Error Returns the response body or WP_Error on failure
     */
    static function recordMembershipEvent(string $apiKey, string $event)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/events", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'event' => $event,
            ])
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
     * @param bool $emailMatchesWpUser
     * @param bool $wpUserIsElevated
     * @return string|\WP_Error
     */
    static function signupFollower(string $apiKey, string $email, string $password, string $url, bool $emailMatchesWpUser = false, bool $wpUserIsElevated = false)
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
                'emailMatchesWpUser' => $emailMatchesWpUser,
                'wpUserIsElevated' => $wpUserIsElevated,
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
    static function loginFollower(string $apiKey, string $email, string $password)
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
     * Verify a follower's email address
     *
     * @param string $apiKey
     * @param string $token
     * @return string|\WP_Error
     */
    static function verifyEmail(string $apiKey, string $token)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/verify-email", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode([
                'token' => $token,
            ]),
            'timeout' => 10,
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
    static function forgotPassword(string $apiKey, string $email)
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
    static function resetPassword(string $apiKey, string $token, string $password)
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
    static function checkoutFollower(string $apiKey, string $jwt, string $redirectUrl)
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
    static function checkFollowerMembershipStatus(string $apiKey, string $jwt, string $redirectUrl)
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

    /**
     * Clear the membership records for the given emails server-side.
     *
     * @param string $apiKey
     * @param string[] $emails
     * @return array|\WP_Error
     */
    static function deleteFollowers(string $apiKey, array $emails)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/purge", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
            'body' => json_encode(['emails' => array_values($emails)]),
            'timeout' => 15,
        ]);

        return $response;
    }

    /**
     * Notify the GL server of the WordPress user id that was created (or reused)
     * for the currently-authenticated follower. Enables the server to reliably
     * identify which WP user to delete on Stripe subscription cancellation.
     *
     * Failures are swallowed: this is a best-effort side-channel update, not a
     * required leg of the membership signup flow.
     *
     * @param string $apiKey
     * @param string $jwt
     * @param int $wpUserId
     * @return string|\WP_Error
     */
    static function patchFollowerWpUserId(string $apiKey, string $jwt, int $wpUserId)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');
        if (!$jwt) return new \WP_Error('missing_jwt', 'Missing JWT');
        if ($wpUserId <= 0) return new \WP_Error('invalid_param', 'Invalid wpUserId');

        $response = wp_remote_request("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/me/wp-user", [
            'method' => 'PATCH',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'Authorization' => "Bearer " . $jwt,
            ],
            'body' => json_encode([
                'wpUserId' => $wpUserId,
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::debug("patchFollowerWpUserId transport error: " . $response->get_error_message());

            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            Logger::debug("patchFollowerWpUserId non-2xx status " . $status . ": " . wp_remote_retrieve_body($response));
        }

        return $response;
    }

    /**
     * Fetch the list of follower accounts on this creator whose WP users the
     * plugin still needs to delete (or dissociate). Polled hourly by WP-Cron.
     *
     * @param string $apiKey
     * @return array|\WP_Error Decoded response body, or WP_Error on transport
     *                         failure / missing api key.
     */
    static function getWpCleanupPending(string $apiKey)
    {
        if (!$apiKey) return new \WP_Error('grocerslist_missing_api_key', 'Missing API key');

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/wp-cleanup/pending", [
            'headers' => [
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'x-api-key' => $apiKey,
            ],
        ]);

        if (is_wp_error($response)) {
            Logger::debug("getWpCleanupPending transport error: " . $response->get_error_message());

            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            Logger::debug("getWpCleanupPending non-2xx status " . $status . ": " . wp_remote_retrieve_body($response));

            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            Logger::debug("getWpCleanupPending: response body could not be parsed as JSON");

            return ['pending' => []];
        }

        return $body;
    }

    /**
     * Acknowledge to the GL server that the plugin has processed a set of
     * pending cleanup entries (deleted the WP user, dissociated on multi-creator,
     * or noticed the WP user was already gone). The server can then clear its
     * cleanup flag for those follower accounts.
     *
     * @param string $apiKey
     * @param array<int, string> $followerAccountIds
     * @return array|\WP_Error|null Null when called with an empty id list.
     */
    static function postWpCleanupComplete(string $apiKey, array $followerAccountIds)
    {
        if (!$apiKey) return new \WP_Error('grocerslist_missing_api_key', 'Missing API key');
        if (empty($followerAccountIds)) return null;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/wp-cleanup/complete", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-gl-plugin-version' => Config::getPluginVersion(),
                'x-api-key' => $apiKey,
            ],
            'body' => json_encode([
                'followerAccountIds' => array_values($followerAccountIds),
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::debug("postWpCleanupComplete transport error: " . $response->get_error_message());

            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            Logger::debug("postWpCleanupComplete non-2xx status " . $status . ": " . wp_remote_retrieve_body($response));
        }

        return $response;
    }

    static function updateMembershipsEnabled(string $apiKey, string $enabled)
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
