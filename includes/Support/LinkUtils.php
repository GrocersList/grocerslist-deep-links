<?php
namespace GrocersList\Support;

use GrocersList\Support\Config;

class LinkUtils {
    public static function buildLinkstaUrl(string $hash): string {
        $sub = Config::getLinkstaSubdomain();
        $prefix = $sub ? "$sub." : '';
        return "https://{$prefix}linksta.io/{$hash}";
    }
}
