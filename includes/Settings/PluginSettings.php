<?php

namespace GrocersList\Settings;

use GrocersList\Support\Logger;

class PluginSettings {
    private const PREFIX = 'grocerslist_';
    private const OLD_PREFIX = 'grocers_list_';

    private const KEY_API = 'api_key';
    private const KEY_AUTO = 'auto_rewrite';
    private const KEY_LINKSTA = 'use_linksta_links';

    private function all_keys() {
        return [
            // settings:
            self::KEY_API,
            self::KEY_AUTO,
            self::KEY_LINKSTA,
            // migration:
            'migration_migrated_posts',
            'migration_total_posts',
            'migration_last_started_at',
            'migration_last_completed_at',
            'migration_total_mappings',
        ];
    }

    public function set_defaults(): void {
        add_option(self::PREFIX . self::KEY_AUTO, true);
        add_option(self::PREFIX . self::KEY_LINKSTA, true);
    }

    // pass through to WP update_option, uses modern prefix
    public static function update_option(string $key, $value): bool {
        return update_option(self::PREFIX . $key, $value);
    }

    // same as WP get_option, but will check legacy prefix and migrate if needed
    public function get_option(string $key, $default): string {
        // Check new key first, fall back to old key
        $val = (string) get_option(self::PREFIX . $key, $default);

        if (empty($val)) {
            $val = (string) get_option(self::OLD_PREFIX . $key, $default);

            // Migrate if found under old key
            if (!empty($val)) {
                $this->migrateOption($key);
            }
        }

        return $val;
    }

    public function getApiKey(): string {
        return $this->get_option(self::KEY_API, '');
    }

    public function setApiKey(string $key): bool {
        $sanitized = sanitize_text_field($key);
        return $this->update_option(self::KEY_API, $sanitized);
    }

    // TODO: move these to be set in GL Mongo DB
    public function isAutoRewriteEnabled(): bool {
        return (bool) $this->get_option(self::KEY_AUTO, null);
    }

    public function setAutoRewrite(bool $enabled): bool {
        return $this->update_option(self::KEY_AUTO, $enabled);
    }

    public function isUseLinkstaLinksEnabled(): bool {
        return (bool) $this->get_option(self::KEY_LINKSTA, null);
    }

    public function setUseLinkstaLinks(bool $enabled): bool {
        $boolVal = $enabled ? '1' : '0';
        return $this->update_option(self::KEY_LINKSTA, $boolVal);
    }

    public function reset(): void {
        foreach ($this->all_keys() as $key) {
            delete_option(self::PREFIX . $key);
            delete_option(self::OLD_PREFIX . $key);
        }

        $this->set_defaults();
    }
    
    /**
     * Migrate a single option from old to new prefix
     */
    private function migrateOption(string $key): void {
        $oldVal = (string) get_option(self::OLD_PREFIX . $key, null);
        $newVal = (string) get_option(self::PREFIX . $key, null);

        if ($newVal == null && $oldVal != null) {
            $this->update_option($key, $oldVal);
            delete_option(self::OLD_PREFIX . $key);
        }
    }
    
    /**
     * Migrate all options from old to new prefix
     */
    public function migrateAllOptions(): void {
        foreach ($this->all_keys() as $key) {
            $this->migrateOption($key);
        }
        Logger::debug("PluginSettings::migrateAllOptions() completed");
    }
}
