<?php

namespace GrocersList\Jobs;

use GrocersList\Service\LinkRewriter;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\Logger;

class MigrationVisitor extends PostVisitor
{
    private LinkRewriter $rewriter;
    private PluginSettings $settings;
    private int $migratedPosts = 0;
    private int $lastMigrationTime = 0;

    public function __construct(
        LinkRewriter   $rewriter,
        PluginSettings $settings,
        Hooks          $hooks,
        int            $batchSize = 10
    )
    {
        parent::__construct($hooks, $batchSize);
        $this->rewriter = $rewriter;
        $this->settings = $settings;
    }

    public function startMigration(): array
    {
        Logger::debug("MigrationVisitor::startMigration()");
        $this->resetCounters();
        return $this->start();
    }

    protected function getPostsForBatch(int $lastId): array
    {
        global $wpdb;

        $cache_key = 'grocers_list_migration_batch_' . $lastId . '_' . $this->batchSize;
        $ids = wp_cache_get($cache_key);

        if ($ids === false) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_status = 'publish'
                       AND p.post_type IN ('post', 'page')
                       AND pm.meta_key = '_grocers_list_needs_migration'
                       AND pm.meta_value = '1'
                       AND p.ID > %d
                     ORDER BY p.ID ASC
                     LIMIT %d",
                    $lastId,
                    $this->batchSize
                )
            );

            wp_cache_set($cache_key, $ids, '', 300);
        }

        return array_filter(array_map('get_post', $ids));
    }

    protected function visitPost($post): bool
    {
        $result = $this->rewriter->rewrite($post->post_content);

        if ($result->rewritten) {
            wp_update_post([
                'ID' => $post->ID,
                'post_content' => $result->content,
            ]);
            $this->migratedPosts++;
        }

        delete_post_meta($post->ID, '_grocers_list_needs_migration');

        return true;
    }

    protected function getTotalPostCount(): int
    {
        global $wpdb;

        $cache_key = 'grocers_list_migration_total_count';
        $count = wp_cache_get($cache_key);

        if ($count === false) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_status = %s
                    AND p.post_type IN (%s, %s)
                    AND pm.meta_key = %s
                    AND pm.meta_value = %s",
                    'publish',
                    'post',
                    'page',
                    '_grocers_list_needs_migration',
                    '1'
                )
            );

            wp_cache_set($cache_key, $count, '', 60 * 5);
        }

        return $count;
    }

    protected function onJobCompleted(): void
    {
        $this->lastMigrationTime = time();
        $this->saveResults();

        wp_cache_delete('grocers_list_migration_total_count');
    }

    private function saveResults(): void
    {
        update_option('grocers_list_migration_migrated_posts', $this->migratedPosts);
        update_option('grocers_list_migration_total_posts', $this->getTotalPosts());
        update_option('grocers_list_migration_last_time', $this->lastMigrationTime);
    }

    private function resetCounters(): void
    {
        $this->migratedPosts = 0;
        $this->lastMigrationTime = 0;
    }

    public function getMigrationInfo(): array
    {
        return [
            'migratedPosts' => (int)get_option('grocers_list_migration_migrated_posts', 0),
            'totalPosts' => (int)get_option('grocers_list_migration_total_posts', 0),
            'processedPosts' => $this->getProcessedPosts(),
            'isComplete' => true,
            'isRunning' => false,
            'lastMigration' => (int)get_option('grocers_list_migration_last_time', 0),
        ];
    }
}
