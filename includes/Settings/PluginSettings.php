<?php

namespace GrocersList\Settings;

use GrocersList\Support\Logger;

class PluginSettings {
    private const PREFIX = 'grocerslist_';
    private const OLD_PREFIX = 'grocers_list_';

    private const KEY_API = 'api_key';
    private const KEY_AUTO = 'auto_rewrite';
    private const KEY_LINKSTA = 'use_linksta_links';
    private const KEY_SETUP = 'setup_complete';

    private function key(string $suffix): string {
        return self::PREFIX . $suffix;
    }
    
    private function oldKey(string $suffix): string {
        return self::OLD_PREFIX . $suffix;
    }

    public function getApiKey(): string {
        // Check new key first, fall back to old key
        $key = (string) get_option($this->key(self::KEY_API), '');
        if (empty($key)) {
            $key = (string) get_option($this->oldKey(self::KEY_API), '');
            // Migrate if found under old key
            if (!empty($key)) {
                $this->migrateOption(self::KEY_API);
            }
        }
        Logger::debug("PluginSettings::getApiKey() => " . ($key ? '[REDACTED]' : '(empty)'));
        return $key;
    }

    public function setApiKey(string $key): void {
        $sanitized = sanitize_text_field($key);
        update_option($this->key(self::KEY_API), $sanitized);
        Logger::debug("PluginSettings::setApiKey() updated");
    }

    public function isAutoRewriteEnabled(): bool {
        // Check new key first, fall back to old key
        $option = get_option($this->key(self::KEY_AUTO), null);
        if ($option === null) {
            $option = get_option($this->oldKey(self::KEY_AUTO), true);
            // Migrate if found under old key
            if (get_option($this->oldKey(self::KEY_AUTO), null) !== null) {
                $this->migrateOption(self::KEY_AUTO);
            }
        }
        $val = (bool) $option;
        Logger::debug("PluginSettings::isAutoRewriteEnabled() => " . ($val ? 'true' : 'false'));
        return $val;
    }

    public function setAutoRewrite(bool $enabled): void {
        update_option($this->key(self::KEY_AUTO), $enabled);
        Logger::debug("PluginSettings::setAutoRewrite() => " . ($enabled ? 'true' : 'false'));
    }

    public function isUseLinkstaLinksEnabled(): bool {
        // Check new key first, fall back to old key
        $val = get_option($this->key(self::KEY_LINKSTA), null);
        if ($val === null) {
            $val = get_option($this->oldKey(self::KEY_LINKSTA), true);
            // Migrate if found under old key
            if (get_option($this->oldKey(self::KEY_LINKSTA), null) !== null) {
                $this->migrateOption(self::KEY_LINKSTA);
            }
        }
        $enabled = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        Logger::debug("PluginSettings::isUseLinkstaLinksEnabled() => " . ($enabled ? 'true' : 'false'));
        return $enabled;
    }

    public function setUseLinkstaLinks(bool $enabled): void {
        $boolVal = $enabled ? '1' : '0';
        update_option($this->key(self::KEY_LINKSTA), $boolVal);
        Logger::debug("PluginSettings::setUseLinkstaLinks() => " . ($enabled ? 'true' : 'false'));
    }

    public function isSetupComplete(): bool {
        // Check new key first, fall back to old key
        $option = get_option($this->key(self::KEY_SETUP), null);
        if ($option === null) {
            $option = get_option($this->oldKey(self::KEY_SETUP), false);
            // Migrate if found under old key
            if (get_option($this->oldKey(self::KEY_SETUP), null) !== null) {
                $this->migrateOption(self::KEY_SETUP);
            }
        }
        $val = (bool) $option;
        Logger::debug("PluginSettings::isSetupComplete() => " . ($val ? 'true' : 'false'));
        return $val;
    }

    public function markSetupComplete(): void {
        update_option($this->key(self::KEY_SETUP), true);
        Logger::debug("PluginSettings::markSetupComplete() called");
    }

    public function reset(): void {
        // Delete both old and new keys
        delete_option($this->key(self::KEY_API));
        delete_option($this->key(self::KEY_AUTO));
        delete_option($this->key(self::KEY_LINKSTA));
        delete_option($this->key(self::KEY_SETUP));
        
        // Also delete old keys if they exist
        delete_option($this->oldKey(self::KEY_API));
        delete_option($this->oldKey(self::KEY_AUTO));
        delete_option($this->oldKey(self::KEY_LINKSTA));
        delete_option($this->oldKey(self::KEY_SETUP));
        
        Logger::debug("PluginSettings::reset() all options deleted");
    }
    
    /**
     * Migrate a single option from old to new prefix
     */
    private function migrateOption(string $key): void {
        $oldValue = get_option($this->oldKey($key), null);
        if ($oldValue !== null) {
            update_option($this->key($key), $oldValue);
            delete_option($this->oldKey($key));
            Logger::debug("Migrated option from {$this->oldKey($key)} to {$this->key($key)}");
        }
    }
    
    /**
     * Migrate all options from old to new prefix
     */
    public function migrateAllOptions(): void {
        $keys = [self::KEY_API, self::KEY_AUTO, self::KEY_LINKSTA, self::KEY_SETUP];
        foreach ($keys as $key) {
            $this->migrateOption($key);
        }
        Logger::debug("PluginSettings::migrateAllOptions() completed");
    }
}
