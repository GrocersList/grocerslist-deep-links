<?php

namespace GrocersList\Database;

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
        global $wpdb;
        
        // Include upgrade functions only if dbDelta is not already defined
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (file_exists($upgrade_file)) {
                require_once($upgrade_file);
            }
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create URL mappings table
        $url_table = $wpdb->prefix . 'grocerslist_url_mappings';
        $sql = "CREATE TABLE $url_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            original_url text NOT NULL,
            original_url_hash varchar(64) NOT NULL,
            linksta_url varchar(255) NOT NULL,
            link_hash varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_original_url (original_url_hash),
            KEY idx_post_id (post_id),
            KEY idx_link_hash (link_hash),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Verify table was created
        if ($wpdb->get_var("SHOW TABLES LIKE '$url_table'") !== $url_table) {
            Logger::debug('Failed to create URL mappings table');
            error_log('GrocersList: Failed to create URL mappings table');
        } else {
            Logger::debug('GrocersList URL mappings table created successfully');
        }
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options(): void
    {
        // Use new prefix for new installations
        add_option('grocerslist_auto_rewrite', true);
        add_option('grocerslist_use_linksta_links', true);
        add_option('grocerslist_setup_complete', false);
    }
    
    /**
     * Migrate options from old prefix to new prefix
     */
    private static function migrate_option_prefixes(): void
    {
        $options_to_migrate = [
            'grocers_list_api_key' => 'grocerslist_api_key',
            'grocers_list_auto_rewrite' => 'grocerslist_auto_rewrite',
            'grocers_list_use_linksta_links' => 'grocerslist_use_linksta_links',
            'grocers_list_setup_complete' => 'grocerslist_setup_complete',
            // Also migrate any count options that may exist
            'grocers_list_link_count_posts_with_links' => 'grocerslist_link_count_posts_with_links',
            'grocers_list_link_count_total_links' => 'grocerslist_link_count_total_links',
            'grocers_list_link_count_total_posts' => 'grocerslist_link_count_total_posts',
            'grocers_list_link_count_processed_posts' => 'grocerslist_link_count_processed_posts',
            'grocers_list_link_count_last_time' => 'grocerslist_link_count_last_time',
        ];
        
        foreach ($options_to_migrate as $old_key => $new_key) {
            $value = get_option($old_key, null);
            if ($value !== null) {
                // Only migrate if new key doesn't exist
                if (get_option($new_key, null) === null) {
                    update_option($new_key, $value);
                }
                // Delete old option after migration
                delete_option($old_key);
                Logger::debug("Migrated option from $old_key to $new_key");
            }
        }
        
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
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}grocerslist_url_mappings");
        
        // Remove options (both old and new prefixes)
        delete_option(self::DB_VERSION_OPTION);
        delete_option(self::OLD_DB_VERSION_OPTION);
        
        // Delete all plugin options with both prefixes
        $all_options = [
            'grocers_list_api_key', 'grocerslist_api_key',
            'grocers_list_auto_rewrite', 'grocerslist_auto_rewrite',
            'grocers_list_use_linksta_links', 'grocerslist_use_linksta_links',
            'grocers_list_setup_complete', 'grocerslist_setup_complete',
            'grocers_list_link_count_posts_with_links', 'grocerslist_link_count_posts_with_links',
            'grocers_list_link_count_total_links', 'grocerslist_link_count_total_links',
            'grocers_list_link_count_total_posts', 'grocerslist_link_count_total_posts',
            'grocers_list_link_count_processed_posts', 'grocerslist_link_count_processed_posts',
            'grocers_list_link_count_last_time', 'grocerslist_link_count_last_time',
            'grocerslist_settings'
        ];
        
        foreach ($all_options as $option) {
            delete_option($option);
        }
        
        Logger::debug('GrocersList plugin uninstalled');
    }
}