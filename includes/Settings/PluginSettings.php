<?php

namespace GrocersList\Settings;

use GrocersList\Jobs\MigrationVisitor;
use GrocersList\Support\Logger;

class PluginSettings {
    private const PREFIX = 'grocerslist_';
    private const OLD_PREFIX = 'grocers_list_';

    private const KEY_API = 'api_key';
    private const KEY_LINKSTA = 'use_linksta_links';

    static function all_keys() {
        return [
            // settings:
            self::KEY_API,
            self::KEY_LINKSTA,
            // migration:
            'migration_migrated_posts',
            'migration_total_posts',
            'migration_last_started_at',
            'migration_last_completed_at',
            'migration_total_mappings',
        ];
    }

    // pass through to WP update_option, uses modern prefix
    static function update_option(string $key, $value): bool {
        return update_option(self::PREFIX . $key, $value);
    }

    // same as WP get_option, but will check legacy prefix and migrate if needed
    static function get_option(string $key, $default): string {
        // Check new key first, fall back to old key
        $val = (string) get_option(self::PREFIX . $key, $default);

        if (empty($val)) {
            $val = (string) get_option(self::OLD_PREFIX . $key, $default);

            // Migrate if found under old key
            if (!empty($val)) {
                self::migrateOption($key);
            }
        }

        return $val;
    }

    static function getApiKey(): string {
        return self::get_option(self::KEY_API, '');
    }

    static function setApiKey(string $key): bool {
        $sanitized = sanitize_text_field($key);
        return self::update_option(self::KEY_API, $sanitized);
    }

    static function isUseLinkstaLinksEnabled(): bool {
        return (bool) self::get_option(self::KEY_LINKSTA, null);
    }

    static function setUseLinkstaLinks(bool $enabled): bool {
        $boolVal = $enabled ? '1' : '0';
        return self::update_option(self::KEY_LINKSTA, $boolVal);
    }

    static function reset(): void {
        foreach (self::all_keys() as $key) {
            delete_option(self::PREFIX . $key);
            delete_option(self::OLD_PREFIX . $key);
        }

        MigrationVisitor::reset();
    }

    /**
     * Migrate a single option from old to new prefix
     */
    static function migrateOption(string $key): void {
        $oldVal = (string) get_option(self::OLD_PREFIX . $key, null);
        $newVal = (string) get_option(self::PREFIX . $key, null);

        if ($newVal == null && $oldVal != null) {
            self::update_option($key, $oldVal);
            delete_option(self::OLD_PREFIX . $key);
        }
    }

    /**
     * Migrate all options from old to new prefix
     */
    static function migrateAllOptions(): void {
        foreach (self::all_keys() as $key) {
            self::migrateOption($key);
        }
        Logger::debug("PluginSettings::migrateAllOptions() completed");
    }
}
