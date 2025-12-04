<?php

namespace GrocersList;

use GrocersList\Admin\AjaxController;
use GrocersList\Admin\CategoryGating;
use GrocersList\Admin\PageGating;
use GrocersList\Admin\PostGating;
use GrocersList\Admin\SettingsPage;
use GrocersList\Frontend\ClientScripts;
use GrocersList\Frontend\PublicAjaxController;
use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Service\LinkRewriter;
use GrocersList\Support\ContentFilter;
use GrocersList\Support\Logger;

class Plugin
{
    private static bool $registered = false;

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

        // Register the hook for async migration
        add_action('migration_visitor_run_async', ['\GrocersList\Jobs\MigrationVisitor', 'start']);

        $creatorSettingsFetcher = new CreatorSettingsFetcher();

        $linkRewriter = new LinkRewriter();
        $linkRewriter->register();

        $ajaxController = new AjaxController($creatorSettingsFetcher);
        $ajaxController->register();

        $publicAjaxController = new PublicAjaxController();
        $publicAjaxController->register();

        // UIs:
        $settingsPage = new SettingsPage($creatorSettingsFetcher);
        $settingsPage->register();

        $clientScripts = new ClientScripts($creatorSettingsFetcher);
        $clientScripts->register();

        $contentFilter = new ContentFilter($creatorSettingsFetcher);
        $contentFilter->register();

        // Register post, page, and category gating components
        $postGating = new PostGating();
        $postGating->register();

        $pageGating = new PageGating();
        $pageGating->register();

        $categoryGating = new CategoryGating();
        $categoryGating->register();

        self::$registered = true;
    }
}
