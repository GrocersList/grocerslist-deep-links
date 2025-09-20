<?php
namespace GrocersList\Support;

class Config {
    public static function getApiBaseDomain(): string {
        if (!defined('GROCERSLIST_API_BASE_DOMAIN')) {
            throw new \RuntimeException('GROCERSLIST_API_BASE_DOMAIN is not defined');
        }
        return GROCERSLIST_API_BASE_DOMAIN;
    }

    public static function getLinkstaSubdomain(): string {
        if (!defined('GROCERSLIST_LINKSTA_SUBDOMAIN')) {
            throw new \RuntimeException('GROCERSLIST_LINKSTA_SUBDOMAIN is not defined');
        }
        return GROCERSLIST_LINKSTA_SUBDOMAIN;
    }

    public static function getExternalJsUrl(): string {
        if (!defined('GROCERSLIST_EXTERNAL_JS_URL')) {
            throw new \RuntimeException('GROCERSLIST_EXTERNAL_JS_URL is not defined');
        }
        return GROCERSLIST_EXTERNAL_JS_URL;
    }

    /**
     * Get plugin version using WordPress's built-in versioning system
     * Falls back to the constant if plugin data is not available
     */
    public static function getPluginVersion(): string {
        static $version = null;
        
        if ($version === null) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            if (defined('GROCERS_LIST_PLUGIN_FILE') && file_exists(GROCERS_LIST_PLUGIN_FILE)) {
                $plugin_data = get_plugin_data(GROCERS_LIST_PLUGIN_FILE);
                $version = $plugin_data['Version'] ?? GROCERS_LIST_VERSION;
            } else {
                $version = GROCERS_LIST_VERSION;
            }
        }
        
        return $version;
    }
}
