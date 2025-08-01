<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Support\Config;
use GrocersList\Support\Logger;

class ApiClient implements IApiClient
{
    public function postAppLinks(array $urls): LinkResponse
    {
        $api_key = get_option('grocers_list_api_key');

        if (!$api_key) {
            return new LinkResponse([]);
        }

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/links", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
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
            ],
        ]);

        if (is_wp_error($response)) return $response;

        return wp_remote_retrieve_body($response);
    }

    /**
     * Signup a follower
     *
     * @param string $apiKey
     * @param string $email
     * @param string $password
     * @return string|\WP_Error
     */
    public function signupFollower(string $apiKey, string $email, string $password)
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/followers/signup", [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
            ],
            'body' => json_encode([
                'username' => $email,
                'password' => $password,
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
            ],
            'body' => json_encode([
                'username' => $email,
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
                'Authorization' => "Bearer " . $jwt,
            ],
        ]);
		
	    return wp_remote_retrieve_body($response);
    }
}
