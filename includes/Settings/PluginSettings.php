<?php

namespace GrocersList\Settings;

use GrocersList\Support\Logger;

class PluginSettings {
    private const PREFIX = 'grocers_list_';

    private const KEY_API = 'api_key';
    private const KEY_AUTO = 'auto_rewrite';
    private const KEY_LINKSTA = 'use_linksta_links';
    private const KEY_SETUP = 'setup_complete';

    private function key(string $suffix): string {
        return self::PREFIX . $suffix;
    }

    public function getApiKey(): string {
        $key = (string) get_option($this->key(self::KEY_API), '');
        Logger::debug("PluginSettings::getApiKey() => " . ($key ? '[REDACTED]' : '(empty)'));
        return $key;
    }

    public function setApiKey(string $key): void {
        $sanitized = sanitize_text_field($key);
        update_option($this->key(self::KEY_API), $sanitized);
        Logger::debug("PluginSettings::setApiKey() updated");
    }

    public function isAutoRewriteEnabled(): bool {
        $val = (bool) get_option($this->key(self::KEY_AUTO), true);
        Logger::debug("PluginSettings::isAutoRewriteEnabled() => " . ($val ? 'true' : 'false'));
        return $val;
    }

    public function setAutoRewrite(bool $enabled): void {
        update_option($this->key(self::KEY_AUTO), $enabled);
        Logger::debug("PluginSettings::setAutoRewrite() => " . ($enabled ? 'true' : 'false'));
    }

    public function isUseLinkstaLinksEnabled(): bool {
        $val = get_option($this->key(self::KEY_LINKSTA), true);
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
        $val = (bool) get_option($this->key(self::KEY_SETUP), false);
        Logger::debug("PluginSettings::isSetupComplete() => " . ($val ? 'true' : 'false'));
        return $val;
    }

    public function markSetupComplete(): void {
        update_option($this->key(self::KEY_SETUP), true);
        Logger::debug("PluginSettings::markSetupComplete() called");
    }

    public function reset(): void {
        delete_option($this->key(self::KEY_API));
        delete_option($this->key(self::KEY_AUTO));
        delete_option($this->key(self::KEY_LINKSTA));
        delete_option($this->key(self::KEY_SETUP));
        Logger::debug("PluginSettings::reset() all options deleted");
    }
}
