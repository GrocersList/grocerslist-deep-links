<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\ILinkReplacer;
use GrocersList\Model\LinkRewriteResult;
use GrocersList\Support\LinkUtils;
use GrocersList\Support\Logger;

class LinkRewriter
{
    private IApiClient $api;
    private ILinkExtractor $extractor;
    private ILinkReplacer $replacer;
    private Hooks $hooks;
    private PluginSettings $settings;

    public function __construct(
        IApiClient     $api,
        ILinkExtractor $extractor,
        ILinkReplacer  $replacer,
        Hooks          $hooks,
        PluginSettings $settings
    )
    {
        $this->hooks = $hooks;
        $this->replacer = $replacer;
        $this->extractor = $extractor;
        $this->api = $api;
        $this->settings = $settings;
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

        $result = $this->rewrite($data['post_content']);
        $data['post_content'] = $result->content;

        if ($result->rewritten) {
            Logger::debug("LinkRewriter::onPostSave() content rewritten, adding redirect hook");
            $this->hooks->addFilter('redirect_post_location', fn($loc) => add_query_arg('adl_rewritten', '1', $loc));
        } else {
            Logger::debug("LinkRewriter::onPostSave() nothing rewritten");
        }

        return $data;
    }

    public function rewrite(string $content): LinkRewriteResult
    {
        Logger::debug("LinkRewriter::rewrite() called");

        $normalized = html_entity_decode(stripslashes($content));
        Logger::debug("Normalized content: $normalized");

        $urls = $this->extractor->extract($normalized);
        Logger::debug("Extracted URLs: " . json_encode($urls));

        if (empty($urls)) {
            Logger::debug("No URLs found");
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
            Logger::debug("Mapped: $original -> $rewritten (always using linksta for rewriting)");
        }

        $result = $this->replacer->replace($normalized, $urlMap);
        return new LinkRewriteResult($result->content, $result->rewritten);
    }
}
