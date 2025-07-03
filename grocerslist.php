<?php
/*
Plugin Name: Grocers List Deep Links for Amazon
Plugin URI: https://grocerslist.com
Description: Automatically rewrites Amazon affiliate links with deep links using Grocers List's App Links Product Catalog.
Requires at least: 4.4
Requires PHP: 7.0
Tested up to: 6.8
Version: 1.0.0
Stable tag: 1.0.0
Author: Grocers List Engineering
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Author URI: https://github.com/GrocersList/grocerslist-wordpress-plugin
*/


if (!defined('ABSPATH')) exit;

define('GROCERS_LIST_VERSION', '1.0.0');

// Include configuration constants
require_once __DIR__ . '/includes/Support/config-constants.php';

require_once __DIR__ . '/includes/Plugin.php';

spl_autoload_register(function ($class) {
    if (strpos($class, 'GrocersList\\') !== 0) return;
    $path = __DIR__ . '/includes/' . str_replace('GrocersList\\', '', $class);
    $path = str_replace('\\', '/', $path) . '.php';
    if (file_exists($path)) require_once $path;
});

(new GrocersList\Plugin())->register();

