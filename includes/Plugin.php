<?php

namespace GrocersList;

use GrocersList\Admin\SettingsPage;
use GrocersList\Admin\PostGating;
use GrocersList\Service\ApiClient;
use GrocersList\Service\LinkRewriter;
use GrocersList\Support\Hooks;
use GrocersList\Support\LinkExtractor;
use GrocersList\Support\LinkReplacer;
use GrocersList\Support\WordPressHooks;
use GrocersList\Support\ContentFilter;
use GrocersList\Support\GatingContentFilter;
use GrocersList\Admin\AjaxController;
use GrocersList\Frontend\PublicAjaxController;
use GrocersList\Frontend\ClientScripts;
use GrocersList\Settings\PluginSettings;
use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Jobs\LinkCountVisitor;
use GrocersList\Support\Logger;

class Plugin
{
    private Hooks $hooks;
    private static bool $registered = false;

    public function __construct(?Hooks $hooks = null)
    {
        $this->hooks = $hooks ?? new WordPressHooks();
    }

    public function register(): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            Logger::enable();
        } else {
            Logger::disable();
        }

        if (self::$registered) {
            Logger::debug('GrocersList\\Plugin::register() skipped, already registered.');
            return;
        }

        $api = new ApiClient();
        $extractor = new LinkExtractor();
        $replacer = new LinkReplacer();
        $pluginSettings = new PluginSettings();

        $rewriter = new LinkRewriter($api, $extractor, $replacer, $this->hooks, $pluginSettings);
        $settings = new SettingsPage($this->hooks, $api, $rewriter);
        $settings->register();
        $rewriter->register();

        $migrationJob = new MigrationVisitor($rewriter, $pluginSettings, $this->hooks, 50);
        $linkCountJob = new LinkCountVisitor($pluginSettings, $this->hooks, $extractor, 500);

        $ajax = new AjaxController($pluginSettings, $api, $migrationJob, $linkCountJob, $this->hooks);
        $ajax->register();

        $publicAjax = new PublicAjaxController($pluginSettings, $api, $this->hooks);
        $publicAjax->register();

        $clientScripts = new ClientScripts($this->hooks);
        $clientScripts->register();

        $contentFilter = new ContentFilter($this->hooks, $pluginSettings);
        $contentFilter->register();

        // Register post gating components
        $postGating = new PostGating($this->hooks);
        $postGating->register();

        $gatingContentFilter = new GatingContentFilter($this->hooks);
        $gatingContentFilter->register();

        self::$registered = true;
    }
}
