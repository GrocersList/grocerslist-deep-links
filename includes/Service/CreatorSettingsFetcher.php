<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;

class CreatorSettingsFetcher
{
    private $creatorSettings;

    /**
     * Get creator settings for WordPress Plugin settings
     *
     * @return array|\WP_Error Returns the response body or WP_Error on failure
     */
    public function getCreatorSettings()
    {
        $apiKey = PluginSettings::getApiKey();

        if (!$apiKey) {
            return null;
        }

        // we memoize and return creatorSettings if it has already been set to avoid duplicate requests
        if ($this->creatorSettings) return $this->creatorSettings;

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/creator-settings", [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $this->creatorSettings = json_decode($response['body'], false);

        return $this->creatorSettings;
    }
}
