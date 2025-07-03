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

        $response = wp_remote_post("https://" . Config::getApiSubdomain() . ".grocerslist.com/api/v1/creator-api/links", [
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

    public function validateApiKey(string $apiKey): bool
    {
        if (!$apiKey) return false;

        $response = wp_remote_get("https://" . Config::getApiSubdomain() . ".grocerslist.com/api/v1/creator-api/validate-api-key", [
            'headers' => [
                'x-api-key' => $apiKey,
            ],
        ]);

        if (is_wp_error($response)) return false;

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200;
    }

    public function loginFollower(string $apiKey, string $email, string $password): string|\WP_Error
    {
        if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_post("https://" . Config::getApiSubdomain() . ".grocerslist.com/api/v1/creator-api/followers/login", [
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

    public function checkFollowerMembershipStatus(string $apiKey, string $jwt): string|\WP_Error
    {
	    if (!$apiKey) return new \WP_Error('invalid_api_key', 'Invalid API key');;

        $response = wp_remote_get("https://" . Config::getApiSubdomain() . ".grocerslist.com/api/v1/creator-api/followers/me", [
            'headers' => [
                'x-api-key' => $apiKey,
                'Authorization' => "Bearer " . $jwt,
            ],
        ]);
		
	    return wp_remote_retrieve_body($response);
    }
}
