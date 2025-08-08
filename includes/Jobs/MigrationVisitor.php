<?php

namespace GrocersList\Jobs;

use GrocersList\Service\LinkRewriter;
use GrocersList\Service\UrlMappingService;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\Logger;

class MigrationVisitor extends PostVisitor
{
    private LinkRewriter $rewriter;
    private ?UrlMappingService $urlMappingService;
    private ILinkExtractor $linkExtractor;
    private PluginSettings $settings;
    private int $migratedPosts = 0;
    private int $lastMigrationTime = 0;
    private int $totalMappingsCreated = 0;

    public function __construct(
        LinkRewriter   $rewriter,
        ?UrlMappingService $urlMappingService,
        ILinkExtractor $linkExtractor,
        PluginSettings $settings,
        Hooks          $hooks,
        int            $batchSize = 10
    )
    {
        parent::__construct($hooks, $batchSize);
        $this->rewriter = $rewriter;
        $this->urlMappingService = $urlMappingService;
        $this->linkExtractor = $linkExtractor;
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
                     WHERE p.post_status = 'publish'
                       AND p.post_type IN ('post', 'page')
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
        // Use NEW mode: create database mappings instead of modifying content
        if ($this->urlMappingService !== null) {
            $content = $post->post_content;
            $normalized = html_entity_decode(stripslashes($content));
            $urls = $this->linkExtractor->extract($normalized);
            
            Logger::debug("MigrationVisitor: Post {$post->ID} has " . count($urls) . " URLs to process");
            
            if (!empty($urls)) {
                // Create URL mappings in the database
                $mappings = $this->urlMappingService->create_url_mappings_batch($urls, $post->ID);
                
                Logger::debug("MigrationVisitor: create_url_mappings_batch returned " . count($mappings) . " mappings");
                
                if (!empty($mappings)) {
                    $this->migratedPosts++;
                    $this->totalMappingsCreated += count($mappings);
                    Logger::debug("MigrationVisitor: Created " . count($mappings) . " mappings for post {$post->ID}");
                } else {
                    Logger::debug("MigrationVisitor: No mappings created for post {$post->ID}");
                }
            }
        } else {
            // Fallback to OLD mode if UrlMappingService not available
            $result = $this->rewriter->rewrite($post->post_content);

            if ($result->rewritten) {
                wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $result->content,
                ]);
                $this->migratedPosts++;
            }
        }

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
                    WHERE p.post_status = %s
                    AND p.post_type IN (%s, %s)",
                    'publish',
                    'post',
                    'page'
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
        update_option('grocers_list_migration_total_mappings', $this->totalMappingsCreated);
    }

    private function resetCounters(): void
    {
        $this->migratedPosts = 0;
        $this->lastMigrationTime = 0;
        $this->totalMappingsCreated = 0;
    }

    public function getMigrationInfo(): array
    {
        return [
            'migratedPosts' => (int)get_option('grocers_list_migration_migrated_posts', 0),
            'totalPosts' => (int)get_option('grocers_list_migration_total_posts', 0),
            'totalMappings' => (int)get_option('grocers_list_migration_total_mappings', 0),
            'processedPosts' => $this->getProcessedPosts(),
            'isComplete' => true,
            'isRunning' => false,
            'lastMigration' => (int)get_option('grocers_list_migration_last_time', 0),
        ];
    }
    
    /**
     * Public method to migrate a single post
     */
    public function migratePost($post): bool
    {
        return $this->visitPost($post);
    }
}
