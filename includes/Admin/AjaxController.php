<?php

namespace GrocersList\Admin;

use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Scanner\PostScanner;
use GrocersList\Service\ApiClient;
use GrocersList\Service\UrlMappingService;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;

class AjaxController
{
    private PluginSettings $settings;
    private ApiClient $api;
    private MigrationVisitor $migrationJob;
    private Hooks $hooks;
    private UrlMappingService $urlMappingService;

    public function __construct(
        PluginSettings   $settings,
        ApiClient        $api,
        MigrationVisitor $migrationJob,
        Hooks            $hooks,
        UrlMappingService  $urlMappingService
    )
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->migrationJob = $migrationJob;
        $this->hooks = $hooks;
        $this->urlMappingService = $urlMappingService;
    }

    public function register(): void
    {
        $actions = [
            'grocers_list_get_state' => 'getState',
            'grocers_list_update_api_key' => 'updateApiKey',
            'grocers_list_update_use_linksta_links' => 'updateUseLinkstaLinks',
            'grocers_list_count_matched_links' => 'countMatchedLinks',
            'grocers_list_find_matched_links' => 'findMatchedLinks',
            'grocers_list_clear_settings' => 'clearSettings',
            'grocers_list_get_migration_status' => 'getMigrationStatus',
            'grocers_list_get_link_count_info' => 'getLinkCountInfo',
            'grocers_list_trigger_migrate' => 'triggerMigrate',
            'grocers_list_update_post_gating_options' => 'updatePostGatingOptions',
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

        $this->settings->reset();

        // remove all mappings (effectively undo-ing migration):
        $this->urlMappingService->reset_mappings();

        $this->settings->reset();
        wp_send_json_success(['message' => 'All settings cleared']);
    }

    public function getState(): void
    {
        check_ajax_referer('grocers_list_get_state', 'security');

        $this->checkPermission('grocers_list_get_state');

        wp_send_json_success([
            'apiKey' => $this->settings->getApiKey(),
            'useLinkstaLinks' => $this->settings->isUseLinkstaLinksEnabled(),
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

        if (!$this->api->validateApiKey($apiKey)) {
            wp_send_json_error(['error' => 'Invalid API key'], 400);
        }

        $this->settings->setApiKey($apiKey);
        wp_send_json_success(['message' => 'API key updated']);
    }

    public function updateUseLinkstaLinks(): void
    {
        check_ajax_referer('grocers_list_update_use_linksta_links', 'security');

        $this->checkPermission('grocers_list_update_use_linksta_links');
        $enabled = isset($_POST['useLinkstaLinks'])
            && sanitize_text_field(wp_unslash($_POST['useLinkstaLinks'])) === '1';        $this->settings->setUseLinkstaLinks($enabled);
        wp_send_json_success(['message' => 'Use Linksta Links setting updated']);
    }

    public function findMatchedLinks(): void
    {
        check_ajax_referer('grocers_list_find_matched_links', 'security');

        $this->checkPermission('grocers_list_find_matched_links');
        
        $results = PostScanner::scanForAmazonLinks();
        
        $matched = array_map(function($post) {
            return [
                'id' => $post['id'],
                'title' => $post['title'],
                'count' => $post['link_count']
            ];
        }, $results['posts']);

        wp_send_json_success(['posts' => $matched]);
    }

    public function countMatchedLinks(): void
    {
        check_ajax_referer('grocers_list_count_matched_links', 'security');

        $this->checkPermission('grocers_list_count_matched_links');
        
        $summary = PostScanner::getSummary();
        wp_send_json_success($summary['data']);
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

        if (!$this->migrationJob) {
            wp_send_json_error(['error' => 'Migration job not available'], 500);
            return;
        }

        $migrationInfo = $this->migrationJob->getMigrationInfo();
        wp_send_json_success($migrationInfo);
    }

    public function processNextCountBatch(): void
    {
        // No-op - batches are now scheduled automatically by the visitor pattern
    }

    public function getLinkCountInfo(): void
    {
        check_ajax_referer('grocers_list_get_link_count_info', 'security');

        $this->checkPermission('grocers_list_get_link_count_info');

        $countInfo = $this->urlMappingService->get_link_count_info();
        wp_send_json_success($countInfo);
    }

    public function triggerMigrate(): void
    {
        check_ajax_referer('grocers_list_trigger_migrate', 'security');

        $this->checkPermission('grocers_list_trigger_migrate');

        if (!$this->migrationJob) {
            wp_send_json_error(['error' => 'Migration job not available'], 500);
            return;
        }

        $migrationInfo = $this->migrationJob->startMigration();

        wp_send_json_success([
            'success' => true,
            'message' => 'Migration completed',
            'data' => $migrationInfo,
        ]);
    }

    public function updatePostGatingOptions(): void
    {
        check_ajax_referer('grocers_list_update_post_gating_options', 'security');

        $this->checkPermission('grocers_list_update_post_gating_options');

        if (!isset($_POST['postId'])) {
            wp_send_json_error(['error' => 'Invalid post ID'], 400);
            return;
        }

        $post_id_raw = sanitize_text_field(wp_unslash($_POST['postId']));

        if (!is_numeric($post_id_raw)) {
            wp_send_json_error(['error' => 'Invalid post ID'], 400);
            return;
        }

        $post_id = intval($post_id_raw);

        if (!get_post($post_id)) {
            wp_send_json_error(['error' => 'Post not found'], 404);
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['error' => 'You do not have permission to edit this post'], 403);
            return;
        }

        $post_gated = isset($_POST['postGated']) && sanitize_text_field(wp_unslash($_POST['postGated'])) === '1' ? '1' : '0';
        $recipe_card_gated = isset($_POST['recipeCardGated']) && sanitize_text_field(wp_unslash($_POST['recipeCardGated'])) === '1' ? '1' : '0';

        update_post_meta($post_id, 'grocers_list_post_gated', $post_gated);
        update_post_meta($post_id, 'grocers_list_recipe_card_gated', $recipe_card_gated);

        wp_send_json_success(['message' => 'Gating options updated']);
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

        $api_key = $this->settings->getApiKey();

        $response = $this->api->updateMembershipsEnabled($api_key, $enabled);

        if (is_wp_error($response)) {
            wp_send_json_error(['error' => 'Failed to update memberships enabled setting. Please check your API Key or contact support for help.'], 500);
            return;
        }
    
        wp_send_json_success(['data' => $enabled, 'message' => 'Memberships enabled setting updated']);
    }
}
