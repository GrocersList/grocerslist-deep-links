<?php

namespace GrocersList\Admin;

use GrocersList\Service\ApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\Regex;
use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Jobs\LinkCountVisitor;
use GrocersList\Support\Logger;

class AjaxController
{
    private PluginSettings $settings;
    private ApiClient $api;
    private MigrationVisitor $migrationJob;
    private LinkCountVisitor $linkCountJob;
    private Hooks $hooks;

    public function __construct(
        PluginSettings   $settings,
        ApiClient        $api,
        MigrationVisitor $migrationJob,
        LinkCountVisitor $linkCountJob,
        Hooks            $hooks
    )
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->migrationJob = $migrationJob;
        $this->linkCountJob = $linkCountJob;
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $actions = [
            'grocers_list_get_state' => 'getState',
            'grocers_list_update_api_key' => 'updateApiKey',
            'grocers_list_update_auto_rewrite' => 'updateAutoRewrite',
            'grocers_list_update_use_linksta_links' => 'updateUseLinkstaLinks',
            'grocers_list_count_matched_links' => 'countMatchedLinks',
            'grocers_list_find_matched_links' => 'findMatchedLinks',
            'grocers_list_mark_setup_complete' => 'markSetupComplete',
            'grocers_list_clear_settings' => 'clearSettings',
            'grocers_list_get_migration_status' => 'getMigrationStatus',
            'grocers_list_recount_links' => 'recountLinks',
            'grocers_list_get_link_count_info' => 'getLinkCountInfo',
            'grocers_list_trigger_migrate' => 'triggerMigrate',
            'grocers_list_trigger_recount_links' => 'triggerRecountLinks',
            'grocers_list_get_post_gating_options' => 'getPostGatingOptions',
            'grocers_list_update_post_gating_options' => 'updatePostGatingOptions',
        ];

        foreach ($actions as $hook => $method) {
            add_action("wp_ajax_{$hook}", [$this, $method]);
        }
    }

    public function clearSettings(): void
    {
        check_ajax_referer('grocers_list_clear_settings', 'security');

        $this->checkPermission('grocers_list_clear_settings');
        delete_option('grocers_list_GrocersList\Jobs\LinkCountVisitor_running');
        delete_option('grocers_list_GrocersList\Jobs\LinkCountVisitor_processed');
        delete_option('grocers_list_GrocersList\Jobs\LinkCountVisitor_total');
        delete_option('grocers_list_GrocersList\Jobs\LinkCountVisitor_last_processed_id');

        delete_option('grocers_list_GrocersList\Jobs\MigrationVisitor_running');
        delete_option('grocers_list_GrocersList\Jobs\MigrationVisitor_processed');
        delete_option('grocers_list_GrocersList\Jobs\MigrationVisitor_total');
        delete_option('grocers_list_GrocersList\Jobs\MigrationVisitor_last_processed_id');
        $this->settings->reset();
        wp_send_json_success(['message' => 'All settings cleared']);
    }

    public function getState(): void
    {
        check_ajax_referer('grocers_list_get_state', 'security');

        $this->checkPermission('grocers_list_get_state');
        Logger::debug('getState called');

        wp_send_json_success([
            'apiKey' => $this->settings->getApiKey(),
            'autoRewriteEnabled' => $this->settings->isAutoRewriteEnabled(),
            'useLinkstaLinks' => $this->settings->isUseLinkstaLinksEnabled(),
            'setupComplete' => $this->settings->isSetupComplete(),
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

    public function updateAutoRewrite(): void
    {
        check_ajax_referer('grocers_list_update_auto_rewrite', 'security');

        $this->checkPermission('grocers_list_update_auto_rewrite');
        $enabled = isset($_POST['autoRewriteEnabled'])
            && sanitize_text_field(wp_unslash($_POST['autoRewriteEnabled'])) === '1';        $this->settings->setAutoRewrite($enabled);
        wp_send_json_success(['message' => 'Auto Rewrite setting updated']);
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
        $posts = $this->getAllPublishedPosts();
        $regex = Regex::amazonLink();

        $matched = array_filter(array_map(function ($post) use ($regex) {
            if (preg_match_all($regex, $post->post_content, $matches)) {
                return [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'count' => count($matches[0]),
                ];
            }
            return null;
        }, $posts));

        wp_send_json_success(['posts' => array_values($matched)]);
    }

    public function countMatchedLinks(): void
    {
        check_ajax_referer('grocers_list_count_matched_links', 'security');

        $this->checkPermission('grocers_list_count_matched_links');
        $posts = $this->getAllPublishedPosts();
        $regex = Regex::amazonLink();

        $postCount = 0;
        $linkCount = 0;

        foreach ($posts as $post) {
            if (preg_match_all($regex, $post->post_content, $matches)) {
                $postCount++;
                $linkCount += count($matches[0]);
            }
        }

        wp_send_json_success([
            'postsWithLinks' => $postCount,
            'totalLinks' => $linkCount,
        ]);
    }

    public function markSetupComplete(): void
    {
        check_ajax_referer('grocers_list_mark_setup_complete', 'security');

        $this->checkPermission('grocers_list_mark_setup_complete');
        Logger::debug("markSetupComplete: starting");
        $this->settings->markSetupComplete();
        wp_send_json_success(['message' => 'Marked setup complete']);
    }

    private function checkPermission(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized'], 403);
        }
    }

    private function getAllPublishedPosts(): array
    {
        return get_posts([
            'numberposts' => -1,
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
        ]);
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

    public function recountLinks(): void
    {
        check_ajax_referer('grocers_list_recount_links', 'security');

        $this->checkPermission('grocers_list_recount_links');

        if (!$this->linkCountJob) {
            wp_send_json_error(['error' => 'Link count job not available'], 500);
            return;
        }

        $countInfo = $this->linkCountJob->startCounting();

        wp_send_json_success($countInfo);
    }

    public function processNextCountBatch(): void
    {
        // No-op - batches are now scheduled automatically by the visitor pattern
    }

    public function getLinkCountInfo(): void
    {
        check_ajax_referer('grocers_list_get_link_count_info', 'security');

        $this->checkPermission('grocers_list_get_link_count_info');

        if (!$this->linkCountJob) {
            wp_send_json_error(['error' => 'Link count job not available'], 500);
            return;
        }

        $countInfo = $this->linkCountJob->getCountInfo();
        wp_send_json_success($countInfo);
    }

    public function triggerMigrate(): void
    {
        check_ajax_referer('grocers_list_trigger_migrate', 'security');

        $this->checkPermission('grocers_list_trigger_migrate');
        Logger::debug("triggerMigrate: starting");

        if (!$this->migrationJob) {
            wp_send_json_error(['error' => 'Migration job not available'], 500);
            return;
        }

        $posts = $this->getAllPublishedPosts();
        $flagged = 0;

        foreach ($posts as $post) {
            if (!get_post_meta($post->ID, '_grocers_list_needs_migration', true)) {
                update_post_meta($post->ID, '_grocers_list_needs_migration', '1');
                $flagged++;
            }
        }

        $migrationInfo = $this->migrationJob->startMigration();

        wp_send_json_success([
            'success' => true,
            'message' => 'Migration completed',
            'flagged' => $flagged,
            'data' => $migrationInfo,
        ]);
    }

    public function triggerRecountLinks(): void
    {
        check_ajax_referer('grocers_list_trigger_recount_links', 'security');

        $this->checkPermission('grocers_list_trigger_recount_links');
        Logger::debug("triggerRecountLinks: starting");

        if (!$this->linkCountJob) {
            wp_send_json_error(['error' => 'Link count job not available'], 500);
            return;
        }

        $countInfo = $this->linkCountJob->startCounting();

        wp_send_json_success([
            'success' => true,
            'message' => 'Link recount completed',
            'data' => $countInfo,
        ]);
    }

    public function getPostGatingOptions(): void
    {
        check_ajax_referer('grocers_list_get_post_gating_options', 'security');

        $this->checkPermission('grocers_list_get_post_gating_options');

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

        $post_gated = get_post_meta($post_id, 'grocers_list_post_gated', true) === '1';
        $recipe_card_gated = get_post_meta($post_id, 'grocers_list_recipe_card_gated', true) === '1';

        wp_send_json_success([
            'postGated' => $post_gated,
            'recipeCardGated' => $recipe_card_gated,
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
}
