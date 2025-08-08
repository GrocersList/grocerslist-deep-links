<?php

namespace GrocersList\Service;

use GrocersList\Database\UrlMappingTable;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\LinkUtils;
use GrocersList\Support\Logger;

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

    public function create_url_mappings_batch(array $urls, int $post_id = 0): array
    {
        if (empty($urls)) {
            return [];
        }

        // Check which URLs we already have mappings for
        $existing_mappings = $this->table->get_mappings_by_urls($urls);
        // Use array_values to reset keys after array_diff
        $missing_urls = array_values(array_diff($urls, array_keys($existing_mappings)));

        Logger::debug("Found " . count($existing_mappings) . " existing mappings, " . count($missing_urls) . " missing");

        // Fetch new mappings for missing URLs
        if (!empty($missing_urls)) {
            Logger::debug("Calling API with " . count($missing_urls) . " URLs: " . json_encode($missing_urls));
            $response = $this->api->postAppLinks($missing_urls);
            Logger::debug("API returned " . count($response->successes) . " successes");
            
            $new_mappings = [];

            foreach ($response->successes as $item) {
                $original_url = html_entity_decode($item->url);
                $linksta_url = LinkUtils::buildLinkstaUrl($item->hash);
                
                $new_mappings[$original_url] = [
                    'linksta_url' => $linksta_url,
                    'link_hash' => $item->hash,
                    'post_id' => $post_id
                ];

                Logger::debug("New mapping: $original_url -> $linksta_url (post_id: $post_id)");
            }

            // Store new mappings in database
            if (!empty($new_mappings)) {
                Logger::debug("Storing " . count($new_mappings) . " new mappings in database");
                $this->table->upsert_mappings($new_mappings);
            } else {
                Logger::debug("No new mappings to store - API returned no successes for URLs: " . json_encode($missing_urls));
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