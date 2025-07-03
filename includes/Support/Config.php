<?php
namespace GrocersList\Support;

class Config {
    public static function getApiSubdomain(): string {
        if (!defined('GROCERSLIST_API_SUBDOMAIN')) {
            throw new \RuntimeException('GROCERSLIST_API_SUBDOMAIN is not defined');
        }
        return GROCERSLIST_API_SUBDOMAIN;
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
}
