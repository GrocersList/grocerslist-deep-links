<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

/**
 * Hourly WP-Cron worker that reconciles WordPress users with the GL server's
 * churn signal. The server can't push into WordPress (no plaintext key at rest),
 * so the plugin polls: fetches pending entries via ApiClient::getWpCleanupPending,
 * deletes/dissociates each matching WP user locally, then acks the processed
 * ids back to the server so it can clear its flags.
 */
class WpUserCleanupService
{
    // Meta key stems mirror MemberService — kept in sync intentionally.
    private const META_IS_PAID_MEMBER = 'glIsPaidMember';
    private const META_SUBSCRIPTION_STATUS = 'glSubscriptionStatus';
    private const META_EMAIL = 'glEmail';
    private const META_IS_PAST_DUE = 'glIsPastDue';
    private const META_SUBSCRIPTION_MANAGEMENT_LINK = 'glSubscriptionManagementLink';
    private const META_LAST_UPDATED = 'glLastUpdated';

    private CreatorSettingsFetcher $creatorSettingsFetcher;

    public function __construct(CreatorSettingsFetcher $creatorSettingsFetcher)
    {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
    }

    public function run(): void
    {
        $apiKey = PluginSettings::getApiKey();
        if (empty($apiKey)) {
            Logger::debug('WpUserCleanupService: no api key configured, skipping run');
            return;
        }

        $callingCreatorId = $this->getCallingCreatorId();
        if ($callingCreatorId === '') {
            Logger::debug('WpUserCleanupService: no creator id resolved, skipping run');
            return;
        }

        $pendingResponse = ApiClient::getWpCleanupPending($apiKey);
        if (is_wp_error($pendingResponse)) {
            Logger::debug('WpUserCleanupService: getWpCleanupPending returned WP_Error, skipping run');
            return;
        }
        if (!is_array($pendingResponse)) {
            Logger::debug('WpUserCleanupService: getWpCleanupPending returned non-array, skipping run');
            return;
        }

        $pending = isset($pendingResponse['pending']) && is_array($pendingResponse['pending'])
            ? $pendingResponse['pending']
            : [];

        if (empty($pending)) {
            return;
        }

        $processedFollowerAccountIds = [];

        foreach ($pending as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $followerAccountId = isset($entry['followerAccountId']) ? (string) $entry['followerAccountId'] : '';
            $wpUserId = isset($entry['wpUserId']) ? (int) $entry['wpUserId'] : 0;

            if ($followerAccountId === '' || $wpUserId <= 0) {
                continue;
            }

            if ($this->processEntry($wpUserId, $callingCreatorId)) {
                $processedFollowerAccountIds[] = $followerAccountId;
            }
        }

        if (!empty($processedFollowerAccountIds)) {
            Logger::debug('WpUserCleanupService: acking ' . count($processedFollowerAccountIds) . ' processed entries');
            ApiClient::postWpCleanupComplete($apiKey, $processedFollowerAccountIds);
        }
    }

    /**
     * Process a single pending entry. Returns true iff the entry should be
     * acked to the server (deleted, dissociated, or already-gone).
     */
    private function processEntry(int $wpUserId, string $callingCreatorId): bool
    {
        $user = get_user_by('id', $wpUserId);
        if (!$user) {
            // Already deleted out-of-band; treat as processed so the server clears its flag.
            return true;
        }

        // Multi-creator meta check: if this WP user is still an active paid member on any
        // OTHER creator, we do not delete the user — we only clear this-creator-namespaced
        // meta so the user is dissociated from this creator only. Still acked.
        if ($this->isActiveOnOtherCreator($wpUserId, $callingCreatorId)) {
            $this->clearCreatorMeta($wpUserId, $callingCreatorId);

            return true;
        }

        // Ensure pluggable wp_delete_user is available in cron context.
        if (!function_exists('wp_delete_user') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $result = wp_delete_user($wpUserId);
        if (!$result) {
            // Do NOT ack — server keeps the flag and we retry next tick.
            Logger::debug("WpUserCleanupService: warn: wp_delete_user returned false for id {$wpUserId}");

            return false;
        }

        return true;
    }

    private function getCallingCreatorId(): string
    {
        $settings = $this->creatorSettingsFetcher->getCreatorSettings();
        if (!$settings || !isset($settings->creatorAccountId)) {
            return '';
        }

        return (string) $settings->creatorAccountId;
    }

    /**
     * Return true when this WP user has a truthy glIsPaidMember-* meta value
     * for any creator id other than the calling creator.
     */
    private function isActiveOnOtherCreator(int $wpUserId, string $callingCreatorId): bool
    {
        $allMeta = get_user_meta($wpUserId);
        if (!is_array($allMeta)) {
            return false;
        }

        $prefix = self::META_IS_PAID_MEMBER . '-';
        $prefixLen = strlen($prefix);

        foreach ($allMeta as $key => $values) {
            if (strncmp($key, $prefix, $prefixLen) !== 0) {
                continue;
            }

            $creatorId = substr($key, $prefixLen);
            if ($creatorId === '' || $creatorId === $callingCreatorId) {
                continue;
            }

            // get_user_meta with no $key returns arrays of raw meta values.
            $raw = is_array($values) ? ($values[0] ?? '') : $values;
            if ($this->isTruthyMeta($raw)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyMeta($raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return intval($raw) === 1;
        }

        if (is_string($raw)) {
            $lower = strtolower(trim($raw));

            return $lower === '1' || $lower === 'true';
        }

        return false;
    }

    private function clearCreatorMeta(int $wpUserId, string $callingCreatorId): void
    {
        $suffix = '-' . $callingCreatorId;
        $keys = [
            self::META_IS_PAID_MEMBER . $suffix,
            self::META_SUBSCRIPTION_STATUS . $suffix,
            self::META_EMAIL . $suffix,
            self::META_IS_PAST_DUE . $suffix,
            self::META_SUBSCRIPTION_MANAGEMENT_LINK . $suffix,
            self::META_LAST_UPDATED . $suffix,
        ];

        foreach ($keys as $key) {
            delete_user_meta($wpUserId, $key);
        }
    }
}
