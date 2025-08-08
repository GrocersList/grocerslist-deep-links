<?php

namespace GrocersList\Database;

use GrocersList\Support\Logger;

class DatabaseMigration
{
    private UrlMappingTable $urlMappingTable;

    public function __construct(UrlMappingTable $urlMappingTable)
    {
        $this->urlMappingTable = $urlMappingTable;
    }

    public function migrate_existing_posts(): array
    {
        Logger::debug("DatabaseMigration::migrate_existing_posts() starting migration");

        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_grocerslist_migrated_to_db',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        $migrated_count = 0;
        $total_mappings = 0;

        foreach ($posts as $post) {
            $content = $post->post_content;
            $mappings = $this->extract_mappings_from_content($content);

            if (!empty($mappings)) {
                $this->urlMappingTable->upsert_mappings($mappings);
                $total_mappings += count($mappings);
                Logger::debug("DatabaseMigration: Migrated " . count($mappings) . " mappings from post {$post->ID}");
            }

            // Mark post as migrated
            update_post_meta($post->ID, '_grocerslist_migrated_to_db', time());
            $migrated_count++;
        }

        Logger::debug("DatabaseMigration::migrate_existing_posts() completed: {$migrated_count} posts, {$total_mappings} mappings");

        return [
            'posts_migrated' => $migrated_count,
            'mappings_created' => $total_mappings
        ];
    }

    private function extract_mappings_from_content(string $content): array
    {
        $mappings = [];

        preg_match_all(
            '/<a\s+[^>]*href="([^"]*)"[^>]*data-grocerslist-rewritten-link="([^"]*)"[^>]*>/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $original_url = html_entity_decode($match[1]);
            $linksta_url = $match[2];
            
            // Extract hash from linksta URL (e.g., "linksta.io/abc123" -> "abc123")
            $url_parts = explode('/', $linksta_url);
            $link_hash = end($url_parts);

            $mappings[$original_url] = [
                'linksta_url' => $linksta_url,
                'link_hash' => $link_hash
            ];
        }

        return $mappings;
    }
}