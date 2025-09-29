<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkRewriteResult;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\LinkExtractor;
use GrocersList\Support\LinkReplacer;
use GrocersList\Support\LinkUtils;

class LinkRewriter
{
    public function register(): void
    {
        add_filter('wp_insert_post_data', [$this, 'onPostSave'], 10, 2);
    }

    public function onPostSave($data, $postarr)
    {
        if (!in_array($data['post_type'], ['post', 'page'])) {
            return $data;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $data;
        }

        if (!PluginSettings::isUseLinkstaLinksEnabled()) {
            return $data;
        }

        $content = $data['post_content'];
        $normalized = html_entity_decode(stripslashes($content));
        $urls = LinkExtractor::extract($normalized);

        if (!empty($urls)) {
            // Get post ID from postarr or data
            $post_id = isset($postarr['ID']) ? $postarr['ID'] : (isset($data['ID']) ? $data['ID'] : 0);

            // Create URL mappings in the database but don't modify content
            $mappings = UrlMappingService::create_url_mappings_batch($urls, $post_id);

            if (!empty($mappings)) {
                add_filter('redirect_post_location', function($loc) {
                    return add_query_arg('adl_mapped', '1', $loc);
                });
            }
        }

        // Return unmodified content - URL replacement happens at render time
        return $data;
    }

    public function rewrite(string $content): LinkRewriteResult
    {
        $normalized = html_entity_decode(stripslashes($content));
        $urls = LinkExtractor::extract($normalized);

        if (empty($urls)) {
            return new LinkRewriteResult($content, false);
        }

        $response = ApiClient::postAppLinks($urls);
        $urlMap = [];

        foreach ($response->successes as $item) {
            $original = html_entity_decode($item->url);
            // Always create the linksta URL for the URL map
            // This ensures the LinkReplacer will always add the data-original-url attribute
            $rewritten = LinkUtils::buildLinkstaUrl($item->hash);
            $urlMap[$original] = $rewritten;
        }

        $result = LinkReplacer::replace($normalized, $urlMap);
        return new LinkRewriteResult($result->content, $result->rewritten);
    }
}
