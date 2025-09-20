<?php

namespace GrocersList;

use GrocersList\Admin\AjaxController;
use GrocersList\Admin\PostGating;
use GrocersList\Admin\SettingsPage;
use GrocersList\Database\UrlMappingTable;
use GrocersList\Frontend\ClientScripts;
use GrocersList\Frontend\PublicAjaxController;
use GrocersList\Jobs\LinkCountVisitor;
use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Service\ApiClient;
use GrocersList\Service\LinkRewriter;
use GrocersList\Service\UrlMappingService;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\ContentFilter;
use GrocersList\Support\Hooks;
use GrocersList\Support\LinkExtractor;
use GrocersList\Support\LinkReplacer;
use GrocersList\Support\Logger;
use GrocersList\Support\WordPressHooks;

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

        // Run installer to check for database updates
        require_once __DIR__ . '/Database/Installer.php';
        Database\Installer::install();

        $api = new ApiClient();
        $extractor = new LinkExtractor();
        $replacer = new LinkReplacer();
        $pluginSettings = new PluginSettings();
        $urlMappingTable = new UrlMappingTable();
        $urlMappingService = new UrlMappingService($api, $extractor, $urlMappingTable);

        $settings = new SettingsPage($this->hooks, $api, $pluginSettings);
        $settings->register();

        $rewriter = new LinkRewriter($api, $extractor, $replacer, $this->hooks, $pluginSettings, $urlMappingService);
        $rewriter->register();

        $migrationJob = new MigrationVisitor($rewriter, $urlMappingService, $extractor, $this->hooks, 50);
        $linkCountJob = new LinkCountVisitor($this->hooks, $extractor, 500, $urlMappingTable);

        $ajax = new AjaxController($pluginSettings, $api, $migrationJob, $linkCountJob, $this->hooks, $urlMappingTable);
        $ajax->register();

        $publicAjax = new PublicAjaxController($pluginSettings, $api, $this->hooks);
        $publicAjax->register();

        $clientScripts = new ClientScripts($this->hooks, $api, $pluginSettings);
        $clientScripts->register();

        $contentFilter = new ContentFilter($this->hooks, $pluginSettings, $urlMappingService, $api);
        $contentFilter->register();

        // Register post gating components
        $postGating = new PostGating($this->hooks);
        $postGating->register();

        self::$registered = true;
    }
}
