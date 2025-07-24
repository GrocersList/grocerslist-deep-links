<?php

namespace GrocersList\Support;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

class ContentFilter
{
    private Hooks $hooks;
    private PluginSettings $settings;

    public function __construct(Hooks $hooks, PluginSettings $settings)
    {
        $this->hooks = $hooks;
        $this->settings = $settings;
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

        if ($this->settings->isUseLinkstaLinksEnabled()) {
            Logger::debug("ContentFilter::filterContent() => use_linksta_links ENABLED, using rewritten links");

            return preg_replace_callback(
                '/<a\s+[^>]*href="([^"]*)"[^>]*data-grocerslist-rewritten-link="([^"]*)"[^>]*>/i',
                function ($matches) {
                    // we hash a timestamp and include it in an encoded param "?token=" to allow us to reliably identify
                    // clicks that should be considered originating from wordpress (which we do not charge for)
                    $split = explode('/', $matches[2]); // split "linksta.io/asdfasdf" => ["linksta.io", "asdfasdf"]
                    $link_hash = end($split);
                    $token_param = $this->create_timestamp_token($link_hash);

                    Logger::debug("ContentFilter::filterContent() using rewritten link: {$matches[1]} -> {$matches[2]}");
                    $tag = str_replace('href="' . $matches[1] . '"', 'href="' . $matches[2] . '?token=' . $token_param . '"', $matches[0]);
                    return str_replace('<a ', '<a data-grocers-list-rewritten="true" ', $tag);
                },
                $content
            );
        }

        Logger::debug("ContentFilter::filterContent() => use_linksta_links DISABLED, removing rewritten link attributes");

        return preg_replace_callback(
            '/<a\s+[^>]*href="([^"]*)"[^>]*data-grocerslist-rewritten-link="([^"]*)"[^>]*>/i',
            function ($matches) {
                Logger::debug("ContentFilter::filterContent() removing rewritten link attribute for: {$matches[1]}");
                $tag = str_replace('data-grocerslist-rewritten-link="' . $matches[2] . '"', '', $matches[0]);
                return str_replace('<a ', '<a data-grocers-list-rewritten="false" ', $tag);
            },
            $content
        );
    }
}
