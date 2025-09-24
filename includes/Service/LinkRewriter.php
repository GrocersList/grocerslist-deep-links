<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkRewriteResult;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\ILinkReplacer;
use GrocersList\Support\LinkUtils;

class LinkRewriter
{
    private IApiClient $api;
    private ILinkExtractor $extractor;
    private ILinkReplacer $replacer;
    private Hooks $hooks;
    private PluginSettings $settings;
    private UrlMappingService $urlMappingService;

    public function __construct(
        IApiClient     $api,
        ILinkExtractor $extractor,
        ILinkReplacer  $replacer,
        Hooks          $hooks,
        PluginSettings $settings,
        UrlMappingService $urlMappingService
    )
    {
        $this->hooks = $hooks;
        $this->replacer = $replacer;
        $this->extractor = $extractor;
        $this->api = $api;
        $this->settings = $settings;
        $this->urlMappingService = $urlMappingService;
    }

    public function register(): void
    {
        $this->hooks->addFilter('wp_insert_post_data', [$this, 'onPostSave'], 10, 2);
    }

    public function onPostSave($data, $postarr)
    {
        if (!in_array($data['post_type'], ['post', 'page'])) {
            return $data;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $data;
        }

        if (!$this->settings->isAutoRewriteEnabled()) {
            return $data;
        }

        $content = $data['post_content'];
        $normalized = html_entity_decode(stripslashes($content));
        $urls = $this->extractor->extract($normalized);

        if (!empty($urls)) {
            // Get post ID from postarr or data
            $post_id = isset($postarr['ID']) ? $postarr['ID'] : (isset($data['ID']) ? $data['ID'] : 0);

            // Create URL mappings in the database but don't modify content
            $mappings = $this->urlMappingService->create_url_mappings_batch($urls, $post_id);

            if (!empty($mappings)) {
                $this->hooks->addFilter('redirect_post_location', function($loc) {
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
        $urls = $this->extractor->extract($normalized);

        if (empty($urls)) {
            return new LinkRewriteResult($content, false);
        }

        $response = $this->api->postAppLinks($urls);
        $urlMap = [];

        foreach ($response->successes as $item) {
            $original = html_entity_decode($item->url);
            // Always create the linksta URL for the URL map
            // This ensures the LinkReplacer will always add the data-original-url attribute
            $rewritten = LinkUtils::buildLinkstaUrl($item->hash);
            $urlMap[$original] = $rewritten;
        }

        $result = $this->replacer->replace($normalized, $urlMap);
        return new LinkRewriteResult($result->content, $result->rewritten);
    }
}
