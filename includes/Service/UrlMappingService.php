<?php

namespace GrocersList\Service;

use GrocersList\Database\UrlMappingTable;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\LinkUtils;

class UrlMappingService
{
    private IApiClient $api;
    private ILinkExtractor $extractor;
    private UrlMappingTable $table;

    public function __construct(
        IApiClient $api,
        ILinkExtractor $extractor,
        UrlMappingTable $table
    ) {
        $this->api = $api;
        $this->extractor = $extractor;
        $this->table = $table;
    }

    public function reset_mappings(): void
    {
        $this->table->truncate_table();
    }

    public function get_link_count_info(): array
    {
        global $wpdb;

        $posts_with_links = 0;
        $total_amazon_links = 0;
        $total_mapped_links = 0;
        $total_unmapped_links = 0;

        // Get all published posts with content // TODO: do we want to run for unpublished posts too?
        $posts = $wpdb->get_results(
            "SELECT ID, post_content
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_content IS NOT NULL
               AND post_content != ''"
        );

        foreach ($posts as $post) {
            $content = $post->post_content;
            $normalized = html_entity_decode(stripslashes($content)); // *CRITICAL - hash will not match DB row if url is not normalized first
            $amazon_links = $this->extractor->extractUnrewrittenLinks($normalized);

            if (!empty($amazon_links)) {
                $total_amazon_links += count($amazon_links);;

                $existing_mappings = $this->table->get_mappings_by_urls($amazon_links);
                $total_mapped_links += count($existing_mappings);

                $unmapped_links = array_values(array_diff($amazon_links, array_keys($existing_mappings)));
                $unmapped_links_count = count($unmapped_links);

                if ($unmapped_links_count > 0) {
                    $posts_with_links++;
                    // $total_amazon_links is NOT unique, so we cannot simply subtract mapped links from the total:
                    $total_unmapped_links += $unmapped_links_count;
                }
            }
        }

        return [
            // posts
            'totalPosts' => count($posts),
            'postsWithLinks' => $posts_with_links,
            // links
            'totalAmazonLinks' => $total_amazon_links,
            'totalMappedLinks' => $total_mapped_links,
            'totalUnmappedLinks' => $total_unmapped_links,
        ];
    }

    public function create_url_mappings_batch(array $urls, int $post_id = 0): array
    {
        if (empty($urls)) {
            return [];
        }

        // Check which URLs we already have mappings for
        $existing_mappings = $this->table->get_mappings_by_urls($urls);
        // Use array_values to reset keys after array_diff
        $missing_urls = array_values(array_diff($urls, array_keys($existing_mappings)));

        // Fetch new mappings for missing URLs
        if (!empty($missing_urls)) {
            $response = $this->api->postAppLinks($missing_urls);

            $new_mappings = [];

            foreach ($response->successes as $item) {
                $original_url = html_entity_decode($item->url);
                $linksta_url = LinkUtils::buildLinkstaUrl($item->hash);
                
                $new_mappings[$original_url] = [
                    'linksta_url' => $linksta_url,
                    'link_hash' => $item->hash,
                    'post_id' => $post_id
                ];
            }

            // Store new mappings in database
            if (!empty($new_mappings)) {
                $this->table->upsert_mappings($new_mappings);
            }

            // Merge with existing mappings
            foreach ($new_mappings as $url => $data) {
                $existing_mappings[$url] = (object)[
                    'linksta_url' => $data['linksta_url'],
                    'link_hash' => $data['link_hash'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
        }

        return $existing_mappings;
    }

    public function get_url_mappings_for_content(string $content): array
    {
        $normalized = html_entity_decode(stripslashes($content));
        $urls = $this->extractor->extract($normalized);

        if (empty($urls)) {
            return [];
        }

        return $this->table->get_mappings_by_urls($urls);
    }
}