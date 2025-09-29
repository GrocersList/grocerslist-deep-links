<?php

namespace GrocersList\Jobs;

use GrocersList\Service\UrlMappingService;
use GrocersList\Support\LinkExtractor;
use GrocersList\Support\Logger;

class MigrationVisitor
{
    private static int $batchSize = 50;
    private static int $ttl = 60 * 10; // 10 minutes

    public static function queueMigration(): array
    {
        $timestamp = time();

        Logger::debug("MigrationVisitor::queueMigration() - scheduling at " . $timestamp);

        set_transient('grocerslist_migration_last_started_at', $timestamp, self::$ttl);

        if (function_exists('wp_schedule_single_event')) {
            Logger::debug("MigrationVisitor::queueMigration() - scheduling via WP-Cron at " . $timestamp);

            if (!wp_next_scheduled('migration_visitor_run_async')) {
                wp_schedule_single_event($timestamp, 'migration_visitor_run_async');
            } else {
                Logger::debug("MigrationVisitor::queueMigration() - already scheduled");
            }
        } else {
            Logger::debug("MigrationVisitor::queueMigration() - starting synchronously at " . $timestamp);
            MigrationVisitor::start();
        }

        return self::getStatus();
    }

    public static function start(): array
    {
        self::processPosts();

        set_transient('grocerslist_migration_last_completed_at', time(), self::$ttl);

        return self::getStatus();
    }

    public static function getStatus(): array
    {
        $started_at = (int)get_transient('grocerslist_migration_last_started_at');
        $completed_at = (int)get_transient('grocerslist_migration_last_completed_at');

        $isRunning = !!$started_at && $completed_at < $started_at;

        return [
            'isComplete' => !!$completed_at && !$isRunning,
            'isRunning' => $isRunning,
            'lastMigrationStartedAt' => $started_at,
            'lastMigrationCompletedAt' => $completed_at,
        ];
    }

    public static function reset(): void
    {
        delete_transient('grocerslist_migration_last_started_at');
        delete_transient('grocerslist_migration_last_completed_at');
    }

    private static function getPostsForBatch(int $lastId): array
    {
        global $wpdb;

        $cache_key = 'grocers_list_migration_batch_' . $lastId . '_' . self::$batchSize;
        $ids = wp_cache_get($cache_key);

        if ($ids === false) {
            // TODO: do we want to run for unpublished posts too?
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
                    self::$batchSize
                )
            );

            wp_cache_set($cache_key, $ids, '', 300);
        }

        return array_filter(array_map('get_post', $ids));
    }

    private static function visitPost(\WP_Post $post): bool
    {
        $content = $post->post_content;
        $normalized = html_entity_decode(stripslashes($content));
        $urls = LinkExtractor::extract($normalized);

        if (!empty($urls)) {
            // Create URL mappings in the database
            UrlMappingService::create_url_mappings_batch($urls, $post->ID);
        }

        return true;
    }

    private static function processPosts(): void
    {
        $lastId = 0;

        do {
            $posts = self::getPostsForBatch($lastId);

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                self::visitPost($post);
                $lastId = max($lastId, $post->ID);
            }

        } while (!empty($posts));
    }
}
