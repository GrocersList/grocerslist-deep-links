<?php

namespace GrocersList\Admin;

use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Service\ApiClient;
use GrocersList\Service\UrlMappingService;
use GrocersList\Settings\PluginSettings;

class AjaxController
{
    public function register(): void
    {
        $actions = [
            'grocers_list_get_state' => 'getState',
            'grocers_list_update_api_key' => 'updateApiKey',
            'grocers_list_update_use_linksta_links' => 'updateUseLinkstaLinks',
            'grocers_list_clear_settings' => 'clearSettings',
            'grocers_list_get_migration_status' => 'getMigrationStatus',
            'grocers_list_get_link_count_info' => 'getLinkCountInfo',
            'grocers_list_trigger_migrate' => 'triggerMigrate',
            'grocers_list_update_memberships_enabled' => 'updateMembershipsEnabled',
        ];

        foreach ($actions as $hook => $method) {
            add_action("wp_ajax_{$hook}", [$this, $method]);
        }
    }

    public function clearSettings(): void
    {
        check_ajax_referer('grocers_list_clear_settings', 'security');

        $this->checkPermission('grocers_list_clear_settings');
        // Clean up any legacy stored counts (both old and new prefixes)

        PluginSettings::reset();

        // remove all mappings (effectively undo-ing migration):
        UrlMappingService::reset_mappings();

        wp_send_json_success(['message' => 'All settings cleared']);
    }

    public function getState(): void
    {
        check_ajax_referer('grocers_list_get_state', 'security');

        $this->checkPermission('grocers_list_get_state');

        wp_send_json_success([
            'apiKey' => PluginSettings::getApiKey(),
            'useLinkstaLinks' => PluginSettings::isUseLinkstaLinksEnabled(),
        ]);
    }

    public function updateApiKey(): void
    {
        check_ajax_referer('grocers_list_update_api_key', 'security');

        $this->checkPermission('grocers_list_update_api_key');
        $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';

        if (strlen($apiKey) < 10) {
            wp_send_json_error(['error' => 'API key too short'], 400);
        }

        if (!ApiClient::validateApiKey($apiKey)) {
            wp_send_json_error(['error' => 'Invalid API key'], 400);
        }

        PluginSettings::setApiKey($apiKey);
        wp_send_json_success(['message' => 'API key updated']);
    }

    public function updateUseLinkstaLinks(): void
    {
        check_ajax_referer('grocers_list_update_use_linksta_links', 'security');

        $this->checkPermission('grocers_list_update_use_linksta_links');
        $enabled = isset($_POST['useLinkstaLinks'])
            && sanitize_text_field(wp_unslash($_POST['useLinkstaLinks'])) === '1';
        PluginSettings::setUseLinkstaLinks($enabled);
        wp_send_json_success(['message' => 'Use Linksta Links setting updated']);
    }

    private function checkPermission(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized'], 403);
        }
    }

    public function getMigrationStatus(): void
    {
        check_ajax_referer('grocers_list_get_migration_status', 'security');

        $this->checkPermission('grocers_list_get_migration_status');

        wp_send_json_success(MigrationVisitor::getStatus());
    }

    public function getLinkCountInfo(): void
    {
        check_ajax_referer('grocers_list_get_link_count_info', 'security');

        $this->checkPermission('grocers_list_get_link_count_info');

        $countInfo = UrlMappingService::get_link_count_info();
        wp_send_json_success($countInfo);
    }

    public function triggerMigrate(): void
    {
        check_ajax_referer('grocers_list_trigger_migrate', 'security');

        $this->checkPermission('grocers_list_trigger_migrate');

        $migrationInfo = MigrationVisitor::queueMigration();

        wp_send_json_success([
            'success' => true,
            'message' => 'Migration completed',
            'data' => $migrationInfo,
        ]);
    }

    public function updateMembershipsEnabled(): void
    {
        check_ajax_referer('grocers_list_update_memberships_enabled', 'security');

        $this->checkPermission('grocers_list_update_memberships_enabled');

        $enabled = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '';

        if (!in_array($enabled, ['0', '1'], true)) {
            wp_send_json_error(['error' => 'Invalid enabled parameter - must be "0" or "1"'], 400);
            return;
        }

        $api_key = PluginSettings::getApiKey();

        $response = ApiClient::updateMembershipsEnabled($api_key, $enabled);

        if (is_wp_error($response)) {
            wp_send_json_error(['error' => 'Failed to update memberships enabled setting. Please check your API Key or contact support for help.'], 500);
            return;
        }

        wp_send_json_success(['data' => $enabled, 'message' => 'Memberships enabled setting updated']);
    }
}
