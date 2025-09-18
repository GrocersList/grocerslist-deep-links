<?php

namespace GrocersList\Support;

use GrocersList\Service\IApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;
use GrocersList\Service\UrlMappingService;

class ContentFilter
{
    private Hooks $hooks;
    private PluginSettings $settings;
    private ?UrlMappingService $urlMappingService;
    private IApiClient $api;

    public function __construct(Hooks $hooks, PluginSettings $settings, ?UrlMappingService $urlMappingService = null, IApiClient $api)
    {
        $this->hooks = $hooks;
        $this->settings = $settings;
        $this->urlMappingService = $urlMappingService;
        $this->api = $api;
    }

    public function register(): void
    {
        $this->hooks->addFilter('the_content', [$this, 'filterContent']);
    }

    private function create_timestamp_token($secret_key)
    {
        $timestamp = time() * 1000; // seconds -> milliseconds, to conform to javascript timestamp format

        $hash = hash_hmac('sha256', $timestamp, $secret_key);
        $encoded = base64_encode(json_encode([
            't' => $timestamp,
            'h' => $hash
        ]));

        return urlencode($encoded);
    }

    public function filterContent(string $content): string
    {
        Logger::debug("ContentFilter::filterContent() called");

        $creatorSettings = $this->api->getCreatorSettings($this->settings->getApiKey());

        if (!$creatorSettings->provisioning->appLinks->hasAppLinksAddon) {
            Logger::debug("ContentFilter::filterContent() => hasAppLinksAddon FALSE");
            return $this->removeDataAttributes($content);
        }

        if (!$this->settings->isUseLinkstaLinksEnabled()) {
            Logger::debug("ContentFilter::filterContent() => use_linksta_links DISABLED");
            return $this->removeDataAttributes($content);
        }

        // Use new database-driven approach if available
        if ($this->urlMappingService !== null) {
            Logger::debug("ContentFilter::filterContent() => using database mappings");
            return $this->filterContentWithDatabaseMappings($content);
        }

        // Fallback to old data attribute approach
        Logger::debug("ContentFilter::filterContent() => using data attributes (fallback)");
        return $this->filterContentWithDataAttributes($content);
    }

    private function filterContentWithDatabaseMappings(string $content): string
    {
        $mappings = $this->urlMappingService->get_url_mappings_for_content($content);
        
        if (empty($mappings)) {
            Logger::debug("ContentFilter::filterContentWithDatabaseMappings() no mappings found");
            // Check if content has old-style data attributes and handle them
            if (strpos($content, 'data-grocerslist-rewritten-link') !== false) {
                Logger::debug("ContentFilter::filterContentWithDatabaseMappings() found old-style data attributes, using fallback");
                return $this->filterContentWithDataAttributes($content);
            }
            return $content;
        }

        Logger::debug("ContentFilter::filterContentWithDatabaseMappings() found " . count($mappings) . " mappings");

        return preg_replace_callback(
            '/<a\s+[^>]*href="([^"]*)"[^>]*>/i',
            function ($matches) use ($mappings) {
                $original_url = html_entity_decode($matches[1]);
                
                if (isset($mappings[$original_url])) {
                    $mapping = $mappings[$original_url];
                    $token_param = $this->create_timestamp_token($mapping->link_hash);
                    $linksta_url_with_token = $mapping->linksta_url . '?token=' . $token_param;

                    Logger::debug("ContentFilter::filterContentWithDatabaseMappings() replacing: {$original_url} -> {$mapping->linksta_url}");
                    
                    $tag = str_replace('href="' . $matches[1] . '"', 'href="' . $linksta_url_with_token . '"', $matches[0]);
                    return str_replace('<a ', '<a data-grocers-list-rewritten="true" ', $tag);
                } else {
                    Logger::debug("ContentFilter::filterContentWithDatabaseMappings() no mapping found for: {$original_url}");
                }

                return $matches[0];
            },
            $content
        );
    }

    private function filterContentWithDataAttributes(string $content): string
    {
        return preg_replace_callback(
            '/<a\s+[^>]*href="([^"]*)"[^>]*data-grocerslist-rewritten-link="([^"]*)"[^>]*>/i',
            function ($matches) {
                // we hash a timestamp and include it in an encoded param "?token=" to allow us to reliably identify
                // clicks that should be considered originating from wordpress (which we do not charge for)
                $split = explode('/', $matches[2]); // split "linksta.io/asdfasdf" => ["linksta.io", "asdfasdf"]
                $link_hash = end($split);
                $token_param = $this->create_timestamp_token($link_hash);

                Logger::debug("ContentFilter::filterContentWithDataAttributes() using rewritten link: {$matches[1]} -> {$matches[2]}");
                $tag = str_replace('href="' . $matches[1] . '"', 'href="' . $matches[2] . '?token=' . $token_param . '"', $matches[0]);
                return str_replace('<a ', '<a data-grocers-list-rewritten="true" ', $tag);
            },
            $content
        );
    }

    private function removeDataAttributes(string $content): string
    {
        return preg_replace_callback(
            '/<a\s+[^>]*href="([^"]*)"[^>]*data-grocerslist-rewritten-link="([^"]*)"[^>]*>/i',
            function ($matches) {
                Logger::debug("ContentFilter::removeDataAttributes() removing rewritten link attribute for: {$matches[1]}");
                $tag = str_replace('data-grocerslist-rewritten-link="' . $matches[2] . '"', '', $matches[0]);
                return str_replace('<a ', '<a data-grocers-list-rewritten="false" ', $tag);
            },
            $content
        );
    }
}
