<?php

namespace GrocersList\Support;

use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Service\UrlMappingService;
use GrocersList\Settings\PluginSettings;

class ContentFilter
{
    private CreatorSettingsFetcher $creatorSettingsFetcher;

    public function __construct(
        CreatorSettingsFetcher $creatorSettingsFetcher
    )
    {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
    }

    public function register(): void
    {
        add_filter('the_content', [$this, 'filterContent']);
    }

    public function filterContent(string $content): string
    {
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings();

        if (!$creatorSettings?->provisioning?->appLinks?->hasAppLinksAddon) {
            return $this->removeDataAttributes($content);
        }

        if (!PluginSettings::isUseLinkstaLinksEnabled()) {
            return $this->removeDataAttributes($content);
        }

        return $this->filterContentWithDatabaseMappings($content);
    }

    private function filterContentWithDatabaseMappings(string $content): string
    {
        $mappings = UrlMappingService::get_url_mappings_for_content($content);
        
        if (empty($mappings)) {
            // Check if content has old-style data attributes and handle them
            if (strpos($content, 'data-grocerslist-rewritten-link') !== false) {
                Logger::debug("ContentFilter::filterContentWithDatabaseMappings() found old-style data attributes, using fallback");
                return $this->filterContentWithDataAttributes($content);
            }
            return $content;
        }

        return preg_replace_callback(
            '/<a\s+[^>]*href="([^"]*)"[^>]*>/i',
            function ($matches) use ($mappings) {
                $original_url = html_entity_decode($matches[1]);
                
                if (isset($mappings[$original_url])) {
                    $mapping = $mappings[$original_url];
                    $linksta_url_with_token = $mapping->linksta_url . '/wp';

                    $tag = str_replace('href="' . $matches[1] . '"', 'href="' . $linksta_url_with_token . '"', $matches[0]);
                    return str_replace('<a ', '<a data-grocers-list-rewritten="true" rel="noopener noreferrer" ', $tag);
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
                Logger::debug("ContentFilter::filterContentWithDataAttributes() using rewritten link: {$matches[1]} -> {$matches[2]}");
                $tag = str_replace('href="' . $matches[1] . '"', 'href="' . $matches[2] . '/wp' . '"', $matches[0]);
                return str_replace('<a ', '<a data-grocers-list-rewritten="true" rel="noopener noreferrer" ', $tag);
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
