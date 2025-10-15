<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;

class CreatorSettingsFetcher
{
    // we cache the /creator-settings response in a transient for 24 hours
    private const CREATOR_SETTINGS_TRANSIENT_KEY = 'grocerslist_creator_settings';
    // don't cache indefinitely... external changes may affect behavior and should take effect within a reasonable period
    // e.g., a Creator may cancel their applinks plan, and should no longer be able to use applinks features
    private const EXPIRE_AFTER_SECONDS = 24 * 60 * 60; // 24 hours

    // we also separately memoize the creatorSettings as an instance variable to prevent the need to fetch the transient
    // multiple times per request
    private $creatorSettings;

    /**
     * Get creator settings for WordPress Plugin settings
     *
     * @param bool $noCache will not read or write using the transient value
     * @return array|\WP_Error Returns the response body or WP_Error on failure
     */
    public function getCreatorSettings(bool $noCache = false)
    {
        $apiKey = PluginSettings::getApiKey();
        $wordpressDomain = Config::getBlogDomain();

        if (!$apiKey) {
            return null;
        }

        // Even if $noCache is true, we still use the instance var val since this method can be called multiple times
        // for a single request.
        if ($this->creatorSettings) return $this->creatorSettings;

        if (!$noCache) {
            $existingSettingsString = get_transient(self::CREATOR_SETTINGS_TRANSIENT_KEY);

            if ($existingSettingsString) {
                $this->creatorSettings = json_decode($existingSettingsString, false);
                return $this->creatorSettings;
            }
        }

        $response = wp_remote_get("https://" . Config::getApiBaseDomain() . "/api/v1/creator-api/creator-settings?ts=" . time() . "&wordpressDomain=" . urlencode($wordpressDomain), [
            'headers' => [
                'x-api-key' => $apiKey,
                'x-gl-plugin-version' => Config::getPluginVersion(),
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $fetchedSettingsString = $response['body'];

        if (!$noCache) {
            set_transient(self::CREATOR_SETTINGS_TRANSIENT_KEY, $fetchedSettingsString, self::EXPIRE_AFTER_SECONDS);
        }

        // Even if $noCache is true, we still set to the instance var since this method can be called multiple times
        // for a single request.
        $this->creatorSettings = json_decode($fetchedSettingsString, false);

        return $this->creatorSettings;
    }

    /**
     * Get memberships FULLY enabled, meaning onboarding is complete and the feature is enabled
     *
     * @return bool
     */
    public function getMembershipsFullyEnabled()
    {
        $creatorSettings = $this->getCreatorSettings();

        if (!$creatorSettings->settings->memberships->enabled) return false;
        if (!$creatorSettings->provisioning->memberships->hasPriceIds) return false;
        if (!$creatorSettings->provisioning->memberships->hasProductId) return false;
        if (!$creatorSettings->provisioning->memberships->hasPaymentAccount) return false;

        return true;
    }

    public function deleteCreatorSettingsTransient() {
        delete_transient(self::CREATOR_SETTINGS_TRANSIENT_KEY);
    }
}
