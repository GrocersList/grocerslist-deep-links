<?php

namespace GrocersList\Database;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

class Installer
{
    const DB_VERSION_OPTION = 'grocerslist_db_version';
    const OLD_DB_VERSION_OPTION = 'grocers_list_db_version';
    const CURRENT_DB_VERSION = '1.2.0';
    const OPTIONS_MIGRATION_VERSION = '1.2.0';

    /**
     * Run installation/upgrade routines
     */
    public static function install(): void
    {
        // Check both old and new version options
        $installed_version = get_option(self::DB_VERSION_OPTION, null);
        if ($installed_version === null) {
            $installed_version = get_option(self::OLD_DB_VERSION_OPTION, '0');
            // If we found an old version, migrate it
            if ($installed_version !== '0') {
                update_option(self::DB_VERSION_OPTION, $installed_version);
                delete_option(self::OLD_DB_VERSION_OPTION);
            }
        }
        
        if (version_compare($installed_version, self::CURRENT_DB_VERSION, '<')) {
            self::create_tables();
            self::set_default_options();
            
            // Run options migration if needed
            if (version_compare($installed_version, self::OPTIONS_MIGRATION_VERSION, '<')) {
                self::migrate_option_prefixes();
            }
            
            update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);
            
            Logger::debug('GrocersList database installed/upgraded to version ' . self::CURRENT_DB_VERSION);
        }
    }

    /**
     * Create all plugin tables
     */
    private static function create_tables(): void
    {
        // Include upgrade functions only if dbDelta is not already defined
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (file_exists($upgrade_file)) {
                require_once($upgrade_file);
            }
        }

        $urlMappingTable = new UrlMappingTable();
        $urlMappingTable->create_table();
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options(): void
    {
        $pluginSettings = new PluginSettings();
        $pluginSettings->set_defaults();
    }
    
    /**
     * Migrate options from old prefix to new prefix
     */
    private static function migrate_option_prefixes(): void
    {
        $pluginSettings = new PluginSettings();
        $pluginSettings->migrateAllOptions();

        Logger::debug('Option prefix migration completed');
    }

    /**
     * Uninstall plugin tables and data
     */
    public static function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        global $wpdb;
        
        // Drop tables
        $urlMappingTable = new UrlMappingTable();
        $urlMappingTable->drop_table();

        // Remove options (both old and new prefixes)
        delete_option(self::DB_VERSION_OPTION);
        delete_option(self::OLD_DB_VERSION_OPTION);
        
        $pluginSettings = new PluginSettings();
        $pluginSettings->reset();
        
        Logger::debug('GrocersList plugin uninstalled');
    }
}